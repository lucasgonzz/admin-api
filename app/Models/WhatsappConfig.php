<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuración de la integración WhatsApp vía Kapso (wrapper Meta Cloud API).
 *
 * 🔴 **Desde el 13/10/2026 hay una fila por PROPÓSITO, no una sola fila** (misión
 * whatsapp-dos-numeros). El `principal` es el número institucional —leads, soporte,
 * implementación, avisos del sistema— y el `asistente` es el número por el que cada dueño habla
 * con el asistente de IA de su propio sistema. Son dos números de WhatsApp distintos, con su
 * propio `phone_number_id`, su propia `kapso_api_key` y su propio secreto de webhook.
 *
 * Se espera **un registro activo por propósito**.
 */
class WhatsappConfig extends Model
{
    /**
     * Propósito del número institucional: leads, soporte, implementación y avisos del sistema.
     *
     * Es el default de la columna y el que devuelve `getActive()`. Todo lo que no se mudó
     * explícitamente al asistente sale por acá.
     */
    const PROPOSITO_PRINCIPAL = 'principal';

    /**
     * Propósito del número por el que los dueños hablan con el asistente de IA de su sistema.
     */
    const PROPOSITO_ASISTENTE = 'asistente';

    /**
     * Nombre de la tabla en base de datos.
     *
     * @var string
     */
    protected $table = 'whatsapp_config';

    /**
     * Campos asignables desde el panel admin o seeders.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'proposito',
        'kapso_api_key',
        'phone_number_id',
        'webhook_secret',
        'meta_webhook_token',
        'display_phone',
        'nombre',
        'waba_id',
        'is_active',
        'test_mode',
    ];

    /**
     * Casteos de tipos para lectura y persistencia consistente.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'test_mode' => 'boolean',
    ];

    /**
     * Devuelve el registro de configuración activo del número PRINCIPAL, o null si no existe.
     *
     * 🔴 **La firma y la semántica de este método no cambian, y eso es deliberado**: lo llaman ~20
     * lugares (leads, soporte, implementación, cobranzas, los avisos del sistema, la descarga de
     * media entrante) y ninguno de ellos se mudó al número del asistente. Lo único que se agregó es
     * el filtro por propósito, que es lo que mantiene la semántica vieja ahora que la tabla tiene
     * más de una fila: sin él, con la fila del asistente cargada, `first()` podría devolver
     * cualquiera de las dos según el orden que le dé MySQL, y los leads empezarían a salir por el
     * número del asistente sin que nada lo avise.
     *
     * Quien quiera el número del asistente usa {@see porProposito()}; quien quiera el que recibió
     * un mensaje, {@see porPhoneNumberId()}.
     *
     * @return self|null
     */
    public static function getActive(): ?self
    {
        return self::where('is_active', true)
            ->where('proposito', self::PROPOSITO_PRINCIPAL)
            ->first();
    }

    /**
     * Fila activa de un propósito.
     *
     * Devuelve null cuando ese número no está configurado, y el llamador tiene que tratarlo como
     * "ese canal no existe todavía" y no como un error: hasta que Lucas cargue el número del
     * asistente en el panel, `porProposito('asistente')` es null y el canal se comporta como antes
     * de la misión.
     *
     * @param string $proposito Uno de los PROPOSITO_* de esta clase.
     *
     * @return self|null
     */
    public static function porProposito(string $proposito): ?self
    {
        $proposito = trim($proposito);
        if ($proposito === '') {
            return null;
        }

        return self::where('is_active', true)
            ->where('proposito', $proposito)
            ->first();
    }

    /**
     * Fila activa que corresponde a un Phone Number ID de Meta.
     *
     * Es la resolución que usa el webhook: el payload de Kapso trae el `phone_number_id` del número
     * que recibió el mensaje, y de ahí sale por qué canal hay que tramitarlo.
     *
     * Devuelve null con el argumento vacío en vez de buscar `phone_number_id = ''`: hay filas con
     * la columna sin cargar (una config a medio configurar en local), y matchearlas contra un
     * payload sin dato sería rutear por casualidad.
     *
     * @param string $phone_number_id Phone Number ID de Meta.
     *
     * @return self|null
     */
    public static function porPhoneNumberId(string $phone_number_id): ?self
    {
        $phone_number_id = trim($phone_number_id);
        if ($phone_number_id === '') {
            return null;
        }

        return self::where('is_active', true)
            ->where('phone_number_id', $phone_number_id)
            ->first();
    }
}
