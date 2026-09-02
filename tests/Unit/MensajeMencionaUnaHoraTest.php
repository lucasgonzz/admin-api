<?php

namespace Tests\Unit;

use App\Services\LeadAiService;
use PHPUnit\Framework\TestCase;

/**
 * El criterio de `LeadAiService::mensaje_menciona_una_hora()`, puro y sin base de datos.
 *
 * Es la mitad que VERIFICA la afirmación del modelo cuando dice "esta es una oferta flexible, no
 * nombré ninguna hora". Declararse flexible no es un salvoconducto: si el texto igual nombra una
 * hora, el mensaje se frena para revisión humana lo mismo que antes de esta misión.
 *
 * 🔴 Lo que este archivo clava son las DOS puntas, y la segunda importa tanto como la primera:
 *
 *   - Que reconozca una hora escrita como la escribe un humano por WhatsApp. Si se le escapa, la
 *     guarda deja pasar un mensaje que nombra un horario que nadie declaró y que nada revalida — el
 *     bug del lead 30, en versión nueva.
 *
 *   - Que NO confunda cualquier número con una hora. Un falso positivo manda a revisión humana una
 *     apertura flexible perfectamente válida, y como el objetivo entero de la misión es que esas
 *     aperturas NO se traben, un detector nervioso rompe la funcionalidad tanto como uno ciego.
 *
 * 🔴 EL HUECO QUE ESTE ARCHIVO CONGELABA, y por qué la aserción cambió. La primera versión afirmaba
 * que `"en 5 minutos"` daba false, y encima lo justificaba como "un número suelto". Pero "en 5
 * minutos" ES una hora: es la forma más natural de decir "12:37" sin decirlo, y el propio prompt la
 * PROHIBE textualmente en la apertura flexible, junto con "12:30" y "a las 12". De las tres formas
 * que el prompt prohíbe, el detector reconocía UNA. La aserción no se aflojó: estaba escrita al
 * revés y codificaba el bug.
 *
 * El detector vive en `LeadMessage::texto_menciona_una_hora()` porque lo usan las dos puntas del
 * contrato (el servidor al generar y el permiso del margen un turno después); acá se lo prueba por
 * el método del service, que es la punta que decide si el mensaje sale o se frena.
 */
class MensajeMencionaUnaHoraTest extends TestCase
{
    /**
     * Textos que SÍ nombran una hora. Cada bloque es una forma distinta de escribirla, y las tres
     * primeras son literalmente las que el prompt de la apertura flexible prohíbe.
     *
     * @return array<string, array<int, string>>
     */
    public function textos_con_hora(): array
    {
        return [
            /* Las tres que el prompt prohíbe por su nombre. */
            'dos puntos'                 => ['Te la dejo lista para las 12:30, ¿te sirve?'],
            'a las N'                    => ['Dale, a las 12 te espero.'],
            'en N minutos'               => ['La tenés lista en 5 minutos.'],

            /* Y el resto de las formas comunes en castellano rioplatense. */
            'hora pelada con dos puntos' => ['12:30'],
            'sufijo pegado'              => ['12hs'],
            'sufijo separado'            => ['9 h'],
            'sufijo con espacio'         => ['Dale, a las 12 hs te espero.'],
            'pm'                         => ['a las 5pm'],
            'am'                         => ['Nos vemos 8am'],
            'minutos exactos'            => ['Te la preparo para las 17:05.'],
            'hora con punto'             => ['Te queda para las 13.30 entonces.'],
            'y media'                    => ['Dale, a las 12 y media.'],
            'y cuarto sin preposicion'   => ['Nos vemos doce y cuarto.'],
            'numero en palabras'         => ['Te espero a las cinco de la tarde.'],
            'a la una'                   => ['Te la dejo lista a la una.'],
            'mediodia'                   => ['Nos vemos al mediodía.'],
            'medio dia separado'         => ['Te queda mejor medio día?'],
            'minutos en palabras'        => ['La tenés en cinco minutos.'],
            'minutos abreviados'         => ['La tenés en 10 min.'],
            'para las'                   => ['Te la dejo lista para las 8.'],
            'hasta las'                  => ['Te la reservo hasta las 18.'],
        ];
    }

    /**
     * Textos que NO nombran ninguna hora. La primera fila es el texto canónico de la apertura
     * flexible: si esta se rompe, la misión entera deja de funcionar.
     *
     * @return array<string, array<int, string>>
     */
    public function textos_sin_hora(): array
    {
        return [
            'apertura canonica'   => ['Si querés te la dejo lista ahora mismo, o para el horario que te quede cómodo — vos decime.'],
            'apertura corta'      => ['ahora mismo o cuando te quede cómodo'],
            'fecha con dia'       => ['el 5 de septiembre'],
            'fecha con mes'       => ['Te la dejo para el 9 de julio.'],
            'cantidad de gente'   => ['somos 3 personas'],
            'duracion en palabra' => ['La recorrida te lleva alrededor de una hora.'],
            'duracion en horas'   => ['La demo dura 2 horas como mucho.'],
            'duracion en minutos' => ['Te lleva 40 min.'],
            'link con uuid'       => ['Entrá acá: https://admin.comerciocity.com/experiencia/3f2504e0-4f89-11d3-9a0c-0305e82c3301'],
            'precio con miles'    => ['El plan sale $15.000 por mes.'],
            'precio chico'        => ['El plan sale $1.500 por mes.'],
            'franja del dia'      => ['Dale, hoy a la tarde.'],
            'vacio'               => [''],
            'solo espacios'       => ['   '],
        ];
    }

    /**
     * @dataProvider textos_con_hora
     *
     * @param string $texto
     *
     * @return void
     */
    public function test_reconoce_las_formas_en_que_se_escribe_una_hora(string $texto): void
    {
        $this->assertTrue(
            $this->service()->menciona_hora($texto),
            "No reconoció la hora en: \"{$texto}\" — la guarda dejaría pasar una oferta con horario sin declarar."
        );
    }

    /**
     * @dataProvider textos_sin_hora
     *
     * @param string $texto
     *
     * @return void
     */
    public function test_no_confunde_un_numero_cualquiera_con_una_hora(string $texto): void
    {
        $this->assertFalse(
            $this->service()->menciona_hora($texto),
            "Vio una hora donde no hay ninguna: \"{$texto}\" — una apertura flexible válida se trabaría para revisión humana."
        );
    }

    /**
     * El plazo en horas ("dentro de las 24 hs") entra por el patrón de sufijo y da true.
     *
     * 🔴 Está acá escrito como lo que es —una decisión, no un olvido— para que nadie lo "arregle"
     * restringiendo el patrón a 0-23: eso convertiría un mensaje que HOY se frena en uno que sale
     * solo, y esa dirección está prohibida. Un mensaje de apertura no tiene por qué hablar de
     * plazos, y si lo hace, el costo es una revisión humana de más.
     *
     * @return void
     */
    public function test_un_plazo_en_horas_frena_a_proposito(): void
    {
        $this->assertTrue(
            $this->service()->menciona_hora('Te contesto dentro de las 24 hs.'),
            'Se aflojó el detector: un texto que antes frenaba dejó de frenar.'
        );
    }

    /**
     * Instancia del service con el helper protegido expuesto. La subclase no cambia ninguna lógica.
     *
     * @return LeadAiService
     */
    private function service(): LeadAiService
    {
        return new class extends LeadAiService {
            /**
             * @param string $texto
             *
             * @return bool
             */
            public function menciona_hora(string $texto): bool
            {
                return $this->mensaje_menciona_una_hora($texto);
            }
        };
    }
}
