<?php

namespace Tests\Feature\Cobranzas;

use App\Helpers\WhatsappNormalizer;

/**
 * `phone_whatsapp` en la tabla de Mensualidades (hallazgo del chequeo independiente, misión
 * cobranzas-mejoras, 18/9/2026): `clients.phone` es de formato libre, así que el backend lo
 * normaliza a dígitos puros -listos para `wa.me/<numero>`- con `WhatsappNormalizer`, el mismo
 * normalizador que ya usa `WhatsappSendService` para mandar mensajes de verdad y
 * `ClientPhoneDirectory` para reconocer los teléfonos de un cliente. NO usa
 * `ArgentinePhoneNormalizer`: ese es del flujo de formularios de implementación
 * (`ImplementationConversationService`/`ImplementationFormMapper`), un dominio distinto (se
 * grepearon los dos antes de elegir cuál era el correcto).
 *
 * El frontend usa `phone_whatsapp` directo, sin volver a tocarlo; `phone` sigue viajando crudo al
 * lado, tal como está cargado, por si hace falta mostrarlo.
 */
class TelefonoWhatsappDeLaTablaTest extends BaseDeCobranzas
{
    /**
     * Pide la tabla de un mes y devuelve la fila de un cliente.
     *
     * @param int $client_id
     *
     * @return array<string, mixed>
     */
    private function fila_de(int $client_id): array
    {
        $response = $this->getJson('/api/admin/cobranzas/mensualidades?meses=2026-09');
        $response->assertStatus(200);

        foreach ($response->json('clientes') as $fila) {
            if ((int) $fila['id'] === $client_id) {
                return $fila;
            }
        }

        $this->fail('El cliente #' . $client_id . ' no está en la tabla.');
    }

    /**
     * 1. Un teléfono cargado en formato local (sin +54, sin el 9 móvil) se normaliza al mismo
     * E.164 que ya usa el resto del sistema para hablarle a ese cliente por WhatsApp — no es una
     * normalización inventada para esta tabla.
     *
     * @return void
     */
    public function test_normaliza_el_telefono_igual_que_el_resto_del_sistema(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente(['phone' => '11 3456-7890']);

        $fila = $this->fila_de($client->id);

        $this->assertSame('11 3456-7890', $fila['phone'], 'phone queda crudo, tal cual está cargado.');
        $this->assertSame('5491134567890', $fila['phone_whatsapp']);

        // El mismo resultado que daría preguntarle directo al normalizador que ya usa
        // WhatsappSendService para mandar mensajes: acá no se reinventa nada.
        $esperado = ltrim(WhatsappNormalizer::normalize('11 3456-7890'), '+');
        $this->assertSame($esperado, $fila['phone_whatsapp']);
    }

    /**
     * 2. Sin teléfono cargado (null o vacío): `phone_whatsapp` es null, no explota ni inventa un
     * número.
     *
     * @return void
     */
    public function test_sin_telefono_phone_whatsapp_es_null(): void
    {
        $this->admin_logueado();
        $sin_cargar = $this->crear_cliente(['phone' => null]);
        $vacio = $this->crear_cliente(['phone' => '']);

        $this->assertNull($this->fila_de($sin_cargar->id)['phone_whatsapp']);
        $this->assertNull($this->fila_de($vacio->id)['phone_whatsapp']);
    }
}
