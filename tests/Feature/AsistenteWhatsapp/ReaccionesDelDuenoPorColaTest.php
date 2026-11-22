<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\ReenviarReaccionAlAsistenteJob;
use App\Models\ClientAssistantMessage;
use App\Services\AsistenteWhatsappService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * El reenvío de la reacción tiene que salir por la conexión `database` y la cola `asistente`.
 *
 * Con la conexión por defecto de admin-api (`sync`) el POST correría dentro del webhook de Kapso y
 * `release()` no reencolaría nada: los reintentos ante un 5xx no existirían.
 */
class ReaccionesDelDuenoPorColaTest extends BaseDelCanal
{
    /**
     * El webhook despacha el job por la conexión y la cola del canal del asistente.
     *
     * @return void
     */
    public function test_el_reenvio_sale_por_la_conexion_database_y_la_cola_del_asistente(): void
    {
        Queue::fake();
        Cache::flush();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente('+5493415551234', true, 'clave-del-cliente');
        $this->crear_client_api($client);

        ClientAssistantMessage::create([
            'client_id'           => $client->id,
            'telefono'            => '+5493415551234',
            'direccion'           => ClientAssistantMessage::DIRECCION_SALIENTE,
            'whatsapp_message_id' => 'wamid.SALCOLA',
            'ai_message_id'       => 5,
            'tipo'                => 'text',
            'texto'               => 'Hola',
            'estado'              => ClientAssistantMessage::ESTADO_ENVIADO,
        ]);

        $this->postear_webhook([
            'event'           => 'whatsapp.message.received',
            'phone_number_id' => self::PHONE_NUMBER_ID_PRINCIPAL,
            'conversation'    => ['phone_number' => '+5493415551234'],
            'message'         => [
                'id'        => 'wamid.REACTCOLA',
                'from'      => '5493415551234',
                'timestamp' => '1763790000',
                'type'      => 'reaction',
                'reaction'  => ['message_id' => 'wamid.SALCOLA', 'emoji' => '👎'],
            ],
        ])->assertStatus(200);

        Queue::assertPushedOn(AsistenteWhatsappService::COLA_DEL_ASISTENTE, ReenviarReaccionAlAsistenteJob::class);

        $job = Queue::pushed(ReenviarReaccionAlAsistenteJob::class)->first();
        $this->assertSame('database', $job->connection);
        $this->assertSame(AsistenteWhatsappService::CONEXION_DE_COLA, $job->connection);
        $this->assertSame('asistente', $job->queue);
    }
}
