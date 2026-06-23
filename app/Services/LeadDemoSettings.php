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

    /** Clave: minutos antes del inicio de la llamada del closer para enviar el bot de Recall.ai. */
    public const KEY_RECALL_BOT_MINUTOS_ANTES = 'recall_bot_minutos_antes';

    /** Valor por defecto: duración de la demo (minutos). */
    private const DEFAULT_DURACION_MINUTOS = 60;

    /** Valor por defecto: setup antes del inicio (minutos). */
    private const DEFAULT_SETUP_MINUTOS_ANTES = 15;

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

    /** Valor por defecto: minutos antes de la llamada del closer para enviar el bot de Recall.ai. */
    private const DEFAULT_RECALL_BOT_MINUTOS_ANTES = 5;

    /** Valores válidos para la frecuencia de slots (minutos). */
    public const VALID_FRECUENCIA_SLOTS = [5, 10, 15, 30, 60];

    /** Mínimo permitido para todos los parámetros (minutos). */
    public const MIN_MINUTOS = 0;

    /** Máximo permitido para todos los parámetros (minutos). */
    public const MAX_MINUTOS = 240;

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
            'gracia_minutos_post'                 => self::get_gracia_minutos_post(),
            'recordatorio_minutos_antes'          => self::get_recordatorio_minutos_antes(),
            'recordatorio_manana_hora'            => self::get_recordatorio_manana_hora(),
            'check_ingreso_minutos_post'          => self::get_check_ingreso_minutos_post(),
            'resumen_minutos_antes_fin'           => self::get_resumen_minutos_antes_fin(),
            'duracion_llamada_closer_minutos'     => self::get_duracion_llamada_closer_minutos(),
            'closer_horario_lunes_viernes'        => self::get_closer_horario_lunes_viernes(),
            'closer_horario_sabado'               => self::get_closer_horario_sabado(),
            'closer_horario_domingo'              => self::get_closer_horario_domingo(),
            'llamada_debe_terminar_en_horario'    => self::get_llamada_debe_terminar_en_horario(),
            'frecuencia_slots_minutos'            => self::get_frecuencia_slots_minutos(),
            'ingreso_timeout_minutos'             => self::get_ingreso_timeout_minutos(),
            'fin_seguimiento_minutos'             => self::get_fin_seguimiento_minutos(),
            'fin_timeout_minutos'                 => self::get_fin_timeout_minutos(),
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
     * Minutos antes del inicio de la llamada del closer para enviar el bot de Recall.ai a la reunión.
     *
     * @return int
     */
    public static function get_recall_bot_minutos_antes(): int
    {
        return self::clamp((int) AdminSetting::get(self::KEY_RECALL_BOT_MINUTOS_ANTES, (string) self::DEFAULT_RECALL_BOT_MINUTOS_ANTES));
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
