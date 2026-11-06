<?php

namespace App\Services;

/** Contrato de la guía de Vender. Los identificadores se resuelven en Empresa. */
final class DemoChatGuia
{
    private const VERSION = 1;
    private const PASOS_NOMBRE = ['abrir_buscador', 'escribir_nombre', 'buscar_nombre', 'elegir_resultado'];
    private const PASO_CODIGO = 'codigo_barras';

    public static function contexto($valor): ?array
    {
        if (! is_array($valor) || ($valor['version'] ?? null) !== self::VERSION || ($valor['pantalla'] ?? null) !== 'vender') {
            return null;
        }
        $paso = $valor['paso'] ?? null;
        if ($paso !== null && $paso !== self::PASO_CODIGO && ! in_array($paso, self::PASOS_NOMBRE, true)) {
            return null;
        }

        return ['version' => self::VERSION, 'pantalla' => 'vender', 'paso' => $paso];
    }

    public static function plan($valor, string $solicitud_uuid): ?array
    {
        if (! is_array($valor) || ! is_array($valor['pasos'] ?? null)) {
            return null;
        }

        $accion = $valor['accion'] ?? null;
        if ($accion === 'cerrar' && $valor['pasos'] === []) {
            return ['version' => self::VERSION, 'solicitud_uuid' => $solicitud_uuid, 'accion' => 'cerrar', 'pasos' => []];
        }
        if ($accion !== 'iniciar' || count($valor['pasos']) < 1 || count($valor['pasos']) > count(self::PASOS_NOMBRE)) {
            return null;
        }

        $pasos = [];
        foreach ($valor['pasos'] as $paso) {
            if (! is_array($paso) || ! is_string($paso['id'] ?? null) || ! is_string($paso['texto'] ?? null)) {
                return null;
            }
            $texto = trim($paso['texto']);
            if ($texto === '' || mb_strlen($texto) > 160 || preg_match('~https?://|www\.|[<>]~i', $texto)) {
                return null;
            }
            $pasos[] = ['id' => $paso['id'], 'texto' => $texto];
        }

        $ids = array_column($pasos, 'id');
        $inicio = array_search($ids[0], self::PASOS_NOMBRE, true);
        if ($ids !== [self::PASO_CODIGO] && ($inicio === false || $ids !== array_slice(self::PASOS_NOMBRE, $inicio, count($ids)))) {
            return null;
        }

        return ['version' => self::VERSION, 'solicitud_uuid' => $solicitud_uuid, 'accion' => 'iniciar', 'pasos' => $pasos];
    }
}
