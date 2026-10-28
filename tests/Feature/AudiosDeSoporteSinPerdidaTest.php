<?php

namespace Tests\Feature;

use App\Events\SupportMessageReceived;
use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fakes\BytesDeAudioSinteticos;
use Tests\TestCase;

class AudiosDeSoporteSinPerdidaTest extends TestCase
{
    use DatabaseTransactions;
    use BytesDeAudioSinteticos;

    private $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Event::fake([SupportMessageReceived::class]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
        parent::tearDown();
    }

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Lucas', 'email' => Str::uuid() . '@prueba.local', 'password' => bcrypt('secret')]);
    }

    private function ticket(): SupportTicket
    {
        $client = Client::create(['name' => 'Cliente de prueba', 'is_active' => true,
            'api_url' => 'https://cliente.test', 'api_key' => 'clave-de-prueba']);
        return SupportTicket::create(['client_id' => $client->id, 'client_user_id' => 0,
            'status' => 'open', 'source' => 'erp', 'opened_at' => now()]);
    }

    private function audio(): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'voz_');
        $this->temporales[] = $ruta;
        file_put_contents($ruta, $this->bytes_ogg_opus());
        return new UploadedFile($ruta, 'nota.ogg', 'audio/ogg', null, true);
    }

    private function enviar(SupportTicket $ticket, string $clave, bool $adjunto = true)
    {
        $datos = ['kind' => 'audio', 'client_message_id' => $clave];
        if ($adjunto) {
            $datos['attachment'] = $this->audio();
        }
        return $this->post('/api/admin/support-ticket/' . $ticket->id . '/message', $datos, ['Accept' => 'application/json']);
    }

    public function test_repetir_una_subida_confirmada_recupera_el_mismo_audio_sin_reenviar(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $clave = (string) Str::uuid();
        $primera = $this->enviar($ticket, $clave)->assertStatus(201);
        $segunda = $this->enviar($ticket, $clave, false)->assertStatus(200);
        $this->assertSame($primera->json('model.id'), $segunda->json('model.id'));
        $this->assertCount(1, $segunda->json('model.attachments'));
        $adjunto = SupportMessageAttachment::findOrFail($segunda->json('model.attachments.0.id'));
        $this->assertSame($this->bytes_ogg_opus(), Storage::disk('public')->get($adjunto->path));
        $this->assertSame(1, SupportMessage::where('support_ticket_id', $ticket->id)->count());
        Http::assertSentCount(1);
        Event::assertDispatchedTimes(SupportMessageReceived::class, 1);
    }

    public function test_un_reintento_tambien_recupera_el_audio_si_el_ticket_ya_se_cerro(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $clave = (string) Str::uuid();
        $primera = $this->enviar($ticket, $clave)->assertStatus(201);
        $ticket->update(['status' => 'closed']);
        $this->enviar($ticket, $clave, false)->assertStatus(200)->assertJsonPath('model.id', $primera->json('model.id'));
        Http::assertSentCount(1);
    }

    public function test_la_clave_no_mezcla_tickets_ni_operadores(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $clave = (string) Str::uuid();
        $a = $this->enviar($ticket, $clave)->assertStatus(201)->json('model.id');
        $b = $this->enviar($this->ticket(), $clave)->assertStatus(201)->json('model.id');
        $this->actingAs($this->admin(), 'sanctum');
        $c = $this->enviar($ticket, $clave)->assertStatus(201)->json('model.id');
        $this->assertCount(3, array_unique([$a, $b, $c]));
        Http::assertSentCount(3);
    }

    public function test_el_cliente_viejo_sin_clave_sigue_pudiendo_mandar_audio(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $this->post('/api/admin/support-ticket/' . $ticket->id . '/message', ['kind' => 'audio',
            'attachment' => $this->audio()], ['Accept' => 'application/json'])->assertStatus(201);
        $this->assertCount(1, $ticket->messages()->first()->attachments);
    }

    public function test_si_el_disco_no_guarda_no_queda_mensaje_confirmado_y_se_puede_reintentar(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $clave = (string) Str::uuid();
        $disco = Storage::disk('public');
        $roto = \Mockery::mock($disco)->makePartial();
        $roto->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::set('public', $roto);
        $this->enviar($ticket, $clave)->assertStatus(500);
        $this->assertSame(0, $ticket->messages()->count());
        $this->assertSame(0, SupportMessageAttachment::where('path', 'like', 'support_messages/' . $ticket->id . '/%')->count());
        Http::assertNothingSent();
        Storage::set('public', $disco);
        $this->enviar($ticket, $clave)->assertStatus(201);
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_un_audio_sin_archivo_no_se_confirma_como_mensaje_vacio(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $this->enviar($ticket, (string) Str::uuid(), false)->assertStatus(422);
        $this->assertSame(0, $ticket->messages()->count());
        Http::assertNothingSent();
    }

    public function test_un_archivo_truncado_se_limpia_y_no_se_confirma_ni_se_envia(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $disco = Storage::disk('public');
        $roto = \Mockery::mock($disco)->makePartial();
        $roto->shouldReceive('putFileAs')->once()->andReturnUsing(function ($carpeta, $archivo, $nombre) use ($disco) {
            $ruta = $carpeta . '/' . $nombre;
            $disco->put($ruta, 'incompleto');
            return $ruta;
        });
        Storage::set('public', $roto);
        $this->enviar($ticket, (string) Str::uuid())->assertStatus(500);
        $this->assertSame(0, $ticket->messages()->count());
        $this->assertSame([], $disco->allFiles('support_messages/' . $ticket->id));
        Http::assertNothingSent();
    }

    public function test_si_el_destino_falla_el_audio_igual_queda_guardado_y_el_post_se_recupera(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $ticket = $this->ticket();
        $ticket->client->update(['api_url' => null]);
        $clave = (string) Str::uuid();
        $primera = $this->enviar($ticket, $clave)->assertStatus(201);
        $primera->assertJsonPath('model.remote_delivery_status', 'not_received');
        $segunda = $this->enviar($ticket, $clave, false)->assertStatus(200);
        $this->assertSame($primera->json('model.id'), $segunda->json('model.id'));
        $adjunto = SupportMessageAttachment::findOrFail($segunda->json('model.attachments.0.id'));
        $this->assertSame($this->bytes_ogg_opus(), Storage::disk('public')->get($adjunto->path));
        Http::assertNothingSent();
    }

    public function test_un_reintento_mientras_la_entrega_esta_en_vuelo_no_manda_otra_copia(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');
        $ticket = $this->ticket();
        $message = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin',
            'sender_admin_id' => $admin->id, 'kind' => 'text', 'body' => 'En vuelo',
            'remote_delivery_status' => 'not_received', 'delivered_at' => now()]);
        $lock = Cache::store('file')->lock('support-outbound-' . $message->id, 300);
        $this->assertTrue($lock->get());
        try {
            $this->post('/api/admin/support-message/' . $message->id . '/retry-remote-sync', [], ['Accept' => 'application/json'])
                ->assertStatus(200)->assertJsonPath('model.id', $message->id);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
        $this->post('/api/admin/support-message/' . $message->id . '/retry-remote-sync', [], ['Accept' => 'application/json'])->assertStatus(200);
        Http::assertSentCount(1);
        // Otro toque después de confirmar tampoco vuelve a sincronizar el ERP.
        $this->post('/api/admin/support-message/' . $message->id . '/retry-remote-sync', [], ['Accept' => 'application/json'])->assertStatus(200);
        Http::assertSentCount(1);
    }
}
