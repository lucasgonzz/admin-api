<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Helpers\WhatsappNormalizer;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\Pipelines\PipelineRuleException;
use App\Services\Pipelines\TableroDeJuanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Alta de un lead que llegó por OTRO medio (misión leads-referidos-compromisos, etapa E1).
 *
 * Un referido, alguien que le escribió a Lucas por su WhatsApp personal o que ya habló por teléfono no pasó por el
 * circuito pensado para los leads (anuncio o web → onboarding → Martín → prueba → Juan). Esta clase lo da de alta
 * con de dónde vino, quién lo recomendó y lo que ya se habló, y lo deja listo para entrar a ese mismo circuito:
 *
 *  - Valida y devuelve el 422 con `errors.<campo>` (misma excepción que el resto del CRM de pipelines).
 *  - Si el teléfono ya tiene lead, NO crea otro: avisa el conflicto, o con `completar_existente` completa ESE lead
 *    (origen sólo si no tenía uno real, quién lo refirió sólo si estaba vacío, y el contexto se AGREGA con encabezado).
 *  - Deja un evento en el hilo (`lead_alta_externa`): hace que el onboarding genérico no corra (hay historial) y le
 *    cuenta a quien abra la conversación cómo llegó.
 *  - Entra al tablero de Juan si nace en `link_enviado` o se pide (en un alta no corre `Lead::saved`, así que se hace
 *    explícito).
 *  - Si se pide, manda el primer contacto por la plantilla de referido, SÓLO si está registrada, activa y aprobada.
 *  - Con `$simular` devuelve lo que haría sin escribir nada (el `dry_run` de `claude/*`).
 *
 * 🔴 Dar de alta NO manda ningún WhatsApp por sí solo: el primer contacto es opt-in (`primer_contacto`).
 * 🔴 Lo que se hace después de crear el lead (tablero, envío) se traga sus fallas: el lead ya está creado y no puede
 * quedar a medias con un 500.
 *
 * La lógica vive acá y no en los controladores (`LeadAltaExternaController` y `Api\ClaudeLeadsExternosController`
 * sólo traducen a HTTP). Además arma el bloque «CÓMO LLEGÓ ESTE LEAD» que lee Martín ({@see bloque_para_el_agente()}).
 */
class LeadAltaExternaService
{
    /** Tope de caracteres del contexto previo (suma de todo lo que se fue agregando). */
    const MAX_CONTEXTO = 60000;

    /** Cuántos caracteres del contexto previo se le muestran a Martín en cada turno. */
    const MAX_CONTEXTO_PARA_EL_AGENTE = 4000;

    /** Máximo de `referido_por_texto` (columna string(120)). */
    const MAX_REFERIDO_TEXTO = 120;

    /** Estados en los que puede nacer un lead de otro medio. */
    const ESTADOS_INICIALES = ['nuevo', 'contactado', 'link_enviado'];

    /** Primer contacto: no se manda nada. */
    const PRIMER_CONTACTO_NINGUNO = 'ninguno';

    /** Primer contacto: plantilla de referido (si está registrada y aprobada). */
    const PRIMER_CONTACTO_PLANTILLA = 'plantilla_referido';

    /** `system_message_kind` del evento que deja el alta en el hilo. */
    const KIND_ALTA_EXTERNA = 'lead_alta_externa';

    /** `system_message_kind` del mensaje del primer contacto por plantilla. */
    const KIND_PRIMER_CONTACTO = 'referido_primer_contacto';

    /** Clave de `admin_settings` con el nombre de la plantilla de referido en Meta. */
    const SETTING_PLANTILLA = 'lead_referido_plantilla';

    /** Texto con el que cada origen se nombra en el hilo y en la nota del tablero. */
    const ETIQUETAS_DE_ORIGEN = [
        LeadOrigenService::ORIGEN_REFERIDO          => 'referido',
        LeadOrigenService::ORIGEN_WHATSAPP_PERSONAL => 'WhatsApp personal',
        LeadOrigenService::ORIGEN_LLAMADA           => 'llamada',
        LeadOrigenService::ORIGEN_OTRO              => 'otro medio',
    ];

    /**
     * Da de alta (o completa) un lead de otro medio.
     *
     * @param array<string, mixed>   $datos   Cuerpo del pedido: phone, contact_name, origen, referido_por_client_id,
     *                                        referido_por_texto, contexto_previo, estado_inicial, company_name,
     *                                        business_type, email, entrar_al_tablero, primer_contacto,
     *                                        completar_existente.
     * @param \App\Models\Admin|null $actor   Quien lo carga (null = Claude por `claude/*`).
     * @param bool                   $simular true = no escribe nada y devuelve lo que haría.
     *
     * @return array<string, mixed> `conflicto` (bool: el teléfono ya tiene lead y no se pidió completar), `lead_id`
     *                              (del existente en un conflicto), `lead` (Lead|null), `creado`, `completado`,
     *                              `simulado`, `primer_contacto` ({estado, motivo?}) y `que_haria` (sólo simulando).
     *
     * @throws PipelineRuleException 422 con `errors.<campo>`.
     */
    public function crear(array $datos, $actor, bool $simular): array
    {
        $limpios = $this->validar($datos);

        $existente = BuscadorDeLeadPorTelefono::buscar($limpios['phone']);
        if ($existente !== null && ! $limpios['completar_existente']) {
            return [
                'conflicto'       => true,
                'lead_id'         => (int) $existente->id,
                'lead'            => $existente,
                'creado'          => false,
                'completado'      => false,
                'simulado'        => $simular,
                'primer_contacto' => ['estado' => 'no_enviado', 'motivo' => 'lead_existente'],
            ];
        }

        if ($existente !== null) {
            return $this->completar($existente, $limpios, $actor, $simular);
        }

        return $this->alta_nueva($limpios, $actor, $simular);
    }

    /**
     * Agrega texto al `contexto_previo` de un lead (lo que usa `claude/leads/{id}/contexto-previo`).
     *
     * 🔴 AGREGA con encabezado `— dd/mm/aaaa HH:MM (<fuente>) —`: nunca reemplaza lo que ya estaba, que puede ser lo que
     * cargó una persona. Con el total por encima de 60.000 caracteres responde 422 y no cambia nada.
     *
     * @param Lead   $lead
     * @param string $texto  Lo que se agrega.
     * @param string $fuente De dónde sale (por ejemplo «whatsapp personal de Lucas»).
     * @param bool   $simular true = devuelve cómo quedaría sin escribir.
     *
     * @return array<string, mixed> `lead`, `simulado`, `largo_total`, `agregado`.
     *
     * @throws PipelineRuleException 422 si el texto está vacío, la fuente es muy larga o se pasa del tope.
     */
    public function agregar_contexto(Lead $lead, string $texto, string $fuente, bool $simular): array
    {
        $texto  = trim($texto);
        $fuente = trim($fuente);

        $errores = [];
        if ($texto === '') {
            $errores['texto'][] = 'Escribí el texto que se agrega al contexto.';
        }
        if (mb_strlen($fuente) > 80) {
            $errores['fuente'][] = 'La fuente no puede tener más de 80 caracteres.';
        }

        $nuevo = $this->unir_contexto((string) $lead->contexto_previo, $texto, $fuente !== '' ? $fuente : 'sin fuente');
        if (mb_strlen($nuevo) > self::MAX_CONTEXTO) {
            $errores['texto'][] = 'El contexto previo no puede pasar de ' . self::MAX_CONTEXTO . ' caracteres en total.';
        }
        if ($errores !== []) {
            throw PipelineRuleException::validacion($errores);
        }

        if (! $simular) {
            $lead->contexto_previo = $nuevo;
            $lead->save();
        }

        return ['lead' => $lead, 'simulado' => $simular, 'largo_total' => mb_strlen($nuevo), 'agregado' => $texto];
    }

    /**
     * El bloque «CÓMO LLEGÓ ESTE LEAD» que Martín lee en cada turno con un lead de otro medio.
     *
     * @param Lead $lead
     *
     * @return string '' si el lead no es externo.
     */
    public function bloque_para_el_agente(Lead $lead): string
    {
        if (! LeadOrigenService::es_externo($lead)) {
            return '';
        }

        $etiqueta  = $this->etiqueta_del_origen($lead);
        $referente = $this->describir_referente($lead);

        $bloque  = 'CÓMO LLEGÓ ESTE LEAD: ' . $etiqueta;
        $bloque .= $referente !== '' ? ', referido por ' . $referente . '.' : '.';
        $bloque .= ' Ya habló con nosotros antes';

        $contexto = trim((string) $lead->contexto_previo);
        if ($contexto !== '') {
            if (mb_strlen($contexto) > self::MAX_CONTEXTO_PARA_EL_AGENTE) {
                $contexto = mb_substr($contexto, 0, self::MAX_CONTEXTO_PARA_EL_AGENTE) . '…';
            }
            $bloque .= '; lo que se habló: ' . $contexto;
        } else {
            $bloque .= '.';
        }

        $bloque .= "\nNo le presentes ComercioCity desde cero, no le repitas preguntas que ya contestó"
            . ($referente !== '' ? ' y nombrá a quien lo refirió en tu primer mensaje.' : '.');

        return $bloque;
    }

    // ------------------------------------------------------------------------------------------
    // Caminos del alta
    // ------------------------------------------------------------------------------------------

    /**
     * Crea el lead nuevo con todo lo que viene del alta.
     *
     * @param array<string, mixed>   $limpios Datos ya validados.
     * @param \App\Models\Admin|null $actor
     * @param bool                   $simular
     *
     * @return array<string, mixed>
     */
    private function alta_nueva(array $limpios, $actor, bool $simular): array
    {
        $referente = $this->describir_referente_de_datos($limpios);

        if ($simular) {
            return [
                'conflicto'       => false,
                'lead_id'         => null,
                'lead'            => null,
                'creado'          => false,
                'completado'      => false,
                'simulado'        => true,
                'primer_contacto' => $this->sin_plantilla($this->evaluar_primer_contacto($limpios, $referente)),
                'que_haria'       => $this->que_haria($limpios, false, $referente),
            ];
        }

        $ahora = AppTime::now();

        $lead = DB::transaction(function () use ($limpios, $actor, $ahora) {
            $nuevo = Lead::create([
                'phone'                  => $limpios['phone'],
                'contact_name'           => $limpios['contact_name'],
                'company_name'           => $limpios['company_name'],
                'business_type'          => $limpios['business_type'],
                'email'                  => $limpios['email'],
                'status'                 => $limpios['estado_inicial'],
                'origen'                 => $limpios['origen'],
                'origen_calculado_at'    => $ahora,
                'referido_por_client_id' => $limpios['referido_por_client_id'],
                'referido_por_texto'     => $limpios['referido_por_texto'],
                'contexto_previo'        => $limpios['contexto_previo'],
                'created_by_admin_id'    => $actor !== null ? (int) $actor->id : null,
            ]);

            // Documento aleatorio de 5 dígitos (contraseña demo y setup remoto), igual que el alta del webhook.
            LeadDocNumberGenerator::assign_to_lead_if_empty($nuevo);

            return $nuevo;
        });

        $lead = Lead::query()->find((int) $lead->id);

        $this->dejar_evento($lead, $this->texto_del_evento($lead, false), $actor);
        $this->entrar_al_tablero_si_corresponde($lead, $limpios);

        return [
            'conflicto'       => false,
            'lead_id'         => (int) $lead->id,
            'lead'            => $lead,
            'creado'          => true,
            'completado'      => false,
            'simulado'        => false,
            'primer_contacto' => $this->primer_contacto($lead, $limpios),
        ];
    }

    /**
     * Completa un lead que ya existía con lo que trae el alta (caso Ro): sin crear otro lead.
     *
     * Reglas: el origen se escribe sólo si el lead no tenía uno real (null o `desconocido`); quién lo refirió sólo si
     * estaba vacío; el contexto se agrega con encabezado. No cambia el estado del lead ni sus datos de contacto.
     *
     * @param Lead                   $existente
     * @param array<string, mixed>   $limpios
     * @param \App\Models\Admin|null $actor
     * @param bool                   $simular
     *
     * @return array<string, mixed>
     *
     * @throws PipelineRuleException Si el contexto, ya unido, pasa del tope.
     */
    private function completar(Lead $existente, array $limpios, $actor, bool $simular): array
    {
        $nuevo_contexto = (string) $existente->contexto_previo;
        if ($limpios['contexto_previo'] !== null) {
            $nuevo_contexto = $this->unir_contexto((string) $existente->contexto_previo, (string) $limpios['contexto_previo'], (string) $limpios['origen']);
            if (mb_strlen($nuevo_contexto) > self::MAX_CONTEXTO) {
                throw PipelineRuleException::validacion([
                    'contexto_previo' => ['El contexto previo no puede pasar de ' . self::MAX_CONTEXTO . ' caracteres en total.'],
                ]);
            }
        }

        $referente = $this->describir_referente_de_datos($limpios);

        if ($simular) {
            return [
                'conflicto'       => false,
                'lead_id'         => (int) $existente->id,
                'lead'            => $existente,
                'creado'          => false,
                'completado'      => true,
                'simulado'        => true,
                'primer_contacto' => $this->sin_plantilla($this->evaluar_primer_contacto($limpios, $referente)),
                'que_haria'       => $this->que_haria($limpios, true, $referente),
            ];
        }

        // Un `desconocido` cuenta como «sin origen»; cualquier otro origen ya calculado se conserva.
        $sin_origen_real = $existente->origen === null || (string) $existente->origen === LeadOrigenService::ORIGEN_DESCONOCIDO;
        if ($sin_origen_real) {
            $existente->origen              = $limpios['origen'];
            $existente->origen_calculado_at = AppTime::now();
        }
        if (empty($existente->referido_por_client_id) && $limpios['referido_por_client_id'] !== null) {
            $existente->referido_por_client_id = $limpios['referido_por_client_id'];
        }
        if (empty($existente->referido_por_texto) && $limpios['referido_por_texto'] !== null) {
            $existente->referido_por_texto = $limpios['referido_por_texto'];
        }
        if ($limpios['contexto_previo'] !== null) {
            $existente->contexto_previo = $nuevo_contexto;
        }
        $existente->save();

        $lead = Lead::query()->find((int) $existente->id);

        $this->dejar_evento($lead, $this->texto_del_evento($lead, true), $actor);
        $this->entrar_al_tablero_si_corresponde($lead, $limpios);

        return [
            'conflicto'       => false,
            'lead_id'         => (int) $lead->id,
            'lead'            => $lead,
            'creado'          => false,
            'completado'      => true,
            'simulado'        => false,
            'primer_contacto' => $this->primer_contacto($lead, $limpios),
        ];
    }

    // ------------------------------------------------------------------------------------------
    // Validación
    // ------------------------------------------------------------------------------------------

    /**
     * Valida y normaliza el pedido.
     *
     * @param array<string, mixed> $datos
     *
     * @return array<string, mixed> phone (normalizado), contact_name, origen, referido_por_client_id (int|null),
     *                              referido_por_texto, contexto_previo (string|null), estado_inicial, company_name,
     *                              business_type, email, entrar_al_tablero, primer_contacto, completar_existente.
     *
     * @throws PipelineRuleException
     */
    private function validar(array $datos): array
    {
        $errores = [];

        $telefono_tipeado = $this->texto($datos, 'phone');
        $telefono         = '';
        if ($telefono_tipeado === null) {
            $errores['phone'][] = 'El teléfono es obligatorio.';
        } else {
            $telefono = $this->normalizar_telefono($telefono_tipeado);
            if ($telefono === '' || strlen((string) preg_replace('/\D+/', '', $telefono)) < 8) {
                $errores['phone'][] = 'El teléfono no parece válido: tiene que tener código de área y número.';
            }
        }

        $nombre = $this->texto($datos, 'contact_name');
        if ($nombre === null) {
            $errores['contact_name'][] = 'El nombre es obligatorio.';
        } elseif (mb_strlen($nombre) > 150) {
            $errores['contact_name'][] = 'El nombre no puede tener más de 150 caracteres.';
        }

        $origen = $this->texto($datos, 'origen');
        if ($origen === null || ! in_array($origen, LeadOrigenService::ORIGENES_EXTERNOS, true)) {
            $errores['origen'][] = 'El origen tiene que ser uno de: ' . implode(', ', LeadOrigenService::ORIGENES_EXTERNOS) . '.';
        }

        $cliente_id = null;
        if (isset($datos['referido_por_client_id']) && $datos['referido_por_client_id'] !== '') {
            $cliente_id = (int) $datos['referido_por_client_id'];
            if ($cliente_id < 1 || ! Client::query()->whereKey($cliente_id)->exists()) {
                $errores['referido_por_client_id'][] = 'El cliente que lo refirió no existe.';
                $cliente_id = null;
            }
        }

        $referido_texto = $this->texto($datos, 'referido_por_texto');
        if ($referido_texto !== null && mb_strlen($referido_texto) > self::MAX_REFERIDO_TEXTO) {
            $errores['referido_por_texto'][] = 'Quien lo refirió no puede tener más de ' . self::MAX_REFERIDO_TEXTO . ' caracteres.';
        }

        $contexto = isset($datos['contexto_previo']) && is_string($datos['contexto_previo']) ? trim($datos['contexto_previo']) : '';
        if (mb_strlen($contexto) > self::MAX_CONTEXTO) {
            $errores['contexto_previo'][] = 'Lo que ya se habló no puede pasar de ' . self::MAX_CONTEXTO . ' caracteres.';
        }

        $estado = $this->texto($datos, 'estado_inicial');
        $estado = $estado === null ? 'nuevo' : $estado;
        if (! in_array($estado, self::ESTADOS_INICIALES, true)) {
            $errores['estado_inicial'][] = 'El estado inicial tiene que ser nuevo, contactado o link_enviado.';
        }

        $empresa = $this->texto($datos, 'company_name');
        if ($empresa !== null && mb_strlen($empresa) > 150) {
            $errores['company_name'][] = 'La empresa no puede tener más de 150 caracteres.';
        }
        $rubro = $this->texto($datos, 'business_type');
        if ($rubro !== null && mb_strlen($rubro) > 80) {
            $errores['business_type'][] = 'El rubro no puede tener más de 80 caracteres.';
        }
        $mail = $this->texto($datos, 'email');
        if ($mail !== null && (mb_strlen($mail) > 150 || filter_var($mail, FILTER_VALIDATE_EMAIL) === false)) {
            $errores['email'][] = 'El mail no parece válido.';
        }

        $primer_contacto = $this->texto($datos, 'primer_contacto');
        $primer_contacto = $primer_contacto === null ? self::PRIMER_CONTACTO_NINGUNO : $primer_contacto;
        if (! in_array($primer_contacto, [self::PRIMER_CONTACTO_NINGUNO, self::PRIMER_CONTACTO_PLANTILLA], true)) {
            $errores['primer_contacto'][] = 'El primer contacto tiene que ser ninguno o plantilla_referido.';
        }

        if ($errores !== []) {
            throw PipelineRuleException::validacion($errores);
        }

        return [
            'phone'                  => $telefono,
            'contact_name'           => $nombre,
            'origen'                 => $origen,
            'referido_por_client_id' => $cliente_id,
            'referido_por_texto'     => $referido_texto,
            'contexto_previo'        => $contexto !== '' ? $contexto : null,
            'estado_inicial'         => $estado,
            'company_name'           => $empresa,
            'business_type'          => $rubro,
            'email'                  => $mail,
            'entrar_al_tablero'      => $this->booleano($datos, 'entrar_al_tablero'),
            'primer_contacto'        => $primer_contacto,
            'completar_existente'    => $this->booleano($datos, 'completar_existente'),
        ];
    }

    /**
     * Deja el teléfono como lo guarda el resto del admin (E.164). Un número que empieza con «+» y no es argentino se
     * conserva tal cual: `WhatsappNormalizer::normalize()` asume Argentina y lo deformaría.
     *
     * @param string $telefono Tal como lo tipeó quien lo carga.
     *
     * @return string '' si no hay dígitos.
     */
    private function normalizar_telefono(string $telefono): string
    {
        $digitos = preg_replace('/\D+/', '', $telefono);
        if (! is_string($digitos) || $digitos === '') {
            return '';
        }

        if (strpos(trim($telefono), '+') === 0 && strpos($digitos, '54') !== 0) {
            return '+' . $digitos;
        }

        return WhatsappNormalizer::normalize($telefono);
    }

    /**
     * Texto recortado de una clave del pedido; null si no vino o quedó vacío.
     *
     * @param array<string, mixed> $datos
     * @param string               $clave
     *
     * @return string|null
     */
    private function texto(array $datos, string $clave)
    {
        if (! isset($datos[$clave]) || ! is_scalar($datos[$clave])) {
            return null;
        }

        $valor = trim((string) $datos[$clave]);

        return $valor === '' ? null : $valor;
    }

    /**
     * Booleano de una clave del pedido (acepta true/false, 1/0 y «true»/«false»).
     *
     * @param array<string, mixed> $datos
     * @param string               $clave
     *
     * @return bool false si no vino.
     */
    private function booleano(array $datos, string $clave): bool
    {
        if (! array_key_exists($clave, $datos) || $datos[$clave] === null) {
            return false;
        }

        return filter_var($datos[$clave], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    // ------------------------------------------------------------------------------------------
    // Contexto y referente
    // ------------------------------------------------------------------------------------------

    /**
     * Une lo que ya había con lo nuevo, con el encabezado `— dd/mm/aaaa HH:MM (<fuente>) —` delante de lo nuevo.
     *
     * @param string $anterior Contexto que ya tenía el lead ('' si ninguno).
     * @param string $nuevo    Texto que se agrega.
     * @param string $fuente   Origen o fuente que va entre paréntesis.
     *
     * @return string
     */
    private function unir_contexto(string $anterior, string $nuevo, string $fuente): string
    {
        $encabezado = '— ' . AppTime::now()->format('d/m/Y H:i') . ' (' . $fuente . ') —';
        $agregado   = $encabezado . "\n" . $nuevo;

        return trim($anterior) === '' ? $agregado : rtrim($anterior) . "\n\n" . $agregado;
    }

    /**
     * Cómo se nombra el origen del lead en el hilo y en el tablero.
     *
     * @param Lead $lead
     *
     * @return string
     */
    private function etiqueta_del_origen(Lead $lead): string
    {
        $origen = (string) $lead->origen;

        return isset(self::ETIQUETAS_DE_ORIGEN[$origen]) ? self::ETIQUETAS_DE_ORIGEN[$origen] : $origen;
    }

    /**
     * Cómo se nombra a quien refirió, para el hilo y para Martín: el cliente («Nombre (Empresa)») o el texto libre.
     *
     * @param Lead $lead
     *
     * @return string '' si nadie lo refirió.
     */
    private function describir_referente(Lead $lead): string
    {
        return $this->describir_referente_de_ids(
            empty($lead->referido_por_client_id) ? null : (int) $lead->referido_por_client_id,
            (string) $lead->referido_por_texto
        );
    }

    /**
     * Lo mismo que {@see describir_referente()} pero a partir de los datos del pedido (todavía no hay lead).
     *
     * @param array<string, mixed> $limpios
     *
     * @return string
     */
    private function describir_referente_de_datos(array $limpios): string
    {
        return $this->describir_referente_de_ids(
            $limpios['referido_por_client_id'],
            (string) ($limpios['referido_por_texto'] !== null ? $limpios['referido_por_texto'] : '')
        );
    }

    /**
     * Nombre de quien refirió a partir del cliente (si hay) o del texto libre.
     *
     * @param int|null $cliente_id
     * @param string   $texto
     *
     * @return string
     */
    private function describir_referente_de_ids($cliente_id, string $texto): string
    {
        if ($cliente_id !== null) {
            $cliente = Client::query()->find($cliente_id);
            if ($cliente !== null) {
                $nombre = $this->nombre_del_cliente($cliente, true);
                if ($nombre !== '') {
                    return $nombre;
                }
            }
        }

        return trim($texto);
    }

    /**
     * Nombre de un cliente para mostrar.
     *
     * @param Client $cliente
     * @param bool   $con_empresa true = «Nombre (Empresa)» (hilo y Martín); false = sólo el nombre (variable de la plantilla).
     *
     * @return string
     */
    private function nombre_del_cliente(Client $cliente, bool $con_empresa): string
    {
        $nombre  = trim((string) $cliente->name);
        $empresa = trim((string) $cliente->company_name);

        if ($nombre === '') {
            return $empresa;
        }
        if ($con_empresa && $empresa !== '' && $empresa !== $nombre) {
            return $nombre . ' (' . $empresa . ')';
        }

        return $nombre;
    }

    /**
     * Texto del evento que queda en el hilo: cómo llegó el lead y que se cargó contexto previo.
     *
     * @param Lead $lead
     * @param bool $completado true = se completó un lead que ya existía.
     *
     * @return string
     */
    private function texto_del_evento(Lead $lead, bool $completado): string
    {
        $referente = $this->describir_referente($lead);

        $texto  = $completado ? 'Se completó el lead con datos de otro medio: ' : 'Llegó por ';
        $texto .= $this->etiqueta_del_origen($lead);
        $texto .= $referente !== '' ? ' (referido por ' . $referente . ')' : '';
        $texto .= '.';
        $texto .= trim((string) $lead->contexto_previo) !== '' ? ' Contexto previo cargado.' : '';

        return $texto;
    }

    /**
     * Deja el evento en el hilo del lead. No actualiza `last_message_at` ni genera badge de «sin leer» (es un
     * `is_status_event`), pero cuenta como historial: hace que el onboarding genérico no corra.
     *
     * @param Lead                   $lead
     * @param string                 $texto
     * @param \App\Models\Admin|null $actor
     *
     * @return void
     */
    private function dejar_evento(Lead $lead, string $texto, $actor): void
    {
        LeadMessage::create([
            'lead_id'               => (int) $lead->id,
            'sender'                => 'sistema',
            'content'               => $texto,
            'status'                => 'enviado',
            'is_followup'           => false,
            'is_status_event'       => true,
            'system_message_kind'   => self::KIND_ALTA_EXTERNA,
            'requiere_verificacion' => false,
            'sent_via'              => $actor === null ? LeadMessage::SENT_VIA_CLAUDE : null,
        ]);
    }

    // ------------------------------------------------------------------------------------------
    // Tablero de Juan
    // ------------------------------------------------------------------------------------------

    /**
     * Da de alta la tarjeta en «Interesados» si el lead nace en `link_enviado` o se pidió entrar al tablero.
     * En un alta no corre `Lead::saved` (Laravel sólo marca `wasChanged` en un update), por eso se hace acá.
     * Nunca lanza: el lead ya está creado.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $limpios
     *
     * @return void
     */
    private function entrar_al_tablero_si_corresponde(Lead $lead, array $limpios): void
    {
        $entra = $limpios['entrar_al_tablero'] || ($limpios['estado_inicial'] === 'link_enviado' && (string) $lead->status === 'link_enviado');
        if (! $entra) {
            return;
        }

        try {
            $referente = $this->describir_referente($lead);
            $motivo    = 'llegó por ' . $this->etiqueta_del_origen($lead) . ($referente !== '' ? ' de ' . $referente : '');

            app(TableroDeJuanService::class)->entrar_en_interesados($lead, $motivo);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('LeadAltaExternaService: no se pudo dar de alta la tarjeta del tablero.', [
                'lead_id' => (int) $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------------------------------
    // Primer contacto por plantilla
    // ------------------------------------------------------------------------------------------

    /**
     * Decide y, si corresponde, hace el primer contacto por la plantilla de referido.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $limpios
     *
     * @return array<string, mixed> `{estado: 'no_pedido'|'enviado'|'no_enviado', motivo?, plantilla?}`.
     */
    private function primer_contacto(Lead $lead, array $limpios): array
    {
        $evaluacion = $this->evaluar_primer_contacto($limpios, $this->describir_referente($lead));
        if ($evaluacion['estado'] !== 'enviable') {
            return $evaluacion;
        }

        /** @var FollowupTemplate $plantilla */
        $plantilla = $evaluacion['plantilla'];
        $variables = [$this->primer_nombre($lead), $this->variable_del_referente($lead)];

        $sender = app(WhatsappSendService::class);
        $wamid  = null;
        try {
            $wamid = $sender->send_template(
                (string) $lead->phone,
                (string) $plantilla->template_name,
                $variables,
                $plantilla->language_code !== null && $plantilla->language_code !== '' ? (string) $plantilla->language_code : 'es_AR',
                'Primer contacto de referido - Lead #' . $lead->id
            );
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('LeadAltaExternaService: falló el envío del primer contacto.', [
                'lead_id' => (int) $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }

        // Se asienta en el hilo haya salido o no: si falló, el banner de error de entrega lo muestra.
        $cuerpo = str_replace(['{{1}}', '{{2}}'], $variables, (string) $plantilla->body_template);
        LeadMessage::create([
            'lead_id'               => (int) $lead->id,
            'sender'                => 'sistema',
            'content'               => $cuerpo,
            'status'                => 'enviado',
            'is_followup'           => false,
            'followup_template_id'  => (int) $plantilla->id,
            'whatsapp_message_id'   => $wamid,
            'sent_at'               => $wamid !== null ? AppTime::now() : null,
            'whatsapp_send_error'   => $wamid === null ? $sender->last_send_error : null,
            'system_message_kind'   => self::KIND_PRIMER_CONTACTO,
            'requiere_verificacion' => false,
        ]);

        if ($wamid === null) {
            return ['estado' => 'no_enviado', 'motivo' => 'envio_fallido'];
        }

        return ['estado' => 'enviado', 'plantilla' => (string) $plantilla->template_name];
    }

    /**
     * Mira si el primer contacto por plantilla se puede mandar, sin mandar nada.
     *
     * @param array<string, mixed> $limpios
     * @param string               $referente Cómo se nombra a quien refirió ('' si nadie).
     *
     * @return array<string, mixed> `estado` = no_pedido | no_enviado (con `motivo`) | enviable (con `plantilla`).
     */
    private function evaluar_primer_contacto(array $limpios, string $referente): array
    {
        if ($limpios['primer_contacto'] !== self::PRIMER_CONTACTO_PLANTILLA) {
            return ['estado' => 'no_pedido'];
        }

        $nombre = trim((string) AdminSetting::get(self::SETTING_PLANTILLA, ''));
        $estado = FollowupTemplate::estado_para_enviar($nombre);
        if ($estado['motivo'] !== null) {
            return ['estado' => 'no_enviado', 'motivo' => (string) $estado['motivo']];
        }

        // La plantilla lleva dos variables: sin quien lo refirió la segunda iría vacía y Meta la rechaza.
        if ($referente === '') {
            return ['estado' => 'no_enviado', 'motivo' => 'sin_referente'];
        }

        return ['estado' => 'enviable', 'plantilla' => $estado['fila']];
    }

    /**
     * Saca el modelo de la plantilla de una evaluación (lo que se devuelve en JSON no puede llevarlo) y marca como
     * «enviaría» lo que se podría mandar.
     *
     * @param array<string, mixed> $evaluacion
     *
     * @return array<string, mixed>
     */
    private function sin_plantilla(array $evaluacion): array
    {
        if ($evaluacion['estado'] === 'enviable') {
            return ['estado' => 'enviaria', 'plantilla' => (string) $evaluacion['plantilla']->template_name];
        }

        return $evaluacion;
    }

    /**
     * Primer nombre del lead (variable {{1}}).
     *
     * @param Lead $lead
     *
     * @return string
     */
    private function primer_nombre(Lead $lead): string
    {
        $partes = preg_split('/\s+/', trim((string) $lead->contact_name));

        return is_array($partes) && isset($partes[0]) && $partes[0] !== '' ? $partes[0] : 'hola';
    }

    /**
     * Quien lo refirió como va en la variable {{2}} de la plantilla: el nombre del cliente (sin empresa) o el texto libre.
     *
     * @param Lead $lead
     *
     * @return string
     */
    private function variable_del_referente(Lead $lead): string
    {
        if (! empty($lead->referido_por_client_id)) {
            $cliente = Client::query()->find((int) $lead->referido_por_client_id);
            if ($cliente !== null) {
                $nombre = $this->nombre_del_cliente($cliente, false);
                if ($nombre !== '') {
                    return $nombre;
                }
            }
        }

        return trim((string) $lead->referido_por_texto);
    }

    // ------------------------------------------------------------------------------------------
    // Simulación
    // ------------------------------------------------------------------------------------------

    /**
     * Resumen de lo que haría el alta (para el `dry_run` de `claude/*`).
     *
     * @param array<string, mixed> $limpios
     * @param bool                 $completa  true = completaría un lead que ya existe.
     * @param string               $referente
     *
     * @return array<string, mixed>
     */
    private function que_haria(array $limpios, bool $completa, string $referente): array
    {
        return [
            'accion'             => $completa ? 'completar_lead_existente' : 'crear_lead',
            'telefono'           => $limpios['phone'],
            'nombre'             => $limpios['contact_name'],
            'origen'             => $limpios['origen'],
            'referido_por'       => $referente !== '' ? $referente : null,
            'estado_inicial'     => $completa ? null : $limpios['estado_inicial'],
            'largo_del_contexto' => $limpios['contexto_previo'] !== null ? mb_strlen((string) $limpios['contexto_previo']) : 0,
            'entra_al_tablero'   => $limpios['entrar_al_tablero'] || $limpios['estado_inicial'] === 'link_enviado',
            'deja_evento'        => true,
        ];
    }
}
