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
 *   - Que reconozca una hora escrita como la escribe un humano por WhatsApp ("12:30", "12hs",
 *     "5pm", "9 h"). Si se le escapa, la guarda deja pasar un mensaje que nombra un horario que
 *     nadie declaró y que nada revalida — el bug del lead 30, en versión nueva.
 *
 *   - Que NO confunda cualquier número con una hora. Un falso positivo manda a revisión humana una
 *     apertura flexible perfectamente válida, y como el objetivo entero de la misión es que esas
 *     aperturas NO se traben, un detector nervioso rompe la funcionalidad tanto como uno ciego.
 *     "El 5 de septiembre", "en 5 minutos" y "somos 3 personas" son las tres formas más comunes de
 *     que aparezca un número suelto en estos mensajes.
 *
 * El método reutiliza las dos regex que LeadAiService ya usaba para detectar el horario que propone
 * el LEAD; este test es también la red de esas dos regex, que hasta ahora no tenían ninguna.
 */
class MensajeMencionaUnaHoraTest extends TestCase
{
    /**
     * @return void
     */
    public function test_reconoce_una_hora_y_no_confunde_otras_cosas(): void
    {
        $service = $this->service();

        $con_hora = [
            '12:30',
            'Te la dejo lista para las 12:30, ¿te sirve?',
            '12hs',
            'Dale, a las 12 hs te espero.',
            'a las 5pm',
            'Nos vemos 8am',
            '9 h',
            'Te la preparo para las 17:05.',
        ];

        foreach ($con_hora as $texto) {
            $this->assertTrue(
                $service->menciona_hora($texto),
                "No reconoció la hora en: \"{$texto}\" — la guarda dejaría pasar una oferta con horario sin declarar."
            );
        }

        $sin_hora = [
            'Si querés te la dejo lista ahora mismo, o para el horario que te quede cómodo — vos decime.',
            'ahora mismo o cuando te quede cómodo',
            'el 5 de septiembre',
            'en 5 minutos',
            'somos 3 personas',
            'La recorrida te lleva alrededor de una hora.',
            '',
            '   ',
        ];

        foreach ($sin_hora as $texto) {
            $this->assertFalse(
                $service->menciona_hora($texto),
                "Vio una hora donde no hay ninguna: \"{$texto}\" — una apertura flexible válida se trabaría para revisión humana."
            );
        }
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
