<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra leads de prueba para {@see \App\Services\LeadFollowupService} y el comando `leads:check-followups`.
 *
 * No se invoca desde {@see DatabaseSeeder} para no mezclar datos de prueba en bases productivas.
 * Uso típico (local):
 *
 *   php artisan db:seed --class=LeadFollowupTestSeeder
 *   php artisan leads:check-followups
 *
 * Casos creados:
 * - **followup-test-ai**: `contactado`, última actividad >24h, sin `is_followup` previos → intenta sugerencia IA (requiere Anthropic).
 * - **followup-test-pause**: `nuevo`, ya tiene 1 mensaje `is_followup` y última actividad >48h → pasa a `en_pausa` **sin** llamar a la API.
 * - **followup-test-too-soon**: `calificado`, mensaje reciente → el servicio no hace nada (control negativo).
 * - **followup-test-skip-pending**: `demo_realizada` con `tiene_sugerencia_pendiente` → el servicio omite el lead.
 */
class LeadFollowupTestSeeder extends Seeder
{
    /**
     * Emails reservados para idempotencia (dominio .invalid RFC 2606).
     */
    private const EMAIL_AI = 'followup-test-ai@example.invalid';

    private const EMAIL_PAUSE = 'followup-test-pause@example.invalid';

    private const EMAIL_TOO_SOON = 'followup-test-too-soon@example.invalid';

    private const EMAIL_SKIP_PENDING = 'followup-test-skip-pending@example.invalid';

    /**
     * Crea o actualiza los cuatro escenarios y sus mensajes.
     *
     * @return void
     */
    public function run()
    {
        $admin = Admin::query()->orderBy('id')->first();
        if (! $admin) {
            if ($this->command) {
                $this->command->warn('LeadFollowupTestSeeder: no hay admins. Ejecutá antes: php artisan db:seed --class=AdminUserSeeder');
            }

            return;
        }

        $this->seed_lead_ai_followup($admin->id);
        $this->seed_lead_pause_without_api($admin->id);
        $this->seed_lead_too_soon($admin->id);
        $this->seed_lead_skip_pending_suggestion($admin->id);

        if ($this->command) {
            $this->command->info('LeadFollowupTestSeeder: listo. Corré: php artisan leads:check-followups');
        }
    }

    /**
     * Lead que cumple horas de espera y debería llamar a Claude (si hay API key).
     *
     * @param int $admin_id
     *
     * @return void
     */
    protected function seed_lead_ai_followup(int $admin_id): void
    {
        $lead = Lead::updateOrCreate(
            ['email' => self::EMAIL_AI],
            [
                'contact_name'                     => 'Lead test IA followup',
                'company_name'                     => 'Empresa Test Followup IA',
                'phone'                            => '+5411000000001',
                'status'                           => 'contactado',
                'created_by_admin_id'              => $admin_id,
                'notes'                            => '[LeadFollowupTestSeeder] Caso IA: contactado + >24h sin followup previo.',
                'tiene_sugerencia_pendiente'       => false,
                'requiere_seguimiento'             => false,
                'tiene_seguimiento_sin_ver'        => false,
            ]
        );

        LeadMessage::query()->where('lead_id', $lead->id)->delete();
        $this->backdate_lead_row($lead->id, Carbon::now()->subDays(5));

        $msg = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'lead',
            'content'               => 'Respuesta de prueba del lead (hace más de 24 h según timestamps forzados).',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ]);
        $this->backdate_message_row($msg->id, Carbon::now()->subHours(30));
    }

    /**
     * Lead que supera `max_followups` del estado `nuevo` (1) → solo pausa, sin Anthropic.
     *
     * @param int $admin_id
     *
     * @return void
     */
    protected function seed_lead_pause_without_api(int $admin_id): void
    {
        $lead = Lead::updateOrCreate(
            ['email' => self::EMAIL_PAUSE],
            [
                'contact_name'               => 'Lead test pausa auto',
                'company_name'               => 'Empresa Test Pausa',
                'phone'                      => '+5411000000002',
                'status'                     => 'nuevo',
                'created_by_admin_id'        => $admin_id,
                'notes'                      => '[LeadFollowupTestSeeder] Caso pausa: nuevo con 1 is_followup ya contado.',
                'tiene_sugerencia_pendiente' => false,
                'requiere_seguimiento'       => false,
                'tiene_seguimiento_sin_ver'  => false,
            ]
        );

        LeadMessage::query()->where('lead_id', $lead->id)->delete();
        $this->backdate_lead_row($lead->id, Carbon::now()->subDays(6));

        $old = Carbon::now()->subHours(72);
        $m_first = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'lead',
            'content'               => 'Hola, primer contacto.',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ]);
        $this->backdate_message_row($m_first->id, $old);

        $m_follow = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => 'Seguimiento automático previo (simulado).',
            'status'                => 'enviado',
            'is_followup'           => true,
            'requiere_verificacion' => false,
        ]);
        $this->backdate_message_row($m_follow->id, $old->copy()->addHour());
    }

    /**
     * Lead con actividad reciente: no debe disparar seguimiento.
     *
     * @param int $admin_id
     *
     * @return void
     */
    protected function seed_lead_too_soon(int $admin_id): void
    {
        $lead = Lead::updateOrCreate(
            ['email' => self::EMAIL_TOO_SOON],
            [
                'contact_name'               => 'Lead test too soon',
                'company_name'               => 'Empresa Test Too Soon',
                'phone'                      => '+5411000000003',
                'status'                     => 'calificado',
                'created_by_admin_id'        => $admin_id,
                'notes'                      => '[LeadFollowupTestSeeder] Control negativo: mensaje hace 5h (<24h).',
                'tiene_sugerencia_pendiente' => false,
                'requiere_seguimiento'       => false,
                'tiene_seguimiento_sin_ver'  => false,
            ]
        );

        LeadMessage::query()->where('lead_id', $lead->id)->delete();
        $this->backdate_lead_row($lead->id, Carbon::now()->subDay());

        $msg = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'lead',
            'content'               => 'Mensaje reciente, no debe cumplir horas_espera.',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ]);
        $this->backdate_message_row($msg->id, Carbon::now()->subHours(5));
    }

    /**
     * Lead con sugerencia pendiente: el servicio lo omite.
     *
     * @param int $admin_id
     *
     * @return void
     */
    protected function seed_lead_skip_pending_suggestion(int $admin_id): void
    {
        $lead = Lead::updateOrCreate(
            ['email' => self::EMAIL_SKIP_PENDING],
            [
                'contact_name'               => 'Lead test skip pending',
                'company_name'               => 'Empresa Test Skip',
                'phone'                      => '+5411000000004',
                'status'                     => 'demo_realizada',
                'created_by_admin_id'        => $admin_id,
                'notes'                      => '[LeadFollowupTestSeeder] tiene_sugerencia_pendiente=true → no procesar.',
                'tiene_sugerencia_pendiente' => true,
                'requiere_seguimiento'       => false,
                'tiene_seguimiento_sin_ver'  => false,
            ]
        );

        LeadMessage::query()->where('lead_id', $lead->id)->delete();
        $this->backdate_lead_row($lead->id, Carbon::now()->subDays(3));

        $msg = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'lead',
            'content'               => 'Contexto viejo pero el lead tiene sugerencia pendiente.',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ]);
        $this->backdate_message_row($msg->id, Carbon::now()->subHours(48));
    }

    /**
     * Ajusta `created_at` / `updated_at` del lead (último recurso si no hay mensajes).
     *
     * @param int         $lead_id
     * @param Carbon $moment
     *
     * @return void
     */
    protected function backdate_lead_row(int $lead_id, Carbon $moment): void
    {
        DB::table('leads')->where('id', $lead_id)->update([
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);
    }

    /**
     * Ajusta timestamps de un mensaje para simular inactividad.
     *
     * @param int         $message_id
     * @param Carbon $moment
     *
     * @return void
     */
    protected function backdate_message_row(int $message_id, Carbon $moment): void
    {
        DB::table('lead_messages')->where('id', $message_id)->update([
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);
    }
}
