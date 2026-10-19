<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\Lead;

/**
 * Fuente única de verdad sobre qué teléfonos pertenecen a un cliente.
 *
 * Replica exactamente las tres fuentes que ya usa WhatsappWebhookController para enrutar
 * un mensaje entrante a soporte: los empleados del cliente, el teléfono de la ficha, y
 * como último recurso el lead que fue promovido a ese cliente. Si un número no está en
 * ninguna de las tres, el webhook no lo va a reconocer como cliente y la respuesta va a
 * caer en el pipeline de leads: por eso el alta de conversación valida contra acá.
 *
 * Desde el 19/10/2026 (misión asistente-arreglos-segunda-prueba, N11) es también la guarda de
 * los seguimientos automáticos de lead: {@see es_lead_de_un_cliente()} le dice al cron de
 * seguimientos, al respaldo de auto-envío y a la página sin demo que ese número es de un cliente
 * y que no le escriban como si fuera un lead.
 */
class ClientPhoneDirectory
{
    /**
     * Prefijo de las claves del índice que guardan los DÍGITOS PELADOS del teléfono guardado (sin
     * normalizar). Es la mitad del índice que sirve para un destino que no es argentino: ahí
     * `equals_estricto()` compara dígito a dígito, sin `normalize()`. Las claves sin prefijo son
     * E.164 canónicos (empiezan con `+`), así que las dos familias no pueden chocar.
     */
    private const PREFIJO_DIGITOS = 'd:';

    /**
     * Separadores con los que se cargan dos o más números en el mismo campo de teléfono:
     * `3454622139 / 3454000000`, `…, …`, `…; …` y `… y …` (la «y» como palabra suelta, para no partir
     * nada que la tenga adentro). Ver candidatos_del_campo().
     */
    private const SEPARADORES_DE_TELEFONOS = '/\s*[\/,;]\s*|\s+y\s+/iu';

    /**
     * Índice de los teléfonos de clientes para {@see es_telefono_de_cliente()}: clave de búsqueda →
     * teléfonos tal como están guardados en la ficha. Se arma UNA vez por instancia, en la primera
     * consulta, y vale para toda la corrida (el cron de seguimientos recorre cientos de leads: sin
     * el índice serían cuatro consultas por lead). Null hasta que se arma.
     *
     * 🔴 Por eso los que lo usan crean una instancia por corrida (`new ClientPhoneDirectory()`) y no
     * la guardan en una propiedad de vida larga: un worker de cola que viviera horas con el mismo
     * índice no vería un cliente dado de alta en el medio.
     *
     * @var array<string, array<int, string>>|null
     */
    private $indice_de_telefonos_de_clientes = null;

    /**
     * Lista los teléfonos elegibles de un cliente, listos para mostrar en un select.
     *
     * @param Client $client Cliente activo del que se quieren los contactos.
     *
     * @return array<int, array<string, mixed>> Filas con label, phone (E.164), raw_phone,
     *                                          client_employee_id y origin.
     */
    public function phones_for_client(Client $client): array
    {
        $rows = [];
        $seen = [];

        // Empleados primero: el webhook también los prioriza, así el ticket queda con
        // client_employee_id y el hilo no se parte cuando el empleado responde.
        $client->loadMissing('client_employees');
        foreach ($client->client_employees as $client_employee) {
            $raw_phone = trim((string) ($client_employee->phone ?? ''));
            if ($raw_phone === '') {
                continue;
            }

            $normalized = WhatsappNormalizer::normalize($raw_phone);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $employee_name = trim((string) ($client_employee->name ?? ''));

            $seen[$normalized] = true;
            $rows[] = [
                'label'              => $employee_name !== '' ? $employee_name : 'Empleado',
                'phone'              => $normalized,
                'raw_phone'          => $raw_phone,
                'client_employee_id' => (int) $client_employee->id,
                'origin'             => 'employee',
            ];
        }

        // Teléfono de la ficha del cliente.
        $client_phone = trim((string) ($client->phone ?? ''));
        if ($client_phone !== '') {
            $normalized = WhatsappNormalizer::normalize($client_phone);
            if ($normalized !== '' && ! isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $rows[] = [
                    'label'              => $client->resolve_display_name(),
                    'phone'              => $normalized,
                    'raw_phone'          => $client_phone,
                    'client_employee_id' => null,
                    'origin'             => 'client',
                ];
            }
        }

        // Lead promovido: el webhook lo usa como fallback cuando clients.phone está vacío.
        $promoted_leads = Lead::query()
            ->where('promoted_client_id', $client->id)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get();

        foreach ($promoted_leads as $lead) {
            $raw_phone = trim((string) $lead->phone);
            if ($raw_phone === '') {
                continue;
            }

            $normalized = WhatsappNormalizer::normalize($raw_phone);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $lead_name = trim((string) ($lead->contact_name ?? ''));

            $seen[$normalized] = true;
            $rows[] = [
                'label'              => $lead_name !== '' ? $lead_name . ' (del lead)' : 'Contacto del lead',
                'phone'              => $normalized,
                'raw_phone'          => $raw_phone,
                'client_employee_id' => null,
                'origin'             => 'lead',
            ];
        }

        return $rows;
    }

    /**
     * Busca un teléfono dentro de los contactos del cliente.
     *
     * Primero por igualdad exacta del E.164 y recién después con el phones_match() del
     * webhook: ese último cae a comparar los últimos ocho dígitos, y usarlo solo haría que un
     * cliente con dos contactos de provincias distintas resuelva al equivocado. La pasada
     * laxa se conserva para que un teléfono tipeado a mano en otro formato siga resolviendo,
     * que es el criterio con el que el webhook enruta la respuesta.
     *
     * @param Client $client Cliente dueño de la conversación.
     * @param string $phone  Teléfono elegido por el operador, en cualquier formato.
     *
     * @return array<string, mixed>|null Fila de phones_for_client(), o null si no pertenece.
     */
    public function resolve_for_client(Client $client, string $phone)
    {
        if (trim($phone) === '') {
            return null;
        }

        $rows = $this->phones_for_client($client);
        $normalized = WhatsappNormalizer::normalize($phone);

        // Primero la igualdad exacta. El select del modal manda el número ya normalizado, así
        // que casi siempre entra por acá; sin esta pasada, un empleado de Rosario y otro de
        // Córdoba que compartan los últimos ocho dígitos se pisan entre sí.
        foreach ($rows as $row) {
            if ($normalized !== '' && (string) $row['phone'] === $normalized) {
                return $row;
            }
        }

        // Recién después el criterio laxo, que es el mismo con el que el webhook enruta: un
        // teléfono tipeado a mano en otro formato tiene que seguir resolviendo.
        foreach ($rows as $row) {
            if (WhatsappNormalizer::phones_match((string) $row['phone'], $phone)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Indica si el cliente tiene al menos un teléfono por el que se lo pueda reconocer.
     *
     * @param Client $client Cliente a evaluar.
     *
     * @return bool
     */
    public function has_any_phone(Client $client): bool
    {
        return count($this->phones_for_client($client)) > 0;
    }

    /**
     * ¿Este lead es, en realidad, un cliente? Si lo es, no recibe seguimientos automáticos.
     *
     * 🔴 Por qué existe (misión asistente-arreglos-segunda-prueba, N11, 19/10/2026): el 18/10 a las
     * 00:00 el cron `leads:check-followups` le mandó «Hace un par de días te escribimos…» al
     * 3444622139, que es el teléfono del dueño de demo3 (cliente 58), porque el lead 644 seguía vivo
     * con ese mismo número. El cron miraba el estado del lead y nada más.
     *
     * Un lead es «de un cliente» en dos casos:
     *   - fue promovido (`promoted_client_id` cargado): ya es cliente, lo lleve quien lo lleve, y el
     *     seguimiento de un lead no tiene nada que decirle. Vale aunque el cliente esté dado de baja:
     *     ese lead puntual ya cerró su ciclo comercial;
     *   - su teléfono es el de un cliente activo, el de un empleado de un cliente activo, el de un
     *     lead promovido a un cliente activo o el de un admin ({@see es_telefono_de_cliente()}).
     *
     * Lo usan los tres caminos automáticos que le escriben a un lead sin que nadie apriete nada:
     * `LeadFollowupService::process_lead()`, el respaldo de auto-envío de un seguimiento retenido
     * (`LeadAiSuggestionAutoSendScheduler` / `AutoSendLeadAiSuggestionJob`) y
     * `leads:check-pagina-sin-demo`. NO lo usan el botón manual de forzar seguimiento, los mensajes
     * que programa un operador ni los recordatorios atados a una demo agendada: ahí hay una persona o
     * un turno detrás, no la cadencia ciega del cron.
     *
     * @param Lead $lead Lead a evaluar.
     *
     * @return bool true si el lead es un cliente (y no hay que mandarle seguimientos automáticos).
     */
    public function es_lead_de_un_cliente(Lead $lead): bool
    {
        if (! empty($lead->promoted_client_id)) {
            return true;
        }

        return $this->es_telefono_de_cliente((string) ($lead->phone ?? ''));
    }

    /**
     * ¿Un mensaje dirigido a este teléfono le llegaría a un cliente (o a un admin)?
     *
     * El conjunto de teléfonos «de cliente» sale de:
     *   1. `clients.phone` de los clientes ACTIVOS (`is_active = true`, la misma columna con la que
     *      `WhatsappWebhookController::find_client_by_phone()` decide quién es cliente). Un cliente
     *      dado de baja no cuenta: el que dejó de serlo puede volver a ser un lead y recibir lo suyo;
     *   2. `client_employees.phone` de esos mismos clientes activos;
     *   3. `leads.phone` de los leads promovidos a un cliente activo — la tercera fuente con la que el
     *      webhook reconoce a un cliente cuando la ficha no tiene el teléfono (ver la clase);
     *   4. `admins.phone_number` de todos los admins: el equipo no es un lead.
     *
     * 🔴 La comparación es ESTRICTA y NUNCA `WhatsappNormalizer::phones_match()`. `phones_match()` cae
     * a los últimos ocho dígitos, y con eso un lead real de Buenos Aires (11 4462-2139) quedaría
     * callado por el dueño de Concordia (344 462-2139): la guarda le apagaría los seguimientos a
     * gente que no es cliente. Lo que se compara es el DESTINO REAL del mensaje —`normalize()` del
     * teléfono del lead, que es exactamente lo que `WhatsappSendService` le pasa a Meta en `to`—
     * contra cada teléfono guardado con `equals_estricto()`, el comparador «de dueño» del canal del
     * asistente. Así `3444622139` en la ficha y `+5493444622139` en el lead (o al revés) son el mismo
     * número, y `+5491144622139` no.
     *
     * El índice se arma una vez por instancia (ver {@see $indice_de_telefonos_de_clientes}); acá solo
     * se buscan los candidatos por clave y se confirma cada uno con `equals_estricto()`, que es el
     * único que decide. Una ficha cargada con el 15 del celular o con varios números en el mismo campo
     * entra al índice con cada número completo que se puede leer en ella (ver candidatos_del_campo());
     * una sin código de área no se puede resolver y no calla a nadie.
     *
     * @param string $phone Teléfono del lead, en cualquier formato.
     *
     * @return bool true si el número es de un cliente activo, de su equipo, o de un admin.
     */
    public function es_telefono_de_cliente(string $phone): bool
    {
        if (trim($phone) === '') {
            return false;
        }

        /* El destino real del mensaje: WhatsappSendService normaliza el `to` con esta misma función
           antes de mandarlo, así que es el número al que de verdad le llegaría. */
        $destino = WhatsappNormalizer::normalize($phone);
        $digitos = preg_replace('/\D+/', '', $destino) ?? '';
        if ($destino === '' || $digitos === '') {
            return false;
        }

        $indice = $this->indice_de_telefonos_de_clientes();

        $candidatos = [];
        foreach ([$destino, self::PREFIJO_DIGITOS . $digitos] as $clave) {
            if (isset($indice[$clave])) {
                $candidatos = array_merge($candidatos, $indice[$clave]);
            }
        }

        foreach ($candidatos as $guardado) {
            if (WhatsappNormalizer::equals_estricto($guardado, $destino)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Arma (una sola vez por instancia) el índice de teléfonos de clientes.
     *
     * Cada teléfono guardado entra con las claves bajo las que `equals_estricto()` lo podría dar por
     * igual a un destino, para que la búsqueda no se pierda ningún candidato:
     *   - su E.164 canónico (`normalize()`): el caso de un destino argentino, que es casi todos;
     *   - si son once dígitos que empiezan con 9 (el móvil argentino sin el 54, `93434544199`), también
     *     el canónico con el 54 por delante: es la segunda vuelta que da `equals_estricto()`;
     *   - sus dígitos pelados con {@see PREFIJO_DIGITOS}: el caso de un destino de otro país, donde
     *     `equals_estricto()` compara dígito a dígito.
     * El índice solo propone candidatos; la decisión la toma siempre `equals_estricto()`.
     *
     * Cuatro consultas en total, sin importar cuántos leads se evalúen después.
     *
     * @return array<string, array<int, string>> Clave → teléfonos tal como están guardados.
     */
    private function indice_de_telefonos_de_clientes(): array
    {
        if ($this->indice_de_telefonos_de_clientes !== null) {
            return $this->indice_de_telefonos_de_clientes;
        }

        $guardados = [];

        $clientes_activos = Client::query()
            ->where('is_active', true)
            ->get(['id', 'phone']);

        $ids_activos = [];
        foreach ($clientes_activos as $cliente) {
            $ids_activos[] = (int) $cliente->id;
            $guardados[]   = (string) ($cliente->phone ?? '');
        }

        if (! empty($ids_activos)) {
            $telefonos_de_empleados = ClientEmployee::query()
                ->whereIn('client_id', $ids_activos)
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->pluck('phone')
                ->all();

            $telefonos_de_leads_promovidos = Lead::query()
                ->whereIn('promoted_client_id', $ids_activos)
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->pluck('phone')
                ->all();

            $guardados = array_merge($guardados, $telefonos_de_empleados, $telefonos_de_leads_promovidos);
        }

        $telefonos_de_admins = Admin::query()
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->pluck('phone_number')
            ->all();

        $guardados = array_merge($guardados, $telefonos_de_admins);

        /* Cada campo puede traer más de un número, o el número con el 15 del celular: se indexan
           todos los números COMPLETOS que se pueden leer en él (ver candidatos_del_campo()). */
        $candidatos = [];
        foreach ($guardados as $campo) {
            foreach (self::candidatos_del_campo((string) $campo) as $candidato) {
                $candidatos[] = $candidato;
            }
        }

        $indice = [];
        foreach ($candidatos as $guardado) {
            $guardado = trim((string) $guardado);
            $digitos  = preg_replace('/\D+/', '', $guardado) ?? '';
            if ($digitos === '') {
                continue;
            }

            $claves = [WhatsappNormalizer::normalize($guardado), self::PREFIJO_DIGITOS . $digitos];
            if (strlen($digitos) === 11 && $digitos[0] === '9') {
                $claves[] = WhatsappNormalizer::normalize('54' . $digitos);
            }

            foreach (array_unique($claves) as $clave) {
                if ($clave === '' || $clave === self::PREFIJO_DIGITOS) {
                    continue;
                }
                $indice[$clave][] = $guardado;
            }
        }

        $this->indice_de_telefonos_de_clientes = $indice;

        return $indice;
    }

    /**
     * Los números completos que se pueden leer en un campo de teléfono cargado a mano.
     *
     * 🔴 Revisión independiente (misión asistente-arreglos-segunda-prueba, 19/10/2026): la guarda no
     * callaba al dueño cuando la ficha traía el 15 del celular (`(0345) 15-462-2139`,
     * `345 15 4622139`, `+54 345 15 4622139`) o dos números en el mismo campo
     * (`3454622139 / 3454000000`): `normalize()` no saca el 15 ni parte el campo, así que el número
     * guardado no coincidía con el destino. Devuelve, sin repetir:
     *   - el campo entero, como estaba (lo que había antes de esta corrección);
     *   - cada número del campo, partido por `/`, `,`, `;` y ` y ` ({@see SEPARADORES_DE_TELEFONOS});
     *   - de cada uno de esos, la versión SIN el 15 ({@see variantes_sin_el_15()}).
     *
     * Siempre son números COMPLETOS (área + abonado): la comparación sigue siendo `equals_estricto()`
     * contra el destino entero y nunca por los últimos dígitos.
     *
     * ⚠️ Lo que no se puede resolver, y se deja así a propósito: un número sin código de área
     * (`4622139`, `15-462-2139`). Para leerlo habría que adivinar el área o comparar por sufijo, que es
     * exactamente lo que le callaría los seguimientos a un lead real de otra ciudad. Esa ficha no calla
     * a nadie; la solución es cargarla completa.
     *
     * @param string $campo Contenido crudo del campo de teléfono.
     *
     * @return array<int, string>
     */
    private static function candidatos_del_campo(string $campo): array
    {
        $campo = trim($campo);
        if ($campo === '') {
            return [];
        }

        $numeros = [$campo];
        $partes  = preg_split(self::SEPARADORES_DE_TELEFONOS, $campo);
        if (is_array($partes) && count($partes) > 1) {
            foreach ($partes as $parte) {
                $parte = trim((string) $parte);
                if ($parte !== '') {
                    $numeros[] = $parte;
                }
            }
        }

        $candidatos = $numeros;
        foreach ($numeros as $numero) {
            foreach (self::variantes_sin_el_15($numero) as $variante) {
                $candidatos[] = $variante;
            }
        }

        return array_values(array_unique($candidatos));
    }

    /**
     * El número sin el 15 del celular, si lo tiene: los 10 dígitos de área + abonado.
     *
     * El 15 es el prefijo de móvil que se marcaba dentro del país entre el código de área y el
     * abonado (`0345 15 462-2139`); WhatsApp no lo usa (`+54 9 345 462-2139`). Se reconoce así:
     * quitados el 0 de larga distancia, el 54 del país y el 9 de móvil internacional, quedan 12
     * dígitos —uno de área de 2 a 4, el 15 y el abonado— y al sacar el 15 quedan los 10 de un número
     * argentino completo. Si hay más de una lectura posible (el 15 podría estar después de un área de
     * 2, de 3 o de 4 dígitos), se devuelven todas: cada una es un número completo y distinto, y solo
     * calla al lead cuyo destino es exactamente ese número.
     *
     * @param string $numero Un solo número, en cualquier formato.
     *
     * @return array<int, string> Cero, una o varias variantes de 10 dígitos.
     */
    private static function variantes_sin_el_15(string $numero): array
    {
        $digitos = ltrim(preg_replace('/\D+/', '', $numero) ?? '', '0');

        if (strpos($digitos, '54') === 0) {
            $digitos = ltrim(substr($digitos, 2), '0');
        }
        if (strlen($digitos) === 13 && $digitos[0] === '9') {
            $digitos = substr($digitos, 1);
        }

        if (strlen($digitos) !== 12) {
            return [];
        }

        $variantes = [];
        foreach ([2, 3, 4] as $largo_del_area) {
            if (substr($digitos, $largo_del_area, 2) === '15') {
                $variantes[] = substr($digitos, 0, $largo_del_area) . substr($digitos, $largo_del_area + 2);
            }
        }

        return $variantes;
    }
}
