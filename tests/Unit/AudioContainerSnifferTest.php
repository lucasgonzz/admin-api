<?php

namespace Tests\Unit;

use App\Services\AudioContainerSniffer;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\BytesDeAudioSinteticos;

/**
 * El detector de contenedor de audio decide qué se le declara a Meta y qué se convierte.
 *
 * Lo que se protege es que NUNCA se tome un contenedor por otro: Ogg/Vorbis y Ogg/Opus se parecen en
 * los primeros cuatro bytes (`OggS`) y se distinguen solo por el paquete de la primera página, y declarar
 * `audio/ogg` sobre un Vorbis o un WebM es el error 131053 (Meta acepta el upload y descarta el mensaje).
 * Los bytes son sintéticos, armados en {@see BytesDeAudioSinteticos}.
 */
class AudioContainerSnifferTest extends TestCase
{
    use BytesDeAudioSinteticos;

    /**
     * Ogg con OpusHead en la primera página es Ogg/Opus.
     *
     * @return void
     */
    public function test_reconoce_ogg_opus()
    {
        $this->assertSame(AudioContainerSniffer::OGG_OPUS, AudioContainerSniffer::sniff($this->bytes_ogg_opus()));
    }

    /**
     * Ogg con `\x01vorbis` es Vorbis: hay que convertirlo, no mandarlo como nota de voz.
     *
     * @return void
     */
    public function test_ogg_vorbis_no_se_confunde_con_opus()
    {
        $contenedor = AudioContainerSniffer::sniff($this->bytes_ogg_vorbis());

        $this->assertSame(AudioContainerSniffer::OGG_VORBIS, $contenedor);
        $this->assertTrue(AudioContainerSniffer::requires_conversion($contenedor));
        $this->assertFalse(AudioContainerSniffer::is_whatsapp_native($contenedor));
    }

    /**
     * Un Ogg con otro códec (ni Opus ni Vorbis) se marca como "otro Ogg" y también se convierte.
     *
     * @return void
     */
    public function test_ogg_con_otro_codec_es_ogg_other()
    {
        $contenedor = AudioContainerSniffer::sniff($this->pagina_ogg("\x7fFLAC\x01\x00\x00\x01"));

        $this->assertSame(AudioContainerSniffer::OGG_OTHER, $contenedor);
        $this->assertTrue(AudioContainerSniffer::requires_conversion($contenedor));
    }

    /**
     * La cabecera EBML es WebM (lo que graba Chrome) y requiere conversión.
     *
     * @return void
     */
    public function test_reconoce_webm_por_la_cabecera_ebml()
    {
        $contenedor = AudioContainerSniffer::sniff($this->bytes_webm());

        $this->assertSame(AudioContainerSniffer::WEBM, $contenedor);
        $this->assertTrue(AudioContainerSniffer::requires_conversion($contenedor));
    }

    /**
     * `ftyp` en el offset 4 es MP4/M4A: sale tal cual si no hay ffmpeg, pero se prefiere convertirlo.
     *
     * @return void
     */
    public function test_reconoce_mp4_por_ftyp_en_el_offset_4()
    {
        $contenedor = AudioContainerSniffer::sniff($this->bytes_m4a());

        $this->assertSame(AudioContainerSniffer::MP4, $contenedor);
        $this->assertTrue(AudioContainerSniffer::prefers_conversion($contenedor));
        $this->assertFalse(AudioContainerSniffer::requires_conversion($contenedor));
    }

    /**
     * MP3: por la etiqueta ID3 y por el frame sync de MPEG audio (FF Fx).
     *
     * @return void
     */
    public function test_reconoce_mp3_con_id3_y_con_frame_sync()
    {
        $this->assertSame(AudioContainerSniffer::MP3, AudioContainerSniffer::sniff($this->bytes_mp3_id3()));
        // Frame sync MPEG-1 Layer III: FF FB.
        $this->assertSame(AudioContainerSniffer::MP3, AudioContainerSniffer::sniff("\xFF\xFB\x90\x00" . str_repeat("\x00", 16)));
        $this->assertTrue(AudioContainerSniffer::is_whatsapp_native(AudioContainerSniffer::MP3));
    }

    /**
     * ADTS AAC (FF F1 / FF F9) NO es MP3: el campo de layer es 00.
     *
     * @return void
     */
    public function test_reconoce_aac_adts_y_no_lo_confunde_con_mp3()
    {
        $this->assertSame(AudioContainerSniffer::AAC, AudioContainerSniffer::sniff("\xFF\xF1\x50\x80" . str_repeat("\x00", 16)));
        $this->assertSame(AudioContainerSniffer::AAC, AudioContainerSniffer::sniff("\xFF\xF9\x50\x80" . str_repeat("\x00", 16)));
    }

    /**
     * AMR, WAV y FLAC tienen su marca propia.
     *
     * @return void
     */
    public function test_reconoce_amr_wav_y_flac()
    {
        $this->assertSame(AudioContainerSniffer::AMR, AudioContainerSniffer::sniff("#!AMR\n" . str_repeat("\x00", 16)));
        $this->assertSame(AudioContainerSniffer::WAV, AudioContainerSniffer::sniff('RIFF' . "\x24\x00\x00\x00" . 'WAVEfmt ' . str_repeat("\x00", 16)));
        $this->assertSame(AudioContainerSniffer::FLAC, AudioContainerSniffer::sniff('fLaC' . str_repeat("\x00", 16)));
        $this->assertTrue(AudioContainerSniffer::requires_conversion(AudioContainerSniffer::WAV));
        $this->assertTrue(AudioContainerSniffer::is_whatsapp_native(AudioContainerSniffer::AMR));
    }

    /**
     * Bytes que no son ningún audio (una imagen, texto, un RIFF que no es WAVE) dan null, no un falso positivo.
     *
     * @return void
     */
    public function test_lo_que_no_es_audio_da_null()
    {
        $this->assertNull(AudioContainerSniffer::sniff("\xFF\xD8\xFF\xE0" . str_repeat("\x00", 16)), 'Un JPEG se tomó por audio.');
        $this->assertNull(AudioContainerSniffer::sniff("%PDF-1.4\n"));
        $this->assertNull(AudioContainerSniffer::sniff('<html></html>'));
        $this->assertNull(AudioContainerSniffer::sniff('RIFF' . "\x24\x00\x00\x00" . 'AVI LIST'), 'Un AVI se tomó por WAV.');
        $this->assertNull(AudioContainerSniffer::sniff(''));
        $this->assertNull(AudioContainerSniffer::sniff('Og'));
    }

    /**
     * El mime y la extensión salen del contenedor real, no de lo que declaró el navegador.
     *
     * @return void
     */
    public function test_mime_y_extension_coherentes_con_el_contenedor()
    {
        $this->assertSame('audio/ogg', AudioContainerSniffer::mime_for(AudioContainerSniffer::OGG_OPUS));
        $this->assertSame('ogg', AudioContainerSniffer::extension_for(AudioContainerSniffer::OGG_OPUS));
        $this->assertSame('audio/mp4', AudioContainerSniffer::mime_for(AudioContainerSniffer::MP4));
        $this->assertSame('m4a', AudioContainerSniffer::extension_for(AudioContainerSniffer::MP4));
        $this->assertSame('audio/mpeg', AudioContainerSniffer::mime_for(AudioContainerSniffer::MP3));
        $this->assertSame('webm', AudioContainerSniffer::extension_for(AudioContainerSniffer::WEBM));
    }

    /**
     * Solo los contenedores inequívocos cuentan como audio sin pista: MP4 y WebM también pueden ser video.
     *
     * @return void
     */
    public function test_mp4_y_webm_no_son_inequivocamente_audio()
    {
        $this->assertFalse(AudioContainerSniffer::is_unambiguous_audio(AudioContainerSniffer::MP4));
        $this->assertFalse(AudioContainerSniffer::is_unambiguous_audio(AudioContainerSniffer::WEBM));
        $this->assertTrue(AudioContainerSniffer::is_unambiguous_audio(AudioContainerSniffer::OGG_OPUS));
        $this->assertTrue(AudioContainerSniffer::is_unambiguous_audio(AudioContainerSniffer::MP3));
    }

    /**
     * Leído de un archivo: solo mira la cabecera, y un archivo inexistente da null sin romper.
     *
     * @return void
     */
    public function test_sniff_file_lee_la_cabecera_de_un_archivo()
    {
        $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sniff_' . uniqid('', true) . '.bin';
        file_put_contents($ruta, $this->bytes_ogg_opus());

        try {
            $this->assertSame(AudioContainerSniffer::OGG_OPUS, AudioContainerSniffer::sniff_file($ruta));
        } finally {
            @unlink($ruta);
        }

        $this->assertNull(AudioContainerSniffer::sniff_file($ruta));
    }
}
