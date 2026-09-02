<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\AdminPushSubscription;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder de producción para dar de baja a Martín de todos los canales de aviso.
 *
 * Martín dejó de trabajar en ComercioCity (confirmado por Lucas el 2/9/2026). Era quien hacía de
 * setter y de atención al cliente; esas tareas las toma Lucas.
 *
 * Deja a los admins que se van sin las TRES cosas que los hacen recibir algo, y hacen falta las
 * tres —cortar una sola no alcanza, lo único que hace es mudarlos de canal:
 *   1. Los nueve flags de destinatario (el rol de setter, los dos "por defecto" y los seis
 *      `notify_*_whatsapp`).
 *   2. Los devices registrados para Web Push.
 *   3. Las campanitas de leads (`lead_admin_notifications`), que entran al conjunto de
 *      destinatarios por encima del rol.
 *
 * 🔴 NO BORRA EL REGISTRO DE ADMIN, y es deliberado: su id cuelga de
 * support_tickets.assigned_admin_id, de lead_messages.sent_by_admin_id y de la pivot
 * admin_task_assignees. Borrarlo dejaría tickets sin dueño, mensajes de la conversación sin autor
 * y tareas históricas rotas. Sin flags, sin devices y sin campanitas no recibe nada, que es el
 * efecto buscado, y sin tocar el historial.
 *
 * 🔴 NO TOCA AL CLOSER. Sigue trabajando y sus avisos no son de Martín. Ver apagar_en_los_que_se_van().
 *
 * ⚠️ NO CONFUNDIR CON EL AGENTE. El bot de WhatsApp que le habla a los leads se sigue llamando
 * "Martín" (decisión de Lucas, misma conversación) y su identidad vive en `agent_identities`, otra
 * tabla. Este seeder toca ÚNICAMENTE `admins`.
 *
 * MÉTODO: apagar en todos los que se van y prender en Lucas, en vez de buscar a Martín por nombre.
 * Es lo que pidió Lucas y además es más robusto: no depende de acertar cómo está escrito el nombre
 * o el email de alguien que ya no está, y deja exactamente el estado final que se quiere. El precio
 * es que si había un tercer admin con alguno de estos flags a propósito, también se lo saca —
 * asumido, salvo el closer, que está exceptuado.
 *
 * 🔴 SI NO ENCUENTRA A LUCAS —O SI ENCUENTRA MÁS DE UNO— NO APAGA NADA. Es la guarda que importa:
 * apagar los flags y después no poder reasignarlos, o reasignarlos a la cuenta equivocada, deja el
 * sistema sin ningún setter real. Un seeder que corre a medias es peor que uno que no corre.
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
     * Columnas que quedan encendidas en el admin de Lucas: TODAS las que se apagan.
     *
     * 🔴 NO ES UN SUBCONJUNTO, Y ESA FUE LA PRIMERA VERSIÓN EQUIVOCADA. Arrancó reponiendo solo el
     * rol de setter y los dos "por defecto", con el argumento de que los `notify_*_whatsapp` son
     * opt-in de canal y los decide Lucas desde la pantalla. El razonamiento es falso para varios de
     * ellos: NO son un canal extra, son el ÚNICO canal de ese aviso, y apagarlos en todos los
     * admins dejaba avisos enteros sin ningún destinatario en todo el sistema.
     *
     *   - `notify_support_escalation_whatsapp` -> los escalados de tickets de soporte no salen ni
     *     por push ni por WhatsApp (EscalationPushNotificationService::notificar_ticket los saca de
     *     este flag). Lucas quedaba de dueño por defecto de tickets de los que no se enteraba.
     *   - `notify_verificacion_whatsapp` -> la verificación por ERROR (fallback de disponibilidad)
     *     no tiene push, solo este WhatsApp.
     *   - `notify_send_errors_whatsapp` -> errores de envío y token de Google Calendar vencido.
     *   - `notify_demo_scheduled_whatsapp` -> el ciclo entero de la demo agendada.
     *
     * Lucas dijo "ahora me voy a ocupar yo de hacer todas esas tareas": si Martín recibía un aviso,
     * lo tiene que recibir Lucas. Que después decida apagar alguno desde la pantalla es asunto suyo,
     * pero el estado en el que lo deja el seeder no puede ser "nadie se entera".
     */
    const COLUMNAS_A_PRENDER_EN_LUCAS = self::COLUMNAS_A_APAGAR;

    /**
     * Deja los flags de destinatario únicamente en el admin de Lucas.
     *
     * @return void
     */
    public function run()
    {
        $candidatos = $this->buscar_a_lucas();

        if ($candidatos->isEmpty()) {
            $this->aviso(
                'warn',
                'BajaDeMartinEnNotificacionesSeeder: no encontré el admin de Lucas en esta base. '
                . 'NO se apagó ningún flag — apagarlos sin poder reasignarlos dejaría el sistema sin ningún setter. '
                . 'Agregá el email o el nombre real a EMAILS_LUCAS / NOMBRES_LUCAS y volvé a correrlo.'
            );

            return;
        }

        /* 🔴 Más de un candidato: tampoco se toca nada. `first()` sin orderBy devuelve lo que
         * MySQL quiera —en la práctica el id más bajo, o sea la cuenta más vieja— y si le
         * aterrizan los flags a una cuenta de prueba llamada "Lucas", el Lucas real queda SIN
         * es_setter: todas las verificaciones y escalados irían a una cuenta fantasma sin devices
         * ni teléfono, y el seeder informaría "todo ok". Prefiero que no corra a que corra mal. */
        if ($candidatos->count() > 1) {
            $detalle = $candidatos->map(function ($admin) {
                return "#{$admin->id} ({$admin->name} / {$admin->email})";
            })->implode(', ');

            $this->aviso(
                'warn',
                'BajaDeMartinEnNotificacionesSeeder: hay más de un admin que matchea a Lucas y no puedo '
                . "elegir sin adivinar: {$detalle}. NO se apagó ningún flag. Dejá una sola fila que "
                . 'matchee EMAILS_LUCAS / NOMBRES_LUCAS y volvé a correrlo.'
            );

            return;
        }

        $lucas    = $candidatos->first();
        $apagados = $this->apagar_en_los_que_se_van($lucas);
        $this->prender_en_lucas($lucas);

        $this->aviso(
            'info',
            "BajaDeMartinEnNotificacionesSeeder: {$apagados} admin(s) quedaron sin flags de destinatario, "
            . 'sin devices de push y sin campanitas de leads. Todos los avisos quedan en el admin '
            . "#{$lucas->id} ({$lucas->name}). El closer no fue tocado y ningún registro de admin fue borrado."
        );
    }

    /**
     * Filas de admin que matchean a Lucas por email o nombre exacto.
     *
     * Devuelve la colección entera y no `first()` a propósito: el llamador necesita distinguir
     * "no está" de "hay varios", y los dos casos frenan el seeder.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function buscar_a_lucas()
    {
        return Admin::where(function ($query) {
            $query->whereIn('email', self::EMAILS_LUCAS)
                  ->orWhereIn('name', self::NOMBRES_LUCAS);
        })->get();
    }

    /**
     * Apaga los flags y corta los canales de los admins que dejan de recibir avisos.
     *
     * 🔴 NO TOCA AL CLOSER, y la primera versión de este seeder sí lo hacía. Apagarle
     * `notify_lead_escalation_whatsapp` al closer mataba los tres avisos que hoy le llegan y que no
     * son escalados —"llamada agendada", "seguimiento post-demo" y "demo realizada"—, porque los
     * tres salen por LeadEscalationWhatsappService sin lista explícita, o sea filtrando por ese
     * flag. El closer sigue trabajando: no hay ningún motivo para apagarle nada.
     *
     * @param Admin $lucas Admin que se queda con todo; tampoco se toca.
     *
     * @return int Cantidad de admins dados de baja.
     */
    private function apagar_en_los_que_se_van(Admin $lucas): int
    {
        /* Los que sí se dan de baja: todo el que no sea Lucas ni el closer. */
        $ids_que_se_van = Admin::where('id', '!=', $lucas->id)
            ->where(function ($query) {
                $query->where('is_closer', false)
                      ->orWhereNull('is_closer');
            })
            ->pluck('id')
            ->all();

        if (empty($ids_que_se_van)) {
            return 0;
        }

        /* Valores fijos, no toggles: por eso correrlo de nuevo no cambia nada. */
        Admin::whereIn('id', $ids_que_se_van)->update(array_fill_keys(self::COLUMNAS_A_APAGAR, false));

        /* Los devices de push: si no, le sigue sonando el teléfono a alguien que se fue. */
        AdminPushSubscription::whereIn('admin_id', $ids_que_se_van)->delete();

        /* 🔴 Y LAS CAMPANITAS, que es lo que la primera versión se olvidaba y volvía inútil el
         * borrado de devices. La campanita entra al conjunto de destinatarios SIEMPRE, por encima
         * del rol. Un admin dado de baja con campanitas viejas seguía siendo destinatario; sin
         * device caía en la rama de respaldo, y esa rama —desde el ruteo por rol— ya no filtra por
         * ningún flag. Resultado: borrarle los devices no lo callaba, lo MUDABA a WhatsApp, a su
         * teléfono personal. Sacarlo de la pivot lo saca del conjunto de verdad. */
        DB::table('lead_admin_notifications')->whereIn('admin_id', $ids_que_se_van)->delete();

        return count($ids_que_se_van);
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
