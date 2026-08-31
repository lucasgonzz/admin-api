<?php

namespace App\Services;

/**
 * Lo que dejó una corrida de aprovisionamiento: qué se creó, qué ya existía y qué credenciales se
 * generaron.
 *
 * Existe por §4 del plan: NO se revierte nada, nunca, automáticamente. La reversión es una
 * operación manual e informada, y para que una persona pueda decidir qué hacer a las tres de la
 * mañana el log tiene que decir exactamente qué quedó creado del otro lado. Este DTO es lo que
 * junta ese inventario mientras los pasos corren.
 *
 * 🔴 Las credenciales que junta este objeto NO se loguean nunca. Viven acá para que el llamador las
 * persista cifradas (ClientApi::provisioning_secrets) y para nada más. Si alguna vez alguien
 * necesita imprimir el resultado, que use resumen(), que a propósito solo nombra recursos.
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
     * Credenciales generadas, con la misma forma que provisioning_secrets (§2, M2).
     *
     * @var array<string, string>
     */
    private $credenciales = [];

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
     * Suma credenciales al inventario (no pisa las que ya estaban salvo que repita la clave).
     *
     * @param  array<string, string>  $credenciales
     * @return void
     */
    public function agregar_credenciales(array $credenciales): void
    {
        $this->credenciales = array_merge($this->credenciales, $credenciales);
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
     * @return array<string, string>
     */
    public function credenciales(): array
    {
        return $this->credenciales;
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
