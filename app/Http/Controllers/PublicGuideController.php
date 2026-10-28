<?php

namespace App\Http\Controllers;

/**
 * Sirve las guías públicas en PDF que se le mandan al cliente por WhatsApp (misión `implementacion-agentes-ia`,
 * 28/10/2026): cómo conectar su número de WhatsApp a los Agentes de IA, en sus dos caminos.
 *
 * 🔴 POR QUÉ UNA RUTA Y NO UN ARCHIVO EN public/. `/deploy-admin` despliega solo app, bootstrap, config,
 * database, resources y routes: un PDF en `public/` no llegaría nunca a producción. Por eso viven en
 * `resources/guias/` y salen por `GET api/guias/{archivo}`.
 *
 * 🔴 Es pública (sin sesión ni clave): el link lo abre el cliente desde el teléfono. La lista blanca de nombres
 * es CERRADA y el nombre pedido se usa solo para buscar en ella: el path del archivo sale de la constante, nunca
 * del pedido, así que no hay forma de leer otro archivo del servidor (`../.env` es simplemente un nombre que no
 * está en la lista → 404).
 */
class PublicGuideController extends Controller
{
    /**
     * Nombre público → archivo dentro de `resources/guias/`. Solo estos dos existen.
     *
     * @var array<string, string>
     */
    const GUIDES = [
        'agentes-ia-numero-propio.pdf' => 'agentes-ia-numero-propio.pdf',
        'agentes-ia-linea-nueva.pdf'   => 'agentes-ia-linea-nueva.pdf',
    ];

    /**
     * Devuelve la guía pedida como PDF para ver en el navegador (`inline`), o 404.
     *
     * @param string $archivo Nombre público pedido (segmento de la URL).
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
     */
    public function show($archivo)
    {
        $archivo = (string) $archivo;

        if (! array_key_exists($archivo, self::GUIDES)) {
            return response()->json(['error' => 'no existe esa guía'], 404);
        }

        $path = resource_path('guias/' . self::GUIDES[$archivo]);

        /* El archivo está versionado; si falta es un deploy roto, y la respuesta tiene que ser un 404 claro y no un 500. */
        if (! is_file($path)) {
            return response()->json(['error' => 'no existe esa guía'], 404);
        }

        return response()->file($path, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $archivo . '"',
            'Cache-Control'       => 'public, max-age=3600',
        ]);
    }
}
