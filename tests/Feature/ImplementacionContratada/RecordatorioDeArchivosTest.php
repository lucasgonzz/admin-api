<?php

namespace Tests\Feature\ImplementacionContratada;

use App\Models\Client;
use App\Models\FollowupTemplate;
use App\Models\PruebaWhatsappEnvio;
use App\Services\Prueba\PruebaVpsService;
use App\Services\PruebaPlantillasService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\PruebaVpsServiceFake;

/**
 * CA25 de la etapa E2 (misión embudo-juan-pipeline): el recordatorio de archivos.
 *
 * Una prueba PAUSADA (pagó la mitad) a la que todavía no le llegó el Excel de artículos recibe, por el ticket del dueño, la plantilla
 * `cc_impl_archivos_recordatorio` los días 1, 3 y 6 desde que pagó (días calendario), una sola vez por día. Los demás días no sale
 * nada, y con los archivos ya recibidos tampoco.
 *
 * Hoy está EN ROJO: la pausa y el recordatorio no existen.
 */
class RecordatorioDeArchivosTest extends BaseDeLaImplementacion
{
    /** Nombre de la plantilla del recordatorio. */
    const PLANTILLA = 'cc_impl_archivos_recordatorio';

    /** @var \App\Services\WhatsappSendService */
    private $espia;

    /** @var Client */
    private $client;

    /**
     * Una prueba pagada el 23/11 a las 12:00, con la bienvenida ya enviada y la plantilla del recordatorio aprobada.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
        $this->app->instance(PruebaVpsService::class, new PruebaVpsServiceFake());
        $this->crear_config_whatsapp();
        $this->admin(true);
        $this->espia = $this->espiar_sender();

        FollowupTemplate::create([
            'template_name' => self::PLANTILLA, 'estado' => PruebaPlantillasService::ESTADO, 'dia_numero' => 1,
            'body_template' => 'Hola {{1}}, ¿cómo vas con los archivos para tu implementación?', 'language_code' => 'es_AR',
            'activa' => true, 'meta_status' => 'APPROVED', 'meta_category' => 'UTILITY', 'uso' => 'Recordatorio de archivos (test).',
            'solo_si_ingreso_confirmado' => false, 'no_marca_error_de_entrega' => false,
        ]);

        list($this->client, $lead) = $this->prueba_con_lead();
        PruebaWhatsappEnvio::create([
            'client_id' => $this->client->id, 'lead_id' => $lead->id, 'plantilla' => PruebaPlantillasService::BIENVENIDA, 'hito' => null,
            'estado' => PruebaWhatsappEnvio::ESTADO_ENVIADO, 'dia' => '2026-11-20', 'enviado_at' => Carbon::parse('2026-11-20 11:00', self::$ZONA_DEL_TABLERO),
        ]);
        $this->fijar_implementacion($this->client, 'mitad', ['gestion'])->assertStatus(200);
    }

    /**
     * Corre `prueba:empujar` el día dado a las 13:00 y devuelve cuántos recordatorios salieron en total.
     *
     * @param string $dia `Y-m-d`.
     *
     * @return int
     */
    private function empujar_el(string $dia): int
    {
        $this->clavar_el_reloj($dia . ' 13:00:00');
        Artisan::call('prueba:empujar', ['--cliente' => $this->client->id]);

        $cantidad = 0;
        foreach ($this->espia->plantillas as $envio) {
            if ($envio['template_name'] === self::PLANTILLA) {
                $cantidad++;
            }
        }

        return $cantidad;
    }

    /**
     * CA25. Salen los días 1, 3 y 6 desde el pago (24, 26 y 29 de noviembre) y no el 25; correr dos veces el mismo día no repite.
     *
     * @return void
     */
    public function test_salen_los_dias_1_3_y_6(): void
    {
        $this->assertSame(1, $this->empujar_el('2026-11-24'));
        $this->assertSame(1, $this->empujar_el('2026-11-24'), 'El mismo día no se repite.');
        $this->assertSame(1, $this->empujar_el('2026-11-25'), 'El día 2 no sale.');
        $this->assertSame(2, $this->empujar_el('2026-11-26'));
        $this->assertSame(3, $this->empujar_el('2026-11-29'));

        $this->assertSame(3, PruebaWhatsappEnvio::query()->where('client_id', $this->client->id)->where('plantilla', self::PLANTILLA)->count());
        $this->assertSame(['Marcela'], $this->espia->plantillas[0]['variables'], 'Con el primer nombre del dueño.');
    }

    /**
     * CA25. Con los archivos ya recibidos no sale nada.
     *
     * @return void
     */
    public function test_con_los_archivos_recibidos_no_sale_nada(): void
    {
        Client::query()->whereKey($this->client->id)->update(['implementacion_articulos_recibidos_at' => Carbon::parse('2026-11-23 18:00', self::$ZONA_DEL_TABLERO)]);

        $this->assertSame(0, $this->empujar_el('2026-11-24'));
        $this->assertSame(0, $this->empujar_el('2026-11-26'));
    }
}
