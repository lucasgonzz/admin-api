<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Services\WhatsappSendService;

/**
 * Reemplazo de `WhatsappSendService` para los tests: anota lo que se le pidió mandar y no sale a
 * la red.
 *
 * Es una subclase y no un mock de PHPUnit porque lo que hay que observar acá son DOS cosas a la
 * vez: qué se llamó (texto libre o plantilla, y con qué) y qué devolvió. Con una subclase eso se
 * lee de una en las aserciones, sin armar expectativas.
 */
class WhatsappDeMentira extends WhatsappSendService
{
    /**
     * Textos libres que se pidieron mandar. Cada ítem: to, body.
     *
     * @var array<int, array<string, string>>
     */
    public $textos = [];

    /**
     * Plantillas que se pidieron mandar. Cada ítem: to, template, variables, language.
     *
     * @var array<int, array<string, mixed>>
     */
    public $plantillas = [];

    /**
     * Qué devuelven los envíos. Null simula un rechazo de Meta.
     *
     * @var string|null
     */
    public $respuesta = 'wamid.DEPRUEBA';

    /**
     * @param string      $to
     * @param string      $body
     * @param string|null $context
     * @param bool        $skip_failure_notification
     *
     * @return string|null
     */
    public function send_text(
        string $to,
        string $body,
        ?string $context = null,
        bool $skip_failure_notification = false
    ): ?string {
        $this->textos[] = ['to' => $to, 'body' => $body];

        if ($this->respuesta === null) {
            $this->last_send_error = 'rechazo simulado';
        }

        return $this->respuesta;
    }

    /**
     * @param string      $to
     * @param string      $template_name
     * @param array       $variables
     * @param string      $language_code
     * @param string|null $context
     *
     * @return string|null
     */
    public function send_template(
        string $to,
        string $template_name,
        array $variables = [],
        string $language_code = 'es_AR',
        ?string $context = null
    ): ?string {
        $this->plantillas[] = [
            'to'        => $to,
            'template'  => $template_name,
            'variables' => $variables,
            'language'  => $language_code,
        ];

        if ($this->respuesta === null) {
            $this->last_send_error = 'rechazo simulado';
        }

        return $this->respuesta;
    }

    /**
     * Cuántos envíos se pidieron en total, del tipo que sean.
     *
     * @return int
     */
    public function cuantos_envios(): int
    {
        return count($this->textos) + count($this->plantillas);
    }
}
