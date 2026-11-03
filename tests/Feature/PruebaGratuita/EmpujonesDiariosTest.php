<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\Client;
use App\Models\Lead;
use App\Models\PruebaWhatsappEnvio;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * El empujón diario por hito faltante (`prueba:empujar` / `PruebaWhatsappService::empujar()`) y el aviso a
 * Tomás al día 5 (misión cruzada prueba-martin-importacion, 3/11/2026).
 *
 * Las reglas del programa, cada una con su prueba: como mucho UNA plantilla por día y por negocio; se frena
 * si el negocio escribió en las últimas 24 h; nunca después de vencida; solo con la plantilla aprobada; y
 * el hito que se empuja es el PRIMERO que falta en el orden del catálogo, según la instancia (C3).
 */
class EmpujonesDiariosTest extends BaseDeLaPrueba
{
    /**
     * Un negocio que ya recibió la bienvenida AYER, con todas las plantillas de paso aprobadas.
     *
     * @param int $dias_restantes
     *
     * @return array{0: Client, 1: Lead}
     */
    private function negocio_con_bienvenida(int $dias_restantes = 5): array
    {
        $this->crear_config_whatsapp();
        $this->admin();
        $this->plantillas_de_la_prueba(true);

        $client = $this->cliente_en_prueba($dias_restantes);
        $lead   = $this->lead_en_prueba($client);

        PruebaWhatsappEnvio::create([
            'client_id'  => $client->id,
            'lead_id'    => $lead->id,
            'plantilla'  => PruebaPlantillasService::BIENVENIDA,
            'hito'       => null,
            'estado'     => PruebaWhatsappEnvio::ESTADO_ENVIADO,
            'dia'        => now(PruebaWhatsappService::ZONA)->subDay()->toDateString(),
            'enviado_at' => now()->subDay(),
        ]);

        return [$client, $lead];
    }

    /** @test */
    public function manda_el_paso_del_primer_hito_faltante_y_solo_uno_por_dia(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio_con_bienvenida();

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso', 'importo']), 200)]);

        $servicio  = app(PruebaWhatsappService::class);
        $resultado = $servicio->empujar($client, $lead);

        $this->assertSame('enviado', $resultado['estado'], (string) $resultado['detalle']);
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame('cc_prueba_paso_articulo_creado', $espia->plantillas[0]['template_name'], 'El primer hito que falta después de ingreso e importo.');
        $this->assertSame(['Marcela'], $espia->plantillas[0]['variables']);

        $envio = PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', 'cc_prueba_paso_articulo_creado')->first();
        $this->assertNotNull($envio);
        $this->assertSame('articulo_creado', $envio->hito);
        $this->assertSame(now(PruebaWhatsappService::ZONA)->toDateString(), $envio->dia);

        /* Una segunda corrida el mismo día no manda nada más. */
        $otra = $servicio->empujar($client, $lead);
        $this->assertSame('ya_hoy', $otra['estado']);
        $this->assertCount(1, $espia->plantillas);
    }

    /** @test */
    public function no_empuja_si_el_negocio_escribio_en_las_ultimas_24_h(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio_con_bienvenida();
        $this->ticket_del_cliente($client, true);

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso']), 200)]);

        $resultado = app(PruebaWhatsappService::class)->empujar($client, $lead);

        $this->assertSame('escribio_hace_poco', $resultado['estado']);
        $this->assertCount(0, $espia->plantillas);
    }

    /** @test */
    public function no_empuja_despues_de_vencida(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio_con_bienvenida(-2);

        $resultado = app(PruebaWhatsappService::class)->empujar($client, $lead);

        $this->assertSame('vencida', $resultado['estado']);
        $this->assertCount(0, $espia->plantillas);
        Http::assertNothingSent();
    }

    /** @test */
    public function sin_la_plantilla_del_paso_aprobada_no_manda_nada(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio_con_bienvenida();

        \App\Models\FollowupTemplate::where('template_name', 'cc_prueba_paso_importo')->update(['activa' => false, 'meta_status' => 'PENDING']);

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso']), 200)]);

        $resultado = app(PruebaWhatsappService::class)->empujar($client, $lead);

        $this->assertSame('plantilla_no_disponible', $resultado['estado']);
        $this->assertStringContainsString('cc_prueba_paso_importo', (string) $resultado['detalle']);
        $this->assertCount(0, $espia->plantillas);
        $this->assertSame(0, PruebaWhatsappEnvio::where('client_id', $client->id)->where('estado', PruebaWhatsappEnvio::ESTADO_EN_COLA)->count(), 'Un empujón no se encola: mañana se vuelve a mirar.');
    }

    /** @test */
    public function cuando_paso_por_todos_los_hitos_no_hay_nada_que_empujar(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio_con_bienvenida();

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(array_keys(\App\Services\PruebaDelClienteService::HITOS)), 200)]);

        $resultado = app(PruebaWhatsappService::class)->empujar($client, $lead);

        $this->assertSame('sin_hito_faltante', $resultado['estado']);
        $this->assertCount(0, $espia->plantillas);
    }

    /** @test */
    public function al_dia_5_avisa_a_tomas_una_sola_vez_y_el_comando_recorre_a_todos(): void
    {
        $espia = $this->espiar_sender();
        /* Vence en 2 días y media hora con una prueba de 7: empezó hace 4 días y 23,5 horas, va por el día 5. */
        [$client, $lead] = $this->negocio_con_bienvenida(2);
        $this->admin(true);

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso']), 200)]);

        $resultados = app(PruebaWhatsappService::class)->empujar_a_todos();

        $this->assertCount(1, $resultados);
        $this->assertSame('avisado', $resultados[0]['tomas']['estado']);
        $this->assertSame('enviado', $resultados[0]['estado']);

        $this->assertCount(1, $espia->textos, 'El aviso a Tomás.');
        $this->assertSame('+5493415550000', $espia->textos[0]['to']);
        $this->assertStringContainsString('va por el día 5 de su prueba gratuita: le quedan 3 días', $espia->textos[0]['body']);
        $this->assertCount(1, $espia->plantillas, 'Y el empujón al negocio.');

        /* El comando, al día siguiente del envío: Tomás ya avisado, el negocio con su tope diario. */
        $this->assertSame(0, Artisan::call('prueba:empujar'));
        $this->assertCount(1, $espia->textos);
        $this->assertCount(1, $espia->plantillas);
        $this->assertStringContainsString('ya_hoy', Artisan::output());
    }

    /** @test */
    public function un_negocio_que_todavia_no_entro_recibe_el_paso_de_ingreso_y_no_otro(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio_con_bienvenida();

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia([]), 200)]);

        $resultado = app(PruebaWhatsappService::class)->empujar($client, $lead);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertSame('cc_prueba_paso_ingreso', $espia->plantillas[0]['template_name']);
    }
}
