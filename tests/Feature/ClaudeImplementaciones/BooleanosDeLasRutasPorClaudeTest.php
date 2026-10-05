<?php

namespace Tests\Feature\ClaudeImplementaciones;

/**
 * Los parámetros booleanos de `claude/implementations/*` (`dry_run`, `saltar`, `reenviar`, `reintentar`,
 * `conciliar`, `marcar_colgadas`) y lo que dice el 422 cuando no son booleanos.
 *
 * La regla `boolean` de Laravel acepta `true`, `false`, `1`, `0`, `"1"` y `"0"`: NO acepta el TEXTO `"false"` ni
 * `"true"` (lo que sale de interpolar un booleano en una plantilla de JSON, o de una sesión que escribe
 * `"dry_run": "false"` con comillas). Ese texto da 422, y es lo correcto en una escritura —no se adivina qué
 * quiso decir quien mandó un texto donde va un booleano—, pero el mensaje que decía "booleano (1, 0, true o
 * false)" mandaba al que lo recibía a probar con `"false"`, que es justo lo que había mandado. El mensaje de
 * este bloque dice que van SIN comillas y que el texto no se acepta.
 *
 * Lo que se protege:
 *  1. 🔴 Que el texto "true"/"false" siga siendo 422 (no se lee como booleano): un `"dry_run": "false"`
 *     interpretado de más es un real que nadie pidió, y interpretado de menos es una simulación que nadie vio.
 *  2. Que el mensaje del 422 sea honesto: dice que va sin comillas y que el texto no se acepta.
 *  3. Que los booleanos de verdad (`true`, `false`, `1`, `0`) sigan pasando la validación.
 */
class BooleanosDeLasRutasPorClaudeTest extends BaseDeImplementaciones
{
    /**
     * Cada ruta con un cuerpo válido salvo por el booleano que se pone a prueba.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    private function casos(): array
    {
        return [
            'alta dry_run'              => ['/api/claude/implementations', ['lead_id' => 1], 'dry_run'],
            'advance dry_run'           => ['/api/claude/implementations/1/advance', ['etapa_actual' => 1], 'dry_run'],
            'advance saltar'            => ['/api/claude/implementations/1/advance', ['etapa_actual' => 1], 'saltar'],
            'install dry_run'           => ['/api/claude/implementations/1/install', [], 'dry_run'],
            'install marcar_colgadas'   => ['/api/claude/implementations/1/install', [], 'marcar_colgadas'],
            'user-setup dry_run'        => ['/api/claude/implementations/1/user-setup', [], 'dry_run'],
            'user-setup reintentar'     => ['/api/claude/implementations/1/user-setup', [], 'reintentar'],
            'user-setup conciliar'      => ['/api/claude/implementations/1/user-setup', [], 'conciliar'],
            'mail dry_run'              => ['/api/claude/implementations/1/mail', ['hito' => 'bienvenida'], 'dry_run'],
            'mail reenviar'             => ['/api/claude/implementations/1/mail', ['hito' => 'bienvenida'], 'reenviar'],
        ];
    }

    /**
     * 1 y 2. 🔴 El TEXTO "false" o "true" es 422 en cada parámetro booleano de cada ruta, y el mensaje dice la
     * verdad: sin comillas, y el texto no se acepta.
     *
     * @return void
     */
    public function test_el_texto_false_o_true_es_422_y_el_mensaje_lo_dice(): void
    {
        foreach ($this->casos() as $nombre => $caso) {
            list($url, $cuerpo, $parametro) = $caso;

            foreach (['false', 'true'] as $texto) {
                $respuesta = $this->postJson($url, array_merge($cuerpo, [$parametro => $texto]), $this->headers());

                $respuesta->assertStatus(422);
                $mensaje = (string) $respuesta->json('errores.' . $parametro . '.0');

                /* Laravel arma el `:attribute` con espacios en lugar de guiones bajos ("dry run"). */
                $this->assertStringContainsString(str_replace('_', ' ', $parametro), $mensaje, $nombre . ': el 422 no habla del parámetro ' . $parametro . '.');
                $this->assertStringContainsString('sin comillas', $mensaje, $nombre . ' con "' . $texto . '": el mensaje no dice que va sin comillas: ' . $mensaje);
                $this->assertStringContainsString('no se acepta', $mensaje, $nombre . ' con "' . $texto . '": el mensaje no dice que el texto no se acepta: ' . $mensaje);
            }
        }
    }

    /**
     * 3. Los booleanos de verdad pasan la validación: `true`, `false`, `1` y `0` (no hay 422 por el tipo; con
     * `dry_run=false` el 422 puede ser por la confirmación del nombre, que es otro motivo y otro texto).
     *
     * @return void
     */
    public function test_los_booleanos_de_verdad_pasan_la_validacion(): void
    {
        foreach ($this->casos() as $nombre => $caso) {
            list($url, $cuerpo, $parametro) = $caso;

            foreach ([true, false, 1, 0] as $valor) {
                $respuesta = $this->postJson($url, array_merge($cuerpo, [$parametro => $valor]), $this->headers());

                $this->assertNull(
                    $respuesta->json('errores.' . $parametro),
                    $nombre . ' con ' . json_encode($valor) . ': el 422 es por el tipo del booleano (' . $this->cuerpo($respuesta) . ').'
                );
            }
        }
    }
}
