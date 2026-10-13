<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use App\Services\LeadOrigenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La lista de frases que el agente reconoce como un «sí» a la demo (misión
 * origen-del-lead-y-conversiones-meta, 13/10/2026): el texto del botón de la página de experiencia,
 * el de la web y el de Instagram.
 *
 * Lo que se protege: el default trae los tres botones; el PUT nuevo guarda la lista limpia; el PUT
 * viejo (el del admin-spa desplegado, que solo manda `cta_whatsapp_texto`) sigue andando y ese texto
 * queda siempre en la lista; y el bloque DEMO DIRECTA del prompt nombra todas las frases y lo dice
 * explícito cuando el último mensaje del lead es una de ellas.
 */
class CtaTextosDeLaDemoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Arranca sin nada guardado en las dos claves del CTA: la base de testing del slot puede traerlas
     * de una verificación anterior, y entonces un test del default pasaría por casualidad.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        AdminSetting::whereIn('key', [
            LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTO,
            LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTOS,
        ])->delete();
    }

    /**
     * @return Admin
     */
    private function autenticar(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'cta-textos-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Payload PUT completo y válido (los campos obligatorios del formulario de demos), con los
     * overrides que cada test necesite.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload_valido(array $overrides = []): array
    {
        return array_merge([
            'duracion_minutos'                   => 60,
            'setup_minutos_antes'                => 15,
            'gracia_minutos_post'                => 10,
            'recordatorio_minutos_antes'         => 15,
            'recordatorio_manana_hora'           => '09:00',
            'check_ingreso_minutos_post'         => 10,
            'resumen_minutos_antes_fin'          => 10,
            'duracion_llamada_closer_minutos'    => 30,
            'frecuencia_slots_minutos'           => 30,
            'llamada_debe_terminar_en_horario'   => false,
            'ingreso_timeout_minutos'            => 15,
            'fin_seguimiento_minutos'            => 10,
            'fin_timeout_minutos'                => 25,
            'pendiente_ingreso_horas_timeout'    => 24,
            'pendiente_terminar_timeout_minutos' => 120,
        ], $overrides);
    }

    /**
     * Sin nada guardado: el texto del botón por defecto (que es la frase vieja de la web), la frase
     * nueva de la web y la de Instagram, sin repetidas.
     *
     * @return void
     */
    public function test_el_default_trae_los_tres_botones(): void
    {
        $this->assertSame([
            'Hola Martín, quiero hacer la demo de ComercioCity',
            'Hola Martín, vi la web de ComercioCity y quiero hacer la demo',
            'Hola Martín, vengo de Instagram y quiero hacer la demo de ComercioCity',
        ], LeadDemoSettings::get_cta_whatsapp_textos());

        $this->assertSame(LeadDemoSettings::get_cta_whatsapp_textos(), LeadOrigenService::frases_de_cta());
    }

    /**
     * El GET devuelve las dos claves: la vieja (texto del botón) y la lista nueva.
     *
     * @return void
     */
    public function test_el_get_devuelve_las_dos_claves(): void
    {
        $this->autenticar();

        $this->getJson('/api/admin/settings/lead-demo')
            ->assertStatus(200)
            ->assertJsonPath('cta_whatsapp_texto', 'Hola Martín, quiero hacer la demo de ComercioCity')
            ->assertJsonPath('cta_whatsapp_textos', LeadDemoSettings::get_cta_whatsapp_textos());
    }

    /**
     * El PUT con la lista la guarda recortada y sin repetidas (comparando normalizado), y el GET la
     * devuelve con el texto del botón adelante.
     *
     * @return void
     */
    public function test_el_put_con_la_lista_la_persiste_limpia(): void
    {
        $this->autenticar();

        $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido([
            'cta_whatsapp_textos' => ['  Quiero la demo, Martín  ', 'quiero la DEMO martin', 'Vengo del video y quiero probarlo'],
        ]))->assertStatus(200)
            ->assertJsonPath('cta_whatsapp_textos', [
                'Hola Martín, quiero hacer la demo de ComercioCity',
                'Quiero la demo, Martín',
                'Vengo del video y quiero probarlo',
            ]);

        $this->assertSame(
            ['Quiero la demo, Martín', 'Vengo del video y quiero probarlo'],
            json_decode((string) AdminSetting::get(LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTOS), true),
            'Se guarda la lista limpia; el texto del botón se suma al leerla, no al guardarla.'
        );

        $this->getJson('/api/admin/settings/lead-demo')
            ->assertJsonPath('cta_whatsapp_textos', [
                'Hola Martín, quiero hacer la demo de ComercioCity',
                'Quiero la demo, Martín',
                'Vengo del video y quiero probarlo',
            ]);
    }

    /**
     * La validación: lista de 1 a 10 frases de 1 a 200 caracteres. Lo inválido es 422 y no toca lo
     * guardado; la lista vacía es un no-op.
     *
     * @return void
     */
    public function test_la_lista_se_valida_y_lo_invalido_no_toca_lo_guardado(): void
    {
        $this->autenticar();
        AdminSetting::set(LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTOS, json_encode(['Frase guardada']));

        $once = [];
        for ($i = 1; $i <= LeadDemoSettings::MAX_CTA_WHATSAPP_TEXTOS + 1; $i++) {
            $once[] = 'Frase número ' . $i;
        }
        $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido(['cta_whatsapp_textos' => $once]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cta_whatsapp_textos']);

        $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido([
            'cta_whatsapp_textos' => ['Frase buena', str_repeat('a', LeadDemoSettings::MAX_CHARS_CTA_TEXTO + 1)],
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['cta_whatsapp_textos.1']);

        $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido([
            'cta_whatsapp_textos' => ['Frase buena', '   '],
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['cta_whatsapp_textos.1']);

        $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido([
            'cta_whatsapp_textos' => 'no es una lista',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['cta_whatsapp_textos']);

        $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido(['cta_whatsapp_textos' => []]))
            ->assertStatus(200);

        $this->assertSame(['Frase guardada'], json_decode((string) AdminSetting::get(LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTOS), true));
    }

    /**
     * El PUT viejo del admin-spa desplegado (solo `cta_whatsapp_texto`) sigue andando, y ese texto
     * entra a la lista aunque lo guardado no lo traiga: cambiar el botón nunca deja de ser un «sí».
     *
     * @return void
     */
    public function test_el_put_viejo_sigue_andando_y_su_texto_queda_en_la_lista(): void
    {
        $this->autenticar();
        AdminSetting::set(LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTOS, json_encode(['Frase guardada']));

        $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido([
            'cta_whatsapp_texto' => 'Martín, quiero probar el sistema',
        ]))->assertStatus(200)
            ->assertJsonPath('cta_whatsapp_texto', 'Martín, quiero probar el sistema')
            ->assertJsonPath('cta_whatsapp_textos', ['Martín, quiero probar el sistema', 'Frase guardada']);

        $this->assertSame(['Frase guardada'], json_decode((string) AdminSetting::get(LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTOS), true), 'El PUT viejo no toca la lista.');

        /* Y sin lista guardada, el botón nuevo va adelante de las frases de origen. */
        AdminSetting::where('key', LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTOS)->delete();
        $this->assertSame([
            'Martín, quiero probar el sistema',
            'Hola Martín, vi la web de ComercioCity y quiero hacer la demo',
            'Hola Martín, quiero hacer la demo de ComercioCity',
            'Hola Martín, vengo de Instagram y quiero hacer la demo de ComercioCity',
        ], LeadDemoSettings::get_cta_whatsapp_textos());
    }

    /**
     * El bloque DEMO DIRECTA nombra todas las frases; la línea explícita aparece SOLO cuando el
     * último mensaje del lead es una de ellas (aunque venga con un saludo delante y sin tildes).
     *
     * @return void
     */
    public function test_el_bloque_de_demo_directa_nombra_las_frases_y_avisa_el_si(): void
    {
        config(['services.admin_spa.url' => 'https://admin.test']);

        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Guillermo González';
        $lead->phone        = '549351' . random_int(1000000, 9999999);
        $lead->status       = 'contactado';
        $lead->save();

        $this->mensaje_del_lead($lead, 'Hola, tengo una ferretería y quiero ordenar el stock');

        $contexto = $this->contexto_de_demo_directa($lead->fresh());

        foreach (LeadDemoSettings::get_cta_whatsapp_textos() as $frase) {
            $this->assertStringContainsString('"' . $frase . '"', $contexto, 'El bloque tiene que nombrar la frase: ' . $frase);
        }
        $this->assertStringNotContainsString('es un SI a la demo', $contexto, 'Sin la frase como último mensaje no va la línea explícita.');

        $this->mensaje_del_lead($lead, 'Buenas! Hola Martin, vi la web de Comerciocity y quiero hacer la demo');

        $contexto = $this->contexto_de_demo_directa($lead->fresh());
        $this->assertStringContainsString('el ULTIMO mensaje del lead es una de esas frases', $contexto);
        $this->assertStringContainsString('es un SI a la demo', $contexto);
    }

    /**
     * Mensaje entrante del lead.
     *
     * @param Lead   $lead
     * @param string $texto
     *
     * @return LeadMessage
     */
    private function mensaje_del_lead(Lead $lead, string $texto): LeadMessage
    {
        $mensaje                        = new LeadMessage();
        $mensaje->lead_id               = $lead->id;
        $mensaje->sender                = 'lead';
        $mensaje->status                = 'enviado';
        $mensaje->is_followup           = false;
        $mensaje->requiere_verificacion = false;
        $mensaje->content               = $texto;
        $mensaje->save();

        return $mensaje;
    }

    /**
     * El bloque DEMO DIRECTA, por reflexión (el método es privado).
     *
     * @param Lead $lead
     *
     * @return string
     */
    private function contexto_de_demo_directa(Lead $lead): string
    {
        $metodo = new \ReflectionMethod(LeadAiService::class, 'build_demo_directa_context');
        $metodo->setAccessible(true);

        return (string) $metodo->invoke(new LeadAiService(), $lead);
    }
}
