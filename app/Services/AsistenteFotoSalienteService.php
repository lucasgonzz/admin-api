<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Las fotos que el asistente del sistema de un cliente le manda al dueño por WhatsApp.
 *
 * Es la mitad de vuelta de {@see AsistenteImagenesService}: ahí las fotos van del dueño al
 * asistente, acá vuelven del asistente al dueño. El `empresa-api` las devuelve como `adjuntos`
 * —`[{tipo:'imagen', url, texto}]`— y son las imágenes del catálogo de artículos de ese cliente,
 * URLs públicas de su hosting o de R2.
 *
 * 🔴 **Por qué existe esta clase: Meta descarta el webp y no lo dice.** La Cloud API acepta en un
 * mensaje de tipo `image` únicamente **jpeg y png**; el webp lo reserva para stickers. Y valida el
 * link de forma **asíncrona**, después de haber contestado 200 con un `wamid` — así que el admin
 * registra "foto enviada", el log queda en verde y al dueño no le llega nada, sin un solo error en
 * ningún lado. Medido en producción el 22/9/2026: el dueño escribió *"la foto nunca me llegó"* un
 * minuto después de que el log dijera `imagenes_enviadas: 1, imagenes_fallidas: 0`. Y no es un caso
 * de borde: en demo3 **las 2.663 fotos de artículos son webp, el 100 %** — o sea que mientras el
 * ERP guarde webp, por link no llega ninguna.
 *
 * 🔴 **Acá tampoco se guarda nada en disco.** Los bytes se bajan, se convierten en memoria y se
 * pasan al envío. Son las fotos del catálogo de un cliente: una copia en el storage del admin no le
 * sirve a nadie. Es la misma regla que ya sigue el servicio de las fotos entrantes.
 *
 * 🔴 **Y a la descarga no se le pega NINGUNA credencial.** La URL es del hosting del cliente, no de
 * Kapso: mandarle el `X-API-Key` de Kapso —como sí hace `WhatsappInboundMediaService` con la media
 * entrante, que sale contra `api.kapso.ai`— sería regalarle la clave de la plataforma al servidor
 * de un tercero. El pedido va pelado.
 */
class AsistenteFotoSalienteService
{
    /**
     * Extensiones con las que un link se manda tal cual, sin bajar nada.
     *
     * Son las que Meta baja sola y acepta. Cualquier otra —y la ausencia de extensión— manda la
     * foto por el carril largo, que es el único que puede afirmar algo sobre los bytes.
     */
    const EXTENSIONES_QUE_META_BAJA = ['jpg', 'jpeg', 'png'];

    /**
     * Mimes que Meta acepta en un mensaje `image`. Webp NO está, y esa ausencia es todo el bug.
     */
    const MIMES_QUE_META_ACEPTA = ['image/jpeg', 'image/png'];

    /**
     * Tope de Meta para la imagen de un mensaje `image`: 5 MB.
     */
    const MAXIMO_DE_BYTES_PARA_META = 5242880;

    /**
     * Tope de lo que se baja del hosting del cliente, en bytes.
     *
     * Más alto que el de Meta a propósito: un original de 8 MB se puede achicar hasta entrar, y
     * descartarlo en la descarga sería perder una foto que se podía mandar. Lo que sí corta es el
     * disparate — un archivo de 50 MB en la memoria del worker del admin, que además corre
     * deployments.
     */
    const MAXIMO_DE_BYTES_DE_DESCARGA = 12582912;

    /**
     * Lado mayor con el que arranca la conversión, en píxeles.
     *
     * Una foto de catálogo en WhatsApp se ve en un teléfono: 1600 px de lado mayor es de sobra y
     * deja el JPEG bien por debajo del tope en la primera pasada, sin una segunda vuelta.
     */
    const LADO_MAXIMO = 1600;

    /**
     * Lado por debajo del cual ya no se sigue achicando: si a esa altura no entra, no es una foto.
     *
     * ⚠️ Es un piso del ACHICADO, no un mínimo de entrada: una foto que ya mide menos que esto se
     * convierte igual, en una sola pasada y sin tocarle el tamaño.
     */
    const LADO_MINIMO = 400;

    /**
     * Calidades de JPEG que se prueban, en orden, antes de achicar otra vez.
     */
    const CALIDADES_DE_JPEG = [82, 68, 55];

    /**
     * Segundos de espera de la descarga.
     *
     * Esto corre adentro del job del turno, que tiene `timeout` 60 y hasta seis fotos. Veinte
     * segundos por foto es el techo de lo que se puede gastar sin poner en riesgo el ingreso.
     */
    const SEGUNDOS_DE_DESCARGA = 20;

    /**
     * Decide si una foto puede salir por link o si hay que bajarla y convertirla.
     *
     * 🔴 **ACÁ ESTÁ LA TENTACIÓN, Y LAS DOS FORMAS DE SIMPLIFICARLA ESTÁN MAL.**
     *
     * *"Mandemos todo por link, que es más barato"* — es lo que se hacía hasta el 22/9/2026 y es el
     * bug: Meta acepta el request, contesta un `wamid`, valida el link después y descarta el webp
     * en silencio. No hay excepción, no hay status de error, no hay línea de log. La única señal es
     * el dueño diciendo que no le llegó nada.
     *
     * *"Convirtamos todas y listo, un solo camino"* — funciona, pero cada foto pasa a costar dos
     * viajes de red (bajarla del hosting del cliente y subirla a `/media`) más una pasada de GD, en
     * un worker que también corre deployments. Para una foto que **ya** es jpeg o png eso es gasto
     * puro: Meta la baja sola, gratis y sin que el admin toque un byte.
     *
     * Por eso la decisión es por extensión y no por lo que declare nadie: `.jpg`, `.jpeg` y `.png`
     * salen por link; **todo lo demás, incluida la URL sin extensión, se baja y se mira por los
     * bytes**. Sin extensión no se puede afirmar nada, y el camino largo es el único que termina en
     * un rechazo *sincrónico* — o sea, el único que puede contar bien.
     *
     * @param string $url URL pública de la foto, tal como la mandó el `empresa-api`.
     *
     * @return bool true si se puede mandar por link tal cual.
     */
    public function va_por_link(string $url): bool
    {
        $ruta      = (string) parse_url(trim($url), PHP_URL_PATH);
        $extension = strtolower((string) pathinfo($ruta, PATHINFO_EXTENSION));

        return in_array($extension, self::EXTENSIONES_QUE_META_BAJA, true);
    }

    /**
     * Baja la foto, confirma qué es de verdad y devuelve los bytes listos para subir a `/media`.
     *
     * @param string $url URL pública de la foto.
     *
     * @return array{binario: string|null, mime: string|null, nombre: string|null, convertida: bool, motivo: string|null}
     *         Con `binario` en null, `motivo` dice por qué en castellano y es lo que va al log.
     */
    public function preparar(string $url): array
    {
        $descarga = $this->descargar($url);
        if ($descarga['binario'] === null) {
            return $this->fallo((string) $descarga['motivo']);
        }

        $binario = (string) $descarga['binario'];

        /* 🔴 El tipo real sale de los BYTES y no de la extensión de la URL, que es justamente la
         * que no alcanzó para nada hasta acá. Misma regla que el servicio de las fotos entrantes:
         * un nombre de archivo es lo que alguien escribió, no lo que hay adentro. */
        $mime_real = $this->mime_de_los_bytes($binario);
        if ($mime_real === null) {
            return $this->fallo('Lo que devolvió el link no es una imagen que se pueda leer.');
        }

        /* Ya es de un tipo que Meta acepta y entra en el tope: se sube tal cual. Pasarla igual por
         * GD sería reencodear un JPEG —perder calidad— para llegar al mismo lugar. */
        if (in_array($mime_real, self::MIMES_QUE_META_ACEPTA, true)
            && strlen($binario) <= self::MAXIMO_DE_BYTES_PARA_META) {
            return [
                'binario'    => $binario,
                'mime'       => $mime_real,
                'nombre'     => $this->nombre_de_archivo($url, $mime_real),
                'convertida' => false,
                'motivo'     => null,
            ];
        }

        $jpeg = $this->a_jpeg($binario);
        if ($jpeg === null) {
            return $this->fallo('No se pudo convertir la foto a JPEG (llegó como ' . $mime_real . ').');
        }

        return [
            'binario'    => $jpeg,
            'mime'       => 'image/jpeg',
            'nombre'     => $this->nombre_de_archivo($url, 'image/jpeg'),
            'convertida' => true,
            'motivo'     => null,
        ];
    }

    /**
     * Baja los bytes del hosting del cliente.
     *
     * No reintenta: esto corre adentro del turno, después de que el texto ya salió, y una foto es
     * un extra. Un hosting que no contesta a la primera no justifica gastarle segundos al job.
     *
     * @param string $url URL pública de la foto.
     *
     * @return array{binario: string|null, motivo: string|null}
     */
    private function descargar(string $url): array
    {
        try {
            /* Sin credenciales y sin `retry()`: ver el docblock de la clase. El `Accept` es para que
             * un hosting que negocia contenido no devuelva una página de error en HTML. */
            $respuesta = Http::timeout(self::SEGUNDOS_DE_DESCARGA)
                ->withHeaders(['Accept' => 'image/*'])
                ->get(trim($url));
        } catch (\Throwable $excepcion) {
            return ['binario' => null, 'motivo' => 'No se pudo bajar la foto: ' . $excepcion->getMessage()];
        }

        if (! $respuesta->successful()) {
            return [
                'binario' => null,
                'motivo'  => 'El hosting del cliente respondió ' . $respuesta->status() . ' al pedir la foto.',
            ];
        }

        $binario = (string) $respuesta->body();
        if ($binario === '') {
            return ['binario' => null, 'motivo' => 'El link devolvió un archivo vacío.'];
        }

        $bytes = strlen($binario);
        if ($bytes > self::MAXIMO_DE_BYTES_DE_DESCARGA) {
            return [
                'binario' => null,
                'motivo'  => 'La foto pesa ' . $this->en_megas($bytes) . ' MB y el máximo que se baja es '
                    . $this->en_megas(self::MAXIMO_DE_BYTES_DE_DESCARGA) . ' MB.',
            ];
        }

        return ['binario' => $binario, 'motivo' => null];
    }

    /**
     * Convierte a JPEG lo que sea que haya llegado, hasta que entre en el tope de Meta.
     *
     * Primero achica al lado máximo, después baja calidad, y recién si con la calidad más baja
     * sigue sin entrar vuelve a achicar a la mitad. Ese orden es a propósito: bajar calidad no se
     * nota casi en una foto de catálogo, y achicar sí.
     *
     * ✅ El PHP 7.4 del VPS de producción tiene GD con WebP, JPEG y PNG (verificado el 22/9/2026
     * contra el servidor real), así que `imagecreatefromstring()` lee el webp derecho. Las guardas
     * de `function_exists` son para no reventar en un entorno sin GD: ahí la foto no sale, que es
     * exactamente lo que pasa hoy, no algo peor.
     *
     * @param string $binario Bytes del original.
     *
     * @return string|null Bytes del JPEG, o null si no se pudo.
     */
    private function a_jpeg(string $binario): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        $imagen = @imagecreatefromstring($binario);
        if ($imagen === false) {
            return null;
        }

        $ancho = imagesx($imagen);
        $alto  = imagesy($imagen);

        if ($ancho < 1 || $alto < 1) {
            imagedestroy($imagen);

            return null;
        }

        $lado = min(max($ancho, $alto), self::LADO_MAXIMO);

        /* 🔴 El bucle hace SIEMPRE una pasada, y recién después mira si puede achicar más. Con la
         * condición al principio (`while ($lado >= LADO_MINIMO)`), una foto de menos de 400 px de
         * lado —un thumbnail del catálogo, que los hay— no entraba nunca y volvía sin convertir, o
         * sea sin llegar. Lo agarró `test_el_409_reintenta_el_envio_sin_volver_a_bajar_ni_convertir`
         * con una imagen de 300 px. */
        while (true) {
            $lienzo = $this->aplanar_sobre_blanco($imagen, $ancho, $alto, $lado);

            if ($lienzo !== null) {
                foreach (self::CALIDADES_DE_JPEG as $calidad) {
                    $jpeg = $this->encodear_jpeg($lienzo, $calidad);

                    if ($jpeg !== null && strlen($jpeg) <= self::MAXIMO_DE_BYTES_PARA_META) {
                        imagedestroy($lienzo);
                        imagedestroy($imagen);

                        return $jpeg;
                    }
                }

                imagedestroy($lienzo);
            }

            if ($lado <= self::LADO_MINIMO) {
                break;
            }

            $lado = max(self::LADO_MINIMO, (int) floor($lado / 2));
        }

        imagedestroy($imagen);

        return null;
    }

    /**
     * Copia la imagen sobre un lienzo blanco del tamaño pedido.
     *
     * 🔴 **El blanco no es estética: JPEG no tiene canal alfa.** Un webp o un png con transparencia
     * —el fondo recortado de una foto de producto es el caso típico del catálogo— sale con el fondo
     * NEGRO si se lo encodea sin aplanar, porque el alfa se descarta y abajo queda el lienzo en
     * cero. Se pinta blanco primero y se copia encima con blending prendido.
     *
     * @param resource|\GdImage $imagen       Imagen de origen.
     * @param int               $ancho        Ancho del origen.
     * @param int               $alto         Alto del origen.
     * @param int               $lado_maximo  Lado mayor que tiene que tener el resultado.
     *
     * @return resource|\GdImage|null
     */
    private function aplanar_sobre_blanco($imagen, int $ancho, int $alto, int $lado_maximo)
    {
        $escala = min(1.0, $lado_maximo / max($ancho, $alto));

        $nuevo_ancho = max(1, (int) round($ancho * $escala));
        $nuevo_alto  = max(1, (int) round($alto * $escala));

        $lienzo = @imagecreatetruecolor($nuevo_ancho, $nuevo_alto);
        if ($lienzo === false) {
            return null;
        }

        imagealphablending($lienzo, true);

        $blanco = imagecolorallocate($lienzo, 255, 255, 255);
        imagefilledrectangle($lienzo, 0, 0, $nuevo_ancho - 1, $nuevo_alto - 1, $blanco);

        $copiada = @imagecopyresampled($lienzo, $imagen, 0, 0, 0, 0, $nuevo_ancho, $nuevo_alto, $ancho, $alto);
        if (! $copiada) {
            imagedestroy($lienzo);

            return null;
        }

        return $lienzo;
    }

    /**
     * Encodea un lienzo a JPEG en memoria.
     *
     * @param resource|\GdImage $lienzo  Imagen ya aplanada.
     * @param int               $calidad 0-100.
     *
     * @return string|null Bytes del JPEG, o null si GD no pudo.
     */
    private function encodear_jpeg($lienzo, int $calidad): ?string
    {
        ob_start();
        $salio = @imagejpeg($lienzo, null, $calidad);
        $bytes = ob_get_clean();

        if (! $salio || ! is_string($bytes) || $bytes === '') {
            return null;
        }

        return $bytes;
    }

    /**
     * Tipo real de una imagen, leído de sus bytes.
     *
     * @param string $binario Bytes del archivo.
     *
     * @return string|null Mime, o null si no es una imagen legible.
     */
    private function mime_de_los_bytes(string $binario): ?string
    {
        $info = @getimagesizefromstring($binario);
        if ($info === false || empty($info['mime'])) {
            return null;
        }

        $mime = strtolower(trim((string) $info['mime']));

        /* `image/jpg` no es un tipo real pero aparece en cabeceras viejas; se normaliza en vez de
         * descartarse, igual que en el servicio de las fotos entrantes. */
        return $mime === 'image/jpg' ? 'image/jpeg' : $mime;
    }

    /**
     * Nombre con el que el archivo viaja en el multipart de `/media`.
     *
     * 🔴 **La extensión tiene que coincidir con el `Content-Type`.** Meta valida el par al subir, y
     * un `foto.webp` declarado `image/jpeg` es un rechazo — que es lo mismo que ya pasa con el
     * audio (ver `resolve_whatsapp_audio_upload_filename()`). Por eso el nombre se arma desde el
     * mime de salida y no desde la URL de origen: de la URL solo se conserva la raíz, que es lo que
     * hace legible un error.
     *
     * @param string $url  URL de origen.
     * @param string $mime Mime con el que se sube.
     *
     * @return string
     */
    private function nombre_de_archivo(string $url, string $mime): string
    {
        $base = basename((string) parse_url(trim($url), PHP_URL_PATH));
        $base = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $base);
        $base = (string) pathinfo($base, PATHINFO_FILENAME);

        if ($base === '') {
            $base = 'foto';
        }

        return mb_strimwidth($base, 0, 60, '') . '.' . ($mime === 'image/png' ? 'png' : 'jpg');
    }

    /**
     * Resultado de un fallo, con su motivo.
     *
     * @param string $motivo Qué pasó, en castellano.
     *
     * @return array{binario: null, mime: null, nombre: null, convertida: bool, motivo: string}
     */
    private function fallo(string $motivo): array
    {
        return [
            'binario'    => null,
            'mime'       => null,
            'nombre'     => null,
            'convertida' => false,
            'motivo'     => $motivo,
        ];
    }

    /**
     * Megabytes con un decimal, para los motivos.
     *
     * @param int $bytes
     *
     * @return string
     */
    private function en_megas(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '');
    }
}
