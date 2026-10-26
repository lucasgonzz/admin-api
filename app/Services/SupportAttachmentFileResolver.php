<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\Adapter\Local;

/**
 * Resuelve la ruta absoluta de un adjunto de soporte, y SOLO si es un archivo del disco `public`.
 *
 * 🔴 Por qué existe: `support_message_attachments.disk` y `.path` los puede escribir el cliente
 * (`InboundSupportMessageController` los guarda tal cual vienen en la metadata remota). Servir el disco
 * que dice la fila permitía pedir `disk = local, path = x.txt` y leer cosas privadas de `storage/app/`
 * (deployments, importaciones, contratos). Por eso el disco de los adjuntos de soporte es SIEMPRE `public`
 * (ahí los guardan tanto el webhook de WhatsApp como el operador), la columna `disk` se ignora, y además
 * el archivo real —después de resolver `..` y symlinks— tiene que quedar adentro de la raíz de ese disco.
 */
class SupportAttachmentFileResolver
{
    /**
     * Ruta absoluta (realpath) del archivo, o null si no existe, no es un archivo o cae fuera del disco `public`.
     *
     * No lanza nunca: cualquier cosa rara (ruta con `..`, absoluta, directorio, bytes nulos, disco que no es
     * local) devuelve null y el llamador contesta 404.
     *
     * @param string $relative_path Ruta guardada en la fila del adjunto, relativa a la raíz del disco `public`.
     *
     * @return string|null
     */
    public function resolve(string $relative_path): ?string
    {
        try {
            // Sin ruta o con bytes nulos no hay nada que servir.
            $relative_path = trim($relative_path);
            if ($relative_path === '' || strpos($relative_path, "\0") !== false) {
                return null;
            }

            // Solo un disco local tiene rutas absolutas contra las que verificar la contención.
            $adapter = Storage::disk('public')->getDriver()->getAdapter();
            if (! $adapter instanceof Local) {
                return null;
            }

            // Raíz real del disco (con symlinks resueltos) y candidato: raíz + ruta sin barra inicial.
            $root_prefix = $adapter->getPathPrefix();
            $real_root = realpath($root_prefix);
            if ($real_root === false) {
                return null;
            }
            $real_file = realpath($root_prefix . ltrim($relative_path, '/\\'));

            // Tiene que existir y ser un archivo común (no un directorio).
            if ($real_file === false || ! is_file($real_file)) {
                return null;
            }

            // Contención: el archivo real queda adentro de la raíz real (en Windows no se distingue mayúsculas).
            $real_root_with_separator = rtrim($real_root, '/\\') . DIRECTORY_SEPARATOR;
            $is_windows = DIRECTORY_SEPARATOR === '\\';
            $inside = $is_windows
                ? stripos($real_file, $real_root_with_separator) === 0
                : strpos($real_file, $real_root_with_separator) === 0;

            return $inside ? $real_file : null;
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
