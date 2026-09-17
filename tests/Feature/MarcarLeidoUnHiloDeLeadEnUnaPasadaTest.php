<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadManualUnreadMark;
use App\Models\LeadMessage;
use App\Models\LeadMessageRead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Abrir la conversación de un lead marca leído TODO el hilo en una sola pasada.
 *
 * Antes era un `firstOrCreate` por mensaje adentro de un `foreach`: DOS consultas por mensaje
 * (el SELECT del firstOrCreate y el INSERT cuando faltaba). Una conversación de 300 mensajes eran
 * hasta 600 consultas, y esto se dispara cada vez que alguien abre el hilo.
 *
 * 🔴 Lo que se prueba acá es el COMPORTAMIENTO, no la velocidad: que marque todos, que sea
 * idempotente, que siga siendo per-admin y que la respuesta sea la misma de siempre. El conteo de
 * consultas está al final y es lo de menos — si el comportamiento se rompe, lo rápido no sirve.
 */
class MarcarLeidoUnHiloDeLeadEnUnaPasadaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Admin del panel.
     *
     * @param string $nombre Nombre del admin.
     *
     * @return Admin
     */
    private function crear_admin(string $nombre): Admin
    {
        $admin           = new Admin();
        $admin->name     = $nombre;
        $admin->email    = Str::slug($nombre) . '+' . Str::random(10) . '@comerciocity.com';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Lead con una conversación de ida y vuelta.
     *
     * @param int $cantidad_mensajes Cuántos mensajes tiene el hilo.
     *
     * @return array{lead: Lead, mensajes: \Illuminate\Support\Collection}
     */
    private function crear_lead_con_conversacion(int $cantidad_mensajes): array
    {
        $lead                = new Lead();
        $lead->contact_name  = 'Juan Perez';
        $lead->company_name  = 'Comercio de prueba';
        $lead->phone         = '+549341555' . random_int(1000, 9999);
        $lead->save();

        $mensajes = collect();
        for ($i = 0; $i < $cantidad_mensajes; $i++) {
            $message          = new LeadMessage();
            $message->lead_id = $lead->id;
            // Se alternan los dos emisores a propósito: el arreglo del 2/7/2026 fue justamente
            // que abrir la conversación marque leído todo el hilo, no solo lo que mandó el lead.
            $message->sender  = ($i % 2 === 0) ? 'lead' : 'admin';
            $message->content = 'Mensaje ' . $i;
            $message->save();

            $mensajes->push($message);
        }

        return ['lead' => $lead, 'mensajes' => $mensajes];
    }

    /**
     * Abrir la conversación marca leídos TODOS los mensajes del hilo, de los dos emisores.
     *
     * @return void
     */
    public function test_marca_todo_el_hilo_no_solo_lo_que_mando_el_lead(): void
    {
        $admin    = $this->crear_admin('Lucas');
        $armado   = $this->crear_lead_con_conversacion(8);
        $lead     = $armado['lead'];
        $mensajes = $armado['mensajes'];

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/mark-whatsapp-messages-read');

        $response->assertStatus(200);

        $marcados = LeadMessageRead::where('admin_id', $admin->id)
            ->whereIn('lead_message_id', $mensajes->pluck('id'))
            ->pluck('lead_message_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $mensajes->pluck('id')->sort()->values()->all(),
            $marcados,
            'Tienen que quedar marcados los 8 mensajes del hilo, sin importar quién los mandó.'
        );

        // Y todos con su read_at cargado: la tabla no lo admite nulo.
        $this->assertSame(
            0,
            LeadMessageRead::where('admin_id', $admin->id)
                ->whereIn('lead_message_id', $mensajes->pluck('id'))
                ->whereNull('read_at')
                ->count(),
            'Ninguna fila de lectura puede quedar sin read_at.'
        );
    }

    /**
     * 🔴 Idempotencia: abrir la conversación dos veces no duplica ni una fila.
     *
     * Es lo que garantizaba el `firstOrCreate` que se sacó, así que es la prueba que no puede
     * faltar. Se corre tres veces y con un mensaje nuevo en el medio, que es el caso real:
     * el operador entra, el lead contesta, el operador vuelve a entrar.
     *
     * @return void
     */
    public function test_abrir_la_conversacion_varias_veces_no_duplica_lecturas(): void
    {
        $admin  = $this->crear_admin('Lucas');
        $armado = $this->crear_lead_con_conversacion(5);
        $lead   = $armado['lead'];

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/mark-whatsapp-messages-read')
            ->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/mark-whatsapp-messages-read')
            ->assertStatus(200);

        // Llega un mensaje nuevo y el operador vuelve a entrar.
        $nuevo          = new LeadMessage();
        $nuevo->lead_id = $lead->id;
        $nuevo->sender  = 'lead';
        $nuevo->content = 'Una consulta más';
        $nuevo->save();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/mark-whatsapp-messages-read')
            ->assertStatus(200);

        $ids_del_hilo = LeadMessage::where('lead_id', $lead->id)->pluck('id');

        $this->assertSame(
            6,
            LeadMessageRead::where('admin_id', $admin->id)->whereIn('lead_message_id', $ids_del_hilo)->count(),
            'Tiene que haber exactamente una fila por mensaje: 5 del hilo original + el que llegó después.'
        );

        // Una fila por mensaje, no dos para alguno.
        $por_mensaje = LeadMessageRead::where('admin_id', $admin->id)
            ->whereIn('lead_message_id', $ids_del_hilo)
            ->get()
            ->groupBy('lead_message_id')
            ->map(function ($filas) {
                return $filas->count();
            });

        foreach ($por_mensaje as $message_id => $cantidad) {
            $this->assertSame(1, $cantidad, "El mensaje {$message_id} quedó con {$cantidad} filas de lectura.");
        }
    }

    /**
     * La lectura sigue siendo per-admin: que Lucas abra el hilo no se lo marca leído a Martín.
     *
     * @return void
     */
    public function test_la_lectura_sigue_siendo_de_cada_admin(): void
    {
        $lucas  = $this->crear_admin('Lucas');
        $martin = $this->crear_admin('Martin');
        $armado = $this->crear_lead_con_conversacion(4);
        $lead   = $armado['lead'];

        $this->actingAs($lucas, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/mark-whatsapp-messages-read')
            ->assertStatus(200);

        $ids_del_hilo = LeadMessage::where('lead_id', $lead->id)->pluck('id');

        $this->assertSame(
            4,
            LeadMessageRead::where('admin_id', $lucas->id)->whereIn('lead_message_id', $ids_del_hilo)->count(),
            'Lucas tiene que tener sus cuatro lecturas.'
        );
        $this->assertSame(
            0,
            LeadMessageRead::where('admin_id', $martin->id)->whereIn('lead_message_id', $ids_del_hilo)->count(),
            'Martín no puede quedar con nada marcado: no abrió la conversación.'
        );

        // Y cuando Martín entra, se marcan las suyas sin tocar las de Lucas.
        $this->actingAs($martin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/mark-whatsapp-messages-read')
            ->assertStatus(200);

        $this->assertSame(4, LeadMessageRead::where('admin_id', $lucas->id)->whereIn('lead_message_id', $ids_del_hilo)->count());
        $this->assertSame(4, LeadMessageRead::where('admin_id', $martin->id)->whereIn('lead_message_id', $ids_del_hilo)->count());
    }

    /**
     * Lo que el mismo endpoint hacía además de marcar: limpiar la marca manual de "no leído" de ese
     * admin y bajar la bandera global de "pendiente de revisión". No se tocó, y tiene que seguir.
     *
     * @return void
     */
    public function test_sigue_limpiando_la_marca_manual_y_la_bandera_de_revision(): void
    {
        $admin  = $this->crear_admin('Lucas');
        $armado = $this->crear_lead_con_conversacion(3);
        $lead   = $armado['lead'];

        $lead->pendiente_revision_at = now();
        $lead->save();

        $marca             = new LeadManualUnreadMark();
        $marca->lead_id    = $lead->id;
        $marca->admin_id   = $admin->id;
        $marca->marked_at  = now();
        $marca->save();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/mark-whatsapp-messages-read')
            ->assertStatus(200);

        $this->assertSame(
            0,
            LeadManualUnreadMark::where('lead_id', $lead->id)->where('admin_id', $admin->id)->count(),
            'Abrir la conversación tiene que seguir borrando la marca manual de no leído.'
        );
        $this->assertNull(
            $lead->fresh()->pendiente_revision_at,
            'Abrir la conversación tiene que seguir bajando la bandera de pendiente de revisión.'
        );
    }

    /**
     * La respuesta no cambió: sigue devolviendo el lead entero bajo la clave `model`.
     *
     * El SPA rehidrata la conversación con eso, así que devolver otra cosa lo dejaría mudo.
     *
     * @return void
     */
    public function test_la_respuesta_sigue_siendo_el_lead_entero(): void
    {
        $admin  = $this->crear_admin('Lucas');
        $armado = $this->crear_lead_con_conversacion(3);
        $lead   = $armado['lead'];

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/mark-whatsapp-messages-read');

        $response->assertStatus(200);

        $payload = $response->json();

        $this->assertArrayHasKey('model', $payload, 'La respuesta tiene que seguir viniendo bajo la clave "model".');
        $this->assertSame((int) $lead->id, (int) $payload['model']['id']);
        // El lead llega con sus relaciones, como siempre (fullModel usa withAll).
        $this->assertArrayHasKey('messages', $payload['model'], 'El lead devuelto tiene que seguir trayendo sus mensajes.');
        $this->assertCount(3, $payload['model']['messages']);
    }

    /**
     * El costo ya no crece con el largo de la conversación.
     *
     * Antes eran hasta 2 consultas POR MENSAJE. Ahora son dos fijas contra lead_message_reads:
     * el SELECT de lo que ya estaba marcado y el INSERT del resto.
     *
     * @return void
     */
    public function test_el_costo_no_crece_con_el_largo_de_la_conversacion(): void
    {
        $admin  = $this->crear_admin('Lucas');
        $armado = $this->crear_lead_con_conversacion(30);
        $lead   = $armado['lead'];

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/mark-whatsapp-messages-read')
            ->assertStatus(200);

        $consultas = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        $contra_lecturas = 0;
        foreach ($consultas as $consulta) {
            if (strpos($consulta['query'], 'lead_message_reads') !== false) {
                $contra_lecturas++;
            }
        }

        // 2 del marcado (select + insert). fullModel() suma las suyas para contar no leídos,
        // pero ninguna de ellas depende de cuántos mensajes tiene el hilo.
        $this->assertLessThanOrEqual(
            5,
            $contra_lecturas,
            "Con 30 mensajes hubo {$contra_lecturas} consultas a lead_message_reads: el costo volvió a crecer con el hilo."
        );
    }
}
