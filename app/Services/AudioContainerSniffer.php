<?php

namespace App\Services;

/**
 * Detecta el contenedor REAL de un audio mirando los bytes mágicos del archivo.
 *
 * 🔴 Por qué existe: ni la extensión ni el mime que declara el navegador dicen qué hay adentro. Un
 * `.ogg` puede ser Ogg/Vorbis (que WhatsApp no acepta como nota de voz), un `.oga` puede ser WebM de
 * Chrome, y el iPhone manda `video/mp4` para un m4a. Declararle a Meta `audio/ogg` sobre algo que no es
 * Ogg/Opus es la clase de bug del error 131053 ("processed as application/octet-stream"): el upload
 * parece andar y el mensaje nunca llega. Este detector es lo que decide qué se manda y qué se convierte.
 *
 * Es una clase de métodos estáticos y sin estado: solo mira bytes, no toca el disco salvo
 * {@see sniff_file()}, que lee la cabecera.
 */
class AudioContainerSniffer
{
    /** Ogg con códec Opus: el único Ogg que WhatsApp acepta (nota de voz). */
    const OGG_OPUS = 'ogg_opus';

    /** Ogg con códec Vorbis: hay que convertirlo. */
    const OGG_VORBIS = 'ogg_vorbis';

    /** Ogg con otro códec (FLAC, Speex, Theora…): hay que convertirlo. */
    const OGG_OTHER = 'ogg_other';

    /** WebM/Matroska (cabecera EBML): lo que graba Chrome con MediaRecorder. Hay que convertirlo. */
    const WEBM = 'webm';

    /** MP4/M4A/3GP (caja `ftyp`): lo que graba Safari. */
    const MP4 = 'mp4';

    /** MP3 (etiqueta ID3 o frame sync MPEG). */
    const MP3 = 'mp3';

    /** AAC en ADTS. */
    const AAC = 'aac';

    /** AMR. */
    const AMR = 'amr';

    /** WAV (RIFF/WAVE). WhatsApp no lo acepta: hay que convertirlo. */
    const WAV = 'wav';

    /** FLAC nativo. WhatsApp no lo acepta: hay que convertirlo. */
    const FLAC = 'flac';

    /**
     * Cantidad de bytes de cabecera que alcanzan para reconocer cualquiera de los contenedores.
     *
     * @var int
     */
    const HEADER_BYTES = 4096;

    /**
     * Reconoce el contenedor a partir de la cabecera del archivo.
     *
     * @param string $header Primeros bytes del archivo (con 64 alcanza para todos los casos).
     *
     * @return string|null Una de las constantes de esta clase, o null si no se reconoce.
     */
    public static function sniff(string $header): ?string
    {
        // Largo de la cabecera recibida.
        $length = strlen($header);
        if ($length < 4) {
            return null;
        }

        // Ogg: `OggS` + paquete de códec en la primera página.
        if (substr($header, 0, 4) === 'OggS') {
            return self::sniff_ogg($header);
        }

        // WebM/Matroska: cabecera EBML 1A 45 DF A3.
        if (substr($header, 0, 4) === "\x1A\x45\xDF\xA3") {
            return self::WEBM;
        }

        // MP4/M4A/3GP: la caja `ftyp` empieza en el offset 4.
        if ($length >= 8 && substr($header, 4, 4) === 'ftyp') {
            return self::MP4;
        }

        // AMR: `#!AMR`.
        if (substr($header, 0, 5) === '#!AMR') {
            return self::AMR;
        }

        // WAV: `RIFF` + 4 bytes de tamaño + `WAVE`.
        if ($length >= 12 && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WAVE') {
            return self::WAV;
        }

        // FLAC nativo.
        if (substr($header, 0, 4) === 'fLaC') {
            return self::FLAC;
        }

        // MP3 con etiqueta ID3.
        if (substr($header, 0, 3) === 'ID3') {
            return self::MP3;
        }

        // Frame sync de MPEG audio (MP3) o ADTS (AAC): 11 bits en 1 (FF + Ex/Fx).
        $first_byte = ord($header[0]);
        $second_byte = ord($header[1]);
        if ($first_byte === 0xFF && ($second_byte & 0xE0) === 0xE0) {
            // Bits de "layer": 00 en ADTS (AAC); 01/10/11 son las capas III/II/I de MPEG audio.
            $layer = ($second_byte >> 1) & 0x03;
            // Versión 01 está reservada en MPEG audio: no es un frame válido.
            $version = ($second_byte >> 3) & 0x03;
            if ($layer === 0) {
                return self::AAC;
            }
            if ($version !== 1) {
                return self::MP3;
            }
        }

        return null;
    }

    /**
     * Reconoce el contenedor de un archivo en disco leyendo solo su cabecera.
     *
     * @param string $absolute_path Ruta absoluta del archivo.
     *
     * @return string|null Contenedor, o null si no existe, no se puede leer o no se reconoce.
     */
    public static function sniff_file(string $absolute_path): ?string
    {
        if (! is_file($absolute_path)) {
            return null;
        }

        $handle = @fopen($absolute_path, 'rb');
        if ($handle === false) {
            return null;
        }

        $header = (string) fread($handle, self::HEADER_BYTES);
        fclose($handle);

        return self::sniff($header);
    }

    /**
     * Distingue Ogg/Opus de Ogg/Vorbis y de cualquier otro Ogg por el paquete de la primera página.
     *
     * La primera página Ogg es: 27 bytes de cabecera, una tabla de segmentos (el byte 26 dice cuántos) y
     * el cuerpo, que arranca con el paquete de identificación del códec (`OpusHead` o `\x01vorbis`).
     * Si por alguna razón el paquete no está justo en el offset esperado, se lo busca dentro de la
     * primera página (los primeros 255 bytes del cuerpo).
     *
     * @param string $header Cabecera que empieza con `OggS`.
     *
     * @return string OGG_OPUS, OGG_VORBIS u OGG_OTHER.
     */
    private static function sniff_ogg(string $header): string
    {
        // Cantidad de segmentos de la primera página (byte 26) y offset donde arranca el cuerpo.
        $segment_count = strlen($header) > 26 ? ord($header[26]) : 0;
        $body_offset = 27 + $segment_count;

        // Primeros bytes del cuerpo de la página: ahí está el paquete de identificación del códec.
        $body_start = (string) substr($header, $body_offset, 255);

        if (strpos($body_start, 'OpusHead') === 0) {
            return self::OGG_OPUS;
        }
        if (strpos($body_start, "\x01vorbis") === 0) {
            return self::OGG_VORBIS;
        }

        // Tolerancia: el paquete no está justo en el offset, pero sí dentro de la primera página.
        if (strpos($body_start, 'OpusHead') !== false) {
            return self::OGG_OPUS;
        }
        if (strpos($body_start, "\x01vorbis") !== false) {
            return self::OGG_VORBIS;
        }

        return self::OGG_OTHER;
    }

    /**
     * Indica si WhatsApp acepta ese contenedor tal cual, sin convertirlo.
     *
     * El MP4 queda afuera a propósito: WhatsApp acepta `audio/mp4`, pero el fMP4 de Chrome/Safari se
     * sube bien y se descarta al procesar, así que se lo convierte cuando hay ffmpeg (ver
     * {@see prefers_conversion()}).
     *
     * @param string|null $container Contenedor detectado.
     *
     * @return bool
     */
    public static function is_whatsapp_native(?string $container): bool
    {
        return in_array($container, [self::OGG_OPUS, self::MP3, self::AAC, self::AMR], true);
    }

    /**
     * Indica si el contenedor NO sirve para WhatsApp y SIEMPRE hay que convertirlo (sin ffmpeg no sale).
     *
     * @param string|null $container Contenedor detectado.
     *
     * @return bool
     */
    public static function requires_conversion(?string $container): bool
    {
        return in_array($container, [self::OGG_VORBIS, self::OGG_OTHER, self::WEBM, self::WAV, self::FLAC], true);
    }

    /**
     * Indica si conviene convertirlo cuando hay ffmpeg, pero si no hay sale tal cual (MP4/M4A).
     *
     * @param string|null $container Contenedor detectado.
     *
     * @return bool
     */
    public static function prefers_conversion(?string $container): bool
    {
        return $container === self::MP4;
    }

    /**
     * Indica si el contenedor es inequívocamente de audio (a diferencia de MP4/WebM, que también
     * pueden ser video).
     *
     * @param string|null $container Contenedor detectado.
     *
     * @return bool
     */
    public static function is_unambiguous_audio(?string $container): bool
    {
        return in_array($container, [
            self::OGG_OPUS, self::OGG_VORBIS, self::OGG_OTHER, self::MP3, self::AAC, self::AMR, self::WAV, self::FLAC,
        ], true);
    }

    /**
     * Mime normalizado (sin parámetros) que corresponde al contenedor.
     *
     * @param string $container Contenedor detectado.
     *
     * @return string
     */
    public static function mime_for(string $container): string
    {
        $map = [
            self::OGG_OPUS   => 'audio/ogg',
            self::OGG_VORBIS => 'audio/ogg',
            self::OGG_OTHER  => 'audio/ogg',
            self::WEBM       => 'audio/webm',
            self::MP4        => 'audio/mp4',
            self::MP3        => 'audio/mpeg',
            self::AAC        => 'audio/aac',
            self::AMR        => 'audio/amr',
            self::WAV        => 'audio/wav',
            self::FLAC       => 'audio/flac',
        ];

        return isset($map[$container]) ? $map[$container] : 'application/octet-stream';
    }

    /**
     * Extensión coherente con el contenedor.
     *
     * @param string $container Contenedor detectado.
     *
     * @return string
     */
    public static function extension_for(string $container): string
    {
        $map = [
            self::OGG_OPUS   => 'ogg',
            self::OGG_VORBIS => 'ogg',
            self::OGG_OTHER  => 'ogg',
            self::WEBM       => 'webm',
            self::MP4        => 'm4a',
            self::MP3        => 'mp3',
            self::AAC        => 'aac',
            self::AMR        => 'amr',
            self::WAV        => 'wav',
            self::FLAC       => 'flac',
        ];

        return isset($map[$container]) ? $map[$container] : 'bin';
    }

    /**
     * Nombre legible del contenedor para los mensajes al operador.
     *
     * @param string $container Contenedor detectado.
     *
     * @return string
     */
    public static function label_for(string $container): string
    {
        $map = [
            self::OGG_OPUS   => 'OGG/Opus',
            self::OGG_VORBIS => 'OGG/Vorbis',
            self::OGG_OTHER  => 'OGG',
            self::WEBM       => 'WebM',
            self::MP4        => 'MP4/M4A',
            self::MP3        => 'MP3',
            self::AAC        => 'AAC',
            self::AMR        => 'AMR',
            self::WAV        => 'WAV',
            self::FLAC       => 'FLAC',
        ];

        return isset($map[$container]) ? $map[$container] : $container;
    }
}
