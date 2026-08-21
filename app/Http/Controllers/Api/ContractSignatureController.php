<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContractSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Firma del PRESTADOR que se estampa en el PDF del contrato de un lead.
 *
 * Se carga una sola vez desde la vista Cuenta de admin-spa. Los cuatro endpoints viven dentro
 * del grupo `auth:sanctum` y NO usan URL firmada: el consumidor es la SPA con su token y la
 * vista previa se arma con `responseType: 'blob'`. Una URL firmada sería un link a la firma de
 * una persona que sobrevive fuera de la sesión.
 */
class ContractSignatureController extends Controller
{
    /**
     * Estado de la firma cargada, para pintar la sección de Cuenta.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        return response()->json(ContractSignatureService::estado(), 200);
    }

    /**
     * Sube (o reemplaza) la firma del PRESTADOR.
     *
     * @param Request $request Multipart con el campo `firma`.
     *
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'firma' => 'required|file|image|mimes:png,jpg,jpeg|max:2048|dimensions:min_width=120,min_height=40,max_width=4000,max_height=4000',
        ]);

        $archivo = $request->file('firma');
        $bytes = (string) $archivo->get();

        // 🔴 Primero getimagesizefromstring y recién después imagecreatefromstring, y el orden
        // NO es indistinto. getimagesizefromstring lee nada más que la cabecera: no descomprime
        // la imagen, así que averigua cuánto va a costar el bitmap sin pagarlo. Al revés, un PNG
        // de 4000×4000 (83 KB, pasa max:2048 y entra justo adentro de las dimensiones máximas)
        // hace que imagecreatefromstring pida 64 MB contra un memory_limit de 128M y la request
        // muera con un fatal de memoria: ni el `@` lo suprime ni un try/catch de PHP 7.4 lo
        // captura, o sea un 500 sin cuerpo JSON y la SPA sin nada que mostrar.
        $info = @getimagesizefromstring($bytes);

        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return response()->json([
                'message' => 'No se pudo leer la imagen. Probá con un PNG o un JPG generado por un editor de imágenes.',
            ], 422);
        }

        $pixeles = (int) $info[0] * (int) $info[1];

        if ($pixeles > ContractSignatureService::PIXELES_MAX) {
            return response()->json([
                'message' => 'La imagen tiene demasiados píxeles (' . $info[0] . '×' . $info[1] . '). '
                    . 'Una firma escaneada no necesita tanto: recortala o bajale la resolución y volvé a probar.',
            ], 422);
        }

        // Chequeo que el validador no cubre: que GD pueda abrir la imagen de verdad. Si no
        // puede, dompdf dibuja su "broken image" —una X gris— adentro del contrato que firma
        // el cliente, y eso es peor que no poner nada. Acá el problema se ve al subir, que es
        // donde tiene que verse.
        $recurso = @imagecreatefromstring($bytes);

        if ($recurso === false) {
            return response()->json([
                'message' => 'No se pudo leer la imagen. Probá con un PNG o un JPG generado por un editor de imágenes.',
            ], 422);
        }

        imagedestroy($recurso);

        // `mimes:png,jpg,jpeg` valida por guessExtension(), que mapea a esas tres extensiones
        // más MIMEs de los tres que acepta el servicio (image/pjpeg, por ejemplo, pasa la
        // validación y no está en el mapa). Sin este try/catch esa discrepancia sale como un
        // 500 sin cuerpo en vez de un 422 con un mensaje que la SPA pueda mostrar. Vale
        // también para el fallo de escritura en disco.
        try {
            ContractSignatureService::guardar($archivo);
        } catch (\RuntimeException $error) {
            return response()->json([
                'message' => 'No se pudo guardar la firma: ' . $error->getMessage(),
            ], 422);
        }

        return response()->json(ContractSignatureService::estado(), 200);
    }

    /**
     * Devuelve los bytes de la firma para la vista previa de la SPA.
     *
     * @return \Illuminate\Http\Response|JsonResponse
     */
    public function file()
    {
        $contenido = ContractSignatureService::contenido();
        $mime = ContractSignatureService::mime();

        if ($contenido === null || $mime === null) {
            return response()->json(['message' => 'No hay firma cargada.'], 404);
        }

        return response($contenido, 200, [
            'Content-Type'  => $mime,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Borra la firma cargada. Idempotente: si no había nada, también devuelve 200.
     *
     * @return JsonResponse
     */
    public function destroy(): JsonResponse
    {
        ContractSignatureService::borrar();

        return response()->json(ContractSignatureService::estado(), 200);
    }
}
