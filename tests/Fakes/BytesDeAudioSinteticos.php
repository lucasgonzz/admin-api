<?php

namespace Tests\Fakes;

/**
 * Bytes de audio armados a mano para los tests (no hay binarios en el repo).
 *
 * Son cabeceras mínimas: alcanzan para el detector de contenedor ({@see \App\Services\AudioContainerSniffer})
 * y para ejercitar el flujo de envío con Kapso falseado, pero NO son audio reproducible.
 */
trait BytesDeAudioSinteticos
{
    /**
     * Primera página Ogg mínima con el paquete de cabecera de un códec.
     *
     * Cabecera Ogg: `OggS` (4), versión (1), flags (1), granule (8), serial (4), secuencia (4), CRC (4),
     * cantidad de segmentos (1, el byte 26) y la tabla de segmentos; después viene el cuerpo con el
     * paquete del códec.
     *
     * @param string $codec_packet Paquete de cabecera del códec (p. ej. `OpusHead…`).
     *
     * @return string
     */
    protected function pagina_ogg(string $codec_packet): string
    {
        return 'OggS' . "\x00" . "\x02"
            . str_repeat("\x00", 8)
            . "\x01\x00\x00\x00"
            . "\x00\x00\x00\x00"
            . "\x00\x00\x00\x00"
            . chr(1) . chr(strlen($codec_packet))
            . $codec_packet
            . str_repeat("\x00", 40);
    }

    /**
     * Ogg/Opus: el único Ogg que WhatsApp acepta como nota de voz.
     *
     * @return string
     */
    protected function bytes_ogg_opus(): string
    {
        return $this->pagina_ogg("OpusHead\x01\x01\x38\x01\x80\xbb\x00\x00\x00\x00\x00");
    }

    /**
     * Ogg/Vorbis: mismo contenedor Ogg pero con un códec que WhatsApp NO acepta.
     *
     * @return string
     */
    protected function bytes_ogg_vorbis(): string
    {
        return $this->pagina_ogg("\x01vorbis\x00\x00\x00\x00\x01\x44\xac\x00\x00");
    }

    /**
     * Cabecera EBML de un WebM (lo que graba Chrome).
     *
     * @return string
     */
    protected function bytes_webm(): string
    {
        return "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm" . str_repeat("\x00", 64);
    }

    /**
     * Caja `ftyp` de un M4A (lo que graba Safari).
     *
     * @return string
     */
    protected function bytes_m4a(): string
    {
        return "\x00\x00\x00\x20ftypM4A \x00\x00\x00\x00M4A mp42isom" . str_repeat("\x00", 64);
    }

    /**
     * Cabecera de un MP3 con etiqueta ID3.
     *
     * @return string
     */
    protected function bytes_mp3_id3(): string
    {
        return "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\x00", 64);
    }
}
