<?php

namespace App\Mail;

/**
 * Contenido estructurado para correos transaccionales ComercioCity en admin-api.
 *
 * Recibe un único array asociativo; cada clave corresponde a una parte del mail:
 *
 * - subject (string)              Asunto del correo
 * - title (string)                Titular principal del cuerpo
 * - paragraphs (string[])         Párrafos de texto, en orden
 * - detail_lines (array)          Lista de filas "etiqueta: valor". Cada ítem: label, value, opcional bold_label (bool)
 * - links (array)                 Enlaces en el cuerpo. Cada ítem: text, url
 * - closing (string|null)         Texto de cierre (p. ej. agradecimiento), opcional
 * - preheader (string|null)       Texto de previsualización junto al asunto, opcional
 * - footer_links (array)          Íconos del pie. Cada ítem: img_url, link_url. Si no se envía, se usa defaultFooterLinks()
 *
 * Extras opcionales para el layout tipo "tarjeta de presentación":
 *
 * - avatar_url (string|null)          URL absoluta del avatar (foto del presentador)
 * - presenter_name (string|null)      Nombre que se muestra debajo del avatar
 * - presenter_role (string|null)      Cargo / rol debajo del nombre
 * - hero_image_url (string|null)      Imagen destacada opcional (hero) arriba del título
 * - video_url (string|null)           URL a la que lleva el click en el "play card"
 * - video_thumbnail_url (string|null) Miniatura del video (se le aplica overlay de play)
 * - video_caption (string|null)       Leyenda debajo del video
 * - video_secondary_cta (array|null)  Botón secundario debajo del caption del video: { text, url }
 * - cta (array|null)                  Call to action: { text, url } — botón destacado
 *
 * Todos los extras son opcionales; cuando faltan, los partials no renderizan
 * su bloque, manteniendo compatibilidad con otros usos del mismo layout.
 */
class ComercioCityMailPayload
{
    /** @var string Asunto del correo */
    public $subject;

    /** @var string Titular principal del cuerpo */
    public $title;

    /** @var string[] Párrafos de texto, en orden */
    public $paragraphs;

    /**
     * Líneas tipo "Etiqueta: valor" (p. ej. detalle de pago).
     *
     * @var array<int, array<string, mixed>>
     */
    public $detail_lines;

    /**
     * Enlaces mostrados en el cuerpo (texto + URL).
     *
     * @var array<int, array<string, string>>
     */
    public $links;

    /** @var string|null Texto de cierre opcional */
    public $closing;

    /** @var string|null Texto oculto junto al asunto en muchos clientes */
    public $preheader;

    /**
     * Enlaces del pie con imagen clickeable. Cada ítem: img_url (absoluta), link_url (destino).
     *
     * @var array<int, array<string, string>>
     */
    public $footer_links;

    /** @var string|null URL absoluta del avatar en la tarjeta de presentación */
    public $avatar_url;

    /** @var string|null Nombre del presentador en la tarjeta */
    public $presenter_name;

    /** @var string|null Cargo / rol del presentador */
    public $presenter_role;

    /** @var string|null Imagen destacada (hero) opcional */
    public $hero_image_url;

    /** @var string|null URL del video que se abre al click */
    public $video_url;

    /** @var string|null Miniatura del video */
    public $video_thumbnail_url;

    /** @var string|null Leyenda debajo del video */
    public $video_caption;

    /**
     * CTA secundario debajo del caption del video.
     *
     * @var array<string, string>|null Estructura: ['text' => ..., 'url' => ...]
     */
    public $video_secondary_cta;

    /**
     * Call to action del correo.
     *
     * @var array<string, string>|null Estructura: ['text' => ..., 'url' => ...]
     */
    public $cta;

    /**
     * Valores por defecto del pie: editar img_url y link_url acá o pasar "footer_links"
     * en el constructor para sobrescribirlos.
     *
     * @return array<int, array<string, string>>
     */
    public static function defaultFooterLinks()
    {
        return [
            [
                'img_url' => 'https://api.comerciocity.com/public/storage/www.png',
                'link_url' => 'https://comerciocity.com',
            ],
            [
                'img_url' => 'https://api.comerciocity.com/public/storage/instagram.png',
                'link_url' => 'https://www.instagram.com/comerciocity_com',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data Claves soportadas (todas opcionales salvo subject/title):
     *                                   subject, title, paragraphs, detail_lines, links, closing,
     *                                   preheader, footer_links, avatar_url, presenter_name,
     *                                   presenter_role, hero_image_url, video_url,
     *                                   video_thumbnail_url, video_caption,
     *                                   video_secondary_cta, cta
     */
    public function __construct(array $data)
    {
        // Campos base del layout ComercioCity
        $this->subject = isset($data['subject']) ? (string) $data['subject'] : '';
        $this->title = isset($data['title']) ? (string) $data['title'] : '';
        $this->paragraphs = isset($data['paragraphs']) && is_array($data['paragraphs'])
            ? $data['paragraphs']
            : [];
        $this->detail_lines = isset($data['detail_lines']) && is_array($data['detail_lines'])
            ? $data['detail_lines']
            : [];
        $this->links = isset($data['links']) && is_array($data['links'])
            ? $data['links']
            : [];
        $this->closing = array_key_exists('closing', $data) ? $data['closing'] : null;
        $this->preheader = array_key_exists('preheader', $data) ? $data['preheader'] : null;

        // Footer: si no se pasa, se cargan los íconos por defecto (web + instagram)
        if (isset($data['footer_links']) && is_array($data['footer_links'])) {
            $this->footer_links = self::normalizeFooterLinks($data['footer_links']);
        } else {
            $this->footer_links = self::normalizeFooterLinks(self::defaultFooterLinks());
        }

        // Extras opcionales para el bloque de "tarjeta de presentación"
        $this->avatar_url = self::nullable_string($data, 'avatar_url');
        $this->presenter_name = self::nullable_string($data, 'presenter_name');
        $this->presenter_role = self::nullable_string($data, 'presenter_role');
        $this->hero_image_url = self::nullable_string($data, 'hero_image_url');
        $this->video_url = self::nullable_string($data, 'video_url');
        $this->video_thumbnail_url = self::nullable_string($data, 'video_thumbnail_url');
        $this->video_caption = self::nullable_string($data, 'video_caption');
        $this->video_secondary_cta = self::normalize_cta($data['video_secondary_cta'] ?? null);
        $this->cta = self::normalize_cta($data['cta'] ?? null);
    }

    /**
     * Indica si el payload contiene información suficiente para renderizar
     * el bloque de tarjeta de presentación (avatar, hero o video).
     *
     * @return bool true si al menos uno de los campos de tarjeta está presente
     */
    public function has_presentation_card()
    {
        return !empty($this->avatar_url)
            || !empty($this->presenter_name)
            || !empty($this->hero_image_url)
            || !empty($this->video_url)
            || !empty($this->video_thumbnail_url);
    }

    /**
     * Normaliza los enlaces del pie descartando filas incompletas.
     *
     * @param array<int, mixed> $links
     *
     * @return array<int, array<string, string>>
     */
    private static function normalizeFooterLinks(array $links)
    {
        $out = [];
        foreach ($links as $row) {
            if (!is_array($row)) {
                continue;
            }
            $imgUrl = isset($row['img_url']) ? trim((string) $row['img_url']) : '';
            $linkUrl = isset($row['link_url']) ? trim((string) $row['link_url']) : '';
            if ($imgUrl === '' || $linkUrl === '') {
                continue;
            }
            $out[] = [
                'img_url' => $imgUrl,
                'link_url' => $linkUrl,
            ];
        }

        return $out;
    }

    /**
     * Extrae un string opcional del array de datos. Si la clave no existe,
     * es null o string vacío luego de trim, devuelve null.
     *
     * @param array<string, mixed> $data
     * @param string $key
     *
     * @return string|null
     */
    private static function nullable_string(array $data, $key)
    {
        if (!array_key_exists($key, $data) || is_null($data[$key])) {
            return null;
        }
        $value = trim((string) $data[$key]);

        return $value === '' ? null : $value;
    }

    /**
     * Valida y normaliza el call to action. Requiere text y url no vacíos.
     *
     * @param mixed $cta
     *
     * @return array<string, string>|null
     */
    private static function normalize_cta($cta)
    {
        if (!is_array($cta)) {
            return null;
        }
        $text = isset($cta['text']) ? trim((string) $cta['text']) : '';
        $url = isset($cta['url']) ? trim((string) $cta['url']) : '';
        if ($text === '' || $url === '') {
            return null;
        }

        return ['text' => $text, 'url' => $url];
    }
}
