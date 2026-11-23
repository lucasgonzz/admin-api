<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Lead;
use App\Models\LeadPipelineStatus;
use App\Models\PipelineOpportunity;
use App\Models\PipelineStage;
use App\Services\Pipelines\TableroDeJuanService;
use Illuminate\Support\Str;

/**
 * Busca a qué lead se refiere Juan cuando dice «hablé con Damián, el de Gradom» (misión embudo-juan-pipeline, E1b).
 *
 * Lo usa el agente de OpenClaw antes de registrar una llamada desde un audio: la búsqueda es SÓLO LECTURA y la decisión
 * (`unico` / `ambiguo` / `sin_candidatos`) se calcula acá, de forma determinista, para que el agente no elija a un lead
 * «porque le pareció».
 *
 * Normalización: minúsculas y sin tildes; el teléfono se compara por sus últimos 8 dígitos.
 *
 * Universo: leads con tarjeta abierta en el pipeline «Leads» y leads con actividad en los últimos 90 días.
 *
 * Puntaje de 0 a 100, gana la regla más alta:
 *  - teléfono (últimos 8 dígitos): 100
 *  - empresa exacta: 90
 *  - nombre de contacto + empresa parcial: 80
 *  - empresa parcial (una palabra de 4 letras o más): 60
 *  - sólo el nombre de contacto: 50
 *  - más 5 si tiene tarjeta abierta en el tablero (sin pasar de 100).
 *
 * `unico` si el primero tiene 80 o más y le saca 20 o más al segundo. Nunca devuelve el teléfono completo.
 */
class LeadCandidatosParaLlamadaService
{
    /** Cuántos candidatos como máximo. */
    const MAXIMO_DE_CANDIDATOS = 5;

    /** Días de actividad que entran al universo. */
    const DIAS_DE_ACTIVIDAD = 90;

    /** Cuántos dígitos del final del teléfono se comparan. */
    const DIGITOS_DEL_TELEFONO = 8;

    /** Largo mínimo de una palabra del nombre de la empresa para que cuente como coincidencia parcial. */
    const LARGO_MINIMO_DE_PALABRA = 4;

    /** Puntaje desde el que el primero puede ser el único candidato. */
    const PUNTAJE_PARA_SER_UNICO = 80;

    /** Ventaja sobre el segundo que necesita el primero para ser el único candidato. */
    const VENTAJA_PARA_SER_UNICO = 20;

    /**
     * Busca los candidatos.
     *
     * @param string $texto    Lo que dijo Juan (nombre y/o negocio); puede ser ''.
     * @param string $telefono Sólo dígitos; puede ser ''.
     *
     * @return array{decision: string, models: array<int, array<string, mixed>>}
     */
    public function buscar(string $texto, string $telefono): array
    {
        $texto_normalizado = $this->normalizar($texto);
        $palabras          = $texto_normalizado === '' ? [] : explode(' ', $texto_normalizado);
        $final_del_telefono = $telefono === '' ? '' : substr($telefono, -self::DIGITOS_DEL_TELEFONO);

        $tarjetas = $this->tarjetas_abiertas();
        $leads    = $this->universo(array_keys($tarjetas));

        $candidatos = [];
        foreach ($leads as $lead) {
            $evaluacion = $this->evaluar($lead, $texto_normalizado, $palabras, $final_del_telefono);
            if ($evaluacion === null) {
                continue;
            }

            $con_tarjeta = isset($tarjetas[(int) $lead->id]);
            $puntaje     = $evaluacion['puntaje'];
            $motivos     = $evaluacion['motivos'];
            if ($con_tarjeta) {
                $puntaje   = min(100, $puntaje + 5);
                $motivos[] = 'Tiene una tarjeta abierta en el tablero';
            }

            $candidatos[] = [
                'lead_id'             => (int) $lead->id,
                'contact_name'        => $lead->contact_name,
                'company_name'        => $lead->company_name,
                'telefono_ultimos_4'  => $this->ultimos_cuatro((string) $lead->phone),
                'status'              => (string) $lead->status,
                'status_label'        => LeadPipelineStatus::label_for((string) $lead->status),
                'etapa'               => $con_tarjeta ? $tarjetas[(int) $lead->id] : null,
                'ultima_actividad_at' => $this->ultima_actividad($lead),
                'puntaje'             => $puntaje,
                'motivos'             => $motivos,
            ];
        }

        usort($candidatos, function ($a, $b) {
            if ($a['puntaje'] !== $b['puntaje']) {
                return $b['puntaje'] <=> $a['puntaje'];
            }

            return $b['lead_id'] <=> $a['lead_id'];
        });

        $candidatos = array_slice($candidatos, 0, self::MAXIMO_DE_CANDIDATOS);

        return ['decision' => $this->decidir($candidatos), 'models' => $candidatos];
    }

    /**
     * La decisión: `unico` si el primero tiene 80 o más y le saca 20 o más al segundo; `ambiguo` si hay candidatos pero no
     * se cumple; `sin_candidatos` si la lista está vacía.
     *
     * @param array<int, array<string, mixed>> $candidatos Ordenados de mayor a menor puntaje.
     *
     * @return string
     */
    private function decidir(array $candidatos): string
    {
        if ($candidatos === []) {
            return 'sin_candidatos';
        }

        $segundo = isset($candidatos[1]) ? $candidatos[1]['puntaje'] : 0;

        if ($candidatos[0]['puntaje'] >= self::PUNTAJE_PARA_SER_UNICO && $candidatos[0]['puntaje'] - $segundo >= self::VENTAJA_PARA_SER_UNICO) {
            return 'unico';
        }

        return 'ambiguo';
    }

    /**
     * Puntúa un lead contra la búsqueda.
     *
     * @param Lead               $lead
     * @param string             $texto    Búsqueda normalizada.
     * @param array<int, string> $palabras Palabras de la búsqueda.
     * @param string             $final_del_telefono Últimos dígitos buscados ('' si no hay teléfono).
     *
     * @return array{puntaje: int, motivos: array<int, string>}|null Null si no coincide en nada.
     */
    private function evaluar(Lead $lead, string $texto, array $palabras, string $final_del_telefono)
    {
        if ($final_del_telefono !== '') {
            $digitos = preg_replace('/\D+/', '', (string) $lead->phone);
            if ($digitos !== '' && substr($digitos, -strlen($final_del_telefono)) === $final_del_telefono) {
                return ['puntaje' => 100, 'motivos' => ['El teléfono coincide (últimos ' . strlen($final_del_telefono) . ' dígitos)']];
            }
        }

        if ($texto === '') {
            return null;
        }

        $empresa  = $this->normalizar((string) $lead->company_name);
        $contacto = $this->normalizar((string) $lead->contact_name);

        if ($empresa !== '' && $empresa === $texto) {
            return ['puntaje' => 90, 'motivos' => ['La empresa coincide exacta']];
        }

        $empresa_parcial = false;
        if ($empresa !== '') {
            $palabras_de_la_empresa = explode(' ', $empresa);
            foreach ($palabras as $palabra) {
                if (mb_strlen($palabra) >= self::LARGO_MINIMO_DE_PALABRA && in_array($palabra, $palabras_de_la_empresa, true)) {
                    $empresa_parcial = true;
                    break;
                }
            }
        }

        $contacto_coincide = false;
        if ($contacto !== '') {
            foreach (explode(' ', $contacto) as $palabra_del_contacto) {
                if (mb_strlen($palabra_del_contacto) >= 3 && in_array($palabra_del_contacto, $palabras, true)) {
                    $contacto_coincide = true;
                    break;
                }
            }
        }

        if ($contacto_coincide && $empresa_parcial) {
            return ['puntaje' => 80, 'motivos' => ['El nombre del contacto y la empresa coinciden']];
        }
        if ($empresa_parcial) {
            return ['puntaje' => 60, 'motivos' => ['La empresa se parece']];
        }
        if ($contacto_coincide) {
            return ['puntaje' => 50, 'motivos' => ['El nombre del contacto coincide']];
        }

        return null;
    }

    /**
     * Las tarjetas abiertas del pipeline de leads: id de lead => nombre de la columna.
     *
     * @return array<int, string>
     */
    private function tarjetas_abiertas(): array
    {
        $pipeline = app(TableroDeJuanService::class)->pipeline();
        if ($pipeline === null) {
            return [];
        }

        $nombres = PipelineStage::query()->where('pipeline_id', $pipeline->id)->pluck('name', 'id')->all();

        $salida = [];
        $filas  = PipelineOpportunity::query()
            ->where('pipeline_id', $pipeline->id)
            ->whereNotNull('lead_id')
            ->whereNull('closed_at')
            ->orderBy('id')
            ->get(['lead_id', 'stage_id']);

        foreach ($filas as $fila) {
            $salida[(int) $fila->lead_id] = isset($nombres[(int) $fila->stage_id]) ? (string) $nombres[(int) $fila->stage_id] : '';
        }

        return $salida;
    }

    /**
     * El universo de la búsqueda: leads con tarjeta abierta o con actividad en los últimos 90 días.
     *
     * @param array<int, int> $ids_con_tarjeta
     *
     * @return \Illuminate\Support\Collection
     */
    private function universo(array $ids_con_tarjeta)
    {
        $desde = AppTime::now()->subDays(self::DIAS_DE_ACTIVIDAD);

        return Lead::query()
            ->where(function ($consulta) use ($ids_con_tarjeta, $desde) {
                $consulta->whereIn('id', $ids_con_tarjeta)
                    ->orWhere('updated_at', '>=', $desde)
                    ->orWhere('last_message_at', '>=', $desde);
            })
            ->get(['id', 'contact_name', 'company_name', 'phone', 'status', 'updated_at', 'last_message_at']);
    }

    /**
     * Minúsculas, sin tildes y con los espacios colapsados.
     *
     * @param string $texto
     *
     * @return string
     */
    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(Str::ascii($texto));
        $texto = preg_replace('/[^a-z0-9]+/', ' ', $texto);

        return trim((string) $texto);
    }

    /**
     * Los últimos 4 dígitos del teléfono (nunca el número completo).
     *
     * @param string $telefono
     *
     * @return string|null
     */
    private function ultimos_cuatro(string $telefono)
    {
        $digitos = preg_replace('/\D+/', '', $telefono);

        return $digitos === '' ? null : substr($digitos, -4);
    }

    /**
     * La última actividad del lead (el último mensaje o, si no hay, la última modificación).
     *
     * @param Lead $lead
     *
     * @return string|null `Y-m-d H:i:s`.
     */
    private function ultima_actividad(Lead $lead)
    {
        $fecha = $lead->last_message_at !== null ? $lead->last_message_at : $lead->updated_at;

        return $fecha === null ? null : \Carbon\Carbon::parse($fecha)->format('Y-m-d H:i:s');
    }
}
