<?php

namespace App\Console\Commands;

use App\Models\LeadMessage;
use Illuminate\Console\Command;

/**
 * Backfill (grupo 186, prompt 02, 22/7/2026): normaliza los `lead_messages` de WhatsApp Flow
 * guardados con el formato viejo (bloque largo de instrucciones para la IA, ver prompt 252,
 * 3/7/2026) al formato nuevo: nota corta y legible para el humano + `kind = 'flow'`.
 *
 * Antes de este backfill esos registros seguían mostrando en el chat del setter el bloque
 * completo de instrucciones internas (ver WhatsappWebhookController::format_whatsapp_flow_note()
 * y LeadAiService::FLOW_NOTE_INSTRUCCION para el esquema nuevo). Es idempotente: un registro ya
 * normalizado (que ya no empieza con el prefijo viejo) no vuelve a matchear el filtro, así que
 * correr el comando dos veces no cambia nada la segunda vez.
 */
class NormalizarNotasFlowCommand extends Command
{
    /**
     * Prefijo exacto con el que arrancaba el bloque viejo (formato hardcodeado en el webhook
     * hasta el prompt 252). Se usa `strpos(...) === 0` en vez de `str_starts_with` porque
     * admin-api corre PHP 7.4 en producción.
     *
     * @var string
     */
    private const PREFIJO_NOTA_VIEJA = '[Formulario de WhatsApp Flow completado por el lead';

    /**
     * Marcador dentro del texto viejo que precede a la lista de campos `clave=valor`.
     *
     * @var string
     */
    private const MARCADOR_DATOS = 'Datos recibidos en el formulario: ';

    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:normalizar-notas-flow {--dry-run : Lista cuántos registros se tocarían sin escribir}';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Normaliza los lead_messages de WhatsApp Flow guardados con el bloque largo viejo a la nota corta + kind=flow';

    /**
     * Recorre los lead_messages candidatos (sender = lead, content con el prefijo viejo) y
     * reescribe content + kind. Idempotente: los ya normalizados dejan de matchear el filtro.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Modo simulación: solo cuenta y muestra, no persiste nada. */
        $dry_run = (bool) $this->option('dry-run');

        /* Candidatos: mensajes del lead cuyo content todavía tiene el formato viejo.
         * El filtro por LIKE es una preselección amplia; la condición exacta con strpos()
         * se aplica después en PHP para no depender de escapes de LIKE en el prefijo. */
        $candidatos = LeadMessage::query()
            ->where('sender', 'lead')
            ->where('content', 'like', self::PREFIJO_NOTA_VIEJA.'%')
            ->orderBy('id')
            ->get();

        $tocados_count = 0;
        $omitidos_count = 0;
        $rows = [];

        foreach ($candidatos as $mensaje) {
            $content_actual = (string) $mensaje->content;

            /* Confirmación exacta del prefijo (PHP 7.4: no hay str_starts_with). */
            if (strpos($content_actual, self::PREFIJO_NOTA_VIEJA) !== 0) {
                $omitidos_count++;
                continue;
            }

            /* Extraer el fragmento de campos entre el marcador "Datos recibidos en el
             * formulario: " y el primer punto seguido de espacio que le sigue. */
            $campos_texto = $this->extraer_campos($content_actual);
            if ($campos_texto === null) {
                $omitidos_count++;
                continue;
            }

            $content_nuevo = "Formulario de WhatsApp Flow completado por el lead. Datos recibidos: {$campos_texto}";

            $rows[] = [
                'id'              => $mensaje->id,
                'lead_id'         => $mensaje->lead_id,
                'content_nuevo'   => $content_nuevo,
            ];

            if (! $dry_run) {
                $mensaje->content = $content_nuevo;
                $mensaje->kind = 'flow';
                $mensaje->save();
            }

            $tocados_count++;
        }

        $this->table(['ID', 'Lead ID', 'Content nuevo'], $rows);

        if ($dry_run) {
            $this->info("Dry-run: se normalizarían {$tocados_count} mensaje(s) de WhatsApp Flow.");
        } else {
            $this->info("Mensajes normalizados: {$tocados_count}.");
        }

        if ($omitidos_count > 0) {
            $this->warn("Mensajes omitidos (no matchearon el formato viejo exacto): {$omitidos_count}.");
        }

        return 0;
    }

    /**
     * Extrae la lista `clave=valor, clave=valor` del bloque viejo, ubicada entre el marcador
     * "Datos recibidos en el formulario: " y el punto que cierra esa oración (seguido de
     * espacio y el resto de las instrucciones para la IA).
     *
     * @param string $content_viejo Texto completo guardado con el formato anterior.
     *
     * @return string|null Los campos tal cual estaban, o null si no se pudo ubicar el marcador.
     */
    private function extraer_campos(string $content_viejo): ?string
    {
        $inicio = strpos($content_viejo, self::MARCADOR_DATOS);
        if ($inicio === false) {
            return null;
        }

        $inicio_campos = $inicio + strlen(self::MARCADOR_DATOS);

        /* El bloque viejo cierra la oración de campos con ". " antes de continuar con las
         * instrucciones para la IA ("NO tomar ninguna acción..."). Buscamos ese corte. */
        $fin = strpos($content_viejo, '. ', $inicio_campos);
        if ($fin === false) {
            // Si no hay continuación (texto corto/atípico), tomar hasta el final quitando el cierre "]".
            $resto = substr($content_viejo, $inicio_campos);

            return rtrim(trim($resto), '.] ');
        }

        return trim(substr($content_viejo, $inicio_campos, $fin - $inicio_campos));
    }
}
