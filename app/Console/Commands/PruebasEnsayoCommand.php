<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\PruebaEncuesta;
use App\Services\Prueba\PruebaCicloService;
use App\Services\Prueba\PruebaEncuestaService;
use App\Services\PruebaDelClienteService;
use App\Services\PruebaWhatsappService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `pruebas:ensayo`: el reloj de ensayo de la prueba gratuita (misión cruzada prueba-e2e-campana-imperio,
 * 11/11/2026). Simula que UN cliente de ensayo va por el día N de su prueba y corre, solo para él, los pasos
 * del ciclo que en producción dispara el reloj: el empujón diario (con la bienvenida pendiente, el aviso de que
 * la prueba está por terminar y los correos), y el vencimiento con la encuesta.
 *
 * No mueve ninguna fecha de la base: fija el reloj del proceso (`Carbon::setTestNow`) mientras corre y lo
 * suelta al terminar. Lo que manda es REAL (WhatsApp, correo, dormir la instancia): por eso las guardas.
 *
 * 🔴 Guardas duras:
 *  - El cliente tiene que estar marcado como ensayo (`clients.es_ensayo`). Se marca con `--marcar`, que solo
 *    acepta un cliente en prueba (nunca uno que contrató) y exige `--confirmar` con su nombre exacto, y se saca
 *    con `--desmarcar`. Desde que está marcado, los barridos reales (`prueba:empujar`, `pruebas:vencer`, los avisos
 *    al closer, la encuesta por barrido) lo saltean: solo este comando le manda cosas.
 *  - El cliente tiene que estar en prueba (`prueba`, o `prueba_vencida` para repetir el vencimiento).
 *  - No recorre a nadie más: nunca llama a los barridos de todos los clientes.
 *  - En un ensayo NO se avisa al closer, salvo `--con-closer`.
 *
 * Uso:
 *   php artisan pruebas:ensayo 79 --marcar --confirmar="Ferretería de ensayo"
 *   php artisan pruebas:ensayo 79 --dia=2 --correr=empujar
 *   php artisan pruebas:ensayo 79 --dia=5 --hora=10:00 --correr=dia5
 *   php artisan pruebas:ensayo 79 --dia=8 --correr=vencer
 *   php artisan pruebas:ensayo 79 --dia=6 --correr=todo --con-closer
 */
class PruebasEnsayoCommand extends Command
{
    /** Pasos que acepta `--correr`. */
    const PASOS = ['empujar', 'dia5', 'vencer', 'todo'];

    /**
     * @var string
     */
    protected $signature = 'pruebas:ensayo
        {cliente : Id del cliente de ensayo}
        {--dia= : Día de la prueba que se simula (1 = el día del alta)}
        {--hora=10:00 : Hora de Argentina del día simulado (HH:MM)}
        {--correr=todo : empujar | dia5 | vencer | todo}
        {--con-closer : También avisa al closer (por defecto, en un ensayo no)}
        {--marcar : Marca al cliente como ensayo y termina (exige --confirmar)}
        {--confirmar= : Con --marcar: el nombre exacto del cliente (clients.name)}
        {--desmarcar : Le saca la marca de ensayo y termina}';

    /**
     * @var string
     */
    protected $description = 'Simula el día N de la prueba gratuita de UN cliente de ensayo y corre sus pasos del ciclo';

    /**
     * @param PruebaWhatsappService   $whatsapp
     * @param PruebaDelClienteService $pruebas
     * @param PruebaCicloService      $ciclo
     * @param PruebaEncuestaService   $encuestas
     *
     * @return int
     */
    public function handle(PruebaWhatsappService $whatsapp, PruebaDelClienteService $pruebas, PruebaCicloService $ciclo, PruebaEncuestaService $encuestas)
    {
        $client = Client::find((int) $this->argument('cliente'));
        if ($client === null) {
            $this->error('No existe el cliente ' . $this->argument('cliente') . '.');

            return 1;
        }

        if ($this->option('marcar') || $this->option('desmarcar')) {
            return $this->cambiar_marca($client, (bool) $this->option('marcar'));
        }

        if (! (bool) $client->es_ensayo) {
            $this->error('El cliente ' . $client->id . ' (' . $client->resolve_display_name() . ') no está marcado como ensayo. Este comando manda mensajes reales: marcá primero la cuenta de ensayo con --marcar.');

            return 1;
        }

        if (! PruebaCicloService::esta_en_prueba($client)) {
            $this->error('El cliente ' . $client->id . ' no está en prueba (estado: ' . (string) $client->estado . ').');

            return 1;
        }

        $correr = (string) $this->option('correr');
        if (! in_array($correr, self::PASOS, true)) {
            $this->error('--correr tiene que ser uno de: ' . implode(', ', self::PASOS) . '.');

            return 1;
        }

        $dia = $this->option('dia');
        if (! is_string($dia) || ! ctype_digit($dia) || (int) $dia < 1 || (int) $dia > 60) {
            $this->error('--dia tiene que ser un número de 1 a 60.');

            return 1;
        }

        $hora = (string) $this->option('hora');
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora) !== 1) {
            $this->error('--hora tiene que ser HH:MM.');

            return 1;
        }

        $reales = $pruebas->datos($client);
        if ($reales['inicio_at'] === null) {
            $this->error('El cliente no tiene fecha de vencimiento de la prueba: no hay desde dónde contar los días.');

            return 1;
        }

        $simulado = self::momento_del_dia(Carbon::parse($reales['inicio_at']), (int) $dia, $hora);
        $filas    = [];

        Carbon::setTestNow($simulado);

        try {
            $client = $client->fresh();
            $lead   = $pruebas->lead_del_cliente($client);
            $datos  = $pruebas->datos($client);
            $closer = (bool) $this->option('con-closer');

            $this->line('Reloj simulado: ' . $simulado->copy()->setTimezone(PruebaWhatsappService::ZONA)->format('d/m/Y H:i') . ' de Argentina'
                . ' · día ' . ($datos['dia_actual'] !== null ? $datos['dia_actual'] : '?') . ' de ' . $datos['dias']
                . ($datos['vencida'] ? ' · la prueba ya venció a esa hora' : ''));

            if ($correr === 'dia5') {
                $aviso   = $whatsapp->avisar_al_negocio_que_esta_por_terminar($client, $lead, $datos);
                $filas[] = ['aviso de que termina', $aviso !== null ? $aviso['estado'] : 'sin WhatsApp (no corresponde, ya salió o sin plantilla)', $aviso !== null ? (string) $aviso['detalle'] : ''];
            }

            if ($correr === 'empujar' || $correr === 'todo') {
                if ($closer) {
                    $tomas   = $whatsapp->avisar_a_tomas_si_es_el_dia_5($client, $lead, $datos);
                    $filas[] = ['aviso al closer (día 5)', $tomas !== null ? $tomas['estado'] : 'no corresponde', ''];
                }

                $paso    = $whatsapp->empujar($client, $lead, $datos);
                $filas[] = ['empujón del día', $paso['estado'], (string) $paso['detalle']];
            }

            if ($correr === 'vencer' || $correr === 'todo') {
                if ($datos['vencida'] !== true) {
                    $filas[] = ['vencimiento', 'todavía no vence', 'A esa hora la prueba sigue en curso.'];
                } else {
                    $ciclo->vencer($client);
                    $client  = $client->fresh();
                    $filas[] = ['vencimiento', (string) $client->estado, ''];

                    $encuesta = $encuestas->enviar_sin_romper($client, PruebaEncuesta::MOTIVO_VENCIDA);
                    $filas[]  = ['encuesta', (string) $encuesta['estado'], 'WhatsApp: ' . ($encuesta['whatsapp'] !== null ? $encuesta['whatsapp'] : '-') . ' · correo: ' . ($encuesta['correo'] !== null ? $encuesta['correo'] : '-')];

                    if ($closer) {
                        $tomas   = $whatsapp->avisar_a_tomas($client, $lead, PruebaWhatsappService::TOMAS_VENCIDA);
                        $filas[] = ['aviso al closer (vencida)', $tomas['estado'], ''];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->error('Falló el ensayo: ' . $e->getMessage());
            Log::channel('daily')->error('pruebas:ensayo: excepción.', ['client_id' => (int) $client->id, 'error' => $e->getMessage()]);

            return 1;
        } finally {
            Carbon::setTestNow();
        }

        $this->table(['Paso', 'Resultado', 'Detalle'], array_map(function (array $fila) {
            return [$fila[0], $fila[1], mb_substr($fila[2], 0, 90)];
        }, $filas));

        Log::channel('daily')->info('pruebas:ensayo: corrida terminada.', [
            'client_id' => (int) $client->id,
            'dia'       => (int) $dia,
            'correr'    => $correr,
            'pasos'     => $filas,
        ]);

        return 0;
    }

    /**
     * El momento que se simula: la hora pedida (de Argentina) del día N de la prueba. El día N es el que cuenta
     * `PruebaDelClienteService::datos()`: de a 24 horas desde el inicio, no por fecha de calendario. Si la hora
     * pedida de esa fecha cae antes de que empiece el día N, se toma la del día siguiente.
     *
     * @param Carbon $inicio Inicio de la prueba.
     * @param int    $dia    1 = el primer día.
     * @param string $hora   HH:MM.
     *
     * @return Carbon
     */
    public static function momento_del_dia(Carbon $inicio, int $dia, string $hora): Carbon
    {
        $desde = $inicio->copy()->addDays($dia - 1);

        $momento = $desde->copy()->setTimezone(PruebaWhatsappService::ZONA)->setTimeFromTimeString($hora . ':00');
        if ($momento->lt($desde)) {
            $momento->addDay();
        }

        return $momento->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /**
     * `--marcar` / `--desmarcar`.
     *
     * @param Client $client
     * @param bool   $marcar
     *
     * @return int
     */
    private function cambiar_marca(Client $client, bool $marcar): int
    {
        if ($marcar && ! PruebaCicloService::esta_en_prueba($client)) {
            $this->error('Solo se marca como ensayo a un cliente en prueba; ' . $client->id . ' está ' . (string) $client->estado . '.');

            return 1;
        }

        /* Marcar saca al negocio de los barridos reales: se confirma con el nombre exacto, como en las rutas de Claude. */
        if ($marcar && (trim((string) $this->option('confirmar')) === '' || trim((string) $this->option('confirmar')) !== trim((string) $client->name))) {
            $this->error('Para marcarlo repetí con --confirmar="<nombre exacto del cliente>" (clients.name). No se marcó nada.');

            return 1;
        }

        $client->es_ensayo = $marcar;
        $client->save();

        $this->info('Cliente ' . $client->id . ' (' . $client->resolve_display_name() . '): ' . ($marcar ? 'marcado como ensayo.' : 'ya no es de ensayo.'));

        return 0;
    }
}
