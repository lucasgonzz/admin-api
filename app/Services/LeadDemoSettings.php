<?php

namespace App\Services;

use App\Models\AdminSetting;

/**
 * Parámetros configurables para el ciclo de vida de las demos.
 *
 * Persistencia en `admin_settings`; controla duración, márgenes de setup/gracia
 * y tiempos de automatizaciones (recordatorio, check de ingreso, resumen del lead).
 */
class LeadDemoSettings
{
    /** Clave: duración estimada de la demo en minutos. */
    public const KEY_DURACION_MINUTOS = 'demo_duracion_minutos';

    /** Clave: minutos antes del inicio para correr demo setup automático. */
    public const KEY_SETUP_MINUTOS_ANTES = 'demo_setup_minutos_antes';

    /**
     * Clave: minutos que un setup puede pasar en `ejecutandose` antes de darse por colgado
     * (misión 60).
     *
     * Existe porque `ejecutandose` era un estado terminal de hecho: el comando de cada minuto sólo
     * mira `pendiente`, así que un setup cuyo proceso murió sin poder escribir el fallo se quedaba
     * ahí para siempre. Medido el 14/8/2026 sobre la base real: tres leads colgados y **ninguno**
     * que hubiera llegado nunca a `exitoso` en dos meses. La lección ya estaba escrita en este repo
     * para `demo_updates` (`DemoUpdateService.php:145`, 13/7/2026) y no se había generalizado.
     */
    public const KEY_SETUP_TIMEOUT_MINUTOS = 'demo_setup_timeout_minutos';

    /**
     * Clave: margen mínimo, en minutos desde ahora, para ofrecer un horario de demo HOY (grupo
     * 306, prompt 02). Solo aplica a leads en la dinámica nueva — la actual sigue sin ofrecer
     * horarios de hoy.
     */
    public const KEY_DEMO_MINIMO_MINUTOS_DESDE_AHORA = 'demo_minimo_minutos_desde_ahora';

    /**
     * Clave: porcentaje del video de introducción que el lead tiene que haber visto para que se
     * le habilite el ingreso a la demo (misión 46, pieza 3).
     *
     * No es un parámetro de minutos: tiene su propio rango 0–100 y su propio clamp. Meterlo en
     * clamp() —que acota a MAX_MINUTOS = 240— parecería inofensivo y de hecho no truncaría nada
     * hoy, pero deja escrito que 100 y 240 son la misma clase de número, y no lo son.
     */
    public const KEY_DEMO_INTRO_UMBRAL_PCT = 'demo_intro_umbral_pct';

    /**
     * Clave: tope de horas de la ventana extendida (misión 47).
     *
     * Es una GUARDA, no algo que el lead elija. Sin tope, "hasta el fin del día" significa que un
     * lead que dice "a partir de las 10 de la mañana" bloquea esa instancia de 09:45 a 00:09 — el
     * día entero, por uno solo que capaz no aparece. Con tres instancias, dos o tres leads así
     * dejan el día sin disponibilidad para nadie.
     */
    public const KEY_VENTANA_EXTENDIDA_MAX_HORAS = 'demo_ventana_extendida_max_horas';

    /** Clave: minutos de gracia post-demo para liberar el slot de disponibilidad. */
    public const KEY_GRACIA_MINUTOS_POST = 'demo_gracia_minutos_post';

    /** Clave: minutos antes del inicio para enviar recordatorio por WhatsApp. */
    public const KEY_RECORDATORIO_MINUTOS_ANTES = 'demo_recordatorio_minutos_antes';

    /** Clave: hora del recordatorio de mañana de demo (formato H:i, ej. 09:00). */
    public const KEY_RECORDATORIO_MANANA_HORA = 'demo_recordatorio_manana_hora';

    /**
     * Clave: minutos post-inicio para preguntar al lead si pudo ingresar.
     *
     * @deprecated Obsoleto tras la feature de ciclo de demo automatizado (prompt 094+).
     *             El check de ingreso ahora se envía en el minuto exacto de inicio (ver prompt 096).
     *             No borrar aún; mantener para no romper comandos existentes.
     */
    public const KEY_CHECK_INGRESO_MINUTOS_POST = 'demo_check_ingreso_minutos_post';

    /** Clave: minutos antes del fin de la demo para generar resumen del lead. */
    public const KEY_RESUMEN_MINUTOS_ANTES_FIN = 'demo_resumen_minutos_antes_fin';

    /** Clave: duración de la llamada del closer post-demo en minutos. */
    public const KEY_DURACION_LLAMADA_CLOSER_MINUTOS = 'demo_duracion_llamada_closer_minutos';

    /** Clave: horario laboral del closer de lunes a viernes (formato H:i-H:i, ej. 09:00-18:00). */
    public const KEY_CLOSER_HORARIO_LUNES_VIERNES = 'demo_closer_horario_lunes_viernes';

    /** Clave: horario laboral del closer los sábados (formato H:i-H:i; vacío = no trabaja). */
    public const KEY_CLOSER_HORARIO_SABADO = 'demo_closer_horario_sabado';

    /** Clave: horario laboral del closer los domingos (formato H:i-H:i; vacío = no trabaja). */
    public const KEY_CLOSER_HORARIO_DOMINGO = 'demo_closer_horario_domingo';

    /**
     * Clave: franja horaria propia de la demo de lunes a viernes (formato H:i-H:i), independiente
     * del horario del closer (grupo 306, prompt 01 — ver `@contexto/demo_experiencia.md` §3.19).
     */
    public const KEY_DEMO_HORARIO_LUNES_VIERNES = 'demo_horario_lunes_viernes';

    /** Clave: franja horaria propia de la demo los sábados (formato H:i-H:i). */
    public const KEY_DEMO_HORARIO_SABADO = 'demo_horario_sabado';

    /** Clave: franja horaria propia de la demo los domingos (formato H:i-H:i). */
    public const KEY_DEMO_HORARIO_DOMINGO = 'demo_horario_domingo';

    /** Clave: indica si la llamada del closer debe terminar dentro del horario laboral (string "1"/"0"). */
    public const KEY_LLAMADA_DEBE_TERMINAR_EN_HORARIO = 'demo_llamada_debe_terminar_en_horario';

    /** Clave: frecuencia en minutos con que se generan los slots disponibles. */
    public const KEY_FRECUENCIA_SLOTS_MINUTOS = 'demo_frecuencia_slots_minutos';

    /** Clave: minutos sin respuesta al check de ingreso antes de marcar demo_pendiente_de_ingreso y avisar a admins. */
    public const KEY_INGRESO_TIMEOUT_MINUTOS = 'demo_ingreso_timeout_minutos';

    /** Clave: minutos desde el check de fin antes de enviar el seguimiento de "¿pudiste terminar?". */
    public const KEY_FIN_SEGUIMIENTO_MINUTOS = 'demo_fin_seguimiento_minutos';

    /** Clave: minutos desde el check de fin antes de marcar demo_pendiente_de_terminar y avisar a admins. */
    public const KEY_FIN_TIMEOUT_MINUTOS = 'demo_fin_timeout_minutos';

    /** Clave: horas desde el inicio de la demo sin ingreso confirmado antes de revertir a calificado. */
    public const KEY_PENDIENTE_INGRESO_HORAS_TIMEOUT = 'demo_pendiente_ingreso_horas_timeout';

    /** Clave: minutos desde el fin de la demo antes de pasar demo_pendiente_de_terminar → closer_activo. */
    public const KEY_PENDIENTE_TERMINAR_TIMEOUT_MINUTOS = 'demo_pendiente_terminar_timeout_minutos';

    /**
     * Clave: ventana de "conversación viva" para el check de fin de demo (grupo 307, prompt 01).
     * Minutos: si hubo un mensaje entrante o saliente dentro de esta ventana, el check se pospone.
     */
    public const KEY_FIN_CHECK_SILENCIO_MINUTOS = 'fin_check_silencio_minutos';

    /**
     * Clave: cuánto se pospone el check de fin de demo cuando hay conversación viva y nadie (ni el
     * agente, a pedido del lead) indicó una demora puntual (grupo 307, prompt 01).
     */
    public const KEY_FIN_CHECK_DEMORA_DEFAULT_MINUTOS = 'fin_check_demora_default_minutos';

    /** Clave: minutos antes del inicio de la llamada del closer para enviar el bot de Recall.ai. */
    public const KEY_RECALL_BOT_MINUTOS_ANTES = 'recall_bot_minutos_antes';

    /**
     * Clave: dinámica de demo con la que nacen los leads nuevos (grupo 293, prompt 01).
     *
     * Es la setting de "con qué dinámica se estampan los leads nuevos al crearse" — no la lee el
     * runtime en cada mensaje, solo el hook `creating` del modelo Lead. Ver comentario de la
     * migración `2026_07_31_140000_add_demo_experiencia_to_leads_table` para el porqué.
     */
    public const KEY_EXPERIENCIA_DEFAULT = 'demo_experiencia_default';

    /** Valor por defecto: duración de la demo (minutos). */
    private const DEFAULT_DURACION_MINUTOS = 60;

    /** Valor por defecto: setup antes del inicio (minutos). */
    private const DEFAULT_SETUP_MINUTOS_ANTES = 15;

    /**
     * Valor por defecto: minutos en `ejecutandose` antes de darlo por colgado (misión 60).
     *
     * 10 y no 3: el setup real tarda hasta dos minutos en producción, y el precio de los dos
     * errores no es el mismo. Pasarse de holgado sólo demora el aviso; quedarse corto marca como
     * fallido un armado que estaba andando bien y habilita el reintento, que dispara otro
     * `migrate:fresh` sobre una instancia ocupada.
     */
    private const DEFAULT_SETUP_TIMEOUT_MINUTOS = 10;

    /**
     * Valor por defecto: margen mínimo para ofrecer un horario de HOY (minutos). Es el tiempo en
     * que el lead entra a la página inmersiva, completa el formulario y mira el video de intro.
     *
     * Bajó de 15 a 5 en la misión 46: esos minutos no son una espera antes de entrar, son los que
     * el lead pasa adentro de la página. No confundir con DEFAULT_SETUP_MINUTOS_ANTES, que sigue
     * en 15 a propósito y mide otra cosa (cuánto antes se prepara la instancia de un turno
     * agendado para más adelante).
     */
    private const DEFAULT_DEMO_MINIMO_MINUTOS_DESDE_AHORA = 5;

    /** Valor por defecto: porcentaje del video de introducción exigido para entrar (misión 46). */
    private const DEFAULT_DEMO_INTRO_UMBRAL_PCT = 90;

    /** Valor por defecto: tope de la ventana extendida, en horas (misión 47). */
    private const DEFAULT_VENTANA_EXTENDIDA_MAX_HORAS = 6;

    /** Valor por defecto: gracia post-demo (minutos). */
    private const DEFAULT_GRACIA_MINUTOS_POST = 10;

    /** Valor por defecto: recordatorio antes del inicio (minutos). */
    private const DEFAULT_RECORDATORIO_MINUTOS_ANTES = 15;

    /** Valor por defecto: hora del recordatorio de mañana de demo. */
    private const DEFAULT_RECORDATORIO_MANANA_HORA = '09:00';

    /** Valor por defecto: check de ingreso post-inicio (minutos). */
    private const DEFAULT_CHECK_INGRESO_MINUTOS_POST = 5;

    /** Valor por defecto: resumen antes del fin de la demo (minutos). */
    private const DEFAULT_RESUMEN_MINUTOS_ANTES_FIN = 10;

    /** Valor por defecto: duración de la llamada del closer post-demo (minutos). */
    private const DEFAULT_DURACION_LLAMADA_CLOSER_MINUTOS = 30;

    /** Valor por defecto: horario laboral del closer de lunes a viernes. */
    private const DEFAULT_CLOSER_HORARIO_LUNES_VIERNES = '09:00-18:00';

    /** Valor por defecto: horario laboral del closer los sábados. */
    private const DEFAULT_CLOSER_HORARIO_SABADO = '10:00-13:00';

    /** Valor por defecto: horario laboral del closer los domingos (vacío = no trabaja). */
    private const DEFAULT_CLOSER_HORARIO_DOMINGO = '';

    /**
     * Valor por defecto: franja completa (00:00-23:59). La demo se ofrece "lo antes posible,
     * siempre" — un default acotado escondería el caso que este grupo viene a habilitar.
     */
    private const DEFAULT_DEMO_HORARIO_LUNES_VIERNES = '00:00-23:59';

    /** Valor por defecto: franja completa de la demo los sábados. */
    private const DEFAULT_DEMO_HORARIO_SABADO = '00:00-23:59';

    /** Valor por defecto: franja completa de la demo los domingos. */
    private const DEFAULT_DEMO_HORARIO_DOMINGO = '00:00-23:59';

    /** Valor por defecto: la llamada del closer NO debe terminar dentro del horario (desactivado). */
    private const DEFAULT_LLAMADA_DEBE_TERMINAR_EN_HORARIO = '0';

    /** Valor por defecto: frecuencia de slots en minutos. */
    private const DEFAULT_FRECUENCIA_SLOTS_MINUTOS = 30;

    /** Valor por defecto: timeout de ingreso (minutos sin respuesta → demo_pendiente_de_ingreso). */
    private const DEFAULT_INGRESO_TIMEOUT_MINUTOS = 15;

    /** Valor por defecto: minutos desde el check de fin antes de insistir una vez más. */
    private const DEFAULT_FIN_SEGUIMIENTO_MINUTOS = 10;

    /** Valor por defecto: timeout de fin (minutos sin confirmación → demo_pendiente_de_terminar). */
    private const DEFAULT_FIN_TIMEOUT_MINUTOS = 25;

    /** Valor por defecto: 24 horas sin ingreso antes de revertir a calificado. */
    private const DEFAULT_PENDIENTE_INGRESO_HORAS_TIMEOUT = 24;

    /** Valor por defecto: 120 minutos (2 horas). */
    private const DEFAULT_PENDIENTE_TERMINAR_TIMEOUT_MINUTOS = 120;

    /** Valor por defecto: ventana de "conversación viva" para el check de fin (minutos). */
    private const DEFAULT_FIN_CHECK_SILENCIO_MINUTOS = 10;

    /** Valor por defecto: demora al posponer el check de fin cuando nadie indicó cuánto (minutos). */
    private const DEFAULT_FIN_CHECK_DEMORA_DEFAULT_MINUTOS = 15;

    /** Valor por defecto: minutos antes de la llamada del closer para enviar el bot de Recall.ai. */
    private const DEFAULT_RECALL_BOT_MINUTOS_ANTES = 5;

    /** Valor por defecto: dinámica de demo para leads nuevos (la que hoy funciona en producción). */
    private const DEFAULT_EXPERIENCIA = 'actual';

    /** Valores válidos para la frecuencia de slots (minutos). */
    public const VALID_FRECUENCIA_SLOTS = [5, 10, 15, 30, 60];

    /** Valores válidos para la dinámica de demo (`demo_experiencia` / `demo_experiencia_default`). */
    public const VALID_EXPERIENCIAS = ['actual', 'nueva'];

    /** Mínimo permitido para todos los parámetros (minutos). */
    public const MIN_MINUTOS = 0;

    /** Máximo permitido para todos los parámetros (minutos). */
    public const MAX_MINUTOS = 240;

    /** Mínimo permitido para el umbral del video de introducción (porcentaje). */
    public const MIN_PCT = 0;

    /** Máximo permitido para el umbral del video de introducción (porcentaje). */
    public const MAX_PCT = 100;

    /** Mínimo permitido para el tope de la ventana extendida (horas). */
    public const MIN_HORAS_VENTANA = 1;

    /** Máximo permitido para el tope de la ventana extendida (horas). */
    public const MAX_HORAS_VENTANA = 12;

    /**
     * Devuelve la configuración completa para el panel (GET settings).
     *
     * @return array<string, int|string>
     */
    public static function to_array(): array
    {
        return [
            'duracion_minutos'                    => self::get_duracion_minutos(),
            'setup_minutos_antes'                 => self::get_setup_minutos_antes(),
            'setup_timeout_minutos'               => self::get_setup_timeout_minutos(),
            'demo_minimo_minutos_desde_ahora'     => self::get_demo_minimo_minutos_desde_ahora(),
            'demo_intro_umbral_pct'               => self::get_demo_intro_umbral_pct(),
            'demo_ventana_extendida_max_horas'    => self::get_ventana_extendida_max_horas(),
            'gracia_minutos_post'                 => self::get_gracia_minutos_post(),
            'recordatorio_minutos_antes'          => self::get_recordatorio_minutos_antes(),
            'recordatorio_manana_hora'            => self::get_recordatorio_manana_hora(),
            'check_ingreso_minutos_post'          => self::get_check_ingreso_minutos_post(),
            'resumen_minutos_antes_fin'           => self::get_resumen_minutos_antes_fin(),
            'duracion_llamada_closer_minutos'     => self::get_duracion_llamada_closer_minutos(),
            'closer_horario_lunes_viernes'        => self::get_closer_horario_lunes_viernes(),
            'closer_horario_sabado'               => self::get_closer_horario_sabado(),
            'closer_horario_domingo'              => self::get_closer_horario_domingo(),
            'demo_horario_lunes_viernes'          => self::get_demo_horario_lunes_viernes(),
            'demo_horario_sabado'                 => self::get_demo_horario_sabado(),
            'demo_horario_domingo'                => self::get_demo_horario_domingo(),
            'llamada_debe_terminar_en_horario'    => self::get_llamada_debe_terminar_en_horario(),
            'frecuencia_slots_minutos'            => self::get_frecuencia_slots_minutos(),
            'ingreso_timeout_minutos'             => self::get_ingreso_timeout_minutos(),
            'fin_seguimiento_minutos'             => self::get_fin_seguimiento_minutos(),
            'fin_timeout_minutos'                 => self::get_fin_timeout_minutos(),
            'pendiente_ingreso_horas_timeout'     => self::get_pendiente_ingreso_horas_timeout(),
            'pendiente_terminar_timeout_minutos'  => self::get_pendiente_terminar_timeout_minutos(),
            'fin_check_silencio_minutos'          => self::get_fin_check_silencio_minutos(),
            'fin_check_demora_default_minutos'    => self::get_fin_check_demora_default_minutos(),
            'experiencia_default'                 => self::get_experiencia_default(),
        ];
    }

    /**
     * Persiste la configuración validada desde admin-spa.
     *
     * @param array<string, mixed> $data Campos del formulario.
     *
     * @return void
     */
    public static function persist_from_request(array $data): void
    {
        AdminSetting::set(self::KEY_DURACION_MINUTOS,                (string) self::clamp((int) $data['duracion_minutos']));
        AdminSetting::set(self::KEY_SETUP_MINUTOS_ANTES,             (string) self::clamp((int) $data['setup_minutos_antes']));

        // Timeout del setup colgado (mision 60): opcional, mismo criterio que los campos de abajo.
        // El SPA todavia no lo manda y una version vieja del front no tiene que borrar el valor.
        if (isset($data['setup_timeout_minutos'])) {
            AdminSetting::set(self::KEY_SETUP_TIMEOUT_MINUTOS, (string) self::clamp_setup_timeout((int) $data['setup_timeout_minutos']));
        }

        // Margen minimo para ofrecer horarios de HOY (grupo 306, prompt 02): opcional a proposito,
        // igual que las franjas de demo del prompt 01 -- el SPA todavia no manda este campo.
        if (isset($data['demo_minimo_minutos_desde_ahora'])) {
            AdminSetting::set(self::KEY_DEMO_MINIMO_MINUTOS_DESDE_AHORA, (string) self::clamp((int) $data['demo_minimo_minutos_desde_ahora']));
        }

        // Umbral del video de introduccion (mision 46): opcional por el mismo motivo que el campo
        // de arriba -- una version anterior del SPA que no lo mande no tiene que borrar el valor.
        if (isset($data['demo_intro_umbral_pct'])) {
            AdminSetting::set(self::KEY_DEMO_INTRO_UMBRAL_PCT, (string) self::clamp_pct((int) $data['demo_intro_umbral_pct']));
        }

        // Tope de la ventana extendida (mision 47), en horas. "sometimes" por el mismo motivo.
        if (isset($data['demo_ventana_extendida_max_horas'])) {
            AdminSetting::set(self::KEY_VENTANA_EXTENDIDA_MAX_HORAS, (string) self::clamp_horas_ventana((int) $data['demo_ventana_extendida_max_horas']));
        }
        AdminSetting::set(self::KEY_GRACIA_MINUTOS_POST,             (string) self::clamp((int) $data['gracia_minutos_post']));
        AdminSetting::set(self::KEY_RECORDATORIO_MINUTOS_ANTES,      (string) self::clamp((int) $data['recordatorio_minutos_antes']));

        // Hora del recordatorio de mañana: string H:i validado; si es inválido, se ignora el cambio.
        if (isset($data['recordatorio_manana_hora']) && self::is_valid_hora_format((string) $data['recordatorio_manana_hora'])) {
            AdminSetting::set(self::KEY_RECORDATORIO_MANANA_HORA, (string) $data['recordatorio_manana_hora']);
        }

        AdminSetting::set(self::KEY_CHECK_INGRESO_MINUTOS_POST,      (string) self::clamp((int) $data['check_ingreso_minutos_post']));
        AdminSetting::set(self::KEY_RESUMEN_MINUTOS_ANTES_FIN,       (string) self::clamp((int) $data['resumen_minutos_antes_fin']));
        AdminSetting::set(self::KEY_DURACION_LLAMADA_CLOSER_MINUTOS, (string) self::clamp((int) $data['duracion_llamada_closer_minutos']));

        // Horario lunes a viernes: validar ambos extremos del rango H:i-H:i; ignorar si alguno es inválido.
        if (isset($data['closer_horario_lunes_viernes'])) {
            $parts = explode('-', (string) $data['closer_horario_lunes_viernes']);
            if (count($parts) === 2 && self::is_valid_hora_format($parts[0]) && self::is_valid_hora_format($parts[1])) {
                AdminSetting::set(self::KEY_CLOSER_HORARIO_LUNES_VIERNES, (string) $data['closer_horario_lunes_viernes']);
            }
        }

        // Horario sábado: vacío es válido (no trabaja); si no vacío, validar rango H:i-H:i.
        if (isset($data['closer_horario_sabado'])) {
            $val = (string) $data['closer_horario_sabado'];
            if ($val === '') {
                AdminSetting::set(self::KEY_CLOSER_HORARIO_SABADO, '');
            } else {
                $parts = explode('-', $val);
                if (count($parts) === 2 && self::is_valid_hora_format($parts[0]) && self::is_valid_hora_format($parts[1])) {
                    AdminSetting::set(self::KEY_CLOSER_HORARIO_SABADO, $val);
                }
            }
        }

        // Horario domingo: vacío es válido (no trabaja); si no vacío, validar rango H:i-H:i.
        if (isset($data['closer_horario_domingo'])) {
            $val = (string) $data['closer_horario_domingo'];
            if ($val === '') {
                AdminSetting::set(self::KEY_CLOSER_HORARIO_DOMINGO, '');
            } else {
                $parts = explode('-', $val);
                if (count($parts) === 2 && self::is_valid_hora_format($parts[0]) && self::is_valid_hora_format($parts[1])) {
                    AdminSetting::set(self::KEY_CLOSER_HORARIO_DOMINGO, $val);
                }
            }
        }

        // Franja de la demo lunes a viernes: validar ambos extremos del rango H:i-H:i; ignorar si alguno es inválido.
        if (isset($data['demo_horario_lunes_viernes'])) {
            $parts = explode('-', (string) $data['demo_horario_lunes_viernes']);
            if (count($parts) === 2 && self::is_valid_hora_format($parts[0]) && self::is_valid_hora_format($parts[1])) {
                AdminSetting::set(self::KEY_DEMO_HORARIO_LUNES_VIERNES, (string) $data['demo_horario_lunes_viernes']);
            }
        }

        // Franja de la demo los sábados: validar ambos extremos del rango H:i-H:i; ignorar si alguno es inválido.
        if (isset($data['demo_horario_sabado'])) {
            $parts = explode('-', (string) $data['demo_horario_sabado']);
            if (count($parts) === 2 && self::is_valid_hora_format($parts[0]) && self::is_valid_hora_format($parts[1])) {
                AdminSetting::set(self::KEY_DEMO_HORARIO_SABADO, (string) $data['demo_horario_sabado']);
            }
        }

        // Franja de la demo los domingos: validar ambos extremos del rango H:i-H:i; ignorar si alguno es inválido.
        if (isset($data['demo_horario_domingo'])) {
            $parts = explode('-', (string) $data['demo_horario_domingo']);
            if (count($parts) === 2 && self::is_valid_hora_format($parts[0]) && self::is_valid_hora_format($parts[1])) {
                AdminSetting::set(self::KEY_DEMO_HORARIO_DOMINGO, (string) $data['demo_horario_domingo']);
            }
        }

        // Checkbox: castear a bool y guardar "1" o "0".
        if (isset($data['llamada_debe_terminar_en_horario'])) {
            AdminSetting::set(self::KEY_LLAMADA_DEBE_TERMINAR_EN_HORARIO, $data['llamada_debe_terminar_en_horario'] ? '1' : '0');
        }

        // Frecuencia de slots: solo aceptar valores del conjunto válido.
        if (isset($data['frecuencia_slots_minutos'])) {
            $freq = (int) $data['frecuencia_slots_minutos'];
            if (in_array($freq, self::VALID_FRECUENCIA_SLOTS, true)) {
                AdminSetting::set(self::KEY_FRECUENCIA_SLOTS_MINUTOS, (string) $freq);
            }
        }

        // Timeouts y seguimiento del ciclo de demo automatizado.
        AdminSetting::set(self::KEY_INGRESO_TIMEOUT_MINUTOS,  (string) self::clamp((int) $data['ingreso_timeout_minutos']));
        AdminSetting::set(self::KEY_FIN_SEGUIMIENTO_MINUTOS,  (string) self::clamp((int) $data['fin_seguimiento_minutos']));
        AdminSetting::set(self::KEY_FIN_TIMEOUT_MINUTOS,      (string) self::clamp((int) $data['fin_timeout_minutos']));

        // Horas sin ingreso antes de revertir demo_pendiente_de_ingreso → calificado (rango propio 1–720, no usa clamp de minutos).
        AdminSetting::set(
            self::KEY_PENDIENTE_INGRESO_HORAS_TIMEOUT,
            (string) self::clamp_pendiente_ingreso_horas((int) ($data['pendiente_ingreso_horas_timeout'] ?? self::DEFAULT_PENDIENTE_INGRESO_HORAS_TIMEOUT))
        );

        AdminSetting::set(self::KEY_PENDIENTE_TERMINAR_TIMEOUT_MINUTOS, (string) self::clamp((int) ($data['pendiente_terminar_timeout_minutos'] ?? self::DEFAULT_PENDIENTE_TERMINAR_TIMEOUT_MINUTOS)));

        // Ventana de "conversación viva" y demora por defecto al posponer el check de fin (grupo
        // 307, prompt 01). "isset" y NO obligatorias: el SPA todavía no manda estas claves.
        if (isset($data['fin_check_silencio_minutos'])) {
            AdminSetting::set(self::KEY_FIN_CHECK_SILENCIO_MINUTOS, (string) self::clamp((int) $data['fin_check_silencio_minutos']));
        }
        if (isset($data['fin_check_demora_default_minutos'])) {
            AdminSetting::set(self::KEY_FIN_CHECK_DEMORA_DEFAULT_MINUTOS, (string) self::clamp((int) $data['fin_check_demora_default_minutos']));
        }

        // Dinámica de demo default para leads nuevos: guarda isset obligatoria, el SPA todavía no
        // manda este campo (lo agrega el prompt 04) y el update() de settings tiene que seguir
        // funcionando igual en el intervalo entre el deploy del backend y el del front.
        if (isset($data['experiencia_default'])) {
            $experiencia = (string) $data['experiencia_default'];
            if (in_array($experiencia, self::VALID_EXPERIENCIAS, true)) {
                AdminSetting::set(self::KEY_EXPERIENCIA_DEFAULT, $experiencia);
            }
        }
    }

    /**
     * Siembra valores por defecto si aún no existen en BD.
     *
     * @return void
     */
    public static function seed_defaults_if_missing(): void
    {
        if (AdminSetting::get(self::KEY_DURACION_MINUTOS) === null) {
            AdminSetting::set(self::KEY_DURACION_MINUTOS, (string) self::DEFAULT_DURACION_MINUTOS);
        }
        if (AdminSetting::get(self::KEY_SETUP_MINUTOS_ANTES) === null) {
            AdminSetting::set(self::KEY_SETUP_MINUTOS_ANTES, (string) self::DEFAULT_SETUP_MINUTOS_ANTES);
        }
        if (AdminSetting::get(self::KEY_SETUP_TIMEOUT_MINUTOS) === null) {
            AdminSetting::set(self::KEY_SETUP_TIMEOUT_MINUTOS, (string) self::DEFAULT_SETUP_TIMEOUT_MINUTOS);
        }
        if (AdminSetting::get(self::KEY_DEMO_MINIMO_MINUTOS_DESDE_AHORA) === null) {
            AdminSetting::set(self::KEY_DEMO_MINIMO_MINUTOS_DESDE_AHORA, (string) self::DEFAULT_DEMO_MINIMO_MINUTOS_DESDE_AHORA);
        }
        if (AdminSetting::get(self::KEY_DEMO_INTRO_UMBRAL_PCT) === null) {
            AdminSetting::set(self::KEY_DEMO_INTRO_UMBRAL_PCT, (string) self::DEFAULT_DEMO_INTRO_UMBRAL_PCT);
        }
        if (AdminSetting::get(self::KEY_VENTANA_EXTENDIDA_MAX_HORAS) === null) {
            AdminSetting::set(self::KEY_VENTANA_EXTENDIDA_MAX_HORAS, (string) self::DEFAULT_VENTANA_EXTENDIDA_MAX_HORAS);
        }
        if (AdminSetting::get(self::KEY_GRACIA_MINUTOS_POST) === null) {
            AdminSetting::set(self::KEY_GRACIA_MINUTOS_POST, (string) self::DEFAULT_GRACIA_MINUTOS_POST);
        }
        if (AdminSetting::get(self::KEY_RECORDATORIO_MINUTOS_ANTES) === null) {
            AdminSetting::set(self::KEY_RECORDATORIO_MINUTOS_ANTES, (string) self::DEFAULT_RECORDATORIO_MINUTOS_ANTES);
        }
        if (AdminSetting::get(self::KEY_RECORDATORIO_MANANA_HORA) === null) {
            AdminSetting::set(self::KEY_RECORDATORIO_MANANA_HORA, self::DEFAULT_RECORDATORIO_MANANA_HORA);
        }
        if (AdminSetting::get(self::KEY_CHECK_INGRESO_MINUTOS_POST) === null) {
            AdminSetting::set(self::KEY_CHECK_INGRESO_MINUTOS_POST, (string) self::DEFAULT_CHECK_INGRESO_MINUTOS_POST);
        }
        if (AdminSetting::get(self::KEY_RESUMEN_MINUTOS_ANTES_FIN) === null) {
            AdminSetting::set(self::KEY_RESUMEN_MINUTOS_ANTES_FIN, (string) self::DEFAULT_RESUMEN_MINUTOS_ANTES_FIN);
        }
        if (AdminSetting::get(self::KEY_DURACION_LLAMADA_CLOSER_MINUTOS) === null) {
            AdminSetting::set(self::KEY_DURACION_LLAMADA_CLOSER_MINUTOS, (string) self::DEFAULT_DURACION_LLAMADA_CLOSER_MINUTOS);
        }
        if (AdminSetting::get(self::KEY_CLOSER_HORARIO_LUNES_VIERNES) === null) {
            AdminSetting::set(self::KEY_CLOSER_HORARIO_LUNES_VIERNES, self::DEFAULT_CLOSER_HORARIO_LUNES_VIERNES);
        }
        if (AdminSetting::get(self::KEY_CLOSER_HORARIO_SABADO) === null) {
            AdminSetting::set(self::KEY_CLOSER_HORARIO_SABADO, self::DEFAULT_CLOSER_HORARIO_SABADO);
        }
        if (AdminSetting::get(self::KEY_CLOSER_HORARIO_DOMINGO) === null) {
            AdminSetting::set(self::KEY_CLOSER_HORARIO_DOMINGO, self::DEFAULT_CLOSER_HORARIO_DOMINGO);
        }
        if (AdminSetting::get(self::KEY_DEMO_HORARIO_LUNES_VIERNES) === null) {
            AdminSetting::set(self::KEY_DEMO_HORARIO_LUNES_VIERNES, self::DEFAULT_DEMO_HORARIO_LUNES_VIERNES);
        }
        if (AdminSetting::get(self::KEY_DEMO_HORARIO_SABADO) === null) {
            AdminSetting::set(self::KEY_DEMO_HORARIO_SABADO, self::DEFAULT_DEMO_HORARIO_SABADO);
        }
        if (AdminSetting::get(self::KEY_DEMO_HORARIO_DOMINGO) === null) {
            AdminSetting::set(self::KEY_DEMO_HORARIO_DOMINGO, self::DEFAULT_DEMO_HORARIO_DOMINGO);
        }
        if (AdminSetting::get(self::KEY_LLAMADA_DEBE_TERMINAR_EN_HORARIO) === null) {
            AdminSetting::set(self::KEY_LLAMADA_DEBE_TERMINAR_EN_HORARIO, self::DEFAULT_LLAMADA_DEBE_TERMINAR_EN_HORARIO);
        }
        if (AdminSetting::get(self::KEY_FRECUENCIA_SLOTS_MINUTOS) === null) {
            AdminSetting::set(self::KEY_FRECUENCIA_SLOTS_MINUTOS, (string) self::DEFAULT_FRECUENCIA_SLOTS_MINUTOS);
        }
        if (AdminSetting::get(self::KEY_INGRESO_TIMEOUT_MINUTOS) === null) {
            AdminSetting::set(self::KEY_INGRESO_TIMEOUT_MINUTOS, (string) self::DEFAULT_INGRESO_TIMEOUT_MINUTOS);
        }
        if (AdminSetting::get(self::KEY_FIN_SEGUIMIENTO_MINUTOS) === null) {
            AdminSetting::set(self::KEY_FIN_SEGUIMIENTO_MINUTOS, (string) self::DEFAULT_FIN_SEGUIMIENTO_MINUTOS);
        }
        if (AdminSetting::get(self::KEY_FIN_TIMEOUT_MINUTOS) === null) {
            AdminSetting::set(self::KEY_FIN_TIMEOUT_MINUTOS, (string) self::DEFAULT_FIN_TIMEOUT_MINUTOS);
        }
        if (AdminSetting::get(self::KEY_PENDIENTE_INGRESO_HORAS_TIMEOUT) === null) {
            AdminSetting::set(self::KEY_PENDIENTE_INGRESO_HORAS_TIMEOUT, (string) self::DEFAULT_PENDIENTE_INGRESO_HORAS_TIMEOUT);
        }
        if (AdminSetting::get(self::KEY_PENDIENTE_TERMINAR_TIMEOUT_MINUTOS) === null) {
            AdminSetting::set(self::KEY_PENDIENTE_TERMINAR_TIMEOUT_MINUTOS, (string) self::DEFAULT_PENDIENTE_TERMINAR_TIMEOUT_MINUTOS);
        }
        if (AdminSetting::get(self::KEY_FIN_CHECK_SILENCIO_MINUTOS) === null) {
            AdminSetting::set(self::KEY_FIN_CHECK_SILENCIO_MINUTOS, (string) self::DEFAULT_FIN_CHECK_SILENCIO_MINUTOS);
        }
        if (AdminSetting::get(self::KEY_FIN_CHECK_DEMORA_DEFAULT_MINUTOS) === null) {
            AdminSetting::set(self::KEY_FIN_CHECK_DEMORA_DEFAULT_MINUTOS, (string) self::DEFAULT_FIN_CHECK_DEMORA_DEFAULT_MINUTOS);
        }
    }

    /**
     * Duración estimada de la demo en minutos.
     *
     * @return int
     */
    public static function get_duracion_minutos(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_DURACION_MINUTOS, (string) self::DEFAULT_DURACION_MINUTOS));
    }

    /**
     * Minutos antes del inicio para correr demo setup automático.
     *
     * @return int
     */
    public static function get_setup_minutos_antes(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_SETUP_MINUTOS_ANTES, (string) self::DEFAULT_SETUP_MINUTOS_ANTES));
    }

    /**
     * Minutos que un setup puede pasar en `ejecutandose` antes de darse por colgado (misión 60).
     *
     * Tiene clamp propio con mínimo 1 y no el de minutos, que arranca en 0: con 0 el comando de
     * vencimiento le pisaría el estado a un setup que acaba de arrancar y todavía está corriendo
     * bien, y ese es exactamente el caso en el que un reintento hace daño.
     *
     * @return int
     */
    public static function get_setup_timeout_minutos(): int
    {
        return self::clamp_setup_timeout((int) AdminSetting::get(self::KEY_SETUP_TIMEOUT_MINUTOS, (string) self::DEFAULT_SETUP_TIMEOUT_MINUTOS));
    }

    /**
     * Margen mínimo, en minutos desde ahora, para ofrecer un horario de demo HOY.
     *
     * Solo se consulta para leads en la dinámica nueva (grupo 306, prompt 02).
     *
     * @return int
     */
    public static function get_demo_minimo_minutos_desde_ahora(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_DEMO_MINIMO_MINUTOS_DESDE_AHORA, (string) self::DEFAULT_DEMO_MINIMO_MINUTOS_DESDE_AHORA));
    }

    /**
     * Porcentaje del video de introducción que el lead tiene que haber visto para que se le
     * habilite el ingreso a la demo (misión 46, pieza 3).
     *
     * @return int
     */
    public static function get_demo_intro_umbral_pct(): int
    {
        return self::clamp_pct((int) AdminSetting::get(self::KEY_DEMO_INTRO_UMBRAL_PCT, (string) self::DEFAULT_DEMO_INTRO_UMBRAL_PCT));
    }

    /**
     * Tope de la ventana extendida, en horas (misión 47).
     *
     * @return int
     */
    public static function get_ventana_extendida_max_horas(): int
    {
        return self::clamp_horas_ventana((int) AdminSetting::get(self::KEY_VENTANA_EXTENDIDA_MAX_HORAS, (string) self::DEFAULT_VENTANA_EXTENDIDA_MAX_HORAS));
    }

    /**
     * Minutos de gracia post-demo para liberar el slot de disponibilidad.
     *
     * @return int
     */
    public static function get_gracia_minutos_post(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_GRACIA_MINUTOS_POST, (string) self::DEFAULT_GRACIA_MINUTOS_POST));
    }

    /**
     * Minutos antes del inicio para enviar recordatorio por WhatsApp al lead.
     *
     * @return int
     */
    public static function get_recordatorio_minutos_antes(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_RECORDATORIO_MINUTOS_ANTES, (string) self::DEFAULT_RECORDATORIO_MINUTOS_ANTES));
    }

    /**
     * Hora del recordatorio de mañana de demo (formato H:i, timezone Argentina).
     *
     * @return string
     */
    public static function get_recordatorio_manana_hora(): string
    {
        $stored = (string) AdminSetting::get(self::KEY_RECORDATORIO_MANANA_HORA, self::DEFAULT_RECORDATORIO_MANANA_HORA);

        if (self::is_valid_hora_format($stored)) {
            return $stored;
        }

        return self::DEFAULT_RECORDATORIO_MANANA_HORA;
    }

    /**
     * Minutos después del inicio para preguntar al lead si pudo ingresar a la demo.
     *
     * @return int
     */
    public static function get_check_ingreso_minutos_post(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_CHECK_INGRESO_MINUTOS_POST, (string) self::DEFAULT_CHECK_INGRESO_MINUTOS_POST));
    }

    /**
     * Minutos antes del fin de la demo para generar resumen del lead para el closer.
     *
     * @return int
     */
    public static function get_resumen_minutos_antes_fin(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_RESUMEN_MINUTOS_ANTES_FIN, (string) self::DEFAULT_RESUMEN_MINUTOS_ANTES_FIN));
    }

    /**
     * Duración de la llamada del closer post-demo en minutos.
     *
     * El closer queda ocupado desde el fin de la gracia hasta fin + este valor.
     * Ningún otro lead puede liberar su demo en esa ventana.
     *
     * @return int
     */
    public static function get_duracion_llamada_closer_minutos(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_DURACION_LLAMADA_CLOSER_MINUTOS, (string) self::DEFAULT_DURACION_LLAMADA_CLOSER_MINUTOS));
    }

    /**
     * Horario laboral del closer de lunes a viernes (formato H:i-H:i).
     *
     * Devuelve el valor almacenado si es válido; de lo contrario, el default.
     *
     * @return string
     */
    public static function get_closer_horario_lunes_viernes(): string
    {
        $stored = (string) AdminSetting::get(self::KEY_CLOSER_HORARIO_LUNES_VIERNES, self::DEFAULT_CLOSER_HORARIO_LUNES_VIERNES);
        $parts  = explode('-', $stored);

        if (count($parts) === 2 && self::is_valid_hora_format($parts[0]) && self::is_valid_hora_format($parts[1])) {
            return $stored;
        }

        return self::DEFAULT_CLOSER_HORARIO_LUNES_VIERNES;
    }

    /**
     * Horario laboral del closer los sábados (formato H:i-H:i o vacío si no trabaja).
     *
     * @return string
     */
    public static function get_closer_horario_sabado(): string
    {
        $stored = (string) AdminSetting::get(self::KEY_CLOSER_HORARIO_SABADO, self::DEFAULT_CLOSER_HORARIO_SABADO);

        if ($stored === '') {
            return '';
        }

        $parts = explode('-', $stored);

        if (count($parts) === 2 && self::is_valid_hora_format($parts[0]) && self::is_valid_hora_format($parts[1])) {
            return $stored;
        }

        return self::DEFAULT_CLOSER_HORARIO_SABADO;
    }

    /**
     * Horario laboral del closer los domingos (formato H:i-H:i o vacío si no trabaja).
     *
     * @return string
     */
    public static function get_closer_horario_domingo(): string
    {
        $stored = (string) AdminSetting::get(self::KEY_CLOSER_HORARIO_DOMINGO, self::DEFAULT_CLOSER_HORARIO_DOMINGO);

        if ($stored === '') {
            return '';
        }

        $parts = explode('-', $stored);

        if (count($parts) === 2 && self::is_valid_hora_format($parts[0]) && self::is_valid_hora_format($parts[1])) {
            return $stored;
        }

        return self::DEFAULT_CLOSER_HORARIO_DOMINGO;
    }

    /**
     * Franja horaria propia de la demo de lunes a viernes (formato H:i-H:i), independiente del
     * horario del closer.
     *
     * @return string
     */
    public static function get_demo_horario_lunes_viernes(): string
    {
        $stored = (string) AdminSetting::get(self::KEY_DEMO_HORARIO_LUNES_VIERNES, self::DEFAULT_DEMO_HORARIO_LUNES_VIERNES);
        $parts  = explode('-', $stored);

        if (count($parts) === 2 && self::is_valid_hora_format($parts[0]) && self::is_valid_hora_format($parts[1])) {
            return $stored;
        }

        return self::DEFAULT_DEMO_HORARIO_LUNES_VIERNES;
    }

    /**
     * Franja horaria propia de la demo los sábados (formato H:i-H:i), independiente del horario
     * del closer.
     *
     * @return string
     */
    public static function get_demo_horario_sabado(): string
    {
        $stored = (string) AdminSetting::get(self::KEY_DEMO_HORARIO_SABADO, self::DEFAULT_DEMO_HORARIO_SABADO);
        $parts  = explode('-', $stored);

        if (count($parts) === 2 && self::is_valid_hora_format($parts[0]) && self::is_valid_hora_format($parts[1])) {
            return $stored;
        }

        return self::DEFAULT_DEMO_HORARIO_SABADO;
    }

    /**
     * Franja horaria propia de la demo los domingos (formato H:i-H:i), independiente del horario
     * del closer.
     *
     * @return string
     */
    public static function get_demo_horario_domingo(): string
    {
        $stored = (string) AdminSetting::get(self::KEY_DEMO_HORARIO_DOMINGO, self::DEFAULT_DEMO_HORARIO_DOMINGO);
        $parts  = explode('-', $stored);

        if (count($parts) === 2 && self::is_valid_hora_format($parts[0]) && self::is_valid_hora_format($parts[1])) {
            return $stored;
        }

        return self::DEFAULT_DEMO_HORARIO_DOMINGO;
    }

    /**
     * Indica si la llamada del closer debe terminar dentro de su horario laboral.
     *
     * Devuelve true cuando el valor almacenado es "1".
     *
     * @return bool
     */
    public static function get_llamada_debe_terminar_en_horario(): bool
    {
        return AdminSetting::get(self::KEY_LLAMADA_DEBE_TERMINAR_EN_HORARIO, self::DEFAULT_LLAMADA_DEBE_TERMINAR_EN_HORARIO) === '1';
    }

    /**
     * Frecuencia en minutos con que se generan los slots disponibles.
     *
     * Solo acepta valores del conjunto VALID_FRECUENCIA_SLOTS; si el almacenado es inválido, devuelve el default.
     *
     * @return int
     */
    public static function get_frecuencia_slots_minutos(): int
    {
        $stored = (int) AdminSetting::get(self::KEY_FRECUENCIA_SLOTS_MINUTOS, (string) self::DEFAULT_FRECUENCIA_SLOTS_MINUTOS);

        if (in_array($stored, self::VALID_FRECUENCIA_SLOTS, true)) {
            return $stored;
        }

        return self::DEFAULT_FRECUENCIA_SLOTS_MINUTOS;
    }

    /**
     * Minutos sin respuesta al check de ingreso antes de marcar demo_pendiente_de_ingreso.
     *
     * @return int
     */
    public static function get_ingreso_timeout_minutos(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_INGRESO_TIMEOUT_MINUTOS, (string) self::DEFAULT_INGRESO_TIMEOUT_MINUTOS));
    }

    /**
     * Minutos desde el check de fin antes de enviar el seguimiento de "¿pudiste terminar?".
     *
     * @return int
     */
    public static function get_fin_seguimiento_minutos(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_FIN_SEGUIMIENTO_MINUTOS, (string) self::DEFAULT_FIN_SEGUIMIENTO_MINUTOS));
    }

    /**
     * Minutos desde el check de fin antes de marcar demo_pendiente_de_terminar.
     *
     * Conceptualmente debe ser mayor que fin_seguimiento_minutos (no se valida de forma cruzada).
     *
     * @return int
     */
    public static function get_fin_timeout_minutos(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_FIN_TIMEOUT_MINUTOS, (string) self::DEFAULT_FIN_TIMEOUT_MINUTOS));
    }

    /**
     * Horas desde el horario de la demo sin ingreso confirmado antes de revertir el lead a calificado.
     *
     * Rango: 1–720 horas (1 hora mínimo, 30 días máximo).
     *
     * @return int
     */
    public static function get_pendiente_ingreso_horas_timeout(): int
    {
        $stored = (int) AdminSetting::get(self::KEY_PENDIENTE_INGRESO_HORAS_TIMEOUT, (string) self::DEFAULT_PENDIENTE_INGRESO_HORAS_TIMEOUT);

        return self::clamp_pendiente_ingreso_horas($stored);
    }

    /**
     * Minutos desde el final de la demo antes de pasar demo_pendiente_de_terminar → closer_activo.
     *
     * Rango: 0–240 minutos.
     *
     * @return int
     */
    public static function get_pendiente_terminar_timeout_minutos(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_PENDIENTE_TERMINAR_TIMEOUT_MINUTOS, (string) self::DEFAULT_PENDIENTE_TERMINAR_TIMEOUT_MINUTOS));
    }

    /**
     * Ventana de "conversación viva" para el check de fin de demo, en minutos.
     *
     * Si hubo un mensaje entrante o saliente dentro de esta ventana (o hay una sugerencia
     * pendiente), `CheckDemoFin` pospone en vez de enviar.
     *
     * @return int
     */
    public static function get_fin_check_silencio_minutos(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_FIN_CHECK_SILENCIO_MINUTOS, (string) self::DEFAULT_FIN_CHECK_SILENCIO_MINUTOS));
    }

    /**
     * Demora, en minutos, al posponer el check de fin de demo cuando nadie indicó cuánto.
     *
     * @return int
     */
    public static function get_fin_check_demora_default_minutos(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_FIN_CHECK_DEMORA_DEFAULT_MINUTOS, (string) self::DEFAULT_FIN_CHECK_DEMORA_DEFAULT_MINUTOS));
    }

    /**
     * Minutos antes del inicio de la llamada del closer para enviar el bot de Recall.ai a la reunión.
     *
     * @return int
     */
    public static function get_recall_bot_minutos_antes(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_RECALL_BOT_MINUTOS_ANTES, (string) self::DEFAULT_RECALL_BOT_MINUTOS_ANTES));
    }

    /**
     * Dinámica de demo con la que se estampan los leads nuevos al crearse.
     *
     * Si el valor guardado en `admin_settings` no está en VALID_EXPERIENCIAS (setting corrupta o
     * nunca configurada), devuelve 'actual': un valor basura acá nunca puede terminar sirviéndole
     * al agente una variante que no existe.
     *
     * @return string
     */
    public static function get_experiencia_default(): string
    {
        $stored = (string) AdminSetting::get(self::KEY_EXPERIENCIA_DEFAULT, self::DEFAULT_EXPERIENCIA);

        if (in_array($stored, self::VALID_EXPERIENCIAS, true)) {
            return $stored;
        }

        return self::DEFAULT_EXPERIENCIA;
    }

    /**
     * Acota un valor entero al rango permitido [MIN_MINUTOS, MAX_MINUTOS].
     *
     * @param int $value
     *
     * @return int
     */
    private static function clamp(int $value): int
    {
        if ($value < self::MIN_MINUTOS) {
            return self::MIN_MINUTOS;
        }
        if ($value > self::MAX_MINUTOS) {
            return self::MAX_MINUTOS;
        }

        return $value;
    }

    /**
     * Acota un porcentaje al rango permitido [MIN_PCT, MAX_PCT].
     *
     * Clamp propio y no clamp(): el de minutos acota a 240, así que un valor de 100 pasaría igual
     * y el bug recién aparecería el día que alguien cambie MAX_MINUTOS. Ver el comentario de
     * KEY_DEMO_INTRO_UMBRAL_PCT.
     *
     * @param int $value Valor en porcentaje.
     *
     * @return int
     */
    private static function clamp_pct(int $value): int
    {
        if ($value < self::MIN_PCT) {
            return self::MIN_PCT;
        }
        if ($value > self::MAX_PCT) {
            return self::MAX_PCT;
        }

        return $value;
    }

    /**
     * Acota el tope de la ventana extendida al rango [MIN_HORAS_VENTANA, MAX_HORAS_VENTANA].
     *
     * Clamp propio: son horas, no minutos ni porcentaje, y el mínimo es 1 y no 0 — una ventana
     * extendida de cero horas no es una ventana, es un turno normal mal escrito.
     *
     * @param int $value Valor en horas.
     *
     * @return int
     */
    private static function clamp_horas_ventana(int $value): int
    {
        if ($value < self::MIN_HORAS_VENTANA) {
            return self::MIN_HORAS_VENTANA;
        }
        if ($value > self::MAX_HORAS_VENTANA) {
            return self::MAX_HORAS_VENTANA;
        }

        return $value;
    }

    /**
     * Acota el timeout del setup colgado al rango [1, MAX_MINUTOS] (misión 60).
     *
     * El mínimo es 1 y no 0 a propósito: ver el docblock de `get_setup_timeout_minutos()`.
     *
     * @param int $value Valor en minutos.
     *
     * @return int
     */
    private static function clamp_setup_timeout(int $value): int
    {
        if ($value < 1) {
            return 1;
        }
        if ($value > self::MAX_MINUTOS) {
            return self::MAX_MINUTOS;
        }

        return $value;
    }

    /**
     * Acota las horas de timeout de demo_pendiente_de_ingreso al rango 1–720.
     *
     * @param int $value Valor en horas.
     *
     * @return int
     */
    private static function clamp_pendiente_ingreso_horas(int $value): int
    {
        if ($value < 1) {
            return 1;
        }
        if ($value > 720) {
            return 720;
        }

        return $value;
    }

    /**
     * Valida que un string tenga formato de hora H:i (ej. 09:00).
     *
     * @param string $value Valor a validar.
     *
     * @return bool
     */
    private static function is_valid_hora_format(string $value): bool
    {
        return \DateTime::createFromFormat('H:i', $value) !== false;
    }
}
