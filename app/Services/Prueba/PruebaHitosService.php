<?php

namespace App\Services\Prueba;

use App\Models\Lead;
use App\Models\LeadDemoHito;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Contrato C2 (empresa → admin), la parte de la PRUEBA: los eventos `prueba.*` que emite la instancia.
 *
 * Cada tipo conocido marca un hito de `tipo = 'prueba'` en `lead_demo_hitos` (`clip_id` = el código
 * corto del tipo, `evento_esperado` = el tipo entero, `estado = completo`, `accion_hecha_at` = cuándo
 * pasó). Es lo que leen M4 (la pestaña «Prueba» y los empujones de Martín) y el bloque `prueba` del
 * contexto de soporte. Un tipo `prueba.*` que no está en el catálogo se guarda igual como hito
 * genérico: un emisor más nuevo que este admin no puede romper el canal.
 *
 * Idempotente: el hito se crea una vez (único por lead + tipo + clip_id) y la fecha es la de la PRIMERA
 * vez; una repetición no la mueve. `prueba.ingreso` se repite cada vez que el negocio entra, y acá
 * solo importa la primera (M4 dispara la bienvenida con ese primer ingreso).
 *
 * `prueba.vencida` además DUERME la instancia (decisión D4): supervisor parado hasta que contrate.
 */
class PruebaHitosService
{
    const PREFIJO = 'prueba.';

    const TIPO_HITO = 'prueba';

    /** Tipo → [clip_id corto (≤ 10), título que ve el equipo]. */
    const CATALOGO = [
        'prueba.ingreso'          => ['ingreso',  'Entró a su sistema'],
        'prueba.importo'          => ['importo',  'Importó su información'],
        'prueba.articulo_creado'  => ['articulo', 'Creó un artículo'],
        'prueba.venta'            => ['venta',    'Hizo una venta'],
        'prueba.cliente_creado'   => ['cliente',  'Creó un cliente'],
        'prueba.pago_cc'          => ['pago_cc',  'Cobró una cuenta corriente'],
        'prueba.compra'           => ['compra',   'Registró una compra'],
        'prueba.gasto'            => ['gasto',    'Registró un gasto'],
        'prueba.tienda_pedido'    => ['tienda',   'Recibió un pedido de la tienda'],
        'prueba.agente'           => ['agente',   'Habló con el agente'],
        'prueba.vencida'          => ['vencida',  'Se le venció la prueba'],
    ];

    public static function es_tipo_de_prueba(string $nombre): bool
    {
        return strpos($nombre, self::PREFIJO) === 0;
    }

    /**
     * El `clip_id` corto de un tipo (≤ 10 caracteres, columna `lead_demo_hitos.clip_id`).
     */
    public static function clip_id_de(string $tipo): string
    {
        if (isset(self::CATALOGO[$tipo])) {
            return self::CATALOGO[$tipo][0];
        }

        $resto = substr($tipo, strlen(self::PREFIJO));
        $resto = (string) preg_replace('/[^a-z0-9_]/', '', strtolower((string) $resto));

        return substr($resto !== '' ? $resto : 'otro', 0, 10);
    }

    public static function titulo_de(string $tipo): string
    {
        if (isset(self::CATALOGO[$tipo])) {
            return self::CATALOGO[$tipo][1];
        }

        return ucfirst(str_replace('_', ' ', (string) substr($tipo, strlen(self::PREFIJO))));
    }

    /**
     * Aplica un evento `prueba.*` al lead: crea el hito si no estaba y, si es el vencimiento, duerme
     * la instancia.
     *
     * @param array<string, mixed> $datos El `datos` del evento (hoy solo se guarda en el evento crudo).
     *
     * @return int 1 si el hito se creó, 0 si ya estaba.
     */
    public function aplicar(Lead $lead, string $tipo, Carbon $ocurrido, array $datos = []): int
    {
        if (! self::es_tipo_de_prueba($tipo)) {
            return 0;
        }

        $creado = DB::transaction(function () use ($lead, $tipo, $ocurrido) {
            $clip_id = self::clip_id_de($tipo);

            $existente = LeadDemoHito::where('lead_id', $lead->id)
                ->where('tipo', self::TIPO_HITO)
                ->where('clip_id', $clip_id)
                ->lockForUpdate()
                ->first();

            if ($existente !== null) {
                return 0;
            }

            $orden = (int) LeadDemoHito::where('lead_id', $lead->id)->max('orden') + 1;

            try {
                LeadDemoHito::create([
                    'lead_id'         => $lead->id,
                    'orden'           => $orden,
                    'tipo'            => self::TIPO_HITO,
                    'seccion'         => null,
                    'clip_id'         => $clip_id,
                    'titulo'          => self::titulo_de($tipo),
                    'evento_esperado' => substr($tipo, 0, 60),
                    'estado'          => LeadDemoHito::ESTADO_COMPLETO,
                    'accion_hecha_at' => $ocurrido,
                ]);
            } catch (QueryException $e) {
                /* 23000: el único (lead, tipo, clip_id) ya lo escribió otro request en el medio. */
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }

                return 0;
            }

            return 1;
        });

        if ($tipo === 'prueba.vencida') {
            $this->dormir_por_vencimiento($lead);
        }

        return $creado;
    }

    /**
     * `prueba.vencida` → la instancia del cliente en prueba se duerme. Best-effort: una falla del
     * VPS no puede tirar abajo la ingesta del canal.
     */
    private function dormir_por_vencimiento(Lead $lead): void
    {
        try {
            app(PruebaCicloService::class)->vencer_por_lead($lead);
        } catch (\Throwable $e) {
            Log::error('PruebaHitosService: no se pudo dormir la instancia al recibir prueba.vencida.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
