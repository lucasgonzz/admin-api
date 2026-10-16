<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\ClientAssistantMessage;
use App\Services\AsistenteWhatsappService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * El canal del asistente sale por su propia cola, `asistente` (misión asistente-envios-a-clientes,
 * 16/10/2026).
 *
 * Hasta el 16/10 el job salía por `default`, la cola que atiende un solo worker por minuto que además
 * corre deployments y demo setups: cada respuesta le llegaba al dueño 60 a 150 segundos tarde. Estas
 * pruebas fijan las tres puntas del arreglo:
 *
 *   1. los TRES despachos del canal piden la cola `asistente` (el del mensaje común, el temporizador
 *      de la foto sin epígrafe y el del cierre de la ráfaga);
 *   2. el `release()` del polling la conserva sin volver a pedirla, con la cola `database` de verdad;
 *   3. el scheduler tiene la red —un worker de esa cola por minuto— sin tocar el worker de `default`.
 *
 * El worker permanente (el programa de supervisor `api_admin_queue_asistente`) no se prueba acá:
 * vive en el VPS y no lo instala el código.
 */
class ColaDelAsistenteTest extends BaseDelCanal
{
    /**
     * Teléfono del dueño en todas las pruebas.
     */
    const TELEFONO = '+5493411234567';

    /**
     * Un mensaje de texto del dueño sale por la conexión `database` y por la cola `asistente`.
     *
     * @return void
     */
    public function test_el_mensaje_del_dueno_sale_por_la_cola_del_asistente(): void
    {
        Queue::fake();
        $this->crear_config_whatsapp();
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook(
            $this->payload_de_texto(self::TELEFONO, 'Cuánto vendí ayer?')
        )->assertStatus(200);

        Queue::assertPushed(EnviarMensajeAlAsistenteJob::class, 1);
        Queue::assertPushed(EnviarMensajeAlAsistenteJob::class, function ($job) {
            return $job->connection === 'database' && $job->queue === 'asistente';
        });

        /* Y la cola es la constante del canal, que es la que lee también el Kernel. */
        $this->assertSame('asistente', AsistenteWhatsappService::COLA_DEL_ASISTENTE);
    }

    /**
     * El temporizador de la foto sin epígrafe y el job que despacha el cierre de la ráfaga también
     * salen por la cola `asistente`.
     *
     * Son los otros dos despachos del canal, y los dos son fáciles de olvidar porque no pasan por el
     * camino del texto: el temporizador sale con `delay` y el del cierre lo despacha el job, no el
     * webhook.
     *
     * @return void
     */
    public function test_el_temporizador_de_la_foto_y_el_cierre_de_la_rafaga_salen_por_la_cola_del_asistente(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));

        $client   = $this->crear_cliente(self::TELEFONO);
        $servicio = app(AsistenteWhatsappService::class);

        $foto = $servicio->recibir([
            'from'                => self::TELEFONO,
            'message_id'          => 'wamid.FOTO1',
            'type'                => 'image',
            'body'                => null,
            'inbound_media'       => [
                'url'               => 'https://kapso.test/media/foto-1.jpg',
                'mime'              => 'image/jpeg',
                'filename'          => null,
                'whatsapp_media_id' => 'media.foto.1',
            ],
            'reply_to_message_id' => null,
        ], $client);

        $this->assertSame(ClientAssistantMessage::ESTADO_ESPERANDO, $foto->estado);

        /* Se le vence la espera sin que llegue la instrucción: el temporizador cierra la ráfaga. */
        Carbon::setTestNow(now()->addSeconds(31));
        $cierre = $servicio->cerrar_espera_vencida($foto->fresh());

        $this->assertNotNull($cierre, 'Con la foto sola, a su temporizador le toca cerrar la ráfaga.');

        $despachados = Queue::pushed(EnviarMensajeAlAsistenteJob::class);
        $this->assertCount(2, $despachados, 'El temporizador y el job común del cierre.');

        foreach ($despachados as $job) {
            $this->assertSame('database', $job->connection);
            $this->assertSame('asistente', $job->queue);
        }

        Carbon::setTestNow();
    }

    /**
     * 🔴 El polling se queda en la cola `asistente`: el `release()` la conserva sin pedirla.
     *
     * Con la cola `database` de verdad y el job corrido por el worker (`fire()`), no por `handle()`
     * derecho: es el único camino donde `release()` reencola de verdad. El sistema del cliente
     * contesta 202 y el job se devuelve a la cola para la primera consulta; esa fila de `jobs` tiene
     * que seguir en `asistente`. Si volviera a `default`, el polling lo atendería el worker de los
     * deployments y la demora que este arreglo saca volvería por la puerta de atrás.
     *
     * @return void
     */
    public function test_el_release_del_polling_conserva_la_cola_del_asistente(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->crear_cliente(self::TELEFONO);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response([
                'ai_conversation_id' => 91,
                'ai_message_id'      => 305,
                'estado'             => 'pendiente',
            ], 202),
        ]);

        $previo = (int) DB::table('jobs')->max('id');

        $this->postear_webhook(
            $this->payload_de_texto(self::TELEFONO, 'Cuánto vendí ayer?')
        )->assertStatus(200);

        $fila = ClientAssistantMessage::where('whatsapp_message_id', 'wamid.ENTRANTE1')->firstOrFail();

        $encolados = DB::table('jobs')->where('id', '>', $previo)->get();
        $this->assertCount(1, $encolados);
        $this->assertSame('asistente', $encolados[0]->queue);

        /* El worker lo levanta de la cola `asistente` y lo corre. */
        $job_de_cola = Queue::connection('database')->pop('asistente');
        $this->assertNotNull($job_de_cola, 'El job tenía que estar disponible en la cola del asistente.');
        $this->assertSame((int) $fila->id, $this->mensaje_id_del_payload($job_de_cola->getRawBody()));

        $job_de_cola->fire();

        $this->assertTrue($job_de_cola->isReleased(), 'Después de la ida el job se devuelve a la cola para consultar.');

        $fila->refresh();
        $this->assertSame(ClientAssistantMessage::ESTADO_ENVIADO, $fila->estado);
        $this->assertSame([], $espia->textos, 'Todavía no hay nada que mandarle al dueño.');

        $reencolados = DB::table('jobs')->where('id', '>', $previo)->get();
        $this->assertCount(1, $reencolados, 'El release borra la reserva y deja UNA fila para la consulta.');
        $this->assertSame('asistente', $reencolados[0]->queue, 'El polling tiene que seguir en la cola del asistente.');
        $this->assertSame((int) $fila->id, $this->mensaje_id_del_payload((string) $reencolados[0]->payload));
    }

    /**
     * El scheduler tiene la red de la cola `asistente`, y el worker de `default` sigue siendo uno solo.
     *
     * @return void
     */
    public function test_el_kernel_tiene_la_red_de_la_cola_del_asistente(): void
    {
        $eventos = $this->app->make(Schedule::class)->events();

        $red = array_values(array_filter($eventos, function ($evento) {
            return strpos((string) $evento->command, 'queue:work database --queue=asistente --stop-when-empty') !== false;
        }));

        $this->assertCount(1, $red, 'Tiene que haber exactamente una red de la cola del asistente.');
        $this->assertSame('* * * * *', $red[0]->expression, 'La red corre cada minuto.');
        $this->assertTrue($red[0]->runInBackground, 'En primer plano el polling del asistente clavaría el scheduler.');
        $this->assertStringStartsWith(
            'exec 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&-; ',
            $red[0]->command,
            'La red tampoco puede heredar el candado del cron.'
        );

        /* El worker de `default` no se tocó: sigue siendo uno solo y sin `--queue`. */
        $de_default = array_values(array_filter($eventos, function ($evento) {
            return strpos((string) $evento->command, 'queue:work database --stop-when-empty') !== false;
        }));

        $this->assertCount(1, $de_default);
        $this->assertStringNotContainsString('--queue', $de_default[0]->command);
    }

    /**
     * El `mensaje_id` del job que viaja en un payload crudo de la cola.
     *
     * @param string $payload Payload JSON de la fila de `jobs`.
     *
     * @return int
     */
    private function mensaje_id_del_payload(string $payload): int
    {
        $datos = json_decode($payload, true);
        $job   = unserialize((string) $datos['data']['command']);

        $propiedad = new \ReflectionProperty(EnviarMensajeAlAsistenteJob::class, 'mensaje_id');
        $propiedad->setAccessible(true);

        return (int) $propiedad->getValue($job);
    }
}
