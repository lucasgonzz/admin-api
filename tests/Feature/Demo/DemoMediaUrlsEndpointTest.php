<?php

namespace Tests\Feature\Demo;

use App\Models\DemoMedia;
use App\Models\Lead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET /api/demo-media-urls`: el hermano de lectura del canal de eventos (misión cruzada
 * demo-panel-recorrido, 17/8/2026).
 *
 * Lo que estas pruebas cuidan es el contrato con empresa-api, que se despliega en otro momento que
 * este lado: la clave del json (`media_urls`), el código de error (401) y la autenticación reusada
 * del canal de eventos (`X-Demo-Eventos-Key`). Si algo de eso cambia, el panel del lead se queda sin
 * videos y nadie se entera hasta que Lucas abre una demo.
 *
 * El caso que le dio origen: el 17/8/2026 las URLs se cargaron 49 minutos DESPUÉS del setup de la
 * instancia, y como hasta entonces viajaban sólo adentro del payload del setup, todos los clips del
 * panel decían "Este video todavía no está disponible".
 */
class DemoMediaUrlsEndpointTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Lead con la dinámica nueva y su token de eventos emitido: exactamente lo que el middleware
     * del canal exige para dejar pasar.
     *
     * @return Lead
     */
    private function crear_lead_con_dinamica_nueva(): Lead
    {
        $lead                     = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = 'Juana Pérez';
        $lead->demo_experiencia   = Lead::EXPERIENCIA_NUEVA;
        $lead->demo_eventos_token = Str::random(64);
        $lead->save();

        return $lead;
    }

    /**
     * Lead de la dinámica que hoy corre en producción: no tiene instancia nueva que pregunte nada.
     * Igual se le emite un token, que es el caso interesante — el token existe y sirve, y lo que lo
     * corta es la dinámica.
     *
     * @return Lead
     */
    private function crear_lead_con_dinamica_actual(): Lead
    {
        $lead                     = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = 'Roberto Gómez';
        $lead->demo_experiencia   = Lead::EXPERIENCIA_ACTUAL;
        $lead->demo_eventos_token = Str::random(64);
        $lead->save();

        return $lead;
    }

    /**
     * Deja `demo_media` con exactamente las filas que le pasan, y nada más.
     *
     * Se vacía la tabla a propósito: `DemoMedia::url_por_slot()` devuelve TODA la tabla (los slots
     * son el catálogo de la demo, no son por lead), así que las filas residuales de la base del slot
     * ensuciarían la comparación exacta del mapa. El borrado vive adentro de la transacción de
     * DatabaseTransactions y se revierte al terminar cada test.
     *
     * @param array<string, string|null> $slots Mapa `slot_id => url` (la url puede ser null o '').
     */
    private function sembrar_media(array $slots): void
    {
        DemoMedia::query()->delete();

        foreach ($slots as $slot_id => $url) {
            DemoMedia::create(['slot_id' => $slot_id, 'url' => $url]);
        }
    }

    /**
     * Pide el mapa de URLs.
     *
     * @param string|null $token Null = sin el header de autenticación.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function pedir(?string $token = null)
    {
        $headers = [];

        if ($token !== null) {
            $headers['X-Demo-Eventos-Key'] = $token;
        }

        return $this->getJson('/api/demo-media-urls', $headers);
    }

    /**
     * El caso feliz: un lead con token válido y dinámica nueva recibe el mapa entero, bajo la clave
     * `media_urls`, que es la que empresa-api va a leer.
     */
    public function test_devuelve_el_mapa_completo_de_urls_con_un_token_valido(): void
    {
        $lead = $this->crear_lead_con_dinamica_nueva();

        $this->sembrar_media([
            'intro' => 'https://cdn.comerciocity.com/demo/intro.mp4',
            '1.1'   => 'https://cdn.comerciocity.com/demo/1-1.mp4',
            '2.1'   => 'https://cdn.comerciocity.com/demo/2-1.mp4',
        ]);

        $respuesta = $this->pedir($lead->demo_eventos_token)->assertStatus(200);

        // La clave exacta es parte del contrato: empresa-api lee `media_urls` y nada más.
        $this->assertSame([
            'intro' => 'https://cdn.comerciocity.com/demo/intro.mp4',
            '1.1'   => 'https://cdn.comerciocity.com/demo/1-1.mp4',
            '2.1'   => 'https://cdn.comerciocity.com/demo/2-1.mp4',
        ], $respuesta->json('media_urls'));

        // Y el cuerpo no trae nada más: un consumidor que se despliega en otro momento no tiene por
        // qué adivinar qué otras claves pueden aparecer.
        $this->assertSame(['media_urls'], array_keys($respuesta->json()));
    }

    /**
     * Sin el header no hay lead, y sin lead no hay respuesta. 401, el mismo que ya devuelve el canal
     * de eventos.
     */
    public function test_sin_el_header_devuelve_401(): void
    {
        $this->crear_lead_con_dinamica_nueva();
        $this->sembrar_media(['1.1' => 'https://cdn.comerciocity.com/demo/1-1.mp4']);

        $respuesta = $this->pedir(null)->assertStatus(401);

        $this->assertSame('no autorizado', $respuesta->json('error'));

        // Y sobre todo: no se filtró ni una URL por el camino del error.
        $this->assertNull($respuesta->json('media_urls'));
    }

    /**
     * Un token que no le pertenece a nadie devuelve el MISMO 401 que la falta de header: distinguir
     * los dos casos le daría a quien prueba tokens al azar una señal de progreso.
     */
    public function test_con_un_token_inexistente_devuelve_401(): void
    {
        $this->crear_lead_con_dinamica_nueva();
        $this->sembrar_media(['1.1' => 'https://cdn.comerciocity.com/demo/1-1.mp4']);

        $respuesta = $this->pedir('un-token-que-no-existe')->assertStatus(401);

        $this->assertSame('no autorizado', $respuesta->json('error'));
        $this->assertNull($respuesta->json('media_urls'));
    }

    /**
     * Un lead de la dinámica `actual` queda afuera aunque su token sea real y esté vigente. Es el
     * rollback previsto de un piloto: volver al lead a la dinámica de producción apaga el canal
     * entero, lectura incluida. Lo corta el middleware con `usa_experiencia_demo_nueva()`.
     */
    public function test_un_lead_de_la_dinamica_actual_devuelve_401(): void
    {
        $lead = $this->crear_lead_con_dinamica_actual();
        $this->sembrar_media(['1.1' => 'https://cdn.comerciocity.com/demo/1-1.mp4']);

        $respuesta = $this->pedir($lead->demo_eventos_token)->assertStatus(401);

        $this->assertSame('no autorizado', $respuesta->json('error'));
        $this->assertNull($respuesta->json('media_urls'));
    }

    /**
     * Un slot sin URL cargada (fila con null o con cadena vacía) NO aparece en el mapa.
     *
     * Del otro lado, empresa decide entre mostrar el video y mostrar "todavía no está disponible"
     * por la presencia de la clave. Si acá se colara un `"3.1": ""`, el panel del lead abriría un
     * `<video>` apuntando a la nada: un rectángulo negro para siempre, que es peor que el cartel.
     */
    public function test_solo_devuelve_los_slots_con_url_no_vacia(): void
    {
        $lead = $this->crear_lead_con_dinamica_nueva();

        $this->sembrar_media([
            '1.1' => 'https://cdn.comerciocity.com/demo/1-1.mp4',
            '2.1' => null,
            '3.1' => '',
        ]);

        $respuesta = $this->pedir($lead->demo_eventos_token)->assertStatus(200);

        $this->assertSame(
            ['1.1' => 'https://cdn.comerciocity.com/demo/1-1.mp4'],
            $respuesta->json('media_urls')
        );
    }

    /**
     * Con la tabla vacía la respuesta es 200 con un mapa vacío, no un 404 ni un error. "Todavía no
     * cargaron ningún video" es un estado normal de la demo, no una falla del canal.
     *
     * ⚠️ Dato del contrato que queda fijado acá: con cero filas el mapa se serializa como ARRAY json
     * vacío (`{"media_urls":[]}`), no como objeto (`{}`), porque `url_por_slot()` devuelve un array
     * de PHP y un array vacío no se distingue de una lista. Para el consumidor de empresa-api, que es
     * PHP, da igual: `json_decode(..., true)` deja `[]` en los dos casos y el `isset($mapa[$clip_id])`
     * responde false. Se afirma tal cual es para que nadie lo "arregle" a ciegas: si algún día el
     * consumidor pasa a ser JavaScript, ESTE test es el que se pone rojo y avisa.
     */
    public function test_sin_ninguna_url_cargada_devuelve_200_con_un_mapa_vacio(): void
    {
        $lead = $this->crear_lead_con_dinamica_nueva();

        $this->sembrar_media([]);

        $respuesta = $this->pedir($lead->demo_eventos_token)->assertStatus(200);

        $this->assertSame([], $respuesta->json('media_urls'));
        $this->assertSame('{"media_urls":[]}', $respuesta->getContent());
    }
}
