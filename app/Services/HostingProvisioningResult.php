<?php

namespace App\Services;

/**
 * Lo que dejó una corrida de aprovisionamiento: qué se creó y qué ya existía.
 *
 * Existe por §4 del plan: NO se revierte nada, nunca, automáticamente. La reversión es una
 * operación manual e informada, y para que una persona pueda decidir qué hacer a las tres de la
 * mañana el log tiene que decir exactamente qué quedó creado del otro lado. Este DTO es lo que
 * junta ese inventario mientras los pasos corren.
 *
 * 🔴 ACÁ ADENTRO NO HAY UNA SOLA CREDENCIAL, y eso es a propósito. Hasta el 31/8/2026 este objeto
 * también acumulaba las contraseñas generadas, con un getter que no llamaba nadie: quien las
 * persiste cifradas es HostingProvisioningService::persistir_secretos(), contra la ClientApi, y
 * quien las muestra es el endpoint de credenciales, que las lee de la base. O sea que las
 * contraseñas de las bases de los clientes quedaban en memoria en un objeto que solo existe para
 * imprimirse. Si alguna vez hace falta mostrar el resultado, resumen() nombra recursos y nada más.
 *
 * PHP 7.4: sin promoción en constructor, sin `readonly`, sin union types.
 */
class HostingProvisioningResult
{
    /**
     * Recursos creados en esta corrida: [['tipo' => 'subdominio', 'nombre' => 'api-lacava'], ...].
     *
     * @var array<int, array<string, string>>
     */
    private $creados = [];

    /**
     * Recursos que el proveedor reportó como ya existentes y se reusaron.
     *
     * @var array<int, array<string, string>>
     */
    private $ya_existian = [];

    /**
     * Registra un recurso creado de cero.
     *
     * @param  string  $tipo    'subdominio' | 'base' | 'cron' | 'sitio' | 'certificado'.
     * @param  string  $nombre  Identificador legible del recurso.
     * @return void
     */
    public function creado(string $tipo, string $nombre): void
    {
        $this->creados[] = ['tipo' => $tipo, 'nombre' => $nombre];
    }

    /**
     * Registra un recurso que ya estaba y se reusó.
     *
     * @param  string  $tipo
     * @param  string  $nombre
     * @return void
     */
    public function ya_existia(string $tipo, string $nombre): void
    {
        $this->ya_existian[] = ['tipo' => $tipo, 'nombre' => $nombre];
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function creados(): array
    {
        return $this->creados;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function ya_existian(): array
    {
        return $this->ya_existian;
    }

    /**
     * Línea para el panel de operaciones. Solo nombres de recursos: ni una credencial.
     *
     * @return string
     */
    public function resumen(): string
    {
        return 'Aprovisionamiento: ' . count($this->creados) . ' recurso(s) creado(s) ['
            . $this->listar($this->creados) . '] y ' . count($this->ya_existian)
            . ' que ya existía(n) [' . $this->listar($this->ya_existian) . '].';
    }

    /**
     * @param  array<int, array<string, string>>  $recursos
     * @return string
     */
    private function listar(array $recursos): string
    {
        $nombres = [];

        foreach ($recursos as $recurso) {
            $nombres[] = $recurso['tipo'] . ':' . $recurso['nombre'];
        }

        return implode(', ', $nombres);
    }
}
