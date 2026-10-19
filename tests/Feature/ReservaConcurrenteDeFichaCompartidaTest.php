<?php

namespace Tests\Feature;

use App\Models\SharedProductFact;
use Tests\TestCase;

class ReservaConcurrenteDeFichaCompartidaTest extends TestCase
{
    public function test_solo_un_proceso_gana_la_primera_reserva_de_un_gtin()
    {
        $this->assertMatchesRegularExpression('/^admin_testing(?:_s\d+)?$/', config('database.connections.mysql.database'), 'Esta prueba exige una base de testing del admin.');
        $base = '779' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 11; $i >= 0; $i--) {
            $sum += (int) $base[$i] * (($i % 2) === 1 ? 3 : 1);
        }
        $gtin = $base . ((10 - $sum % 10) % 10);
        $canonical = '0' . $gtin;
        $worker = tempnam(sys_get_temp_dir(), 'cc-reserva-');
        $source = '<?php putenv("APP_ENV=testing"); require ' . var_export(base_path('vendor/autoload.php'), true)
            . '; $app = require ' . var_export(base_path('bootstrap/app.php'), true)
            . '; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            . 'echo json_encode(app(App\\Services\\SharedProductFactsService::class)->claim('
            . var_export($canonical, true) . ', (int) $argv[1], []));';
        file_put_contents($worker, $source);
        $workers = [];
        try {
            // Dos procesos PHP con conexiones MySQL distintas, sin transacción envolvente del
            // test. El segundo INSERT compite de verdad con el unique/lock del primero.
            foreach ([990000001, 990000002] as $clientId) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, $worker, (string) $clientId],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $states = [];
            foreach ($workers as $item) {
                list($process, $pipes) = $item;
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
                $payload = json_decode($output, true);
                $this->assertIsArray($payload, $output . $error);
                $states[] = $payload['status'];
            }
            sort($states);
            $this->assertSame(['claimed', 'pending'], $states);
            $this->assertSame(1, SharedProductFact::where('gtin', $canonical)->count());
        } finally {
            SharedProductFact::where('gtin', $canonical)->delete();
            if (is_file($worker)) unlink($worker);
        }
    }
}
