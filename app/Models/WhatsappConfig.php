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
     * Fila de un propósito, ACTIVA O NO. Es la que lee y escribe el panel.
     *
     * 🔴 Distinta de {@see porProposito()}, que solo devuelve la activa y es la que usa todo el resto del
     * admin. El panel necesita ver también la fila APAGADA: el interruptor de «número activo» es la
     * forma de volver atrás el segundo número sin tocar la base, y si el panel no la encontrara cuando
     * está apagada, no habría cómo volver a prenderla (y guardar crearía una fila duplicada).
     *
     * @param string $proposito Uno de los PROPOSITO_* de esta clase.
     *
     * @return self|null
     */
    public static function delProposito(string $proposito): ?self
    {
        $proposito = trim($proposito);
        if ($proposito === '') {
            return null;
        }

        return self::where('proposito', $proposito)->orderBy('id')->first();
    }

    /**
     * Fila de un propósito, SOLO si ese número ya está en condiciones de atender su canal entero.
     *
     * 🔴 **«Listo» es mucho más que «hay una fila»** (misión asistente-segundo-numero, 15/10/2026), y
     * esa diferencia es lo que garantiza que el admin no cambia NADA para los clientes actuales
     * mientras el número del asistente no esté terminado de configurar:
     *
     *   - `principal`: alcanza con la fila activa (lo que `getActive()` siempre pidió). No se le suman
     *     condiciones nuevas: el número de leads y clientes tiene que seguir funcionando con la
     *     configuración que ya tiene.
     *   - `asistente`: fila activa **con `phone_number_id`, `kapso_api_key`, `webhook_secret` y
     *     `display_phone`**. Los dos primeros son lo que hace falta para enviar; el tercero lo escribe
     *     el comando que da de alta el webhook en Kapso, o sea que es la señal de que el número
     *     también puede RECIBIR. Un número que envía pero no recibe es peor que no tenerlo: el dueño
     *     leería el informe en un chat al que no puede contestar. El cuarto —el número tal como se
     *     lee— es lo que se le dice a un dueño que escribe por el número equivocado: sin él no hay a
     *     dónde mandarlo. Con la fila a medias —alta hecha en el panel, webhook sin registrar— todo
     *     sigue saliendo y entrando por el principal, como hasta hoy.
     *
     * Todos los consumidores que tienen que decidir «¿mando por el número del asistente o por el
     * principal?» pasan por acá, y no por `porProposito()`, que solo mira que la fila exista.
     *
     * @param string $proposito Uno de los PROPOSITO_* de esta clase.
     *
     * @return self|null
     */
    public static function canalListo(string $proposito): ?self
    {
        $config = self::porProposito($proposito);
        if ($config === null) {
            return null;
        }

        if ($proposito === self::PROPOSITO_ASISTENTE && ! $config->tieneLoNecesarioParaAtender()) {
            return null;
        }

        return $config;
    }

    /**
     * Propósito por el que hay que mandar (y por el que hay que medir la ventana de 24 h) lo que
     * pertenece al canal del asistente: el propio `asistente` si su número está listo, y `principal`
     * si no.
     *
     * 🔴 La ventana de 24 h de Meta es por PAR de números: lo que un dueño le escribió al número de
     * leads no habilita texto libre por el número del asistente, ni al revés. Quien manda por uno
     * tiene que medir la ventana sobre el mismo. Devolver acá el propósito EFECTIVO —y no siempre
     * `asistente`— es lo que mantiene la medición de los informes y de los avisos tal cual estaba
     * hasta que el segundo número exista de verdad.
     *
     * @return string Uno de los PROPOSITO_*.
     */
    public static function propositoEfectivoDelAsistente(): string
    {
        return self::canalListo(self::PROPOSITO_ASISTENTE) !== null
            ? self::PROPOSITO_ASISTENTE
            : self::PROPOSITO_PRINCIPAL;
    }

    /**
     * Qué le falta a esta fila para atender el canal del asistente, por nombre de campo.
     *
     * No devuelve ningún valor guardado: solo nombres, para que un diagnóstico (`whatsapp:estado`, el
     * panel) pueda decir «falta el secreto del webhook» sin tocar jamás la clave o el secreto.
     *
     * @return array<int, string> Vacío = no falta nada.
     */
    public function loQueLeFaltaParaAtender(): array
    {
        $faltan = [];

        if (trim((string) $this->phone_number_id) === '') {
            $faltan[] = 'phone_number_id';
        }
        if (trim((string) $this->kapso_api_key) === '') {
            $faltan[] = 'kapso_api_key';
        }
        if (trim((string) $this->webhook_secret) === '') {
            $faltan[] = 'webhook_secret';
        }
        // «Un número que se pueda marcar»: ocho dígitos como mínimo, ignorando signos y espacios. Solo el
        // asistente lo necesita (es lo que se le dice a un dueño que escribe por el número equivocado).
        if ($this->proposito === self::PROPOSITO_ASISTENTE
            && strlen((string) preg_replace('/\D+/', '', (string) $this->display_phone)) < 8) {
            $faltan[] = 'display_phone';
        }

        return $faltan;
    }

    /**
     * Si esta fila tiene todo lo que un número necesita para enviar Y recibir.
     *
     * @return bool
     */
    public function tieneLoNecesarioParaAtender(): bool
    {
        return $this->loQueLeFaltaParaAtender() === [];
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
