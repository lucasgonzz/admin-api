<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FollowupTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Alta en lote de las plantillas de LEAD (`followup_templates`) desde afuera, por Claude.
 *
 * Mismo molde que {@see ClaudeClientTemplatesController}, que hace lo mismo para las plantillas de
 * SOPORTE (`client_templates`): Lucas y Claude arman el texto, Lucas lo hace aprobar en Meta, y
 * después Claude lo carga acá con la clave del header. No hay pantalla de alta en el admin para
 * estas plantillas — `FollowupTemplateController` solo tiene `update_json()` (PUT), nunca un POST.
 *
 * 🔴 Dos propiedades que no son adorno y hay que conservar si esto se toca:
 *
 *  1. IDEMPOTENCIA POR `template_name`. Claude reenvía el lote entero cada vez que corrige un
 *     texto o un día. Sin idempotencia, la segunda corrida deja la plantilla repetida.
 *  2. ES ESTRICTAMENTE ADITIVO: nunca borra una fila que no vino en el payload. Mismo criterio que
 *     ClaudeClientTemplatesController y ClaudeVersionItemsIngestController.
 *
 * 🔴 EL `estado` DE UNA PLANTILLA DE LEAD NO TIENE POR QUÉ SER UN STATUS DEL PIPELINE, Y ESO ES
 * DELIBERADO PARA LAS PLANTILLAS DEL CHEQUEO DIARIO (`manual_coordinacion`, `manual_closer`,
 * `manual_nutricion`, ver `comercial/plantillas_chequeo_diario.md` del repo de conocimiento).
 * `LeadFollowupService::find_template_for()` busca plantillas con
 * `where('estado', $lead->status)`, y `$lead->status` es siempre un slug real del pipeline
 * (`LeadPipelineStatus::all_slugs()`, ninguno empieza con `manual_`). Una plantilla cargada con un
 * `estado` que no es un status real del pipeline NUNCA puede matchear esa consulta, así que el
 * cron de seguimientos automáticos (cada 2 horas) jamás la levanta ni la dispara sola — queda
 * disponible solo para que un humano (o el chequeo diario, `/leads`) la mande a mano por
 * `POST claude/leads/{id}/send-template`. Este endpoint NO fuerza el prefijo `manual_`: es un alta
 * genérica para `followup_templates`, y la convención de nombrar así los estados que no son del
 * pipeline es responsabilidad de quien arma el payload, no una regla de este controlador.
 */
class ClaudeFollowupTemplatesController extends Controller
{
    /**
     * Alta idempotente de un lote de plantillas de lead.
     *
     * Reenviar el mismo `template_name` actualiza la fila; nunca crea una segunda. Lo que no vino
     * en el payload se queda donde está.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        // Validación de contrato externo: este endpoint lo llama Claude con la clave del header,
        // no el SPA, así que acá sí conviene validar campo por campo.
        $request->validate([
            'templates'                              => 'required|array|min:1',
            'templates.*.template_name'              => 'required|string|max:120',
            'templates.*.estado'                     => 'required|string|max:40',
            'templates.*.dia_numero'                 => 'required|integer|min:1',
            'templates.*.body_template'              => 'required|string',
            'templates.*.language_code'              => 'nullable|string|max:10',
            'templates.*.activa'                     => 'nullable|boolean',
            'templates.*.solo_si_ingreso_confirmado' => 'nullable|boolean',
        ]);

        // Filas del payload, filtradas con is_array por robustez aunque la validación ya lo cubra.
        $filas = array_values(array_filter((array) $request->input('templates', []), 'is_array'));

        $resultados = ['creadas' => 0, 'actualizadas' => 0];

        // Nombres tocados en esta corrida: se usa al final para devolver exactamente las filas que
        // el payload afectó, no la tabla entera.
        $nombres_tocados = [];

        // El lote entra o no entra: si una fila explota a mitad, no puede quedar medio lote cargado,
        // porque Claude no tiene cómo saber dónde se cortó.
        DB::transaction(function () use ($filas, &$resultados, &$nombres_tocados) {
            foreach ($filas as $fila) {
                // Un nombre con espacios de más rompería la idempotencia: la segunda corrida no
                // encontraría la fila y crearía una gemela. Se trimea antes de buscar y de guardar.
                $template_name = trim((string) ($fila['template_name'] ?? ''));
                if ($template_name === '') {
                    continue;
                }

                // `followup_templates` no tiene índice único en `template_name` (a diferencia de
                // `client_templates`): el lock solo protege la fila si ya existe. Alcanza para el
                // uso real de este endpoint (Claude reenvía el lote de a una corrida por vez, nunca
                // en paralelo); no es la garantía a prueba de dos requests simultáneos que sí tiene
                // ClaudeClientTemplatesController.
                $existente = FollowupTemplate::query()
                    ->where('template_name', $template_name)
                    ->lockForUpdate()
                    ->first();

                $datos = $this->fila_de_datos($fila, $template_name, $existente);

                if ($existente !== null) {
                    $existente->update($datos);
                    $resultados['actualizadas']++;
                } else {
                    FollowupTemplate::create($datos);
                    $resultados['creadas']++;
                }

                $nombres_tocados[] = $template_name;
            }
        });

        $templates = FollowupTemplate::query()
            ->whereIn('template_name', $nombres_tocados)
            ->orderBy('estado')
            ->orderBy('dia_numero')
            ->get();

        // Auditoría de la ingesta. 🔴 Nunca se loguea la clave del header, ni la recibida ni la
        // configurada: solo qué se cargó.
        Log::channel('daily')->info('ClaudeFollowupTemplatesController: alta de plantillas de lead.', [
            'resultados'     => $resultados,
            'template_names' => $nombres_tocados,
        ]);

        return response()->json([
            'resultados' => $resultados,
            'templates'  => $templates,
        ], 200);
    }

    /**
     * Arma la fila de columnas a guardar a partir del payload de una plantilla.
     *
     * En una actualización, un campo opcional ausente NO borra lo que ya estaba.
     *
     * @param  array               $fila          Payload de una plantilla, ya validado.
     * @param  string              $template_name Nombre de Meta, ya trimeado.
     * @param  FollowupTemplate|null $existente   Fila que ya estaba, si la había.
     * @return array<string, mixed>
     */
    protected function fila_de_datos(array $fila, string $template_name, $existente): array
    {
        $datos = ['template_name' => $template_name];

        // `estado`, `dia_numero` y `body_template` son required en la validación: siempre llegan.
        $datos['estado']        = trim((string) ($fila['estado'] ?? ''));
        $datos['dia_numero']    = (int) ($fila['dia_numero'] ?? 0);
        $datos['body_template'] = trim((string) ($fila['body_template'] ?? ''));

        // `language_code` no es nullable en la tabla: un null explícito del payload se ignora en
        // vez de romper el insert. Un idioma vacío no es un dato, es un campo que no vino.
        if (array_key_exists('language_code', $fila) && trim((string) $fila['language_code']) !== '') {
            $datos['language_code'] = trim((string) $fila['language_code']);
        }

        if (array_key_exists('activa', $fila) && $fila['activa'] !== null) {
            $datos['activa'] = filter_var($fila['activa'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('solo_si_ingreso_confirmado', $fila) && $fila['solo_si_ingreso_confirmado'] !== null) {
            $datos['solo_si_ingreso_confirmado'] = filter_var($fila['solo_si_ingreso_confirmado'], FILTER_VALIDATE_BOOLEAN);
        }

        // Defaults de alta: solo para una fila nueva. En una actualización, no mandarlos deja lo
        // que la fila ya tenía.
        if ($existente === null) {
            if (! array_key_exists('language_code', $datos)) {
                $datos['language_code'] = 'es_AR';
            }
            if (! array_key_exists('activa', $datos)) {
                $datos['activa'] = true;
            }
            if (! array_key_exists('solo_si_ingreso_confirmado', $datos)) {
                $datos['solo_si_ingreso_confirmado'] = false;
            }
        }

        return $datos;
    }
}
