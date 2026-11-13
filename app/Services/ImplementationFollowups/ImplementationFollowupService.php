<?php

namespace App\Services\ImplementationFollowups;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationFollowup;
use App\Models\ImplementationFollowupEvent;
use App\Models\ImplementationStage;
use App\Models\SupportCodexCase;
use App\Models\SupportCodexOperation;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\AdminPushNotificationService;
use App\Services\ImplementationSupportWhatsappService;
use App\Services\PruebaDelClienteService;
use App\Services\SupportCodexService;
use App\Services\SupportCodexSettings;
use App\Services\SupportImplementationContextService;
use App\Services\SupportWhatsappOpenerService;
use App\Services\WhatsappSessionWindowService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * El seguimiento proactivo de las implementaciones de sistema (misión cruzada `seguimiento-de-implementaciones`,
 * 11/11/2026): Martín le escribe al cliente cada 2 días hábiles para avanzar al próximo paso.
 *
 * 🔴 EL ADMIN DECIDE CUÁNDO; EL MONITOR, QUÉ. Este servicio lleva el reloj (alta, contacto del cliente, pausas,
 * frenado, terminado), ofrece los vencidos al monitor (`pendientes`), los reserva (`reservar`) con el contexto de
 * todo lo hablado, guarda los hitos de uso que mide el monitor y MANDA el mensaje que redacta Martín. El envío no se
 * reescribe: es `ImplementationSupportWhatsappService` (resolver_contacto → plan → reservar → enviar → registrar), el
 * mismo de los WhatsApp de hitos de la implementación, con la acción `seguimiento`. La respuesta del cliente entra
 * como un inbound normal del ticket y la atiende Martín por el circuito de siempre.
 *
 * Reglas (las del contrato admin ↔ monitor del plan de la misión):
 *  1. Alta: implementación de sistema `in_progress`, cliente activo, no en prueba gratuita, sin fila → `activo`.
 *  2. Contacto del cliente: un entrante más nuevo que el último visto reinicia el reloj y el contador (y saca de
 *     `frenado`).
 *  3. Fin de pausa: `pausado` con `paused_until` < hoy → `activo`.
 *  4. Frenado: vencido con `unanswered_count >= max_unanswered` → `frenado` y aviso a Lucas (no se escribe).
 *  5. Terminado: implementación `completed` y todos los hitos obligatorios cumplidos o «no aplica».
 *  6. Días hábiles: lunes a viernes, sin feriados (`DiasHabiles`).
 *
 * Kill switch: `AdminSetting implementation_followups_enabled` (default `'1'`). Con `'0'` el comando no hace nada,
 * `pending` devuelve `enabled: false` y no se reserva ni se manda nada.
 */
class ImplementationFollowupService
{
    /** Clave del kill switch en `admin_settings`. */
    const SETTING_ENABLED = 'implementation_followups_enabled';

    /** Minutos de la reserva del monitor (como la de los tickets). */
    const LEASE_MINUTES = 15;

    /** Cuántos seguimientos ofrece `pending` por vuelta. */
    const TOPE_DE_PENDIENTES = 10;

    /** Cuántos eventos viajan en el contexto. */
    const EVENTOS_EN_EL_CONTEXTO = 20;

    /** Largo máximo del mensaje de un seguimiento (el contrato: 1..550). */
    const LARGO_MAXIMO = 550;

    /** La acción con la que el envío queda registrado en la etapa (`ClaudeImplementationOpsController::ACCIONES`). */
    const ACCION = 'seguimiento';

    /** El operador que figura en la plantilla `cc_soporte_apertura` («Hola X, soy <operador>…»). */
    const OPERADOR = 'Martín';

    /** Minutos que se corre un seguimiento cuyo envío falló (Kapso, Meta), para que no se reofrezca en la misma vuelta. */
    const MINUTOS_TRAS_UN_FALLO = 60;

    /** Fallas seguidas (envío `failed` o `release` con error) a partir de las cuales el seguimiento se corre +2 hábiles y se avisa. */
    const TOPE_DE_FALLAS = 2;

    /** Omisiones seguidas (sin enviado ni contacto del cliente en el medio) a partir de las cuales, al vencer, se frena. */
    const TOPE_DE_OMISIONES = 2;

    /** `pause_reason` de un seguimiento pausado porque la implementación está pausada en el admin. */
    const MOTIVO_IMPLEMENTACION_PAUSADA = 'implementación pausada en el admin';

    /**
     * Lo que se le dice a Lucas por cada causa estructural que frena el seguimiento.
     *
     * @var array<string, string>
     */
    const TEXTOS_ESTRUCTURALES = [
        'cliente_inactivo'   => 'el cliente está dado de baja.',
        'sin_implementacion' => 'no tiene una implementación de sistema donde registrar el envío.',
        'sin_etapa'          => 'la implementación no tiene cargada la etapa actual.',
        'sin_contacto'       => 'el dueño no tiene un teléfono válido en la ficha.',
        'respuesta_al_hilo'  => 'la respuesta del cliente no caería en el ticket (implementación en modo automático).',
    ];

    /**
     * Los hitos de uso OBLIGATORIOS para dar por terminada la puesta en marcha (las claves que mide el monitor con
     * `hitos_uso.py`). Martín o Lucas pueden marcar alguno «no aplica» (un negocio que no fía no tiene cuenta
     * corriente; uno sin ARCA no factura).
     *
     * @var array<int, string>
     */
    const HITOS_OBLIGATORIOS = ['articulo_creado', 'articulos_actualizados', 'venta', 'venta_cc', 'pago_cc', 'compra', 'factura_arca'];

    /** Patrón de una clave de hito (las desconocidas se guardan igual, pero con esta forma). */
    const PATRON_DE_CLAVE = '/^[a-z0-9_]{1,60}$/';

    /* ==============================================================================================
     | Interruptores
     |============================================================================================= */

    /**
     * El kill switch del seguimiento.
     *
     * @return bool
     */
    public static function enabled()
    {
        return ! in_array((string) AdminSetting::get(self::SETTING_ENABLED, '1'), ['0', 'false'], true);
    }

    /**
     * Ahora, en hora de Argentina.
     *
     * @return Carbon
     */
    protected function ahora()
    {
        return Carbon::now(DiasHabiles::ZONA_HORARIA);
    }

    /* ==============================================================================================
     | El scheduler (`implementaciones:seguimientos`)
     |============================================================================================= */

    /**
     * Una corrida del scheduler: altas y las reglas 2 a 5 sobre cada seguimiento no terminado y sin reserva vigente
     * (uno reservado lo está atendiendo el monitor; se mira en la próxima corrida). Idempotente: dos corridas
     * seguidas no cambian nada la segunda.
     *
     * @return array<string, int> Contadores de lo que hizo.
     */
    public function procesar()
    {
        $cuenta = ['habilitado' => 1, 'creados' => 0, 'contactos' => 0, 'reanudados' => 0, 'frenados' => 0, 'terminados' => 0];

        if (! self::enabled()) {
            $cuenta['habilitado'] = 0;

            return $cuenta;
        }

        $cuenta['creados'] = $this->dar_de_alta_las_nuevas();

        $ids = ImplementationFollowup::where('status', '!=', ImplementationFollowup::STATUS_TERMINADO)->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            try {
                $hecho = $this->procesar_uno((int) $id);
            } catch (\Throwable $e) {
                Log::channel('daily')->error('Seguimiento de implementación: falló la corrida de uno.', [
                    'implementation_followup_id' => (int) $id,
                    'error'                      => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($hecho as $clave) {
                $cuenta[$clave]++;
            }
        }

        return $cuenta;
    }

    /**
     * Regla 1: una fila `activo` por cada implementación de sistema `in_progress` de un cliente activo que no esté
     * en prueba gratuita y no tenga seguimiento.
     *
     * @return int Cuántas creó.
     */
    public function dar_de_alta_las_nuevas()
    {
        $creados = 0;

        $implementaciones = Implementation::where('status', 'in_progress')
            ->whereNotIn('client_id', ImplementationFollowup::select('client_id'))
            ->orderBy('id')
            ->get();

        foreach ($implementaciones as $implementation) {
            $client = Client::find((int) $implementation->client_id);

            if ($client === null || ! $client->is_active || app(PruebaDelClienteService::class)->es_cliente_en_prueba($client)) {
                continue;
            }

            $avisos = [];
            $creado = DB::transaction(function () use ($client, $implementation, &$avisos) {
                if (ImplementationFollowup::where('client_id', $client->id)->lockForUpdate()->exists()) {
                    return null;
                }

                $followup = $this->crear($client, (int) $implementation->id, ImplementationFollowupEvent::SOURCE_SISTEMA, 'Alta automática: implementación del sistema en curso.');

                /* Si nace con una causa que el texto no arregla (sin teléfono, implementación automática...), nace
                   frenado y Lucas se entera ya, no a los dos días. */
                $motivo = $this->motivo_estructural($followup);
                if ($motivo !== null) {
                    $this->frenar($followup, $motivo,
                        'Frenado al nacer: el seguimiento no se puede mandar (' . $motivo . ').',
                        'El seguimiento no se puede mandar: ' . self::TEXTOS_ESTRUCTURALES[$motivo],
                        $avisos, true);
                }

                return $followup;
            });

            if ($creado !== null) {
                $creados++;
                $this->mandar_avisos((int) $creado->id, $avisos);
            }
        }

        return $creados;
    }

    /**
     * Crea la fila `activo` y su evento `creado`. `next_due_at` = 2 días hábiles después de
     * `max(último entrante del cliente, ahora)` a las 10:00.
     *
     * @param Client   $client            El cliente.
     * @param int|null $implementation_id La implementación de sistema.
     * @param string   $source            sistema | lucas.
     * @param string   $detalle           El texto del evento.
     *
     * @return ImplementationFollowup
     */
    protected function crear(Client $client, $implementation_id, $source, $detalle)
    {
        $ultimo = $this->ultimo_contacto_del_cliente((int) $client->id);
        $ahora  = $this->ahora();
        $desde  = $ultimo !== null && $ultimo->greaterThan($ahora) ? $ultimo : $ahora;

        $followup                         = new ImplementationFollowup();
        $followup->client_id              = (int) $client->id;
        $followup->implementation_id      = $implementation_id;
        $followup->status                 = ImplementationFollowup::STATUS_ACTIVO;
        $followup->interval_business_days = 2;
        $followup->max_unanswered         = 2;
        $followup->unanswered_count       = 0;
        $followup->last_client_contact_at = $ultimo;
        $followup->next_due_at            = DiasHabiles::sumar($desde, $followup->interval_business_days);
        $followup->save();

        $this->evento($followup, ImplementationFollowupEvent::KIND_CREADO, $source, $detalle, [
            'implementation_id' => $implementation_id,
            'proximo_at'        => $followup->next_due_at->toIso8601String(),
        ]);

        return $followup;
    }

    /**
     * Las reglas sobre un seguimiento en una corrida del scheduler, con la fila bloqueada. Los avisos a Lucas salen
     * DESPUÉS del commit.
     *
     * @param int $id El seguimiento.
     *
     * @return array<int, string> Las claves de `procesar()` a sumar.
     */
    protected function procesar_uno($id)
    {
        $avisos = [];

        $hecho = DB::transaction(function () use ($id, &$avisos) {
            $followup = ImplementationFollowup::where('id', $id)->lockForUpdate()->first();

            if ($followup === null || $followup->status === ImplementationFollowup::STATUS_TERMINADO || $followup->reserva_vigente()) {
                return [];
            }

            return $this->ordenar($followup, $avisos);
        });

        $this->mandar_avisos($id, $avisos);

        return $hecho;
    }

    /**
     * Aplica, en orden, todas las reglas que no dependen del monitor sobre una fila bloqueada y sin reserva vigente.
     * La usan el scheduler, `pending` (antes de ofrecer) y el claim nuevo: los tres deciden con la misma cuenta.
     *
     *  1. Regla 2: el cliente escribió (contador en 0, sale de `frenado`, reloj desde el contacto).
     *  2. La implementación pausada en el admin pausa el seguimiento, y al volver a `in_progress` lo reanuda.
     *  3. Regla 3: se cumplió la pausa.
     *  4. El reloj ve al equipo: si alguien del equipo (Martín incluido) le escribió al cliente hace menos de
     *     `interval_business_days` días hábiles, el vencimiento se corre.
     *  5. Regla 5: terminado.
     *  6. Vencido y activo: regla 4 (tope sin respuesta), tope de omisiones y las causas estructurales que el
     *     texto no arregla (sin contacto, respuesta a otro hilo, cliente inactivo, sin etapa, sin implementación)
     *     → `frenado` con `data.motivo` y aviso a Lucas.
     *
     * @param ImplementationFollowup                           $followup La fila (bloqueada por el llamador).
     * @param array<int, array{0: string, 1: string, 2: mixed}> $avisos   Los avisos a mandar después del commit.
     *
     * @return array<int, string> Las claves de `procesar()` a sumar.
     */
    protected function ordenar(ImplementationFollowup $followup, array &$avisos)
    {
        $hecho = [];
        $ahora = $this->ahora();

        if ($this->aplicar_contacto($followup)) {
            $hecho[] = 'contactos';
        }

        $implementation = $this->implementacion_de($followup);

        /* La implementación pausada en el admin. */
        if ($implementation !== null && $implementation->status === 'paused'
            && in_array($followup->status, [ImplementationFollowup::STATUS_ACTIVO, ImplementationFollowup::STATUS_FRENADO], true)) {
            $followup->status       = ImplementationFollowup::STATUS_PAUSADO;
            $followup->paused_until = null;
            $followup->pause_reason = self::MOTIVO_IMPLEMENTACION_PAUSADA;
            $followup->save();
            $this->evento($followup, ImplementationFollowupEvent::KIND_PAUSADO, ImplementationFollowupEvent::SOURCE_SISTEMA,
                'La implementación está pausada en el admin: no se le escribe hasta que se retome.', ['motivo' => 'implementacion_pausada']);
        } elseif ($implementation !== null && $implementation->status === 'in_progress'
            && $followup->status === ImplementationFollowup::STATUS_PAUSADO && $followup->paused_until === null
            && $followup->pause_reason === self::MOTIVO_IMPLEMENTACION_PAUSADA) {
            $followup->status       = ImplementationFollowup::STATUS_ACTIVO;
            $followup->pause_reason = null;
            $followup->next_due_at  = DiasHabiles::sumar($ahora, (int) $followup->interval_business_days);
            $followup->save();
            $this->evento($followup, ImplementationFollowupEvent::KIND_REANUDADO, ImplementationFollowupEvent::SOURCE_SISTEMA,
                'La implementación se retomó en el admin: el seguimiento sigue.', ['proximo_at' => $followup->next_due_at->toIso8601String()]);
            $hecho[] = 'reanudados';
        }

        /* Regla 3: se cumplió la pausa. */
        if ($followup->status === ImplementationFollowup::STATUS_PAUSADO && $followup->paused_until !== null
            && $followup->paused_until->copy()->startOfDay()->lt($ahora->copy()->startOfDay())) {
            $followup->status = ImplementationFollowup::STATUS_ACTIVO;
            $followup->save();
            $this->evento($followup, ImplementationFollowupEvent::KIND_REANUDADO, ImplementationFollowupEvent::SOURCE_SISTEMA,
                'Se cumplió la pausa (hasta el ' . $followup->paused_until->format('d/m/Y') . ').');
            $hecho[] = 'reanudados';
        }

        $this->aplicar_equipo($followup);

        /* Regla 5: la implementación cerró y el negocio opera. Antes que el frenado: un seguimiento terminado no se frena. */
        if ($implementation !== null && $implementation->status === 'completed'
            && count($this->obligatorios_pendientes($followup)) === 0) {
            $followup->status = ImplementationFollowup::STATUS_TERMINADO;
            $followup->save();
            $evento = $this->evento($followup, ImplementationFollowupEvent::KIND_TERMINADO, ImplementationFollowupEvent::SOURCE_SISTEMA,
                'Implementación completada y todos los hitos obligatorios cumplidos o marcados «no aplica».');
            $avisos[] = ['terminado', 'La puesta en marcha terminó: implementación completada y hitos obligatorios cumplidos.', $evento];
            $hecho[] = 'terminados';

            return $hecho;
        }

        if ($followup->status !== ImplementationFollowup::STATUS_ACTIVO || $followup->next_due_at === null || $followup->next_due_at->greaterThan($ahora)) {
            return $hecho;
        }

        /* Regla 4: vencido y ya sin respuesta las veces del tope. */
        if ((int) $followup->unanswered_count >= (int) $followup->max_unanswered) {
            $this->frenar($followup, 'sin_respuesta',
                'Frenado: ' . (int) $followup->unanswered_count . ' seguimientos sin respuesta. No se le escribe más hasta que conteste o Lucas lo reactive.',
                (int) $followup->unanswered_count . ' seguimientos sin respuesta: Martín frenó y no le escribe más hasta que conteste.',
                $avisos, false);
            $hecho[] = 'frenados';

            return $hecho;
        }

        /* Tope de omisiones: dos seguidas sin enviado ni contacto del cliente en el medio. */
        $omisiones = $this->omisiones_seguidas($followup);
        if (count($omisiones) >= self::TOPE_DE_OMISIONES) {
            $this->frenar($followup, 'omisiones',
                'Frenado: ' . count($omisiones) . ' seguimientos seguidos omitidos.',
                count($omisiones) . ' seguimientos seguidos omitidos por Martín (lo que sigue es del equipo). Motivos: ' . implode(' | ', $omisiones),
                $avisos, false);
            $hecho[] = 'frenados';

            return $hecho;
        }

        /* Causas estructurales: el envío fallaría por algo que el texto no arregla. */
        $motivo = $this->motivo_estructural($followup);
        if ($motivo !== null) {
            $this->frenar($followup, $motivo,
                'Frenado: el seguimiento no se puede mandar (' . $motivo . ').',
                'El seguimiento no se puede mandar: ' . self::TEXTOS_ESTRUCTURALES[$motivo],
                $avisos, true);
            $hecho[] = 'frenados';
        }

        return $hecho;
    }

    /**
     * Frena un seguimiento (fila bloqueada): `frenado`, evento `frenado` con `data.motivo` y UN aviso a Lucas. Con
     * `$sin_repetir`, si el último frenado ya fue por el mismo motivo no se vuelve a avisar (un seguimiento que el
     * cliente reactiva al escribir y vuelve a caer por la misma causa estructural).
     *
     * @param ImplementationFollowup                           $followup   La fila.
     * @param string                                           $motivo     El código.
     * @param string                                           $detalle    El texto del evento.
     * @param string                                           $aviso      El cuerpo del push.
     * @param array<int, array{0: string, 1: string, 2: mixed}> $avisos     Los avisos a mandar después del commit.
     * @param bool                                             $sin_repetir true = no repetir el aviso por el mismo motivo.
     *
     * @return void
     */
    protected function frenar(ImplementationFollowup $followup, $motivo, $detalle, $aviso, array &$avisos, $sin_repetir)
    {
        $previo = ImplementationFollowupEvent::where('implementation_followup_id', $followup->id)
            ->where('kind', ImplementationFollowupEvent::KIND_FRENADO)->orderByDesc('id')->first();
        $repetido = $sin_repetir && $previo !== null && is_array($previo->data)
            && isset($previo->data['motivo']) && $previo->data['motivo'] === $motivo;

        $followup->status = ImplementationFollowup::STATUS_FRENADO;
        $followup->save();
        $evento = $this->evento($followup, ImplementationFollowupEvent::KIND_FRENADO, ImplementationFollowupEvent::SOURCE_SISTEMA, $detalle, [
            'motivo' => (string) $motivo,
        ]);

        if (! $repetido) {
            $avisos[] = ['frenado', $aviso, $evento];
        }
    }

    /**
     * La causa por la que el envío de este seguimiento fallaría sin importar el texto, o null.
     *
     * `respuesta_al_hilo` (la respuesta del cliente no caería en el ticket: implementación en modo automático) solo
     * se mira con el soporte de Codex prendido: apagado, no caería en el ticket ninguna, y eso no es del seguimiento.
     *
     * @param ImplementationFollowup $followup El seguimiento.
     *
     * @return string|null `cliente_inactivo`, `sin_implementacion`, `sin_etapa`, `sin_contacto` o `respuesta_al_hilo`.
     */
    public function motivo_estructural(ImplementationFollowup $followup)
    {
        $client = Client::find((int) $followup->client_id);
        if ($client === null || ! $client->is_active) {
            return 'cliente_inactivo';
        }

        $implementation = $this->implementacion_de($followup);
        if ($implementation === null) {
            return 'sin_implementacion';
        }

        if ($this->etapa_del_envio($implementation) === null) {
            return 'sin_etapa';
        }

        $whatsapp = app(ImplementationSupportWhatsappService::class);
        if ($whatsapp->resolver_contacto($client, null)['contacto'] === null) {
            return 'sin_contacto';
        }

        if (SupportCodexSettings::enabled() && $whatsapp->adonde_iria_la_respuesta($client, null) !== 'ticket') {
            return 'respuesta_al_hilo';
        }

        return null;
    }

    /**
     * El reloj ve al equipo (fila bloqueada): si alguien del equipo le escribió al cliente (un mensaje `admin` real y
     * no rechazado, en cualquier ticket suyo, Martín incluido), el seguimiento no vence antes de
     * `interval_business_days` días hábiles desde ese mensaje. Solo corre el vencimiento hacia adelante: el envío del
     * propio seguimiento ya deja +2, y acá da lo mismo. No toca `unanswered_count`.
     *
     * @param ImplementationFollowup $followup La fila.
     *
     * @return bool true = corrió el vencimiento.
     */
    protected function aplicar_equipo(ImplementationFollowup $followup)
    {
        if (! in_array($followup->status, [ImplementationFollowup::STATUS_ACTIVO, ImplementationFollowup::STATUS_PAUSADO], true)) {
            return false;
        }

        $ultimo = $this->ultimo_mensaje_del_equipo((int) $followup->client_id);
        if ($ultimo === null) {
            return false;
        }

        $minimo = DiasHabiles::sumar($ultimo, (int) $followup->interval_business_days);
        if ($followup->next_due_at !== null && ! $minimo->greaterThan($followup->next_due_at)) {
            return false;
        }

        $followup->next_due_at = $minimo;
        $followup->save();

        return true;
    }

    /**
     * El último mensaje real del equipo (`sender_type = admin`, sin borradores de la IA ni los que no le llegaron) en
     * cualquier ticket del cliente.
     *
     * @param int $client_id El cliente.
     *
     * @return Carbon|null
     */
    public function ultimo_mensaje_del_equipo($client_id)
    {
        $consulta = SupportMessage::query()
            ->join('support_tickets', 'support_tickets.id', '=', 'support_messages.support_ticket_id')
            ->where('support_tickets.client_id', (int) $client_id)
            ->where('support_messages.sender_type', 'admin')
            ->where(function ($entregado) {
                $entregado->whereNull('support_messages.remote_delivery_status')
                    ->orWhere('support_messages.remote_delivery_status', '!=', 'not_received');
            });
        SupportMessage::apply_mensaje_real_conditions($consulta, 'support_messages');

        $valor = $consulta->max('support_messages.created_at');

        return $valor === null ? null : Carbon::parse($valor, config('app.timezone'))->setTimezone(DiasHabiles::ZONA_HORARIA);
    }

    /**
     * Las omisiones seguidas más recientes (sus motivos): eventos `omitido` sin un `enviado`, un `frenado` o un
     * `reanudado` en el medio, y posteriores al último contacto del cliente. Los demás eventos no cortan.
     *
     * @param ImplementationFollowup $followup El seguimiento.
     *
     * @return array<int, string>
     */
    protected function omisiones_seguidas(ImplementationFollowup $followup)
    {
        $motivos = [];

        foreach ($this->eventos_desde_el_contacto($followup) as $evento) {
            if ($evento->kind === ImplementationFollowupEvent::KIND_OMITIDO) {
                $motivos[] = (string) $evento->detail;
            } elseif (in_array($evento->kind, [ImplementationFollowupEvent::KIND_ENVIADO, ImplementationFollowupEvent::KIND_FRENADO, ImplementationFollowupEvent::KIND_REANUDADO], true)) {
                break;
            }
        }

        return $motivos;
    }

    /**
     * Cuántas fallas seguidas tiene el seguimiento: eventos `fallido` (un envío `failed` o un `release` con error)
     * sin un `enviado`, `pospuesto`, `pausado`, `omitido`, `reanudado` o `frenado` en el medio, y posteriores al último
     * contacto del cliente. `hitos`, `editado` y `creado` no cortan.
     *
     * @param ImplementationFollowup $followup El seguimiento.
     *
     * @return int
     */
    protected function fallas_seguidas(ImplementationFollowup $followup)
    {
        $fallas  = 0;
        $cortan  = [
            ImplementationFollowupEvent::KIND_ENVIADO, ImplementationFollowupEvent::KIND_POSPUESTO, ImplementationFollowupEvent::KIND_PAUSADO,
            ImplementationFollowupEvent::KIND_OMITIDO, ImplementationFollowupEvent::KIND_REANUDADO, ImplementationFollowupEvent::KIND_FRENADO,
        ];

        foreach ($this->eventos_desde_el_contacto($followup) as $evento) {
            if ($evento->kind === ImplementationFollowupEvent::KIND_FALLIDO) {
                $fallas++;
            } elseif (in_array($evento->kind, $cortan, true)) {
                break;
            }
        }

        return $fallas;
    }

    /**
     * Los eventos del seguimiento posteriores al último contacto del cliente, el más nuevo primero (tope 50).
     *
     * @param ImplementationFollowup $followup El seguimiento.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function eventos_desde_el_contacto(ImplementationFollowup $followup)
    {
        $consulta = ImplementationFollowupEvent::where('implementation_followup_id', $followup->id)->orderByDesc('id')->limit(50);

        if ($followup->last_client_contact_at !== null) {
            $consulta->where('created_at', '>', $followup->last_client_contact_at);
        }

        return $consulta->get();
    }

    /**
     * Manda los avisos acumulados después del commit, cada uno anotado en su evento.
     *
     * @param int                                              $id     El seguimiento.
     * @param array<int, array{0: string, 1: string, 2: mixed}> $avisos Los avisos.
     *
     * @return void
     */
    protected function mandar_avisos($id, array $avisos)
    {
        if (count($avisos) === 0) {
            return;
        }

        $followup = ImplementationFollowup::find($id);
        if ($followup === null) {
            return;
        }

        foreach ($avisos as $aviso) {
            $this->avisar_a_lucas($followup, $aviso[0], $aviso[1], isset($aviso[2]) ? $aviso[2] : null);
        }
    }

    /**
     * Regla 2 sobre una fila ya bloqueada: si el cliente escribió (en cualquier ticket suyo) después del último
     * contacto visto, lo guarda, pone el contador en 0, saca de `frenado` (evento `reanudado`) y corre el
     * vencimiento a 2 días hábiles después de ese contacto (o al primer hábil después de la pausa, lo que sea más
     * tarde). Un seguimiento terminado no se toca.
     *
     * @param ImplementationFollowup $followup La fila (bloqueada por el llamador).
     *
     * @return bool true = el cliente escribió y se aplicó.
     */
    protected function aplicar_contacto(ImplementationFollowup $followup)
    {
        if ($followup->status === ImplementationFollowup::STATUS_TERMINADO) {
            return false;
        }

        $ultimo = $this->ultimo_contacto_del_cliente((int) $followup->client_id);

        if ($ultimo === null || ($followup->last_client_contact_at !== null && $ultimo->lte($followup->last_client_contact_at))) {
            return false;
        }

        $followup->last_client_contact_at = $ultimo;
        $followup->unanswered_count       = 0;

        $proximo = DiasHabiles::sumar($ultimo, (int) $followup->interval_business_days);
        if ($followup->paused_until !== null) {
            $tras_la_pausa = DiasHabiles::primero_despues_de($followup->paused_until);
            if ($tras_la_pausa->greaterThan($proximo)) {
                $proximo = $tras_la_pausa;
            }
        }
        $followup->next_due_at = $proximo;

        $estaba_frenado = $followup->status === ImplementationFollowup::STATUS_FRENADO;
        if ($estaba_frenado) {
            $followup->status = ImplementationFollowup::STATUS_ACTIVO;
        }

        $followup->save();

        if ($estaba_frenado) {
            $this->evento($followup, ImplementationFollowupEvent::KIND_REANUDADO, ImplementationFollowupEvent::SOURCE_SISTEMA,
                'El cliente volvió a escribir: el seguimiento sigue.');
        }

        return true;
    }

    /**
     * El último entrante real del cliente: `max(support_tickets.last_client_message_at)` de todos sus tickets.
     *
     * @param int $client_id El cliente.
     *
     * @return Carbon|null
     */
    public function ultimo_contacto_del_cliente($client_id)
    {
        $valor = SupportTicket::where('client_id', (int) $client_id)->max('last_client_message_at');

        return $valor === null ? null : Carbon::parse($valor, config('app.timezone'))->setTimezone(DiasHabiles::ZONA_HORARIA);
    }

    /* ==============================================================================================
     | 1. pending
     |============================================================================================= */

    /**
     * Los seguimientos que el monitor tiene que atender ahora (tope 10): `activo`, vencidos, debajo del tope sin
     * respuesta, sin reserva vigente, solo en horario (lun–vie 10–18 de Argentina) y con Codex prendido.
     *
     * Antes de decidir aplica a cada candidato las mismas reglas que el scheduler (`ordenar()`): si el cliente o el
     * equipo escribieron desde la última corrida ya no está vencido, y si el envío fallaría por una causa que el
     * texto no arregla (sin contacto, respuesta a otro hilo, cliente inactivo, sin etapa, sin implementación) o por
     * los topes (sin respuesta, omisiones) queda `frenado` con aviso a Lucas y no se ofrece.
     *
     * Excluye además, sin cambiarle el estado (es transitorio), al cliente que esté en prueba y al que tenga un
     * ticket abierto que atiende otro: Martín con la reserva o con algo esperándolo (`inbound`, `follow_up`,
     * `mission_completed`), o Lucas (respuesta automática apagada, caso en `manual_review` u operación sin terminar,
     * lo mismo con lo que se calla Martín).
     *
     * @return array<string, mixed> `{enabled, en_horario, followups, server_time}`.
     */
    public function pendientes()
    {
        $ahora      = $this->ahora();
        $habilitado = self::enabled();
        $en_horario = DiasHabiles::en_horario($ahora);
        $items      = [];

        if ($habilitado && $en_horario && SupportCodexSettings::enabled()) {
            $candidatos = ImplementationFollowup::where('status', ImplementationFollowup::STATUS_ACTIVO)
                ->whereNotNull('next_due_at')
                ->where('next_due_at', '<=', $ahora)
                ->whereColumn('unanswered_count', '<', 'max_unanswered')
                ->where(function ($consulta) use ($ahora) {
                    $consulta->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $ahora);
                })
                ->orderBy('next_due_at')
                ->orderBy('id')
                ->get();

            foreach ($candidatos as $candidato) {
                $avisos   = [];
                $followup = DB::transaction(function () use ($candidato, &$avisos) {
                    $fila = ImplementationFollowup::where('id', $candidato->id)->lockForUpdate()->first();
                    if ($fila !== null && ! $fila->reserva_vigente() && $fila->status !== ImplementationFollowup::STATUS_TERMINADO) {
                        $this->ordenar($fila, $avisos);
                    }

                    return $fila;
                });
                $this->mandar_avisos((int) $candidato->id, $avisos);

                if ($followup === null || ! $this->corresponde_ofrecer($followup, $ahora)) {
                    continue;
                }

                $client = Client::find((int) $followup->client_id);

                $items[] = [
                    'id'                => (int) $followup->id,
                    'client_id'         => (int) $followup->client_id,
                    'client_name'       => $client === null ? 'Cliente' : $client->resolve_display_name(),
                    'implementation_id' => $followup->implementation_id === null ? null : (int) $followup->implementation_id,
                    'next_due_at'       => $followup->next_due_at->toIso8601String(),
                    'reason'            => 'implementation_followup',
                ];

                if (count($items) >= self::TOPE_DE_PENDIENTES) {
                    break;
                }
            }
        }

        return [
            'enabled'     => $habilitado,
            'en_horario'  => $en_horario,
            'followups'   => $items,
            'server_time' => $ahora->toIso8601String(),
        ];
    }

    /**
     * Si un seguimiento (ya con la regla 2 aplicada) se le puede ofrecer al monitor ahora.
     *
     * @param ImplementationFollowup $followup El seguimiento.
     * @param Carbon                 $ahora    Ahora.
     *
     * @return bool
     */
    protected function corresponde_ofrecer(ImplementationFollowup $followup, Carbon $ahora)
    {
        if ($followup->status !== ImplementationFollowup::STATUS_ACTIVO || $followup->reserva_vigente()
            || $followup->next_due_at === null || $followup->next_due_at->greaterThan($ahora)
            || (int) $followup->unanswered_count >= (int) $followup->max_unanswered) {
            return false;
        }

        $client = Client::find((int) $followup->client_id);
        if ($client === null || ! $client->is_active || app(PruebaDelClienteService::class)->es_cliente_en_prueba($client)) {
            return false;
        }

        return $this->quien_atiende_al_cliente((int) $client->id) === null;
    }

    /**
     * Si algún ticket abierto del cliente lo está atendiendo otro, y quién: `martin` (reserva vigente de su caso, o
     * algo esperándolo: `inbound`, `follow_up`, `mission_completed`) o `lucas` (respuesta automática apagada, caso en
     * `manual_review` u operación de Codex sin terminar: lo mismo con lo que se calla Martín, réplica de
     * `SupportCodexService::callado_para_codex()`, que es privado). null = nadie.
     *
     * @param int $client_id El cliente.
     *
     * @return string|null
     */
    protected function quien_atiende_al_cliente($client_id)
    {
        $codex   = app(SupportCodexService::class);
        $tickets = SupportTicket::where('client_id', (int) $client_id)->where('status', 'open')->get();

        foreach ($tickets as $ticket) {
            $caso = SupportCodexCase::where('support_ticket_id', $ticket->id)->first();

            if (! $ticket->claude_auto_reply || ($caso !== null && $caso->state === 'manual_review')
                || SupportCodexOperation::where('support_ticket_id', $ticket->id)
                    ->whereNotIn('status', ['sent', 'reconciled_sent', 'reconciled_not_sent', 'cancelled'])->exists()) {
                return 'lucas';
            }

            if ($caso !== null && $caso->lease_expires_at !== null && $caso->lease_expires_at->isFuture()) {
                return 'martin';
            }

            if ($codex->motivo_pendiente($ticket, $caso) !== null) {
                return 'martin';
            }
        }

        return null;
    }

    /* ==============================================================================================
     | 2. claim
     |============================================================================================= */

    /**
     * Reserva (o renueva) un seguimiento para el monitor y devuelve su contexto.
     *
     * Sin `lease_token`: reserva nueva. 409 `reservado` si otro la tiene vigente; 409 `no_vencido` si ya no
     * corresponde (no está activo, no venció, llegó al tope sin respuesta, el cliente escribió recién —se aplica la
     * regla 2 antes de mirar—, o se está fuera de horario). Con el token vigente: renueva 15 minutos (mismo token,
     * mismo `run_id`). Un token que no es el vigente: 409 `reservado`.
     *
     * Con el token y `sin_contexto: true` (renovación liviana) devuelve solo `{lease_token, lease_expires_at, run_id}`,
     * sin armar el contexto.
     *
     * @param int         $id           El seguimiento.
     * @param string|null $lease_token  El token, para renovar.
     * @param bool        $sin_contexto true = renovación sin contexto (solo con token).
     *
     * @return array<string, mixed> `{lease_token, lease_expires_at, run_id, context}`.
     */
    public function reservar($id, $lease_token, $sin_contexto = false)
    {
        if (! self::enabled()) {
            throw new SeguimientoException(409, 'deshabilitado', 'El seguimiento de implementaciones está apagado (implementation_followups_enabled = 0).');
        }

        $token = trim((string) $lease_token);

        /* Las reglas del scheduler primero, en su propia transacción: si el cliente o el equipo escribieron recién
           el seguimiento ya no está vencido, y si cayó en un tope o una causa estructural queda frenado; eso tiene
           que quedar guardado (y avisado) aunque la reserva se rechace con 409. */
        if ($token === '') {
            $avisos = [];
            DB::transaction(function () use ($id, &$avisos) {
                $fila = $this->buscar_bloqueado($id);
                if (! $fila->reserva_vigente() && $fila->status !== ImplementationFollowup::STATUS_TERMINADO) {
                    $this->ordenar($fila, $avisos);
                }
            });
            $this->mandar_avisos((int) $id, $avisos);
        }

        $reserva = DB::transaction(function () use ($id, $token) {
            $followup = $this->buscar_bloqueado($id);
            $ahora    = $this->ahora();

            if ($token !== '') {
                if (! $this->token_vigente($followup, $token)) {
                    throw new SeguimientoException(409, 'reservado', 'La reserva venció o es de otro proceso.');
                }

                $followup->lease_expires_at = $ahora->copy()->addMinutes(self::LEASE_MINUTES);
                $followup->save();

                return ['followup' => $followup, 'token' => $token];
            }

            if ($followup->reserva_vigente()) {
                throw new SeguimientoException(409, 'reservado', 'Otro proceso tiene reservado este seguimiento.', [
                    'lease_expires_at' => $followup->lease_expires_at->toIso8601String(),
                ]);
            }

            if ($followup->status !== ImplementationFollowup::STATUS_ACTIVO || $followup->next_due_at === null
                || $followup->next_due_at->greaterThan($ahora)
                || (int) $followup->unanswered_count >= (int) $followup->max_unanswered
                || ! DiasHabiles::en_horario($ahora)) {
                throw new SeguimientoException(409, 'no_vencido', 'Este seguimiento ya no corresponde ahora (no está activo, no venció, llegó al tope sin respuesta, el cliente escribió o es fuera de horario).', [
                    'seguimiento' => $this->bloque_del_seguimiento($followup, false),
                ]);
            }

            $quien = $this->quien_atiende_al_cliente((int) $followup->client_id);
            if ($quien !== null) {
                throw new SeguimientoException(409, 'no_vencido', 'Ahora no: ' . ($quien === 'lucas' ? 'Lucas' : 'Martín') . ' está atendiendo un ticket de este cliente.', [
                    'razon'       => $quien === 'lucas' ? 'lucas_atendiendo' : 'martin_atendiendo',
                    'seguimiento' => $this->bloque_del_seguimiento($followup, false),
                ]);
            }

            $token = Str::random(64);
            $followup->lease_token      = hash('sha256', $token);
            $followup->lease_expires_at = $ahora->copy()->addMinutes(self::LEASE_MINUTES);
            $followup->lease_claimed_at = $ahora->copy();
            $followup->run_id           = (string) Str::uuid();
            $followup->save();

            return ['followup' => $followup, 'token' => $token];
        });

        /** @var ImplementationFollowup $followup */
        $followup  = $reserva['followup'];
        $respuesta = [
            'lease_token'      => $reserva['token'],
            'lease_expires_at' => $followup->lease_expires_at->toIso8601String(),
            'run_id'           => (string) $followup->run_id,
        ];

        if ($sin_contexto && $token !== '') {
            return $respuesta;
        }

        $respuesta['context'] = $this->contexto($followup);

        return $respuesta;
    }

    /**
     * El contexto que lee Martín para escribir el seguimiento.
     *
     * @param ImplementationFollowup $followup El seguimiento.
     *
     * @return array<string, mixed>
     */
    public function contexto(ImplementationFollowup $followup)
    {
        $client = Client::find((int) $followup->client_id);

        if ($client === null) {
            return [
                'seguimiento'    => $this->bloque_del_seguimiento($followup, true),
                'client'         => null,
                'implementation' => null,
                'contacto'       => $this->contacto_vacio('sin_cliente'),
                'historial'      => [],
                'martin_ocupado' => false,
                'ficha_cliente'  => null,
                'indice_tickets' => null,
            ];
        }

        $contacto = $this->bloque_del_contacto($client);
        $memoria  = app(\App\Services\ClientMemory\ClientSupportMemoryService::class);

        return [
            'seguimiento'    => $this->bloque_del_seguimiento($followup, true),
            'client'         => [
                'id'           => (int) $client->id,
                'name'         => $client->resolve_display_name(),
                'company_name' => $client->company_name,
                'slug'         => $client->slug,
                'user_id'      => $client->user_id === null ? null : (int) $client->user_id,
                'estado'       => $client->estado === null ? null : (string) $client->estado,
            ],
            'implementation' => $this->bloque_de_la_implementacion($client),
            'contacto'       => $contacto['bloque'],
            'historial'      => $this->historial($client),
            'martin_ocupado' => $contacto['martin_ocupado'],
            /* La ficha del cliente y el índice de TODOS sus tickets (misión cruzada ficha-del-cliente, 11/11/2026): los
               mismos bloques que el contexto de un ticket, armados para el dueño (que ve todos). Nunca lanzan. */
            'ficha_cliente'  => $memoria->bloque_para_el_cliente((int) $client->id),
            'indice_tickets' => $memoria->indice_de_tickets($memoria->ticket_del_dueno($client)),
        ];
    }

    /**
     * El objeto `seguimiento` del contrato (con o sin `eventos`).
     *
     * Suma `obligatorios_pendientes` (no está en la lista del contrato; es aditivo): los hitos obligatorios que
     * todavía no se cumplieron ni se marcaron «no aplica».
     *
     * @param ImplementationFollowup $followup     El seguimiento.
     * @param bool                   $con_eventos  true = con los últimos 20 eventos.
     *
     * @return array<string, mixed>
     */
    public function bloque_del_seguimiento(ImplementationFollowup $followup, $con_eventos)
    {
        $bloque = [
            'id'                         => (int) $followup->id,
            'status'                     => (string) $followup->status,
            'intervalo_dias_habiles'     => (int) $followup->interval_business_days,
            'max_sin_respuesta'          => (int) $followup->max_unanswered,
            'sin_respuesta'              => (int) $followup->unanswered_count,
            'proximo_at'                 => $this->iso($followup->next_due_at),
            'pausado_hasta'              => $followup->paused_until === null ? null : $followup->paused_until->format('Y-m-d'),
            'motivo_pausa'               => $followup->pause_reason,
            'ultimo_envio_at'            => $this->iso($followup->last_sent_at),
            'ultimo_contacto_cliente_at' => $this->iso($followup->last_client_contact_at),
            'hitos'                      => is_array($followup->hitos) ? $followup->hitos : null,
            'hitos_at'                   => $this->iso($followup->hitos_at),
            'hitos_no_aplican'           => is_array($followup->hitos_no_aplican) ? array_values($followup->hitos_no_aplican) : [],
            'obligatorios_pendientes'    => $this->obligatorios_pendientes($followup),
        ];

        if ($con_eventos) {
            $bloque['eventos'] = ImplementationFollowupEvent::where('implementation_followup_id', $followup->id)
                ->orderByDesc('id')
                ->limit(self::EVENTOS_EN_EL_CONTEXTO)
                ->get()
                ->map(function (ImplementationFollowupEvent $evento) {
                    return [
                        'kind'       => (string) $evento->kind,
                        'source'     => (string) $evento->source,
                        'detail'     => $evento->detail,
                        'created_at' => $this->iso($evento->created_at),
                    ];
                })
                ->values()
                ->all();
        }

        return $bloque;
    }

    /**
     * El bloque `implementation` del contexto: el MISMO de `SupportImplementationContextService` que recibe Martín
     * en un ticket, armado por cliente (sin ticket).
     *
     * 🔴 Sin tocar ese servicio (está fuera del alcance mecánico de la misión): se le pasa un ticket EN MEMORIA
     * —nunca se guarda— del dueño por WhatsApp, que es el contacto al que escribe el seguimiento (con un ticket del
     * dueño el servicio arma la conversación enmascarada igual que en un ticket real). `form_link` nunca viaja acá:
     * se pone en null después.
     *
     * @param Client $client El cliente.
     *
     * @return array<string, mixed>|null
     */
    protected function bloque_de_la_implementacion(Client $client)
    {
        $ticket = $this->ticket_en_memoria($client);
        $bloque = app(SupportImplementationContextService::class)->bloque_para_el_ticket($ticket);

        if (is_array($bloque) && isset($bloque['sistema']) && is_array($bloque['sistema'])) {
            $bloque['sistema']['form_link'] = null;
        }

        return $bloque;
    }

    /**
     * Los últimos 5 tickets del cliente (cualquier estado), cada uno con sus últimos 30 mensajes reales: la misma
     * forma que `GET support-codex/tickets/{id}/history`, por el mismo `SupportCodexService::history()`.
     *
     * 🔴 `history()` lista los tickets de id MENOR que el del ticket que recibe; acá se le pasa uno en memoria (del
     * dueño, que ve todos los del cliente) con un id por encima de cualquiera real, así devuelve los 5 más nuevos.
     *
     * @param Client $client El cliente.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function historial(Client $client)
    {
        try {
            $ticket     = $this->ticket_en_memoria($client);
            $ticket->id = PHP_INT_MAX;

            $historia = app(SupportCodexService::class)->history($ticket);

            return isset($historia['tickets']) && is_array($historia['tickets']) ? $historia['tickets'] : [];
        } catch (\Throwable $e) {
            Log::channel('daily')->error('Seguimiento de implementación: no se pudo armar el historial.', [
                'client_id' => (int) $client->id,
                'error'     => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Un ticket de WhatsApp del dueño, EN MEMORIA (nunca se guarda), para reusar los armados por ticket.
     *
     * @param Client $client El cliente.
     *
     * @return SupportTicket
     */
    protected function ticket_en_memoria(Client $client)
    {
        $ticket                     = new SupportTicket();
        $ticket->client_id          = (int) $client->id;
        $ticket->client_employee_id = null;
        $ticket->client_user_id     = 0;
        $ticket->source             = 'whatsapp';
        $ticket->status             = 'open';
        $ticket->setRelation('client', $client);
        $ticket->setRelation('client_employee', null);

        return $ticket;
    }

    /**
     * El contacto al que iría el seguimiento y si Martín está ocupado con su ticket.
     *
     * @param Client $client El cliente.
     *
     * @return array{bloque: array<string, mixed>, martin_ocupado: bool}
     */
    protected function bloque_del_contacto(Client $client)
    {
        $resuelto = app(ImplementationSupportWhatsappService::class)->resolver_contacto($client, null);
        $contacto = $resuelto['contacto'];

        if ($contacto === null) {
            return ['bloque' => $this->contacto_vacio((string) $resuelto['motivo']), 'martin_ocupado' => false];
        }

        $telefono    = (string) $contacto['phone'];
        $empleado_id = isset($contacto['client_employee_id']) ? $contacto['client_employee_id'] : null;
        $ticket      = app(SupportWhatsappOpenerService::class)->find_reusable_ticket($client, $telefono, $empleado_id);
        $ventana     = app(WhatsappSessionWindowService::class)->window_state($telefono);

        $ocupado = false;
        if ($ticket !== null) {
            $caso    = SupportCodexCase::where('support_ticket_id', $ticket->id)->first();
            $ocupado = $caso !== null && $caso->lease_expires_at !== null && $caso->lease_expires_at->isFuture();
        }

        return [
            'bloque' => [
                'nombre'             => (string) $contacto['label'],
                'client_employee_id' => $empleado_id === null ? null : (int) $empleado_id,
                'ticket_destino_id'  => $ticket === null ? null : (int) $ticket->id,
                'ventana'            => [
                    'open'       => (bool) $ventana['open'],
                    'expires_at' => isset($ventana['expires_at']) ? $ventana['expires_at'] : null,
                ],
            ],
            'martin_ocupado' => $ocupado,
        ];
    }

    /**
     * El bloque `contacto` cuando no hay a quién escribirle (con el motivo: `sin_contacto`, `sin_cliente`).
     *
     * @param string $motivo El motivo.
     *
     * @return array<string, mixed>
     */
    protected function contacto_vacio($motivo)
    {
        return [
            'nombre'             => null,
            'client_employee_id' => null,
            'ticket_destino_id'  => null,
            'ventana'            => ['open' => false, 'expires_at' => null],
            'motivo'             => $motivo,
        ];
    }

    /* ==============================================================================================
     | 3. milestones
     |============================================================================================= */

    /**
     * Guarda la medición de los hitos de uso que hizo el monitor. Evento `hitos` solo si cambió algo, con las claves
     * NUEVAS (las que pasaron a tener cantidad > 0) en `data.nuevos`. Las claves desconocidas se guardan igual.
     *
     * @param int                  $id          El seguimiento.
     * @param string               $lease_token La reserva.
     * @param string               $medido_at   ISO.
     * @param array<string, mixed> $hitos       `{clave: {cantidad, primero_at, ultimo_at}}`.
     *
     * @return array<string, mixed> `{guardado, nuevos, obligatorios_pendientes}`.
     */
    public function guardar_hitos($id, $lease_token, $medido_at, array $hitos)
    {
        return DB::transaction(function () use ($id, $lease_token, $medido_at, $hitos) {
            $followup = $this->buscar_bloqueado($id);
            $this->exigir_reserva($followup, $lease_token);

            /* Se fusiona por clave: una medición que no trae una clave (una consulta que falló a medias, un monitor
               más viejo) no borra lo que ya estaba guardado de esa clave. */
            $previo = is_array($followup->hitos) ? $followup->hitos : [];
            $nuevo  = array_merge($previo, $this->normalizar_hitos($hitos));
            ksort($nuevo);

            $nuevos = [];
            foreach ($nuevo as $clave => $hito) {
                $antes = isset($previo[$clave]['cantidad']) ? (int) $previo[$clave]['cantidad'] : 0;
                if ((int) $hito['cantidad'] > 0 && $antes === 0) {
                    $nuevos[] = $clave;
                }
            }

            $cambio = $nuevo != $previo;

            $followup->hitos    = $nuevo;
            $followup->hitos_at = Carbon::parse((string) $medido_at)->setTimezone(DiasHabiles::ZONA_HORARIA);
            $followup->save();

            if ($cambio) {
                $this->evento($followup, ImplementationFollowupEvent::KIND_HITOS, ImplementationFollowupEvent::SOURCE_MARTIN,
                    count($nuevos) > 0 ? 'Hitos nuevos: ' . implode(', ', $nuevos) . '.' : 'Cambió la medición de los hitos.',
                    ['nuevos' => $nuevos]);
            }

            return [
                'guardado'                => true,
                'nuevos'                  => $nuevos,
                'obligatorios_pendientes' => $this->obligatorios_pendientes($followup),
            ];
        });
    }

    /**
     * La medición con la forma fija `{cantidad: int, primero_at: string|null, ultimo_at: string|null}`, ordenada por
     * clave (así dos mediciones iguales comparan iguales).
     *
     * @param array<string, mixed> $hitos Lo que mandó el monitor (ya validado).
     *
     * @return array<string, array<string, mixed>>
     */
    protected function normalizar_hitos(array $hitos)
    {
        $salida = [];

        foreach ($hitos as $clave => $hito) {
            $hito = is_array($hito) ? $hito : [];

            $salida[(string) $clave] = [
                'cantidad'   => isset($hito['cantidad']) ? (int) $hito['cantidad'] : 0,
                'primero_at' => isset($hito['primero_at']) && $hito['primero_at'] !== '' ? (string) $hito['primero_at'] : null,
                'ultimo_at'  => isset($hito['ultimo_at']) && $hito['ultimo_at'] !== '' ? (string) $hito['ultimo_at'] : null,
            ];
        }

        ksort($salida);

        return $salida;
    }

    /**
     * Los hitos obligatorios que todavía no se cumplieron (cantidad > 0) ni están en `hitos_no_aplican`.
     *
     * @param ImplementationFollowup $followup El seguimiento.
     *
     * @return array<int, string>
     */
    public function obligatorios_pendientes(ImplementationFollowup $followup)
    {
        $hitos      = is_array($followup->hitos) ? $followup->hitos : [];
        $no_aplican = is_array($followup->hitos_no_aplican) ? $followup->hitos_no_aplican : [];
        $pendientes = [];

        foreach (self::HITOS_OBLIGATORIOS as $clave) {
            $cumplido = isset($hitos[$clave]['cantidad']) && (int) $hitos[$clave]['cantidad'] > 0;
            if (! $cumplido && ! in_array($clave, $no_aplican, true)) {
                $pendientes[] = $clave;
            }
        }

        return $pendientes;
    }

    /* ==============================================================================================
     | 4. send
     |============================================================================================= */

    /**
     * Manda el seguimiento que redactó Martín, por el ticket del contacto (`ImplementationSupportWhatsappService`).
     *
     * Orden de los frenos:
     *   1. Idempotencia por `operation_id` ANTES de la reserva: un reintento de un envío que ya terminó (y soltó la
     *      reserva) devuelve el resultado guardado sin mandar nada. Si la primera sigue mandando: 409 `envio_en_curso`.
     *   2. La reserva vigente (409 `reservado`) y el seguimiento `activo` (409 `no_vencido`).
     *   3. El cliente escribió después del claim: 409 `cliente_escribio` (se suelta la reserva y se aplica la regla 2).
     *   4. Cliente activo, implementación y etapa (422), contacto (422 `sin_contacto`), remitente (422 `sin_admin`).
     *   5. El plan del envío: Martín atendiendo o con algo pendiente en el ticket → 423 `martin_ocupado`; la respuesta
     *      no caería en el ticket → 422 `respuesta_al_hilo`; texto largo con la ventana cerrada → 422.
     *   6. Reserva de la operación en la etapa, envío y registro (acción `seguimiento`).
     *
     * Resultado: `sent` (o `partial`, que se informa `sent` con `parcial: true`: el cliente ya recibió algo) suma
     * uno sin respuesta, corre el próximo a +2 hábiles y deja el evento `enviado` con el texto. `failed` deja un
     * `fallido` y lo corre una hora. `unknown` deja un `fallido` incierto, lo corre +2 hábiles (no se reintenta) y le
     * avisa a Lucas que mire el ticket. En los tres casos se suelta la reserva.
     *
     * Fuera de lun–vie 09:30–19:00 de Argentina: 409 `fuera_de_horario` sin mandar ni soltar la reserva (la suelta
     * el monitor). Con `aviso_a_lucas`, después de asentar el resultado (cualquiera) le llega a Lucas ese texto con el
     * cliente: Martín le escribe al cliente y a la vez le pasa algo pendiente del equipo.
     *
     * Dos fallas seguidas (este `failed` después de otro `fallido`, de un envío o de un `release` con error, sin un
     * enviado, una pausa, una omisión o un contacto del cliente en el medio): el seguimiento se corre +2 hábiles y
     * se le avisa a Lucas una vez.
     *
     * @param int         $id            El seguimiento.
     * @param string      $lease_token   La reserva.
     * @param string      $operation_id  uuid.
     * @param string      $body          El texto (1..550).
     * @param string|null $aviso_a_lucas Lo que Martín le pasa a Lucas (1..500), o null.
     *
     * @return array<string, mixed>
     */
    public function enviar($id, $lease_token, $operation_id, $body, $aviso_a_lucas = null)
    {
        $body     = trim((string) $body);
        $followup = ImplementationFollowup::find((int) $id);

        if ($followup === null) {
            throw new SeguimientoException(404, 'sin_seguimiento', 'No existe el seguimiento ' . (int) $id . '.');
        }

        $servicio       = app(ImplementationSupportWhatsappService::class);
        $implementation = $this->implementacion_de($followup);
        $client         = Client::find((int) $followup->client_id);

        /* --- 1. Idempotencia. --- */
        if ($implementation !== null) {
            $previa = $servicio->buscar_operacion($implementation, $operation_id);
            if ($previa !== null) {
                return $this->respuesta_de_una_operacion_previa($followup, $previa, $operation_id);
            }
        }

        if (! self::enabled()) {
            throw new SeguimientoException(409, 'deshabilitado', 'El seguimiento de implementaciones está apagado. No se mandó nada.');
        }

        if (! DiasHabiles::en_horario_de_envio($this->ahora())) {
            throw new SeguimientoException(409, 'fuera_de_horario', 'Un seguimiento solo sale de lunes a viernes de 09:30 a 19:00 (Argentina). No se mandó nada y la reserva sigue: soltala.');
        }

        /* --- 2 y 3. La reserva, el estado y el cliente que escribió. El 409 `cliente_escribio` se tira DESPUÉS del
           commit: la reserva suelta y la regla 2 aplicada tienen que quedar guardadas. --- */
        $escribio = DB::transaction(function () use ($id, $lease_token) {
            $fila = $this->buscar_bloqueado($id);
            $this->exigir_reserva($fila, $lease_token);

            if ($fila->status !== ImplementationFollowup::STATUS_ACTIVO) {
                throw new SeguimientoException(409, 'no_vencido', 'El seguimiento ya no está activo (' . $fila->status . '). No se mandó nada.');
            }

            $ultimo = $this->ultimo_contacto_del_cliente((int) $fila->client_id);
            if ($ultimo !== null && $fila->lease_claimed_at !== null && $ultimo->greaterThan($fila->lease_claimed_at)) {
                $this->soltar($fila);
                $this->aplicar_contacto($fila);

                return true;
            }

            return false;
        });

        if ($escribio) {
            throw new SeguimientoException(409, 'cliente_escribio', 'El cliente escribió después de que Martín leyó la conversación: lo atiende Martín por el ticket. No se mandó nada.');
        }

        /* --- 4. Cliente, implementación, etapa, contacto y remitente. --- */
        if ($client === null || ! $client->is_active) {
            throw new SeguimientoException(422, 'cliente_inactivo', 'El cliente no existe o está dado de baja. No se mandó nada.');
        }

        if ($implementation === null) {
            throw new SeguimientoException(422, 'sin_implementacion', 'El seguimiento no tiene una implementación de sistema donde registrar el envío. No se mandó nada.');
        }

        $etapa = $this->etapa_del_envio($implementation);
        if ($etapa === null) {
            throw new SeguimientoException(422, 'sin_etapa', 'La implementación no tiene la etapa cargada. No se mandó nada.');
        }

        $resuelto = $servicio->resolver_contacto($client, null);
        $contacto = $resuelto['contacto'];
        if ($contacto === null) {
            throw new SeguimientoException(422, 'sin_contacto', 'El dueño no tiene un teléfono válido en la ficha. No se mandó nada.');
        }

        $admin = $this->remitente();
        if ($admin === null) {
            throw new SeguimientoException(422, 'sin_admin', 'No hay ningún admin en la base para figurar como remitente. No se mandó nada.');
        }

        $telefono = (string) $contacto['phone'];

        /* --- 5. El plan: Martín, el hilo de la respuesta y el largo. --- */
        $plan = $servicio->plan($client, $contacto, $body, $admin);

        if (in_array('martin_atendiendo', $plan['impedimentos'], true) || in_array('martin_pendiente', $plan['impedimentos'], true)) {
            throw new SeguimientoException(423, 'martin_ocupado', 'Martín está atendiendo el ticket del contacto o tiene un mensaje del cliente esperando. No se mandó nada.', [
                'ticket_id'        => $plan['ticket'] === null ? null : (int) $plan['ticket']->id,
                'martin_pendiente' => $plan['martin_pendiente'],
            ]);
        }

        if (in_array('respuesta_al_hilo', $plan['impedimentos'], true)) {
            throw new SeguimientoException(422, 'respuesta_al_hilo', 'Lo que conteste el cliente caería en ' . $plan['respuesta_ira_a'] . ' y no en el ticket. No se mandó nada.', [
                'respuesta_ira_a' => $plan['respuesta_ira_a'],
            ]);
        }

        if (in_array('texto_largo_para_plantilla', $plan['impedimentos'], true)) {
            throw new SeguimientoException(422, 'texto_largo_para_plantilla', 'La ventana está cerrada y el texto no entra en la plantilla. No se mandó nada.', [
                'largo' => $plan['largo_para_plantilla'],
                'tope'  => $plan['tope_para_plantilla'],
            ]);
        }

        /* --- 6. La operación, el envío y el registro. --- */
        $huella  = $servicio->huella($body, self::ACCION, $etapa, $telefono);
        $reserva = $servicio->reservar($implementation, $etapa, $operation_id, $huella, self::ACCION);

        if ($reserva['sin_etapa']) {
            throw new SeguimientoException(422, 'sin_etapa', 'La implementación no tiene la etapa cargada. No se mandó nada.');
        }

        if (! $reserva['reservada']) {
            return $this->respuesta_de_una_operacion_previa($followup, $reserva['existente'], $operation_id);
        }

        $resultado = $servicio->enviar($client, $contacto, $body, $admin);
        $resultado['respuesta_ira_a'] = $plan['respuesta_ira_a'];
        $resultado['avisos']          = $plan['avisos'];
        $operacion = $servicio->registrar($implementation, $etapa, $operation_id, self::ACCION, $body, $telefono, $resultado);

        $asentado = DB::transaction(function () use ($id, $operacion, $operation_id, $body) {
            $fila = $this->buscar_bloqueado($id);

            return $this->asentar_el_envio($fila, $operacion, $operation_id, $body);
        });

        $followup = ImplementationFollowup::find((int) $id);
        if ($asentado['aviso'] !== null) {
            $this->avisar_a_lucas($followup, $asentado['aviso'][0], $asentado['aviso'][1], $asentado['evento']);
        }

        $aviso_a_lucas = trim((string) $aviso_a_lucas);
        if ($aviso_a_lucas !== '') {
            $this->avisar_a_lucas($followup, 'pedido_de_martin', $aviso_a_lucas, $asentado['evento']);
        }

        return array_merge($this->respuesta_del_envio($operacion, $operation_id, false), [
            'seguimiento' => $this->bloque_del_seguimiento($followup, false),
        ]);
    }

    /**
     * Asienta en el seguimiento el resultado de un envío y suelta la reserva (fila bloqueada por el llamador).
     *
     * @param ImplementationFollowup $followup     La fila.
     * @param array<string, mixed>   $operacion    La operación registrada.
     * @param string                 $operation_id La clave.
     * @param string                 $body         El texto.
     *
     * @return array{evento: ImplementationFollowupEvent, aviso: array{0: string, 1: string}|null} El evento que dejó y,
     *         si hace falta, el aviso para Lucas (envío incierto o segunda falla seguida).
     */
    protected function asentar_el_envio(ImplementationFollowup $followup, array $operacion, $operation_id, $body)
    {
        $ahora  = $this->ahora();
        $estado = isset($operacion['estado']) ? (string) $operacion['estado'] : ImplementationSupportWhatsappService::ESTADO_FALLIDO;
        $datos  = [
            'operation_id'  => (string) $operation_id,
            'estado'        => $estado,
            'ticket_id'     => isset($operacion['ticket_id']) ? $operacion['ticket_id'] : null,
            'message_id'    => isset($operacion['message_id']) ? $operacion['message_id'] : null,
            'used_template' => isset($operacion['uso_plantilla']) ? $operacion['uso_plantilla'] : null,
        ];
        $ticket_id = isset($operacion['ticket_id']) && $operacion['ticket_id'] !== null ? (int) $operacion['ticket_id'] : null;
        $aviso     = null;

        if (in_array($estado, [ImplementationSupportWhatsappService::ESTADO_ENVIADO, ImplementationSupportWhatsappService::ESTADO_PARCIAL], true)) {
            $followup->unanswered_count = (int) $followup->unanswered_count + 1;
            $followup->last_sent_at     = $ahora->copy();
            $followup->next_due_at      = DiasHabiles::sumar($ahora, (int) $followup->interval_business_days);
            $evento = $this->evento($followup, ImplementationFollowupEvent::KIND_ENVIADO, ImplementationFollowupEvent::SOURCE_MARTIN, $body, $datos, $ticket_id);
        } elseif ($estado === ImplementationSupportWhatsappService::ESTADO_INCIERTO) {
            $followup->next_due_at = DiasHabiles::sumar($ahora, (int) $followup->interval_business_days);
            $evento = $this->evento($followup, ImplementationFollowupEvent::KIND_FALLIDO, ImplementationFollowupEvent::SOURCE_MARTIN,
                'Envío incierto (no se sabe si salió; no se reintenta): ' . $body, $datos, $ticket_id);
            $aviso = ['envio_incierto', 'El seguimiento quedó con el envío incierto (no se sabe si le llegó). Mirá el ticket del cliente antes de volver a escribirle. '
                . 'El monitor deja este seguimiento bloqueado localmente hasta 3 días hábiles (_soporte-codex\\seguimientos\\inciertos\\' . (int) $followup->id . '.json).'];
        } else {
            $error  = isset($operacion['error']) ? (string) $operacion['error'] : '';
            $previas = $this->fallas_seguidas($followup);
            $evento = $this->evento($followup, ImplementationFollowupEvent::KIND_FALLIDO, ImplementationFollowupEvent::SOURCE_MARTIN,
                'El envío no salió' . ($error !== '' ? ': ' . $error : '.'), $datos, $ticket_id);
            $aviso  = $this->correr_por_fallas($followup, $previas + 1, 'El envío no salió' . ($error !== '' ? ' (' . $error . ')' : ''));
        }

        $this->soltar($followup);

        return ['evento' => $evento, 'aviso' => $aviso];
    }

    /**
     * Corre el seguimiento después de una falla (fila bloqueada). Desde la segunda seguida, +2 hábiles, y en la
     * segunda justo, un aviso a Lucas. Con la primera, una hora si `$correr_la_primera` (un envío `failed`, para que no
     * se reofrezca en la misma vuelta); un `release` con error, la primera vez, no cambia nada.
     *
     * @param ImplementationFollowup $followup          La fila.
     * @param int                    $fallas            Cuántas fallas seguidas hay, contando esta.
     * @param string                 $motivo            Lo que falló.
     * @param bool                   $correr_la_primera true = la primera falla corre una hora.
     *
     * @return array{0: string, 1: string}|null El aviso, si corresponde.
     */
    protected function correr_por_fallas(ImplementationFollowup $followup, $fallas, $motivo, $correr_la_primera = true)
    {
        $ahora = $this->ahora();

        if ($fallas >= self::TOPE_DE_FALLAS) {
            $followup->next_due_at = DiasHabiles::sumar($ahora, (int) $followup->interval_business_days);
            $followup->save();

            return $fallas === self::TOPE_DE_FALLAS
                ? ['fallas', $fallas . ' fallas seguidas del seguimiento; se corre 2 días hábiles. Última: ' . $motivo . '.']
                : null;
        }

        if (! $correr_la_primera) {
            return null;
        }

        $minimo = $ahora->copy()->addMinutes(self::MINUTOS_TRAS_UN_FALLO);
        if ($followup->next_due_at === null || $followup->next_due_at->lt($minimo)) {
            $followup->next_due_at = $minimo;
            $followup->save();
        }

        return null;
    }

    /**
     * La respuesta ante una `operation_id` que ya existe en la implementación: el resultado guardado si terminó;
     * 409 `envio_en_curso` si sigue mandando; 409 `operation_id_reusado` si es de otra acción.
     *
     * @param ImplementationFollowup                              $followup     El seguimiento.
     * @param array{etapa: int, operacion: array<string, mixed>}  $previa       La operación encontrada.
     * @param string                                              $operation_id La clave.
     *
     * @return array<string, mixed>
     */
    protected function respuesta_de_una_operacion_previa(ImplementationFollowup $followup, array $previa, $operation_id)
    {
        $operacion = $previa['operacion'];

        if (! isset($operacion['accion']) || $operacion['accion'] !== self::ACCION) {
            throw new SeguimientoException(409, 'operation_id_reusado', 'Esa operation_id ya se usó para otro mensaje de la implementación. No se mandó nada.');
        }

        if (isset($operacion['estado']) && $operacion['estado'] === ImplementationSupportWhatsappService::ESTADO_ENVIANDO) {
            throw new SeguimientoException(409, 'envio_en_curso', 'Ese envío se está mandando (o su proceso se cortó en el medio): todavía no se sabe si salió. No se mandó nada.');
        }

        return array_merge($this->respuesta_del_envio($operacion, $operation_id, true), [
            'seguimiento' => $this->bloque_del_seguimiento($followup, false),
        ]);
    }

    /**
     * El cuerpo del contrato de un envío: `{status, ticket_id, message_id, used_template}` más la clave y si es
     * repetido. `partial` se informa como `sent` con `parcial: true` (el cliente ya recibió algo).
     *
     * @param array<string, mixed> $operacion    La operación.
     * @param string               $operation_id La clave.
     * @param bool                 $repetida     true = resultado guardado de una llamada anterior.
     *
     * @return array<string, mixed>
     */
    protected function respuesta_del_envio(array $operacion, $operation_id, $repetida)
    {
        $estado = isset($operacion['estado']) ? (string) $operacion['estado'] : ImplementationSupportWhatsappService::ESTADO_FALLIDO;
        $status = 'failed';
        if (in_array($estado, [ImplementationSupportWhatsappService::ESTADO_ENVIADO, ImplementationSupportWhatsappService::ESTADO_PARCIAL], true)) {
            $status = 'sent';
        } elseif ($estado === ImplementationSupportWhatsappService::ESTADO_INCIERTO) {
            $status = 'unknown';
        }

        return [
            'status'        => $status,
            'parcial'       => $estado === ImplementationSupportWhatsappService::ESTADO_PARCIAL,
            'ticket_id'     => isset($operacion['ticket_id']) && $operacion['ticket_id'] !== null ? (int) $operacion['ticket_id'] : null,
            'message_id'    => isset($operacion['message_id']) && $operacion['message_id'] !== null ? (int) $operacion['message_id'] : null,
            'used_template' => isset($operacion['uso_plantilla']) ? $operacion['uso_plantilla'] : null,
            'operation_id'  => (string) $operation_id,
            'repetida'      => (bool) $repetida,
            'error'         => isset($operacion['error']) ? $operacion['error'] : null,
        ];
    }

    /**
     * La etapa donde se registra el envío: la actual; con la implementación `completed`, la última que tiene fila.
     *
     * @param Implementation $implementation La implementación.
     *
     * @return int|null null = no tiene esa etapa cargada.
     */
    protected function etapa_del_envio(Implementation $implementation)
    {
        if ($implementation->status === 'completed') {
            $ultima = ImplementationStage::where('implementation_id', $implementation->id)->max('stage_number');

            return $ultima === null ? null : (int) $ultima;
        }

        $actual = (int) $implementation->current_stage;

        return ImplementationStage::where('implementation_id', $implementation->id)->where('stage_number', $actual)->exists() ? $actual : null;
    }

    /**
     * El remitente del envío: el mismo admin con el que `claude/*` firma lo que hace
     * (`ClaudeImplementationOpsController::resolver_creador()`, replicado: es `protected`), con el nombre cambiado
     * EN MEMORIA a «Martín» para la plantilla. 🔴 Es un clon que nunca se guarda: ningún Admin
     * real cambia de nombre.
     *
     * @return Admin|null
     */
    protected function remitente()
    {
        $admin       = null;
        $configurado = config('services.claude_task_ingest.default_creator_admin_id');

        if (! empty($configurado)) {
            $admin = Admin::find((int) $configurado);
        }

        if ($admin === null) {
            $admin = Admin::where('is_default_task_assignee', true)->first();
        }

        if ($admin === null) {
            $admin = Admin::orderBy('id')->first();
        }

        if ($admin === null) {
            return null;
        }

        $martin       = clone $admin;
        $martin->name = self::OPERADOR;

        return $martin;
    }

    /* ==============================================================================================
     | 5. postpone y 6. release
     |============================================================================================= */

    /**
     * Martín decide no escribir ahora: `pausa` (el cliente pidió tiempo, hasta una fecha) u `omitir` (lo que sigue es
     * del equipo, o no hay nada útil para decir). Suma `no_aplican`, avisa a Lucas si se pide y suelta la reserva.
     *
     * @param int                  $id    El seguimiento.
     * @param array<string, mixed> $input lease_token, tipo, hasta?, motivo, avisar_a_lucas?, no_aplican?.
     *
     * @return array<string, mixed> `{ok, seguimiento}`.
     */
    public function posponer($id, array $input)
    {
        $tipo   = (string) $input['tipo'];
        $motivo = trim((string) $input['motivo']);

        $followup = DB::transaction(function () use ($id, $input, $tipo, $motivo) {
            $fila = $this->buscar_bloqueado($id);
            $this->exigir_reserva($fila, (string) $input['lease_token']);
            $this->exigir_no_terminado($fila);

            $this->sumar_no_aplican($fila, isset($input['no_aplican']) && is_array($input['no_aplican']) ? $input['no_aplican'] : []);

            if ($tipo === 'pausa') {
                $evento = $this->pausar($fila, (string) $input['hasta'], $motivo, ImplementationFollowupEvent::SOURCE_MARTIN, null);
            } else {
                $fila->next_due_at = DiasHabiles::sumar($this->ahora(), (int) $fila->interval_business_days);
                $fila->save();
                $evento = $this->evento($fila, ImplementationFollowupEvent::KIND_OMITIDO, ImplementationFollowupEvent::SOURCE_MARTIN, $motivo, [
                    'proximo_at' => $fila->next_due_at->toIso8601String(),
                ]);
            }

            $this->soltar($fila);

            return ['fila' => $fila, 'evento' => $evento];
        });

        $evento   = $followup['evento'];
        $followup = $followup['fila'];

        if (! empty($input['avisar_a_lucas'])) {
            $this->avisar_a_lucas($followup, $tipo === 'pausa' ? 'pausa' : 'omitido', $motivo, $evento);
        }

        return ['ok' => true, 'seguimiento' => $this->bloque_del_seguimiento($followup->refresh(), false)];
    }

    /**
     * Suelta la reserva sin cambiar nada; con `error`, deja un evento `fallido`.
     *
     * @param int         $id          El seguimiento.
     * @param string      $lease_token La reserva.
     * @param string|null $error       Lo que falló en el monitor.
     *
     * @return array<string, mixed> `{released: true}`.
     */
    public function soltar_reserva($id, $lease_token, $error)
    {
        $aviso = null;

        $respuesta = DB::transaction(function () use ($id, $lease_token, $error, &$aviso) {
            $fila = $this->buscar_bloqueado($id);
            $this->exigir_reserva($fila, $lease_token);

            $error = trim((string) $error);
            if ($error !== '') {
                /* Un release con error entra en la racha de fallas: a la segunda seguida, +2 hábiles y aviso. */
                $previas = $this->fallas_seguidas($fila);
                $evento  = $this->evento($fila, ImplementationFollowupEvent::KIND_FALLIDO, ImplementationFollowupEvent::SOURCE_MARTIN, $error);
                $correr  = $this->correr_por_fallas($fila, $previas + 1, $error, false);
                if ($correr !== null) {
                    $aviso = [$correr[0], $correr[1], $evento];
                }
            }

            $this->soltar($fila);

            return ['released' => true];
        });

        if ($aviso !== null) {
            $this->mandar_avisos((int) $id, [$aviso]);
        }

        return $respuesta;
    }

    /* ==============================================================================================
     | 7. Pausa desde un ticket
     |============================================================================================= */

    /**
     * Desde una atención normal (el cliente escribió «esta semana no puedo»): pausa o reanuda el seguimiento del
     * cliente del ticket, con la reserva DEL TICKET (la misma validación que `implementation-note`: 423
     * `invalid_lease`). 404 `sin_seguimiento` si el cliente no tiene.
     *
     * @param int                  $ticket_id El ticket.
     * @param array<string, mixed> $input     lease_token, accion, hasta?, motivo.
     *
     * @return array<string, mixed> `{registrado: true, seguimiento}`.
     */
    public function desde_el_ticket($ticket_id, array $input)
    {
        return DB::transaction(function () use ($ticket_id, $input) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail((int) $ticket_id);
            $caso   = SupportCodexCase::where('support_ticket_id', $ticket->id)->lockForUpdate()->first();
            app(SupportCodexService::class)->assert_lease($caso, (string) $input['lease_token']);

            $fila = empty($ticket->client_id) ? null
                : ImplementationFollowup::where('client_id', (int) $ticket->client_id)->lockForUpdate()->first();

            if ($fila === null) {
                throw new SeguimientoException(404, 'sin_seguimiento', 'El cliente de este ticket no tiene seguimiento de implementación.');
            }

            $this->exigir_no_terminado($fila);
            $motivo = trim((string) $input['motivo']);

            if ($input['accion'] === 'pausar') {
                $this->pausar($fila, (string) $input['hasta'], $motivo, ImplementationFollowupEvent::SOURCE_MARTIN, (int) $ticket->id);
            } else {
                $fila->status           = ImplementationFollowup::STATUS_ACTIVO;
                $fila->paused_until     = null;
                $fila->pause_reason     = null;
                $fila->unanswered_count = 0;
                $fila->next_due_at      = DiasHabiles::sumar($this->ahora(), (int) $fila->interval_business_days);
                $fila->save();
                $this->evento($fila, ImplementationFollowupEvent::KIND_REANUDADO, ImplementationFollowupEvent::SOURCE_MARTIN, $motivo, [
                    'proximo_at' => $fila->next_due_at->toIso8601String(),
                ], (int) $ticket->id);
            }

            return ['registrado' => true, 'seguimiento' => $this->bloque_del_seguimiento($fila, false)];
        });
    }

    /* ==============================================================================================
     | 8. El bloque del contexto del ticket
     |============================================================================================= */

    /**
     * La clave `seguimiento_implementacion` del contexto de un ticket: el objeto `seguimiento` sin `eventos`, o null
     * si el cliente no tiene. Nunca lanza (el contexto se arma en cada claim): ante un error, null y al log.
     *
     * @param SupportTicket $ticket El ticket.
     *
     * @return array<string, mixed>|null
     */
    public function bloque_para_el_ticket(SupportTicket $ticket)
    {
        try {
            if (empty($ticket->client_id)) {
                return null;
            }

            $followup = ImplementationFollowup::where('client_id', (int) $ticket->client_id)->first();

            return $followup === null ? null : $this->bloque_del_seguimiento($followup, false);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('Seguimiento de implementación: no se pudo armar el bloque del ticket.', [
                'support_ticket_id' => (int) $ticket->id,
                'error'             => $e->getMessage(),
            ]);

            return null;
        }
    }

    /* ==============================================================================================
     | 9. Gestión de Lucas
     |============================================================================================= */

    /**
     * La lista para Lucas, con cliente y próximo.
     *
     * @param string|null $status Filtro.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listar($status)
    {
        $consulta = ImplementationFollowup::orderBy('status')->orderBy('next_due_at')->orderBy('id');

        if ($status !== null && $status !== '') {
            $consulta->where('status', $status);
        }

        return $consulta->get()->map(function (ImplementationFollowup $followup) {
            $client = Client::find((int) $followup->client_id);

            return array_merge($this->bloque_del_seguimiento($followup, false), [
                'client_id'         => (int) $followup->client_id,
                'client_name'       => $client === null ? null : $client->resolve_display_name(),
                'implementation_id' => $followup->implementation_id === null ? null : (int) $followup->implementation_id,
                'reservado'         => $followup->reserva_vigente(),
            ]);
        })->values()->all();
    }

    /**
     * Alta manual de Lucas, aunque la implementación esté cerrada (la más nueva del cliente, de cualquier estado).
     *
     * @param int $client_id El cliente.
     *
     * @return array<string, mixed> El seguimiento.
     */
    public function alta_manual($client_id)
    {
        $client = Client::find((int) $client_id);

        if ($client === null) {
            throw new SeguimientoException(404, 'sin_cliente', 'No existe el cliente ' . (int) $client_id . '.');
        }

        if (app(PruebaDelClienteService::class)->es_cliente_en_prueba($client)) {
            throw new SeguimientoException(422, 'cliente_en_prueba', 'El cliente está en prueba gratuita: tiene sus propios empujones (prueba:empujar).');
        }

        return DB::transaction(function () use ($client) {
            $existente = ImplementationFollowup::where('client_id', $client->id)->lockForUpdate()->first();
            if ($existente !== null) {
                throw new SeguimientoException(409, 'ya_existe', 'El cliente ya tiene seguimiento: cambialo con PATCH.', [
                    'seguimiento' => $this->bloque_del_seguimiento($existente, false),
                ]);
            }

            $implementation = Implementation::where('client_id', $client->id)->orderByDesc('id')->first();
            $followup = $this->crear($client, $implementation === null ? null : (int) $implementation->id, ImplementationFollowupEvent::SOURCE_LUCAS, 'Alta manual de Lucas.');

            return $this->bloque_del_seguimiento($followup, true);
        });
    }

    /**
     * Cambios de Lucas. Cada cambio deja su evento con `source: lucas`: el de estado con su tipo (`pausado`,
     * `reanudado`, `frenado`, `terminado`) y los demás campos un `editado`.
     *
     * @param int                  $id      El seguimiento.
     * @param array<string, mixed> $cambios status?, interval_business_days?, max_unanswered?, paused_until?, pause_reason?, hitos_no_aplican?.
     *
     * @return array<string, mixed> El seguimiento.
     */
    public function editar($id, array $cambios)
    {
        return DB::transaction(function () use ($id, $cambios) {
            $fila  = $this->buscar_bloqueado($id);
            $antes = (string) $fila->status;
            $campos = [];

            foreach (['interval_business_days', 'max_unanswered'] as $campo) {
                if (array_key_exists($campo, $cambios)) {
                    $fila->{$campo} = (int) $cambios[$campo];
                    $campos[] = $campo;
                }
            }

            if (array_key_exists('pause_reason', $cambios)) {
                $fila->pause_reason = $cambios['pause_reason'] === null ? null : mb_substr(trim((string) $cambios['pause_reason']), 0, 500);
                $campos[] = 'pause_reason';
            }

            if (array_key_exists('paused_until', $cambios)) {
                $fila->paused_until = $cambios['paused_until'] === null ? null : Carbon::parse((string) $cambios['paused_until'], DiasHabiles::ZONA_HORARIA)->startOfDay();
                $campos[] = 'paused_until';
            }

            if (array_key_exists('hitos_no_aplican', $cambios)) {
                $fila->hitos_no_aplican = array_values(array_unique(array_map('strval', (array) $cambios['hitos_no_aplican'])));
                $campos[] = 'hitos_no_aplican';
            }

            $nuevo = array_key_exists('status', $cambios) && $cambios['status'] !== null ? (string) $cambios['status'] : $antes;

            if ($nuevo === ImplementationFollowup::STATUS_PAUSADO && $fila->paused_until === null) {
                throw new SeguimientoException(422, 'sin_fecha', 'Para pausar hace falta paused_until.');
            }

            $fila->status = $nuevo;

            if ($nuevo === ImplementationFollowup::STATUS_PAUSADO) {
                $fila->next_due_at = DiasHabiles::primero_despues_de($fila->paused_until);
            } elseif ($nuevo === ImplementationFollowup::STATUS_ACTIVO && $antes !== ImplementationFollowup::STATUS_ACTIVO) {
                /* Reactivar desde frenado o pausado: el contador vuelve a 0 (si no, uno frenado se frena otra vez en la
                   próxima corrida) y, si no tiene próximo, corre desde hoy. */
                $fila->unanswered_count = 0;
                $fila->paused_until     = null;
                if ($fila->next_due_at === null) {
                    $fila->next_due_at = DiasHabiles::sumar($this->ahora(), (int) $fila->interval_business_days);
                }
            }

            $fila->save();

            if ($nuevo !== $antes) {
                $tipos = [
                    ImplementationFollowup::STATUS_PAUSADO   => ImplementationFollowupEvent::KIND_PAUSADO,
                    ImplementationFollowup::STATUS_ACTIVO    => ImplementationFollowupEvent::KIND_REANUDADO,
                    ImplementationFollowup::STATUS_FRENADO   => ImplementationFollowupEvent::KIND_FRENADO,
                    ImplementationFollowup::STATUS_TERMINADO => ImplementationFollowupEvent::KIND_TERMINADO,
                ];
                $this->evento($fila, $tipos[$nuevo], ImplementationFollowupEvent::SOURCE_LUCAS, 'Lucas cambió el estado: ' . $antes . ' → ' . $nuevo . '.', [
                    'campos' => $campos,
                ]);
            } elseif (count($campos) > 0) {
                $this->evento($fila, ImplementationFollowupEvent::KIND_EDITADO, ImplementationFollowupEvent::SOURCE_LUCAS, 'Lucas cambió: ' . implode(', ', $campos) . '.', [
                    'campos' => $campos,
                ]);
            }

            return $this->bloque_del_seguimiento($fila, true);
        });
    }

    /* ==============================================================================================
     | Helpers
     |============================================================================================= */

    /**
     * Pausa (fila bloqueada): `pausado`, `paused_until`, `pause_reason` y `next_due_at` = primer hábil después de
     * `hasta` a las 10:00. Evento `pausado`. `hasta` tiene que ser hoy o más adelante (422 `fecha_pasada`).
     *
     * @param ImplementationFollowup $fila      La fila.
     * @param string                 $hasta     YYYY-MM-DD.
     * @param string                 $motivo    El motivo.
     * @param string                 $source    martin | lucas.
     * @param int|null               $ticket_id El ticket desde el que se pausó.
     *
     * @return void
     */
    protected function pausar(ImplementationFollowup $fila, $hasta, $motivo, $source, $ticket_id)
    {
        $fecha = Carbon::createFromFormat('Y-m-d', (string) $hasta, DiasHabiles::ZONA_HORARIA)->startOfDay();

        if ($fecha->lt($this->ahora()->startOfDay())) {
            throw new SeguimientoException(422, 'fecha_pasada', '`hasta` tiene que ser hoy o una fecha futura.');
        }

        $fila->status       = ImplementationFollowup::STATUS_PAUSADO;
        $fila->paused_until = $fecha;
        $fila->pause_reason = mb_substr((string) $motivo, 0, 500);
        $fila->next_due_at  = DiasHabiles::primero_despues_de($fecha);
        $fila->save();

        return $this->evento($fila, ImplementationFollowupEvent::KIND_PAUSADO, $source, $motivo, [
            'hasta'      => $fecha->format('Y-m-d'),
            'proximo_at' => $fila->next_due_at->toIso8601String(),
        ], $ticket_id);
    }

    /**
     * Suma claves a `hitos_no_aplican` (sin repetir) y guarda.
     *
     * @param ImplementationFollowup $fila   La fila.
     * @param array<int, string>     $claves Las claves.
     *
     * @return void
     */
    protected function sumar_no_aplican(ImplementationFollowup $fila, array $claves)
    {
        if (count($claves) === 0) {
            return;
        }

        $actuales = is_array($fila->hitos_no_aplican) ? $fila->hitos_no_aplican : [];
        $fila->hitos_no_aplican = array_values(array_unique(array_merge($actuales, array_map('strval', $claves))));
        $fila->save();
    }

    /**
     * El seguimiento con su fila bloqueada (adentro de una transacción), o 404 `sin_seguimiento`.
     *
     * @param int $id El seguimiento.
     *
     * @return ImplementationFollowup
     */
    protected function buscar_bloqueado($id)
    {
        $fila = ImplementationFollowup::where('id', (int) $id)->lockForUpdate()->first();

        if ($fila === null) {
            throw new SeguimientoException(404, 'sin_seguimiento', 'No existe el seguimiento ' . (int) $id . '.');
        }

        return $fila;
    }

    /**
     * Si el token es el de la reserva vigente.
     *
     * @param ImplementationFollowup $fila  La fila.
     * @param string                 $token El token en claro.
     *
     * @return bool
     */
    protected function token_vigente(ImplementationFollowup $fila, $token)
    {
        return $fila->reserva_vigente() && trim((string) $token) !== '' && hash_equals((string) $fila->lease_token, hash('sha256', (string) $token));
    }

    /**
     * Si el token es el de la reserva vigente del seguimiento: la misma validación por hash de `token_vigente()`,
     * expuesta para las rutas de la ficha del cliente desde el seguimiento (misión cruzada `ficha-del-cliente`,
     * 11/11/2026), que no tienen por qué copiarla.
     *
     * @param ImplementationFollowup $fila  La fila.
     * @param string                 $token El token en claro.
     *
     * @return bool
     */
    public function reserva_valida(ImplementationFollowup $fila, $token)
    {
        return $this->token_vigente($fila, $token);
    }

    /**
     * 409 `reservado` si el token no es el de la reserva vigente.
     *
     * @param ImplementationFollowup $fila  La fila.
     * @param string                 $token El token.
     *
     * @return void
     */
    protected function exigir_reserva(ImplementationFollowup $fila, $token)
    {
        if (! $this->token_vigente($fila, $token)) {
            throw new SeguimientoException(409, 'reservado', 'La reserva venció o es de otro proceso.');
        }
    }

    /**
     * 409 `seguimiento_terminado` si el seguimiento ya terminó (no se pausa ni se pospone uno cerrado).
     *
     * @param ImplementationFollowup $fila La fila.
     *
     * @return void
     */
    protected function exigir_no_terminado(ImplementationFollowup $fila)
    {
        if ($fila->status === ImplementationFollowup::STATUS_TERMINADO) {
            throw new SeguimientoException(409, 'seguimiento_terminado', 'El seguimiento ya terminó.');
        }
    }

    /**
     * Suelta la reserva (y guarda).
     *
     * @param ImplementationFollowup $fila La fila.
     *
     * @return void
     */
    protected function soltar(ImplementationFollowup $fila)
    {
        $fila->lease_token      = null;
        $fila->lease_expires_at = null;
        $fila->lease_claimed_at = null;
        $fila->save();
    }

    /**
     * La implementación del seguimiento: la de `implementation_id` o, si no tiene, la del cliente.
     *
     * @param ImplementationFollowup $followup El seguimiento.
     *
     * @return Implementation|null
     */
    protected function implementacion_de(ImplementationFollowup $followup)
    {
        if ($followup->implementation_id !== null) {
            $implementation = Implementation::find((int) $followup->implementation_id);
            if ($implementation !== null) {
                return $implementation;
            }
        }

        return Implementation::where('client_id', (int) $followup->client_id)->orderByDesc('id')->first();
    }

    /**
     * Deja un evento.
     *
     * @param ImplementationFollowup     $followup  El seguimiento.
     * @param string                     $kind      El tipo.
     * @param string                     $source    sistema | martin | lucas.
     * @param string|null                $detalle   El texto.
     * @param array<string, mixed>|null  $datos     Datos estructurados.
     * @param int|null                   $ticket_id El ticket, si viene de uno.
     *
     * @return ImplementationFollowupEvent
     */
    protected function evento(ImplementationFollowup $followup, $kind, $source, $detalle, array $datos = null, $ticket_id = null)
    {
        return ImplementationFollowupEvent::create([
            'implementation_followup_id' => (int) $followup->id,
            'kind'                       => (string) $kind,
            'source'                     => (string) $source,
            'detail'                     => $detalle,
            'data'                       => $datos,
            'support_ticket_id'          => $ticket_id,
        ]);
    }

    /**
     * Aviso a Lucas por push al admin, a los mismos destinatarios que el aviso de atención de soporte
     * (`SupportAttentionNotificationService::destinatarios()`: el dueño por defecto del soporte y los que reciben las
     * escalaciones). Nunca lanza: un push que falla queda en el log y el seguimiento sigue. Deja el resultado en un
     * evento del tipo del aviso (`data.aviso_a_lucas`).
     *
     * @param ImplementationFollowup           $followup El seguimiento.
     * @param string                           $motivo   frenado | terminado | pausa | omitido | envio_incierto | fallas | pedido_de_martin.
     * @param string                           $texto    El cuerpo del push.
     * @param ImplementationFollowupEvent|null $evento   El evento donde se anota el resultado (null = no se anota).
     *
     * @return array<string, mixed> `{admin_ids, aceptados}`.
     */
    public function avisar_a_lucas(ImplementationFollowup $followup, $motivo, $texto, ImplementationFollowupEvent $evento = null)
    {
        $resultado = ['motivo' => (string) $motivo, 'admin_ids' => [], 'aceptados' => 0];

        try {
            $client = Client::find((int) $followup->client_id);
            $nombre = $client === null ? ('cliente ' . (int) $followup->client_id) : $client->resolve_display_name();
            $ids    = Admin::where(function ($consulta) {
                $consulta->where('is_default_support_owner', true)->orWhere('notify_support_escalation_whatsapp', true);
            })->pluck('id')->map(function ($valor) {
                return (int) $valor;
            })->all();

            $resultado['admin_ids'] = $ids;
            $push = app(AdminPushNotificationService::class);

            foreach ($ids as $admin_id) {
                $envio = $push->send_to_admin_with_result($admin_id, 'Seguimiento de implementación: ' . $nombre, (string) $texto, [
                    'url'                        => '/implementaciones/sistema',
                    'tag'                        => 'seguimiento-implementacion-' . (int) $followup->id,
                    'implementation_followup_id' => (int) $followup->id,
                    'client_id'                  => (int) $followup->client_id,
                    'motivo'                     => (string) $motivo,
                ]);
                $resultado['aceptados'] += count(isset($envio['accepted_endpoint_hashes']) ? $envio['accepted_endpoint_hashes'] : []);
            }
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('Seguimiento de implementación: no salió el aviso a Lucas.', [
                'implementation_followup_id' => (int) $followup->id,
                'error'                      => $e->getMessage(),
            ]);
            $resultado['error'] = $e->getMessage();
        }

        if ($evento !== null) {
            $evento = ImplementationFollowupEvent::find($evento->id);
        }
        if ($evento !== null) {
            $datos = is_array($evento->data) ? $evento->data : [];
            /* Un evento puede llevar más de un aviso (el incierto y el pedido de Martín): se guardan todos. */
            if (isset($datos['aviso_a_lucas'])) {
                $previos = isset($datos['avisos_a_lucas']) && is_array($datos['avisos_a_lucas']) ? $datos['avisos_a_lucas'] : [$datos['aviso_a_lucas']];
                $previos[] = $resultado;
                $datos['avisos_a_lucas'] = $previos;
            }
            $datos['aviso_a_lucas'] = $resultado;
            $evento->data = $datos;
            $evento->save();
        }

        return $resultado;
    }

    /**
     * Un instante en ISO 8601, o null.
     *
     * @param mixed $valor Carbon o null.
     *
     * @return string|null
     */
    protected function iso($valor)
    {
        return $valor === null ? null : Carbon::parse($valor)->toIso8601String();
    }
}
