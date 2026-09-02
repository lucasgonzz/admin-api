<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\AdminPushSubscription;
use Illuminate\Database\Seeder;

/**
 * Seeder de producción para dar de baja a Martín de todos los canales de aviso.
 *
 * Martín dejó de trabajar en ComercioCity (confirmado por Lucas el 2/9/2026). Era quien hacía de
 * setter y de atención al cliente; esas tareas las toma Lucas. Este seeder apaga los flags que lo
 * hacen destinatario de algo —el rol de setter, los dos "por defecto" y los seis avisos por
 * WhatsApp— y le borra los devices registrados para Web Push, que es el único canal que le sigue
 * llegando al teléfono aunque no entre nunca más al panel.
 *
 * 🔴 NO BORRA EL REGISTRO DE ADMIN, y es deliberado: su id cuelga de
 * support_tickets.assigned_admin_id, de lead_messages.sent_by_admin_id y de la pivot
 * admin_task_assignees. Borrarlo dejaría tickets sin dueño, mensajes de la conversación sin autor
 * y tareas históricas rotas. Un admin sin flags y sin devices no recibe nada, que es exactamente
 * el efecto buscado, y sin tocar el historial.
 *
 * ⚠️ NO CONFUNDIR CON EL AGENTE. El bot de WhatsApp que le habla a los leads se sigue llamando
 * "Martín" (decisión de Lucas, misma conversación) y su identidad vive en `agent_identities`, otra
 * tabla. Este seeder toca ÚNICAMENTE `admins`.
 *
 * MÉTODO: apagar en todos y prender en Lucas, en vez de buscar a Martín por nombre. Es lo que pidió
 * Lucas y además es más robusto: no depende de acertar cómo está escrito el nombre o el email de
 * alguien que ya no está, y deja exactamente el estado final que se quiere. El precio es que si
 * había un tercer admin con alguno de estos flags a propósito, también se lo saca — asumido.
 *
 * 🔴 SI NO ENCUENTRA A LUCAS, NO APAGA NADA. Es la guarda que importa: apagar los flags de todos y
 * después no poder prendérselos a nadie dejaría el sistema sin ningún setter, y con eso todos los
 * avisos de verificación y de escalado caerían en el fallback de campanita
 * ({@see \App\Services\LeadNotificationAudienceResolver}). Un seeder que corre a medias es peor
 * que uno que no corre.
 *
 * Idempotente: escribe valores fijos y borra filas que la segunda vez ya no existen. Correrlo dos
 * veces da el mismo resultado que correrlo una.
 *
 * Se corre a mano y NO está registrado en DatabaseSeeder (familia de los seeders sueltos de
 * producción, molde: AddIsStatusEventToLeadMessagesSeeder):
 *
 *   php artisan db:seed --class=BajaDeMartinEnNotificacionesSeeder --force
 */
class BajaDeMartinEnNotificacionesSeeder extends Seeder
{
    /**
     * Emails con los que puede estar cargado Lucas. Coincidencia EXACTA, nunca LIKE: un
     * `LIKE 'lucas%'` engancharía cualquier cuenta que empiece igual.
     */
    const EMAILS_LUCAS = ['lucas', 'lucas@comerciocity.com', 'lucasgonzalez5500@gmail.com'];

    /** Nombres con los que puede estar cargado Lucas. Coincidencia exacta. */
    const NOMBRES_LUCAS = ['Lucas', 'Lucas González', 'Lucas Gonzalez'];

    /**
     * Columnas de `admins` que se apagan en todos los admins antes de prendérselas a Lucas.
     *
     * Son las que convierten a un admin en destinatario de algo: el rol de setter, los dos
     * "por defecto" y los seis canales de WhatsApp. Verificadas contra el esquema el 2/9/2026.
     *
     * `is_closer` NO está en la lista a propósito: el closer es otra persona y sigue trabajando.
     */
    const COLUMNAS_A_APAGAR = [
        'es_setter',
        'is_default_support_owner',
        'is_default_task_assignee',
        'notify_lead_escalation_whatsapp',
        'notify_support_escalation_whatsapp',
        'notify_demo_scheduled_whatsapp',
        'notify_send_errors_whatsapp',
        'notify_verificacion_whatsapp',
        'notify_verificacion_agendamiento_whatsapp',
    ];

    /**
     * Columnas que quedan encendidas en el admin de Lucas.
     *
     * Es el subconjunto de arriba que corresponde a las tareas que absorbe: el rol de setter (que
     * gobierna el ruteo de avisos y la asignación de tareas de leads) y los dos "por defecto" de
     * soporte y tareas. Los `notify_*_whatsapp` NO se prenden solos: son opt-in de canal y los
     * decide Lucas desde la pantalla de Usuarios admin, no un seeder.
     */
    const COLUMNAS_A_PRENDER_EN_LUCAS = [
        'es_setter',
        'is_default_support_owner',
        'is_default_task_assignee',
    ];

    /**
     * Deja los flags de destinatario únicamente en el admin de Lucas.
     *
     * @return void
     */
    public function run()
    {
        $lucas = $this->buscar_a_lucas();

        if ($lucas === null) {
            $this->aviso(
                'warn',
                'BajaDeMartinEnNotificacionesSeeder: no encontré el admin de Lucas en esta base. '
                . 'NO se apagó ningún flag — apagarlos sin poder reasignarlos dejaría el sistema sin ningún setter. '
                . 'Agregá el email o el nombre real a EMAILS_LUCAS / NOMBRES_LUCAS y volvé a correrlo.'
            );

            return;
        }

        $apagados = $this->apagar_en_todos();
        $this->prender_en_lucas($lucas);

        $this->aviso(
            'info',
            "BajaDeMartinEnNotificacionesSeeder: {$apagados} admin(s) quedaron sin flags de destinatario; "
            . "los flags de setter y los dos 'por defecto' quedan en el admin #{$lucas->id} ({$lucas->name}). "
            . 'Ningún registro de admin fue borrado.'
        );
    }

    /**
     * Ubica el admin de Lucas por email o nombre exacto.
     *
     * @return Admin|null
     */
    private function buscar_a_lucas(): ?Admin
    {
        return Admin::where(function ($query) {
            $query->whereIn('email', self::EMAILS_LUCAS)
                  ->orWhereIn('name', self::NOMBRES_LUCAS);
        })->first();
    }

    /**
     * Apaga las nueve columnas en todos los admins y les borra los devices de push a los que
     * dejan de ser destinatarios.
     *
     * @return int Cantidad de admins que tenían al menos un flag encendido.
     */
    private function apagar_en_todos(): int
    {
        /* Se cuentan ANTES de apagar: después del update la condición no matchea a nadie y el
         * mensaje final diría siempre 0. */
        $afectados = Admin::where(function ($query) {
            foreach (self::COLUMNAS_A_APAGAR as $columna) {
                $query->orWhere($columna, true);
            }
        })->count();

        /* Valores fijos, no toggles: por eso correrlo de nuevo no cambia nada. */
        Admin::query()->update(array_fill_keys(self::COLUMNAS_A_APAGAR, false));

        /* Los devices de push de los que ya no son destinatarios de nada. Se borran para que a
         * alguien que se fue no le siga sonando el teléfono: el ruteo por rol ya no lo elegiría,
         * pero un device vivo es una puerta abierta que no hace falta dejar. Los del closer y los
         * de Lucas se conservan. */
        $ids_que_conservan_device = Admin::where('is_closer', true)
            ->orWhereIn('email', self::EMAILS_LUCAS)
            ->orWhereIn('name', self::NOMBRES_LUCAS)
            ->pluck('id')
            ->all();

        AdminPushSubscription::whereNotIn('admin_id', $ids_que_conservan_device)->delete();

        return (int) $afectados;
    }

    /**
     * Prende en el admin de Lucas los flags de las tareas que absorbe.
     *
     * @param Admin $lucas
     *
     * @return void
     */
    private function prender_en_lucas(Admin $lucas): void
    {
        Admin::where('id', $lucas->id)->update(array_fill_keys(self::COLUMNAS_A_PRENDER_EN_LUCAS, true));
    }

    /**
     * Escribe en la consola solo si el seeder corre desde artisan.
     *
     * `$this->command` es null cuando alguien instancia el seeder a mano (por ejemplo un test que
     * hace `new BajaDeMartinEnNotificacionesSeeder()` en vez de `$this->seed(...)`), y ahí un
     * `$this->command->info()` tira "Call to a member function info() on null".
     *
     * @param string $nivel  'info' o 'warn'.
     * @param string $texto
     *
     * @return void
     */
    private function aviso(string $nivel, string $texto): void
    {
        if ($this->command === null) {
            return;
        }

        if ($nivel === 'warn') {
            $this->command->warn($texto);

            return;
        }

        $this->command->info($texto);
    }
}
