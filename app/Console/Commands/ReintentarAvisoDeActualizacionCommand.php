<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientUpgradeNotice;
use App\Services\AvisoDeActualizacionService;
use App\Services\ClientContactEmailResolver;
use Illuminate\Console\Command;

/**
 * La punta manual del aviso de actualización: lista lo que quedó debiendo y lo reintenta.
 *
 * 🔴 **Existe porque la unicidad de `client_upgrade_notices` cierra la puerta automática.** El par
 * (actualización, cliente) es único justamente para que cerrar dos veces el mismo upgrade no mande
 * dos mails — y el precio de eso es que un aviso que quedó `sin_mail` no se puede reabrir
 * guardando el upgrade otra vez. Este comando es esa reapertura.
 *
 * El caso real que lo motiva: el aviso queda `sin_mail` porque el cliente no tenía casilla en
 * ningún lado (ni en la ficha ni en su `empresa-api`, que todavía corre una versión sin el
 * endpoint). Después alguien se la pide al dueño por WhatsApp y la consigue. Ahí se corre:
 *
 *     php artisan aviso-actualizacion:reintentar --cliente=quino --email=dueno@ejemplo.com --aplicar
 *
 * Modo reporte por defecto, igual que `realinear_version_de_clientes`: sin `--aplicar` no escribe
 * ni manda nada, solo dice qué haría.
 *
 * 🔴 **Seguro de correr dos veces.** No decide por el `estado` de la fila sino por lo que
 * efectivamente salió (`mail_enviado_at` / `whatsapp_enviado_at`), así que un aviso ya completo no
 * manda nada y uno al que solo le faltó el WhatsApp manda el WhatsApp y no repite el mail.
 */
class ReintentarAvisoDeActualizacionCommand extends Command
{
    /**
     * Nombre y opciones del comando artisan.
     *
     * @var string
     */
    protected $signature = 'aviso-actualizacion:reintentar
        {--cliente= : Slug o id del cliente a reintentar. Sin esta opción, solo reporta}
        {--email= : Casilla del dueño; se valida y se guarda en clients.email antes de reintentar}
        {--aplicar : Escribe y manda; sin esta opción solo reporta}';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Lista y reintenta los avisos de actualización que quedaron debiendo el mail o el WhatsApp (modo reporte por defecto, --aplicar para mandar)';

    /**
     * @param AvisoDeActualizacionService $service Inyectado por el contenedor.
     *
     * @return int Código de salida (0 = éxito, 1 = el pedido no se pudo resolver).
     */
    public function handle(AvisoDeActualizacionService $service): int
    {
        $slug_o_id = trim((string) $this->option('cliente'));

        if ($slug_o_id === '') {
            return $this->reportar();
        }

        return $this->reintentar_un_cliente($service, $slug_o_id);
    }

    /**
     * El reporte: a quién le falta el mail, y a quién solo el WhatsApp.
     *
     * Son dos bloques y no uno porque se miran por motivos distintos. El primero es el que se
     * revisa para salir a buscar casillas; el segundo, el que se revisa después de crear la
     * plantilla en Meta.
     *
     * @return int
     */
    private function reportar(): int
    {
        $sin_mail = $this->candidatos_sin_mail()->get();

        if ($sin_mail->isEmpty()) {
            $this->info('No hay ningún aviso esperando el mail.');
        } else {
            $this->warn($sin_mail->count() . ' aviso(s) sin mandar el mail:');
            $this->table(
                ['Aviso', 'Cliente', 'Negocio', 'Upgrade', 'Versión', 'Estado', 'Motivo'],
                $sin_mail->map(function ($aviso) {
                    return $this->fila_del_reporte($aviso);
                })->all()
            );
        }

        $solo_whatsapp = $this->candidatos_solo_whatsapp()->get();

        if ($solo_whatsapp->isEmpty()) {
            $this->info('No hay ningún aviso con el mail mandado y el WhatsApp debiendo.');
        } else {
            $this->warn($solo_whatsapp->count() . ' aviso(s) con el mail mandado y el WhatsApp debiendo:');
            $this->table(
                ['Aviso', 'Cliente', 'Negocio', 'Upgrade', 'Versión', 'Estado', 'Motivo'],
                $solo_whatsapp->map(function ($aviso) {
                    return $this->fila_del_reporte($aviso);
                })->all()
            );
        }

        $this->line('');
        $this->line('Para reintentar uno: aviso-actualizacion:reintentar --cliente=<slug|id> [--email=<casilla>] --aplicar');

        return 0;
    }

    /**
     * Reintenta el aviso pendiente más reciente de un cliente.
     *
     * @param AvisoDeActualizacionService $service
     * @param string                      $slug_o_id
     *
     * @return int
     */
    private function reintentar_un_cliente(AvisoDeActualizacionService $service, string $slug_o_id): int
    {
        $aplicar = (bool) $this->option('aplicar');

        $client = $this->buscar_cliente($slug_o_id);

        if (! $client instanceof Client) {
            $this->error('No se encontró ningún cliente con slug o id "' . $slug_o_id . '".');

            return 1;
        }

        /* La casilla nueva, si vino. Se valida ANTES de tocar nada. */
        $email_pedido = trim((string) $this->option('email'));
        $email_valido = null;

        if ($email_pedido !== '') {
            $email_valido = ClientContactEmailResolver::mail_valido($email_pedido);

            if ($email_valido === null) {
                $this->error('"' . $email_pedido . '" no es una dirección de correo válida. No se tocó nada.');

                return 1;
            }
        }

        /*
         * El pendiente más reciente de ese cliente. Se mira el mail primero y el WhatsApp después,
         * porque un aviso al que le falta el mail es más urgente que uno al que solo le falta el
         * golpecito en el hombro.
         */
        $aviso = $this->candidatos_sin_mail()->where('client_id', $client->id)->first();
        $que_falta = 'el mail';

        if (! $aviso instanceof ClientUpgradeNotice) {
            $aviso = $this->candidatos_solo_whatsapp()->where('client_id', $client->id)->first();
            $que_falta = 'el WhatsApp (el mail ya salió y no se vuelve a mandar)';
        }

        if (! $aviso instanceof ClientUpgradeNotice) {
            $this->info(
                'El cliente ' . $this->nombre($client) . ' no tiene ningún aviso pendiente: o ya salió '
                . 'todo, o nunca se le cerró una actualización.'
            );

            /* Aun así, si vino una casilla, guardarla sirve para el próximo upgrade. */
            if ($email_valido !== null) {
                if ($aplicar) {
                    Client::where('id', $client->id)->update(['email' => $email_valido]);
                    $this->info('Se guardó "' . $email_valido . '" en la ficha del cliente para la próxima.');
                } else {
                    $this->warn('Con --aplicar se guardaría "' . $email_valido . '" en la ficha del cliente.');
                }
            }

            return 0;
        }

        $this->line('Cliente: ' . $this->nombre($client) . ' (#' . $client->id . ')');
        $this->line('Aviso #' . $aviso->id . ' de la actualización #' . $aviso->client_version_upgrade_id
            . ', estado "' . $aviso->estado . '".');
        $this->line('Falta: ' . $que_falta . '.');

        if ($email_valido !== null) {
            $this->line('Casilla a usar: ' . $email_valido . '.');
        } elseif (! is_null($client->email)) {
            $this->line('Casilla a usar: la que ya está en la ficha (' . $client->email . ').');
        } else {
            $this->line('Casilla a usar: ninguna cargada; se le va a preguntar a su sistema.');
        }

        if (! $aplicar) {
            $this->warn('Modo reporte: no se escribió ni se mandó nada. Corré con --aplicar.');

            return 0;
        }

        if ($email_valido !== null) {
            Client::where('id', $client->id)->update(['email' => $email_valido]);
            $this->info('Casilla guardada en la ficha del cliente.');
        }

        $resultado = $service->reintentar($aviso->fresh());

        $this->info('Resultado: estado "' . $resultado->estado . '".');
        $this->line('Mail: ' . (is_null($resultado->mail_enviado_at) ? 'no salió' : 'salió a ' . $resultado->email) . '.');
        $this->line('WhatsApp: ' . (is_null($resultado->whatsapp_enviado_at) ? 'no salió' : 'salió') . '.');

        if (! is_null($resultado->error)) {
            $this->warn('Nota: ' . $resultado->error);
        }

        return 0;
    }

    /**
     * Avisos a los que todavía les falta el mail.
     *
     * Son los `sin_mail` (no había casilla) y los `error` (falló de verdad). 🔴 `pendiente` NO
     * entra: ese lo tiene el job de la cola y meterse sería mandarlo dos veces. `sin_novedades`
     * tampoco: reintentar eso da el mismo resultado vacío.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function candidatos_sin_mail()
    {
        return ClientUpgradeNotice::query()
            ->whereNull('mail_enviado_at')
            ->whereIn('estado', [ClientUpgradeNotice::ESTADO_SIN_MAIL, ClientUpgradeNotice::ESTADO_ERROR])
            ->with(['client', 'client_version_upgrade.to_version'])
            ->orderByDesc('id');
    }

    /**
     * Avisos con el mail mandado y el WhatsApp debiendo.
     *
     * Solo los de clientes CON teléfono: sin teléfono no hay WhatsApp posible y esas filas
     * quedarían en la lista para siempre, ensuciando lo que sí se puede resolver.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function candidatos_solo_whatsapp()
    {
        return ClientUpgradeNotice::query()
            ->whereNotNull('mail_enviado_at')
            ->whereNull('whatsapp_enviado_at')
            ->whereHas('client', function ($query) {
                $query->whereNotNull('phone')->where('phone', '!=', '');
            })
            ->with(['client', 'client_version_upgrade.to_version'])
            ->orderByDesc('id');
    }

    /**
     * Una fila del reporte.
     *
     * @param ClientUpgradeNotice $aviso
     *
     * @return array<int, string>
     */
    private function fila_del_reporte(ClientUpgradeNotice $aviso): array
    {
        $client  = $aviso->client;
        $upgrade = $aviso->client_version_upgrade;

        $version = '';
        if (! is_null($upgrade) && ! is_null($upgrade->to_version)) {
            $version = (string) $upgrade->to_version->version;
        }

        $motivo = (string) $aviso->error;
        if (mb_strlen($motivo) > 70) {
            $motivo = mb_substr($motivo, 0, 67) . '...';
        }

        return [
            '#' . $aviso->id,
            is_null($client) ? '(sin cliente)' : (string) $client->slug,
            is_null($client) ? '' : $this->nombre($client),
            '#' . $aviso->client_version_upgrade_id,
            $version,
            (string) $aviso->estado,
            $motivo,
        ];
    }

    /**
     * Busca el cliente por slug y, si no, por id.
     *
     * @param string $slug_o_id
     *
     * @return Client|null
     */
    private function buscar_cliente(string $slug_o_id): ?Client
    {
        $por_slug = Client::where('slug', $slug_o_id)->first();

        if ($por_slug instanceof Client) {
            return $por_slug;
        }

        if (ctype_digit($slug_o_id)) {
            return Client::find((int) $slug_o_id);
        }

        return null;
    }

    /**
     * Nombre con el que se muestra un cliente.
     *
     * @param Client $client
     *
     * @return string
     */
    private function nombre(Client $client): string
    {
        $company = trim((string) $client->company_name);

        return $company !== '' ? $company : trim((string) $client->name);
    }
}
