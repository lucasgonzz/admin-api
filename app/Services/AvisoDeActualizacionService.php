<?php

namespace App\Services;

use App\Mail\ClientVersionUpgradeMail;
use App\Models\Client;
use App\Models\ClientUpgradeNotice;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use App\Models\VersionNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Le avisa al dueño de un negocio que le actualizamos el sistema: un mail con las novedades y un
 * WhatsApp contándole que le mandamos ese mail.
 *
 * Hasta acá el dueño no se enteraba de nada. Las novedades ya estaban cargadas
 * (`version_notifications`, las publica cada misión al terminar) pero solo se veían adentro del
 * sistema, si entraba a mirarlas.
 *
 * El orden importa y no es intercambiable:
 *
 *   1. **El registro primero.** `registrar()` crea la fila de `client_upgrade_notices` antes de
 *      que salga nada. Es lo que deduplica (único por actualización + cliente) y lo que deja
 *      rastro de un aviso que quedó debiendo.
 *   2. **La casilla.** `ClientContactEmailResolver`, que mira `clients.email` y si no hay le
 *      pregunta al `empresa-api` del cliente. Sin casilla no sale nada y queda `sin_mail`.
 *   3. **Las novedades.** Si esas versiones no traen ninguna para este cliente, el mail NO se
 *      manda: queda `sin_novedades`. Un mail que dice "actualizamos tu sistema" y abajo no tiene
 *      nada es peor que no mandarlo.
 *   4. **El mail.**
 *   5. **El WhatsApp, y solo si el mail salió.** 🔴 El mensaje dice "te mandamos un mail":
 *      mandarlo sin haber mandado el mail es mentirle al dueño.
 *
 * 🔴 **Nada de acá puede hacer fallar un upgrade.** Cuando esto corre, el upgrade ya cerró bien;
 * el aviso es un extra. Todo lo que falle queda en la fila con el motivo y no se propaga.
 */
class AvisoDeActualizacionService
{
    /**
     * @var ClientContactEmailResolver
     */
    private $resolver_de_casilla;

    /**
     * @var WhatsappSendService
     */
    private $whatsapp;

    /**
     * @var WhatsappSessionWindowService
     */
    private $ventana;

    /**
     * Las tres dependencias son inyectables para los tests; en producción se arman solas.
     *
     * @param ClientContactEmailResolver|null   $resolver_de_casilla
     * @param WhatsappSendService|null          $whatsapp
     * @param WhatsappSessionWindowService|null $ventana
     */
    public function __construct(
        ?ClientContactEmailResolver $resolver_de_casilla = null,
        ?WhatsappSendService $whatsapp = null,
        ?WhatsappSessionWindowService $ventana = null
    ) {
        $this->resolver_de_casilla = $resolver_de_casilla !== null
            ? $resolver_de_casilla
            : new ClientContactEmailResolver();

        $this->whatsapp = $whatsapp !== null ? $whatsapp : new WhatsappSendService();

        $this->ventana = $ventana !== null ? $ventana : new WhatsappSessionWindowService();
    }

    /**
     * Deja anotado que a este upgrade le falta avisarle al dueño, y devuelve esa anotación.
     *
     * 🔴 **Devuelve null cuando NO hay nada que hacer**, y eso es toda la deduplicación del canal:
     * si ya existe una fila para este par y no está `pendiente`, el aviso ya se trabajó. El hook
     * `saved` de `ClientVersionUpgrade` puede pasar más de una vez por el mismo upgrade (basta con
     * devolverlo a `actualizandose` y cerrarlo de nuevo desde la grilla), y sin este corte cada
     * pasada sería un mail más contándole al dueño la misma actualización.
     *
     * Un aviso que quedó en `sin_mail`, `sin_novedades` o `error` TAMPOCO se reintenta solo: si se
     * reintentara en cada `save()`, un cliente sin casilla pagaría una llamada HTTP a su
     * `empresa-api` por cada vez que alguien toca el upgrade. Reabrir uno de esos es a mano,
     * borrando la fila.
     *
     * @param ClientVersionUpgrade $upgrade Actualización que acaba de cerrarse.
     *
     * @return ClientUpgradeNotice|null La anotación a trabajar, o null si no hay nada que hacer.
     */
    public function registrar(ClientVersionUpgrade $upgrade): ?ClientUpgradeNotice
    {
        if (is_null($upgrade->client_id)) {
            return null;
        }

        $existente = ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)
            ->where('client_id', $upgrade->client_id)
            ->first();

        if ($existente instanceof ClientUpgradeNotice) {
            return $existente->esta_pendiente() ? $existente : null;
        }

        $client = Client::find($upgrade->client_id);

        if (! $client instanceof Client) {
            return null;
        }

        $aviso = new ClientUpgradeNotice();
        $aviso->client_version_upgrade_id = (int) $upgrade->id;
        $aviso->client_id                 = (int) $client->id;
        // Lo que haya HOY en la ficha. Puede quedar null y llenarse recién en avisar(), cuando el
        // resolver le pregunte al cliente.
        $aviso->email                     = ClientContactEmailResolver::mail_valido($client->email);
        $aviso->estado                    = ClientUpgradeNotice::ESTADO_PENDIENTE;
        $aviso->save();

        return $aviso;
    }

    /**
     * Manda el aviso completo: mail con las novedades y, si el mail salió, el WhatsApp.
     *
     * @param ClientVersionUpgrade $upgrade Actualización ya cerrada.
     *
     * @return ClientUpgradeNotice|null La fila con el resultado, o null si no había nada que hacer.
     */
    public function avisar(ClientVersionUpgrade $upgrade): ?ClientUpgradeNotice
    {
        $aviso = $this->registrar($upgrade);

        if ($aviso === null) {
            return null;
        }

        return $this->ejecutar($upgrade, $aviso);
    }

    /**
     * Vuelve a intentar un aviso que quedó debiendo algo, sin pasar por el candado de `registrar()`.
     *
     * Lo usa el comando `aviso-actualizacion:reintentar`, que es la punta manual del canal: el
     * aviso quedó `sin_mail` porque el cliente no tenía casilla en ningún lado, después alguien se
     * la consiguió por WhatsApp, y hay que mandar lo que quedó pendiente. El hook no sirve para
     * eso —la unicidad de (actualización, cliente) es justamente lo que impide que guardar el
     * upgrade lo dispare de nuevo—, así que la reapertura entra por acá.
     *
     * 🔴 **No manda lo que ya salió.** `trabajar()` decide paso por paso mirando
     * `mail_enviado_at` y `whatsapp_enviado_at`, así que reintentar un aviso al que solo le faltó
     * el WhatsApp manda el WhatsApp y nada más. Correr esto dos veces sobre el mismo aviso no
     * duplica nada.
     *
     * @param ClientUpgradeNotice $aviso Fila existente.
     *
     * @return ClientUpgradeNotice La misma fila, con el resultado.
     */
    public function reintentar(ClientUpgradeNotice $aviso): ClientUpgradeNotice
    {
        $upgrade = ClientVersionUpgrade::find($aviso->client_version_upgrade_id);

        if (! $upgrade instanceof ClientVersionUpgrade) {
            return $this->cerrar_con_error($aviso, 'La actualización de este aviso ya no existe.');
        }

        $resultado = $this->ejecutar($upgrade, $aviso);

        return $resultado !== null ? $resultado : $aviso;
    }

    /**
     * Resuelve el cliente y corre el aviso, atajando cualquier caída.
     *
     * @param ClientVersionUpgrade $upgrade
     * @param ClientUpgradeNotice  $aviso
     *
     * @return ClientUpgradeNotice|null
     */
    private function ejecutar(ClientVersionUpgrade $upgrade, ClientUpgradeNotice $aviso): ?ClientUpgradeNotice
    {
        $client = Client::find($aviso->client_id);

        if (! $client instanceof Client) {
            return $this->cerrar_con_error($aviso, 'El cliente ya no existe.');
        }

        try {
            return $this->trabajar($upgrade, $client, $aviso);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('AvisoDeActualizacion: el aviso se cayó entero.', [
                'client_version_upgrade_id' => $upgrade->id,
                'client_id'                 => $client->id,
                'error'                     => $exception->getMessage(),
            ]);

            return $this->cerrar_con_error($aviso, $exception->getMessage());
        }
    }

    /**
     * El cuerpo del aviso, ya con el cliente y la fila en la mano.
     *
     * 🔴 **Los dos tramos se deciden por SU PROPIA fecha, no por el estado de la fila.** Esa es la
     * única forma de que un reintento sea seguro: `mail_enviado_at` con fecha significa que el
     * mail salió y no se puede desmandar, así que ese tramo se saltea y solo se trabaja lo que
     * falta. Mirar el `estado` en su lugar volvería a mandar el mail de un aviso que quedó
     * `enviado` con el WhatsApp debiendo, que es el caso exacto que este canal produce cuando la
     * plantilla de Meta todavía no está creada.
     *
     * @param ClientVersionUpgrade $upgrade
     * @param Client               $client
     * @param ClientUpgradeNotice  $aviso
     *
     * @return ClientUpgradeNotice
     */
    private function trabajar(
        ClientVersionUpgrade $upgrade,
        Client $client,
        ClientUpgradeNotice $aviso
    ): ClientUpgradeNotice {
        if ($aviso->mail_pendiente()) {

            /* 2. La casilla. */
            $casilla = $this->resolver_de_casilla->resolve($client);

            if ($casilla === null) {
                $aviso->estado = ClientUpgradeNotice::ESTADO_SIN_MAIL;
                $aviso->error  = 'No hay casilla de correo del dueño: ni en la ficha del cliente ni '
                    . 'en lo que contestó su sistema. Cargala a mano en el panel.';
                $aviso->save();

                return $aviso;
            }

            $aviso->email = $casilla;

            /* 3. Las novedades de las versiones que trajo ESTE upgrade, para ESTE cliente. */
            $novedades = $this->novedades_del_upgrade($upgrade, $client);

            if (empty($novedades)) {
                $aviso->estado = ClientUpgradeNotice::ESTADO_SIN_NOVEDADES;
                $aviso->error  = 'Las versiones de esta actualización no traen ninguna novedad '
                    . 'visible para este cliente. El mail no se mandó a propósito: uno que anuncia '
                    . 'una actualización y no dice nada es peor que ninguno.';
                $aviso->save();

                return $aviso;
            }

            /* 4. El mail. */
            try {
                Mail::to($casilla)->send(ClientVersionUpgradeMail::armar(
                    $this->nombre_del_negocio($client),
                    $this->version_de_destino($upgrade),
                    $novedades
                ));
            } catch (\Throwable $exception) {
                Log::channel('daily')->error('AvisoDeActualizacion: no se pudo mandar el mail.', [
                    'client_id' => $client->id,
                    'email'     => $casilla,
                    'error'     => $exception->getMessage(),
                ]);

                return $this->cerrar_con_error($aviso, 'No se pudo mandar el mail: ' . $exception->getMessage());
            }

            $aviso->estado          = ClientUpgradeNotice::ESTADO_ENVIADO;
            $aviso->mail_enviado_at = now();
            $aviso->error           = null;
            $aviso->save();
        }

        /* 5. El WhatsApp, y solo con el mail ya mandado. */
        if ($aviso->whatsapp_pendiente()) {
            $this->avisar_por_whatsapp($client, $aviso);
        }

        return $aviso;
    }

    /**
     * El golpecito en el hombro por WhatsApp: "te actualizamos el sistema y te mandamos un mail".
     *
     * 🔴 Nada de acá cambia el `estado` de la fila. El mail ya salió y eso es lo que el estado
     * dice; que el WhatsApp no haya salido se lee en `whatsapp_enviado_at` (null) y en `error`,
     * que acá se usa como nota y no como falla.
     *
     * @param Client              $client
     * @param ClientUpgradeNotice $aviso Fila ya guardada como `enviado`.
     *
     * @return void
     */
    private function avisar_por_whatsapp(Client $client, ClientUpgradeNotice $aviso): void
    {
        $telefono = trim((string) $client->phone);

        if ($telefono === '') {
            // No es un error del aviso: el mail salió. Es un dato que falta en la ficha.
            $this->anotar_en_el_aviso($aviso, 'El cliente no tiene teléfono cargado, así que no salió el WhatsApp.');

            return;
        }

        try {
            $estado_de_la_ventana = $this->ventana->window_state($telefono);
            $abierta = ! empty($estado_de_la_ventana['open']);

            if ($abierta) {
                $whatsapp_message_id = $this->whatsapp->send_text(
                    $telefono,
                    $this->texto_del_whatsapp($aviso->email),
                    'Aviso de actualización al cliente ' . $client->id
                );
            } else {
                /*
                 * 🔴 Fuera de la ventana de 24 hs solo entra una plantilla aprobada, y mientras esa
                 * fila no exista NO SE MANDA NADA. Un send_template con una plantilla que no está
                 * creada en Meta lo rechaza Meta; inventarle un nombre por defecto sería garantizar
                 * el rechazo y encima ensuciar la calidad del número.
                 */
                $plantilla = AsistenteWhatsappSettings::plantilla_de_actualizaciones();

                if ($plantilla === '') {
                    $this->anotar_en_el_aviso(
                        $aviso,
                        'La ventana de 24 hs está cerrada y todavía no hay plantilla de Meta cargada '
                        . 'en el AdminSetting "' . AsistenteWhatsappSettings::KEY_ACTUALIZACION_TEMPLATE_NAME
                        . '", así que el WhatsApp quedó pendiente. El mail sí salió.'
                    );

                    return;
                }

                $whatsapp_message_id = $this->whatsapp->send_template(
                    $telefono,
                    $plantilla,
                    // Una sola variable: el nombre del negocio.
                    [$this->nombre_del_negocio($client)],
                    AsistenteWhatsappSettings::idioma_de_la_plantilla_de_actualizaciones(),
                    'Aviso de actualización al cliente ' . $client->id
                );
            }
        } catch (\Throwable $exception) {
            $this->anotar_en_el_aviso($aviso, 'El WhatsApp se cayó: ' . $exception->getMessage() . '. El mail sí salió.');

            return;
        }

        if ($whatsapp_message_id === null) {
            $motivo = $this->whatsapp->last_send_error !== null
                ? $this->whatsapp->last_send_error
                : 'sin motivo informado';

            $this->anotar_en_el_aviso($aviso, 'Meta rechazó el WhatsApp (' . $motivo . '). El mail sí salió.');

            return;
        }

        $aviso->whatsapp_enviado_at = now();
        $aviso->whatsapp_message_id = $whatsapp_message_id;
        // Se limpia la nota: si este envío es un reintento, el `error` traía por qué no había
        // salido la vez anterior y dejarlo escrito haría pensar que sigue debiendo algo.
        $aviso->error               = null;
        $aviso->save();
    }

    /**
     * Las novedades que le corresponden a este cliente por las versiones de esta actualización.
     *
     * 🔴 El rango sale de la pivot `client_version_upgrade_versions` (`confirmed_versions()`) y
     * NO de derivar los `id` entre `from_version_id` y `to_version_id`. Esa derivación es
     * justamente lo que la migración de la pivot vino a reemplazar: no filtraba por `status` ni
     * ordenaba por número de versión, y con hotfixes de por medio el `id` no refleja el orden.
     *
     * El filtro por cliente es `forClientId()` del trait `RestrictsToClients`: sin filas en el
     * pivote una novedad aplica a todos; con filas, solo a los clientes vinculados.
     *
     * @param ClientVersionUpgrade $upgrade
     * @param Client               $client
     *
     * @return array<int, array<string, string>> Cada ítem: title, body.
     */
    private function novedades_del_upgrade(ClientVersionUpgrade $upgrade, Client $client): array
    {
        $upgrade->loadMissing('confirmed_versions');

        $version_ids = $upgrade->confirmed_versions->pluck('id')->all();

        if (empty($version_ids)) {
            return [];
        }

        $novedades = VersionNotification::query()
            ->whereIn('version_id', $version_ids)
            ->where('is_active', true)
            ->forClientId((int) $client->id)
            // Por `sort_order`, que es el orden con el que se cargaron dentro de su versión, y
            // `id` para desempatar: sin el segundo criterio, dos novedades de versiones distintas
            // con el mismo `sort_order` saldrían en el orden que le pinte a MySQL.
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $salida = [];

        foreach ($novedades as $novedad) {
            $salida[] = [
                'title' => (string) $novedad->title,
                'body'  => (string) $novedad->body,
            ];
        }

        return $salida;
    }

    /**
     * El texto del WhatsApp cuando la ventana de 24 hs está abierta.
     *
     * @param string|null $casilla Dirección a la que salió el mail, para nombrarla.
     *
     * @return string
     */
    private function texto_del_whatsapp(?string $casilla): string
    {
        $donde = $casilla !== null && trim($casilla) !== ''
            ? ' a ' . trim($casilla)
            : '';

        return 'Hola! Te actualizamos el sistema: ya tenés las mejoras nuevas andando. '
            . 'Te mandamos un mail' . $donde . ' con el detalle de todo lo que trae. '
            . 'Si querés que te explique alguna, preguntame por acá.';
    }

    /**
     * Nombre con el que se le habla al negocio.
     *
     * @param Client $client
     *
     * @return string Vacío si el cliente no tiene ninguno de los dos.
     */
    private function nombre_del_negocio(Client $client): string
    {
        $company = trim((string) $client->company_name);

        return $company !== '' ? $company : trim((string) $client->name);
    }

    /**
     * Número de la versión a la que quedó el cliente.
     *
     * @param ClientVersionUpgrade $upgrade
     *
     * @return string Vacío si no se pudo resolver.
     */
    private function version_de_destino(ClientVersionUpgrade $upgrade): string
    {
        if (is_null($upgrade->to_version_id)) {
            return '';
        }

        $version = Version::find($upgrade->to_version_id);

        return $version instanceof Version ? trim((string) $version->version) : '';
    }

    /**
     * Deja la fila en `error` con el motivo.
     *
     * @param ClientUpgradeNotice $aviso
     * @param string              $motivo
     *
     * @return ClientUpgradeNotice
     */
    private function cerrar_con_error(ClientUpgradeNotice $aviso, string $motivo): ClientUpgradeNotice
    {
        $aviso->estado = ClientUpgradeNotice::ESTADO_ERROR;
        $aviso->error  = $motivo;
        $aviso->save();

        return $aviso;
    }

    /**
     * Anota algo en el `error` de la fila SIN tocar el estado. Se usa para el WhatsApp que no
     * salió: el mail sí salió y el estado tiene que seguir diciéndolo.
     *
     * @param ClientUpgradeNotice $aviso
     * @param string              $nota
     *
     * @return void
     */
    private function anotar_en_el_aviso(ClientUpgradeNotice $aviso, string $nota): void
    {
        $aviso->error = $nota;
        $aviso->save();

        Log::channel('daily')->info('AvisoDeActualizacion: ' . $nota, [
            'client_upgrade_notice_id' => $aviso->id,
            'client_id'                => $aviso->client_id,
        ]);
    }
}
