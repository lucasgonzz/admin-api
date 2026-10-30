<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;

/**
 * Marca (o desmarca) clientes como «no paga mensualidad» por nombre (misión no-paga-mensualidad,
 * 29/10/2026).
 *
 * Un cliente cargado en el admin que no usa el sistema no paga mensualidad: con la marca, el módulo
 * Cobranzas deja de contarlo como deuda. La pantalla del cliente (pestaña Mensualidad) tiene el
 * mismo interruptor; este comando existe para marcar de una vez varios clientes, y para que el
 * deploy deje la marca puesta sin que nadie tenga que entrar uno por uno.
 *
 * Cada nombre se busca primero por coincidencia EXACTA (sin distinguir mayúsculas) contra `name` y
 * `company_name`, y si no hay ninguna, por coincidencia parcial. Tiene que dar **un solo** cliente:
 * con cero o con varios, el comando no escribe NADA para ninguno de los nombres y lo dice, porque
 * adivinar cuál de dos «HT5 …» es el que no paga es peor que no marcar.
 *
 * Idempotente: marcar uno ya marcado no cambia nada. Con `--simular` solo muestra a quién marcaría.
 */
class CobranzasMarcarNoPagaCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cobranzas:marcar-no-paga
        {nombres* : Nombre (o parte del nombre) de cada cliente, entre comillas si lleva espacios; «#123» apunta a un cliente por su id}
        {--quitar : Saca la marca en vez de ponerla (vuelven a contar como deuda)}
        {--simular : Muestra a quién tocaría sin escribir nada}';

    /**
     * @var string
     */
    protected $description = 'Marca clientes como «no paga mensualidad» (o saca la marca con --quitar) para que Cobranzas no los cuente como deuda. Idempotente; --simular no escribe.';

    /**
     * @return int
     */
    public function handle(): int
    {
        $nombres = array_values(array_filter(array_map('trim', (array) $this->argument('nombres')), function ($nombre) {
            return $nombre !== '';
        }));
        if (count($nombres) === 0) {
            $this->error('Indicá al menos un nombre de cliente.');

            return 1;
        }

        $valor = ! $this->option('quitar');
        $simular = (bool) $this->option('simular');

        /** Los clientes ya resueltos, por nombre pedido. */
        $resueltos = [];
        $hubo_error = false;

        foreach ($nombres as $nombre) {
            $encontrados = $this->buscar($nombre);

            if ($encontrados->count() === 0) {
                $this->error('«' . $nombre . '»: no hay ningún cliente con ese nombre.');
                $hubo_error = true;
                continue;
            }

            if ($encontrados->count() > 1) {
                $this->error('«' . $nombre . '»: coincide con ' . $encontrados->count() . ' clientes, no sé cuál es:');
                foreach ($encontrados as $client) {
                    $this->line('    #' . $client->id . '  ' . $this->etiqueta($client));
                }
                $hubo_error = true;
                continue;
            }

            $resueltos[$nombre] = $encontrados->first();
        }

        if ($hubo_error) {
            $this->error('No se escribió nada: corregí los nombres y volvé a correr.');

            return 1;
        }

        foreach ($resueltos as $nombre => $client) {
            $ya_estaba = (bool) $client->no_paga_mensualidad === $valor;

            $accion = $ya_estaba
                ? 'ya estaba ' . ($valor ? 'marcado' : 'sin marca')
                : ($valor ? 'se marca como NO PAGA' : 'se saca la marca');

            if (! $simular && ! $ya_estaba) {
                $client->no_paga_mensualidad = $valor;
                $client->save();
            }

            $this->line('#' . $client->id . '  ' . $this->etiqueta($client) . '  ←  «' . $nombre . '»  ·  ' . ($simular && ! $ya_estaba ? '(simulado) ' : '') . $accion);
        }

        return 0;
    }

    /**
     * Los clientes que responden a ese nombre: los exactos si hay, y si no, los parciales.
     *
     * @param string $nombre
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function buscar(string $nombre)
    {
        // «#123» apunta a un cliente por su id: sin ambigüedad posible.
        if (preg_match('/^#(\d+)$/', $nombre, $coincidencia)) {
            return Client::query()->where('id', (int) $coincidencia[1])->get();
        }

        $minuscula = mb_strtolower($nombre);

        $exactos = Client::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$minuscula])
            ->orWhereRaw('LOWER(TRIM(company_name)) = ?', [$minuscula])
            ->orderBy('id')
            ->get();

        if ($exactos->isNotEmpty()) {
            return $exactos;
        }

        $patron = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $minuscula) . '%';

        return Client::query()
            ->whereRaw('LOWER(name) LIKE ?', [$patron])
            ->orWhereRaw('LOWER(company_name) LIKE ?', [$patron])
            ->orderBy('id')
            ->get();
    }

    /**
     * «Empresa (nombre)» para mostrar en la consola.
     *
     * @param Client $client
     *
     * @return string
     */
    protected function etiqueta(Client $client): string
    {
        $company = trim((string) $client->company_name);
        $name = trim((string) $client->name);

        if ($company !== '' && $name !== '' && mb_strtolower($company) !== mb_strtolower($name)) {
            return $company . ' (' . $name . ')';
        }

        return $company !== '' ? $company : $name;
    }
}
