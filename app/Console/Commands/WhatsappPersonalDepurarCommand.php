<?php

namespace App\Console\Commands;

use App\Helpers\WhatsappNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retención de los chats del número personal (misión wa-personal-coexistencia).
 *
 * Borra los mensajes con más de N días (default 90, `WHATSAPP_PERSONAL_RETENCION_DIAS` o `--dias`) y los chats que
 * quedan sin mensajes. Con `--todo-del-chat=<tel>` borra un chat completo, a pedido de Lucas. Sólo toca
 * `whatsapp_personal_messages` y `whatsapp_personal_chats`.
 *
 * Se agenda a diario en `App\Console\Kernel`.
 */
class WhatsappPersonalDepurarCommand extends Command
{
    /**
     * Nombre y opciones del comando.
     *
     * @var string
     */
    protected $signature = 'whatsapp:personal-depurar
                            {--dias= : Borra los mensajes con más de estos días (por defecto la retención configurada, 90)}
                            {--todo-del-chat= : Borra TODO ese chat (teléfono en cualquier formato) y no hace nada más}';

    /**
     * Descripción para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Borra los mensajes viejos del WhatsApp personal (retención) o un chat entero';

    /**
     * Ejecuta el comando.
     *
     * @return int 0 si todo salió bien.
     */
    public function handle(): int
    {
        $chat = trim((string) $this->option('todo-del-chat'));
        if ($chat !== '') {
            return $this->borrar_chat($chat);
        }

        $dias = trim((string) $this->option('dias'));
        $dias = $dias !== '' ? (int) $dias : (int) config('panel_agent.whatsapp_personal_retencion_dias', 90);
        if ($dias < 1) {
            $this->error('Los días de retención tienen que ser 1 o más.');

            return 1;
        }

        $corte = Carbon::now()->subDays($dias)->toDateTimeString();

        $mensajes = DB::table('whatsapp_personal_messages')->where(function ($viejo) use ($corte) {
            $viejo->where('sent_at', '<', $corte)->orWhere(function ($sin_hora) use ($corte) {
                $sin_hora->whereNull('sent_at')->where('created_at', '<', $corte);
            });
        })->delete();

        $chats = DB::table('whatsapp_personal_chats')->whereNotExists(function ($query) {
            $query->select(DB::raw(1))->from('whatsapp_personal_messages')
                ->whereColumn('whatsapp_personal_messages.chat_telefono', 'whatsapp_personal_chats.chat_telefono');
        })->delete();

        $this->info('Retención de ' . $dias . ' días: ' . $mensajes . ' mensaje(s) y ' . $chats . ' chat(s) vacío(s) borrados.');

        return 0;
    }

    /**
     * Borra un chat completo.
     *
     * @param string $telefono Teléfono en cualquier formato.
     *
     * @return int
     */
    private function borrar_chat(string $telefono): int
    {
        $normalizado = WhatsappNormalizer::normalize($telefono);
        if ($normalizado === '') {
            $this->error('El teléfono no es válido.');

            return 1;
        }

        $mensajes = DB::table('whatsapp_personal_messages')->where('chat_telefono', $normalizado)->delete();
        $chats    = DB::table('whatsapp_personal_chats')->where('chat_telefono', $normalizado)->delete();

        $this->info('Chat …' . substr($normalizado, -4) . ': ' . $mensajes . ' mensaje(s) y ' . $chats . ' chat borrados.');

        return 0;
    }
}
