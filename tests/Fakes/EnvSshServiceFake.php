<?php

namespace Tests\Fakes;

use App\Models\ClientApi;
use App\Services\EnvSshService;

/**
 * Reemplazo de EnvSshService para los tests: guarda los .env en memoria en vez de abrir SSH.
 *
 * Ninguna prueba puede conectarse a un servidor de un cliente real, así que el .env de cada API
 * vive acá adentro. Lo que sí se prueba de verdad es el comportamiento que importa: que
 * previsualizar no escriba, que un archivo cambiado no se pise, y que un cliente que falla no se
 * lleve puesto al resto.
 */
class EnvSshServiceFake extends EnvSshService
{
    /**
     * Contenido crudo del .env de cada API, indexado por client_api_id.
     *
     * @var array<int, string>
     */
    public $envs = [];

    /**
     * Ids de API que fallan al leer, con el mensaje de error a devolver.
     *
     * @var array<int, string>
     */
    public $fallan_al_leer = [];

    /**
     * Escrituras efectivamente realizadas: client_api_id => [KEY => valor].
     *
     * @var array<int, array<string, string>>
     */
    public $escrituras = [];

    /**
     * Backups creados: client_api_id => path del backup.
     *
     * @var array<int, string>
     */
    public $backups = [];

    /**
     * No abre nada: en los tests no hay servidor al que conectarse.
     *
     * @param  ClientApi  $client_api
     * @return void
     */
    public function connect_for(ClientApi $client_api): void
    {
        // Sin conexión real.
    }

    /**
     * No abre nada.
     *
     * @param  string  $credential_type
     * @return void
     */
    public function connect_to(string $credential_type): void
    {
        // Sin conexión real.
    }

    /**
     * No cierra nada.
     *
     * @return void
     */
    public function disconnect(): void
    {
        // Sin conexión real.
    }

    /**
     * Devuelve el .env en memoria de esa API.
     *
     * @param  ClientApi  $client_api
     * @return string
     * @throws \RuntimeException Si esa API está marcada como fallada o no tiene .env cargado.
     */
    public function read_env_raw_for(ClientApi $client_api): string
    {
        if (isset($this->fallan_al_leer[$client_api->id])) {
            throw new \RuntimeException($this->fallan_al_leer[$client_api->id]);
        }

        if (! isset($this->envs[$client_api->id])) {
            throw new \RuntimeException('No existe el archivo .env para la API ' . $client_api->id);
        }

        return $this->envs[$client_api->id];
    }

    /**
     * Hash del .env en memoria.
     *
     * @param  ClientApi  $client_api
     * @return string
     */
    public function env_hash_for(ClientApi $client_api): string
    {
        return hash('sha256', $this->read_env_raw_for($client_api));
    }

    /**
     * Registra el backup sin copiar nada.
     *
     * @param  ClientApi  $client_api
     * @param  string     $timestamp
     * @return string
     */
    public function backup_env_for(ClientApi $client_api, string $timestamp): string
    {
        $path = '/fake/' . $client_api->id . '/.env.bak-' . $timestamp;

        $this->backups[$client_api->id] = $path;

        return $path;
    }

    /**
     * Registra la escritura y actualiza el .env en memoria.
     *
     * @param  ClientApi  $client_api
     * @param  array<string, string>  $vars_to_update
     * @return void
     */
    public function write_env_vars_for(ClientApi $client_api, array $vars_to_update): void
    {
        if (! isset($this->escrituras[$client_api->id])) {
            $this->escrituras[$client_api->id] = [];
        }

        foreach ($vars_to_update as $key => $value) {
            $this->escrituras[$client_api->id][$key] = $value;

            /* Refleja el cambio en el .env en memoria, como haría el sed en el servidor. */
            $this->envs[$client_api->id] = $this->reemplazar_linea(
                $this->envs[$client_api->id],
                (string) $key,
                (string) $value
            );
        }
    }

    /**
     * Reemplaza (o agrega) una línea KEY=valor en el contenido de un .env.
     *
     * @param  string  $contenido
     * @param  string  $key
     * @param  string  $value
     * @return string
     */
    private function reemplazar_linea(string $contenido, string $key, string $value): string
    {
        $lineas     = explode("\n", $contenido);
        $reemplazada = false;

        foreach ($lineas as $i => $linea) {
            if (strncmp(trim($linea), $key . '=', strlen($key) + 1) === 0) {
                $lineas[$i]  = $key . '=' . $value;
                $reemplazada = true;
            }
        }

        if (! $reemplazada) {
            $lineas[] = $key . '=' . $value;
        }

        return implode("\n", $lineas);
    }
}
