<?php

namespace Tests\Feature\TextosDelEmbudo;

use App\Models\Admin;
use App\Services\LeadLlamadaService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\TableroDeJuan\BaseDelTableroDeJuan;

/**
 * Enmienda 4 de la ronda 2 de E3, el otro lugar: cuando el agente carga una llamada sin decir quién la hizo, `quien_hizo_la_llamada`
 * elige a Juan: primero `solo_leads`; si no hay, un `es_vendedor` que NO sea `is_closer`; si no hay, el closer. El closer marcado como
 * vendedor y más viejo que Juan no le gana.
 */
class QuienHizoLaLlamadaDelAgenteTest extends BaseDelTableroDeJuan
{
    /**
     * Carga una llamada de audio como el agente, sin `hecha_por_admin_id`, y devuelve quién figura como autor.
     *
     * @return int
     */
    private function autor_de_la_llamada(): int
    {
        $agente = Admin::create(['name' => 'Agente', 'email' => 'agente-dest-' . uniqid() . '@test.local', 'password' => bcrypt('secret'), 'es_agente' => true]);
        $lead   = $this->crear_lead_del_tablero('contactado');

        app(LeadLlamadaService::class)->registrar($lead, $agente, [
            'atendio' => true, 'resultado' => 'sigue_solo', 'origen' => 'audio_juan', 'origen_clave' => 'wa:dest-' . uniqid(),
        ]);

        return (int) DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->value('admin_id');
    }

    /**
     * @return void
     */
    public function test_el_closer_vendedor_mas_viejo_no_le_gana_a_juan(): void
    {
        $this->sembrar_el_tablero();
        Admin::query()->update(['solo_leads' => false, 'es_vendedor' => false, 'is_closer' => false]);
        $closer = Admin::create(['name' => 'Closer', 'email' => 'closer-dest@test.local', 'password' => bcrypt('secret'), 'is_closer' => true, 'es_vendedor' => true]);
        $juan   = Admin::create(['name' => 'Juan', 'email' => 'juan-dest2@test.local', 'password' => bcrypt('secret'), 'solo_leads' => true]);
        $this->assertLessThan((int) $juan->id, (int) $closer->id, 'Precondición: el closer es más viejo.');

        $this->assertSame((int) $juan->id, $this->autor_de_la_llamada());
    }

    /**
     * @return void
     */
    public function test_sin_juan_gana_un_vendedor_que_no_es_closer_y_sin_ninguno_el_closer(): void
    {
        $this->sembrar_el_tablero();
        Admin::query()->update(['solo_leads' => false, 'es_vendedor' => false, 'is_closer' => false]);
        $closer = Admin::create(['name' => 'Closer', 'email' => 'closer-dest3@test.local', 'password' => bcrypt('secret'), 'is_closer' => true, 'es_vendedor' => true]);

        $this->assertSame((int) $closer->id, $this->autor_de_la_llamada(), 'Sin nadie más, el closer.');

        $vendedor = Admin::create(['name' => 'Vendedor', 'email' => 'vendedor-dest3@test.local', 'password' => bcrypt('secret'), 'es_vendedor' => true]);

        $this->assertSame((int) $vendedor->id, $this->autor_de_la_llamada(), 'Un vendedor que no es closer le gana al closer.');
    }
}
