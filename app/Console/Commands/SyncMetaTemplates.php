<?php

namespace App\Console\Commands;

use App\Models\FollowupTemplate;
use App\Services\MetaTemplateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Trae de Meta el estado de todas las plantillas registradas y activa sola la que fue aprobada.
 *
 * 🔴 ESTE COMANDO EXISTE PORQUE META NO AVISA. Relevado el 13/10/2026 (ficha
 * `seguimientos-y-plantillas.md` §4): el proxy de Kapso no documenta webhooks de estado de
 * plantilla, y los dos webhooks que el admin recibe hoy solo procesan `whatsapp.message.*` y
 * `messages[].referral`. O sea que una plantilla creada queda en `PENDING` para siempre desde el
 * punto de vista del admin, aunque Meta la haya aprobado veinte minutos después. Sin este barrido,
 * cada plantilla nueva necesitaría que alguien se acuerde de ir a mirarla.
 *
 * Una pasada son una o dos llamadas HTTP para TODAS las plantillas (el listado de Meta viene
 * paginado de 100 en 100 y hoy hay ~38), no una por fila: por eso corre cada hora sin costo real.
 *
 * Lo que hace, en orden:
 *   1. Pide la lista completa de plantillas de la WABA.
 *   2. Las cruza por `name` con `followup_templates.template_name`.
 *   3. Baja el estado, la categoría, el id, el idioma y el motivo de rechazo de cada una, con
 *      {@see MetaTemplateService::aplicar_estado_de_meta()} — que es donde vive la regla de
 *      activación y desactivación, compartida con `POST claude/templates/meta/{name}/refresh`.
 *
 * 🔴 LO QUE NO HACE, Y ES LO QUE LO VUELVE SEGURO DE CORRER CADA HORA SOBRE PRODUCCIÓN: no toca una
 * fila que Meta no devolvió. Hoy hay nombres de plantilla hardcodeados en el código (`cc_check_*`,
 * `cc_recordatorio_*`, `cc_admin_*`) y filas sembradas por los seeders; si una no aparece en la
 * respuesta —porque está en otra WABA, porque la lista se cortó, porque Meta contestó 200 con menos
 * datos— lo correcto es dejarla como está y contarla aparte. Apagarlas en masa dejaría al pipeline
 * sin poder mandar una sola plantilla fuera de la ventana de 24 hs, en silencio.
 *
 * 🔴 Y NO CREA NI BORRA NADA, ni en Meta ni en la base: solo actualiza filas que ya existen. Las
 * plantillas que Meta tiene y el admin no quedan reportadas como `en_meta_sin_registrar` para que
 * alguien decida si vale registrarlas (`POST claude/followup-templates`), pero este comando no
 * decide eso solo.
 */
class SyncMetaTemplates extends Command
{
    /**
     * @var string
     */
    protected $signature = 'templates:sync-meta';

    /**
     * @var string
     */
    protected $description = 'Trae de Meta el estado de las plantillas de WhatsApp registradas y activa las aprobadas';

    /**
     * @param MetaTemplateService $meta
     *
     * @return int
     */
    public function handle(MetaTemplateService $meta)
    {
        $respuesta = $meta->listar();

        if ($respuesta['ok'] !== true) {
            /*
             * Exit 1 y no 0: el scheduler no lo mira, pero una corrida a mano sí, y sobre todo el
             * `/deploy-admin` que lo corre después del deploy para validar la integración (es lo
             * que pide el plan de M5: traer el estado de las ~38 que ya existen sin crear ninguna).
             * Un "sin configuración" que devuelve 0 parece un éxito con cero plantillas.
             */
            $this->error('No se pudo listar las plantillas de Meta (' . $respuesta['motivo'] . '): '
                . ($respuesta['mensaje'] !== null ? $respuesta['mensaje'] : 'sin detalle'));

            if ($respuesta['motivo'] === 'sin_permiso_en_meta') {
                $this->warn('La clave de Kapso no tiene permiso de plantillas sobre esta WABA: el estado se mira a '
                    . 'mano en Meta Business Suite (contexto/cuentas_meta.md del repo de conocimiento).');
            }
            if ($respuesta['motivo'] === 'sin_waba_id') {
                $this->warn('Cargá el WABA en el panel, en Cuenta > WhatsApp: las plantillas se piden por WABA y no '
                    . 'por phone_number_id.');
            }

            Log::channel('daily')->warning('templates:sync-meta: no se pudo listar las plantillas.', [
                'motivo' => $respuesta['motivo'],
                'http'   => $respuesta['http'],
            ]);

            return 1;
        }

        /* Lista de Meta indexada por nombre, para cruzar sin N consultas. */
        $de_meta = [];
        foreach ($respuesta['datos'] as $fila_de_meta) {
            if (isset($fila_de_meta['name']) && trim((string) $fila_de_meta['name']) !== '') {
                $de_meta[(string) $fila_de_meta['name']] = $fila_de_meta;
            }
        }

        $stats = [
            'en_meta'               => count($de_meta),
            'registradas'           => 0,
            'encontradas'           => 0,
            'sin_respuesta_de_meta' => 0,
            'cambiaron'             => 0,
            'activadas'             => 0,
            'desactivadas'          => 0,
        ];
        $activadas = [];
        $desactivadas = [];

        $filas = FollowupTemplate::query()->orderBy('id')->get();
        $stats['registradas'] = $filas->count();

        foreach ($filas as $fila) {
            $nombre = (string) $fila->template_name;
            $de_esta = isset($de_meta[$nombre]) ? $de_meta[$nombre] : [];

            $cambio = $meta->aplicar_estado_de_meta($fila, $de_esta);

            if ($cambio['existe_en_meta'] !== true) {
                $stats['sin_respuesta_de_meta']++;
                continue;
            }

            $stats['encontradas']++;
            if ($cambio['cambio'] === true) {
                $stats['cambiaron']++;
            }
            if ($cambio['activada'] === true) {
                $stats['activadas']++;
                $activadas[] = $nombre;
            }
            if ($cambio['desactivada'] === true) {
                $stats['desactivadas']++;
                $desactivadas[] = $nombre;
            }
        }

        /* Las que Meta tiene y el admin no. No se registran solas: ver el 🔴 del docblock. */
        $registrados = $filas->pluck('template_name')->map(function ($nombre) {
            return (string) $nombre;
        })->all();
        $sin_registrar = array_values(array_diff(array_keys($de_meta), $registrados));

        $this->info('Plantillas en Meta: ' . $stats['en_meta']);
        $this->info('Registradas en el admin: ' . $stats['registradas']);
        $this->info('Cruzadas por nombre: ' . $stats['encontradas']);
        $this->info('Sin respuesta de Meta (quedaron como estaban): ' . $stats['sin_respuesta_de_meta']);
        $this->info('Cambiaron de estado: ' . $stats['cambiaron']);
        $this->info('Activadas por pasar a APPROVED: ' . $stats['activadas']
            . (! empty($activadas) ? ' (' . implode(', ', $activadas) . ')' : ''));
        $this->info('Desactivadas por rechazo o baja: ' . $stats['desactivadas']
            . (! empty($desactivadas) ? ' (' . implode(', ', $desactivadas) . ')' : ''));
        $this->info('En Meta sin registrar en el admin: ' . count($sin_registrar)
            . (! empty($sin_registrar) ? ' (' . implode(', ', $sin_registrar) . ')' : ''));

        Log::channel('daily')->info('templates:sync-meta: barrido terminado.', [
            'stats'                  => $stats,
            'activadas'              => $activadas,
            'desactivadas'           => $desactivadas,
            'en_meta_sin_registrar'  => $sin_registrar,
        ]);

        return 0;
    }
}
