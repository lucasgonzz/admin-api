<?php

namespace App\Console\Commands;

use App\Models\WhatsappConfig;
use Illuminate\Console\Command;

/**
 * Da de alta (o actualiza) la fila `personal` de `whatsapp_config`: el número de Lucas en coexistencia con Kapso
 * (misión wa-personal-coexistencia).
 *
 * Existe para NO pasar secretos por HTTP: la clave de Kapso y el WABA se copian del principal (es la misma WABA y el
 * mismo proyecto de Kapso) dentro del servidor, y el secreto del webhook lo genera `whatsapp:registrar-webhook
 * --proposito=personal`; este comando nunca lo toca.
 *
 * Reglas:
 *   - Rechaza un `phone_number_id` que ya use OTRA fila (no se escribe nada).
 *   - Sin `--activar` la fila queda apagada; con él, activa. `test_mode` queda siempre en false.
 *   - La clave de Kapso NUNCA se imprime.
 *   - `--dry-run` muestra qué haría sin escribir.
 *
 * Ejemplo:
 *
 *   php artisan whatsapp:personal-cargar --phone-number-id=123456789012345 --display-phone="+54 9 3444 62-2139" --activar
 */
class WhatsappPersonalCargarCommand extends Command
{
    /**
     * Nombre y opciones del comando.
     *
     * @var string
     */
    protected $signature = 'whatsapp:personal-cargar
                            {--phone-number-id= : Phone Number ID del número personal en Kapso (no es un secreto)}
                            {--display-phone= : El número tal como se lee (informativo)}
                            {--waba-id= : WABA; por defecto el del principal}
                            {--activar : Deja la fila activa (sin esto queda apagada)}
                            {--dry-run : Muestra qué haría sin escribir nada}';

    /**
     * Descripción para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Carga la fila del número personal de WhatsApp copiando la clave y el WABA del principal';

    /**
     * Ejecuta el comando.
     *
     * @return int 0 si todo salió bien.
     */
    public function handle(): int
    {
        $phone_number_id = trim((string) $this->option('phone-number-id'));
        if ($phone_number_id === '') {
            $this->error('Falta --phone-number-id (se copia de app.kapso.ai).');

            return 1;
        }

        $principal = WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_PRINCIPAL);
        $clave     = $principal !== null ? trim((string) $principal->kapso_api_key) : '';
        if ($clave === '') {
            $this->error('El número principal no tiene clave de Kapso cargada: no hay una para copiar.');

            return 1;
        }

        $repetido = WhatsappConfig::where('phone_number_id', $phone_number_id)
            ->where('proposito', '!=', WhatsappConfig::PROPOSITO_PERSONAL)
            ->exists();
        if ($repetido) {
            $this->error('Ese phone_number_id ya lo usa otra fila de whatsapp_config. No se escribió nada.');

            return 1;
        }

        $fila     = WhatsappConfig::delProposito(WhatsappConfig::PROPOSITO_PERSONAL);
        $es_nueva = $fila === null;
        if ($es_nueva) {
            $fila            = new WhatsappConfig();
            $fila->proposito = WhatsappConfig::PROPOSITO_PERSONAL;
            $fila->is_active = false;
        }

        $fila->phone_number_id = $phone_number_id;
        $fila->kapso_api_key   = $clave;
        $fila->test_mode       = false;

        $waba = trim((string) $this->option('waba-id'));
        if ($waba === '' && $principal !== null) {
            $waba = trim((string) $principal->waba_id);
        }
        if ($waba !== '') {
            $fila->waba_id = $waba;
        }

        $display = trim((string) $this->option('display-phone'));
        if ($display !== '') {
            $fila->display_phone = $display;
        }

        if ((bool) $this->option('activar')) {
            $fila->is_active = true;
        }

        $this->info(($es_nueva ? 'Se crearía' : 'Se actualizaría') . ' la fila personal:');
        $this->line('  phone_number_id : ' . $phone_number_id);
        $this->line('  número visible  : ' . ($fila->display_phone !== null ? $fila->display_phone : '(sin cargar)'));
        $this->line('  WABA            : ' . ($fila->waba_id !== null ? $fila->waba_id : '(sin cargar)'));
        $this->line('  clave de Kapso  : se copia la del principal (no se muestra)');
        $this->line('  activa          : ' . ($fila->is_active ? 'sí' : 'no'));
        $this->line('  secreto webhook : no se toca (lo da whatsapp:registrar-webhook --proposito=personal)');

        if ((bool) $this->option('dry-run')) {
            $this->warn('--dry-run: no se escribió nada.');

            return 0;
        }

        $fila->save();
        $this->info('Listo. Siguiente paso: php artisan whatsapp:registrar-webhook --proposito=personal');

        return 0;
    }
}
