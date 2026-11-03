<?php

namespace App\Services\Prueba;

use App\Models\Lead;
use App\Models\TrialInstance;

/**
 * Contrato C6, la lectura: `GET landing/prueba/{uuid}/estado` → `{ estado, etapa, progreso, url_ingreso,
 * video_url, whatsapp_soporte, mensaje }`.
 *
 *  - `estado`: `creando` mientras `ArmarPruebaJob` trabaja, `lista` cuando el negocio puede entrar, `fallida`
 *    si algo se rompió (con `mensaje` para la landing).
 *  - `etapa`: texto corto en castellano para la animación; `progreso` 0-100.
 *  - `url_ingreso`: solo con `lista`: `https://<slug>.comerciocity.com/demo/ingreso?t=<token>`.
 */
class PruebaEstadoService
{
    const ESTADO_CREANDO = 'creando';
    const ESTADO_LISTA   = 'lista';
    const ESTADO_FALLIDA = 'fallida';

    /** Etapa interna → texto que ve el negocio. */
    const TEXTOS = [
        'encolada'   => 'Reservando tu sistema',
        'reservada'  => 'Reservando tu sistema',
        'dns'        => 'Creando tu dirección',
        'env'        => 'Configurando tu sistema',
        'alias'      => 'Conectando tu dirección',
        'demo_setup' => 'Cargando datos de ejemplo de tu rubro',
        'ssl'        => 'Asegurando la conexión',
        'lista'      => 'Tu sistema está listo',
    ];

    const MENSAJE_FALLIDA = 'No pudimos terminar de armar tu sistema. Ya nos avisó el sistema; te escribimos por WhatsApp apenas esté listo.';

    /**
     * @return array<string, mixed>|null Null si el uuid no es de una prueba.
     */
    public function estado(string $uuid): ?array
    {
        /* El uuid PROPIO de la prueba (`trial_instances.seguimiento_uuid`), no el del lead: el del lead viaja en los
           links públicos de la demo y no abre el seguimiento de la prueba. */
        $instancia = TrialInstance::where('seguimiento_uuid', $uuid)->orderByDesc('id')->first();
        if ($instancia === null || ! $instancia->lead_id) {
            return null;
        }

        $lead = Lead::find($instancia->lead_id);
        if ($lead === null) {
            return null;
        }

        return $this->armar($lead, $instancia);
    }

    /**
     * @return array<string, mixed>
     */
    public function armar(Lead $lead, TrialInstance $instancia): array
    {
        $base = [
            'estado'           => self::ESTADO_CREANDO,
            'etapa'            => self::texto_de_etapa((string) $instancia->etapa),
            'progreso'         => max(0, min(100, (int) $instancia->progreso)),
            'url_ingreso'      => null,
            'video_url'        => PruebaSettings::video_url(),
            'whatsapp_soporte' => PruebaSettings::whatsapp_soporte(),
            'mensaje'          => null,
        ];

        if (in_array($instancia->estado, [TrialInstance::ESTADO_ASIGNADA, TrialInstance::ESTADO_CLIENTE], true)) {
            $slug  = (string) $instancia->alias_slug;
            $token = (string) $lead->demo_ingreso_token;

            $base['estado']      = self::ESTADO_LISTA;
            $base['etapa']       = self::TEXTOS['lista'];
            $base['progreso']    = 100;
            $base['url_ingreso'] = $slug !== '' && $token !== '' ? PruebaNombres::url_ingreso($slug, $token) : null;

            return $base;
        }

        if (in_array($instancia->estado, [TrialInstance::ESTADO_FALLIDA, TrialInstance::ESTADO_BORRANDO, TrialInstance::ESTADO_BORRADA], true)) {
            $base['estado']  = self::ESTADO_FALLIDA;
            $base['mensaje'] = self::MENSAJE_FALLIDA;

            return $base;
        }

        return $base;
    }

    public static function texto_de_etapa(string $etapa): string
    {
        if (isset(self::TEXTOS[$etapa])) {
            return self::TEXTOS[$etapa];
        }

        return self::TEXTOS['encolada'];
    }
}
