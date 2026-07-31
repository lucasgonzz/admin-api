<?php

namespace App\Services;

use App\Models\Lead;

/**
 * Traduce las nueve respuestas del formulario de configuración de la página inmersiva de demo
 * (`contexto/demo_catalogo.md` §2) hacia y desde las columnas de `leads` (grupo 300, prompt 01).
 *
 * Es la ÚNICA pieza del sistema que conoce este mapeo. En particular, tres de las nueve preguntas
 * reusan columnas que la tabla `leads` ya tenía antes de este prompt (ver docblock de la migración
 * `2026_07_31_160000_add_demo_form_fields_to_leads_table.php`):
 *
 *  - `tipo_precios` (`unico` | `listas`)      → `use_price_lists` (boolean)
 *  - `usa_depositos` (boolean)                → `use_deposits` (boolean), directo
 *  - `usa_cuentas_corrientes_clientes` (bool) → `omitir_cuentas_corrientes` (boolean), INVERTIDO
 *
 * Las otras seis respuestas se escriben directo en columnas homónimas creadas por esa misma
 * migración. Nadie más debe hacer esta traducción a mano: cualquier lugar que necesite leer o
 * escribir respuestas del formulario tiene que pasar por `to_lead()` / `from_lead()`.
 */
class LeadDemoFormMapper
{
    /**
     * Aplica al lead las nueve respuestas del formulario, traduciéndolas a las columnas
     * correspondientes. NO persiste: deja el modelo modificado en memoria y es responsabilidad de
     * quien llama decidir cuándo hacer `$lead->save()` (por ejemplo, junto con
     * `demo_form_completado_at` en el mismo `save()`).
     *
     * @param Lead                  $lead       Lead sobre el que se aplican las respuestas.
     * @param array<string, mixed>  $respuestas Respuestas crudas del formulario, con las nueve
     *                                          claves de `demo_catalogo.md` §2: `tipo_precios`
     *                                          (string `unico`|`listas`) y las otras ocho como
     *                                          booleanos (`usa_depositos`,
     *                                          `usa_cuentas_corrientes_clientes`,
     *                                          `costos_en_dolares`,
     *                                          `descuentos_por_metodo_pago`,
     *                                          `usa_cuentas_corrientes_proveedores`,
     *                                          `usa_presupuestos`, `registra_compras`,
     *                                          `usa_ecommerce`). Claves ausentes se toman como el
     *                                          valor "apagado" (false / unico).
     *
     * @return Lead El mismo lead recibido, con los atributos modificados (sin guardar).
     */
    public static function to_lead(Lead $lead, array $respuestas): Lead
    {
        // tipo_precios es la única respuesta no-booleana del formulario: 'listas' activa la
        // columna existente use_price_lists; cualquier otro valor (incluido 'unico', el default
        // del catálogo) cae en precio único (false).
        $lead->use_price_lists = (($respuestas['tipo_precios'] ?? null) === 'listas');

        // usa_depositos → use_deposits: mapeo directo, mismo valor booleano, la columna existente
        // solo cambia el nombre a la convención en inglés que ya tenía la tabla `leads`.
        $lead->use_deposits = (bool) ($respuestas['usa_depositos'] ?? false);

        // usa_cuentas_corrientes_clientes → omitir_cuentas_corrientes, INVERTIDO A PROPÓSITO.
        // La columna existente nació con semántica negativa ("omitir") mientras que la pregunta
        // del formulario está en positivo ("usa"). Un lead que SÍ usa cuentas corrientes (true)
        // tiene que quedar guardado con omitir_cuentas_corrientes = false, y viceversa. No es un
        // error de tipeo: ver la advertencia al principio del prompt que agregó este mapeo
        // (grupo 300, prompt 01) — es el error más fácil de cometer en todo este archivo.
        $lead->omitir_cuentas_corrientes = ! (bool) ($respuestas['usa_cuentas_corrientes_clientes'] ?? false);

        // Las seis respuestas restantes no tenían columna antes de este prompt: se escriben
        // directo, sin ninguna traducción, en su columna homónima.
        $lead->costos_en_dolares                  = (bool) ($respuestas['costos_en_dolares'] ?? false);
        $lead->descuentos_por_metodo_pago         = (bool) ($respuestas['descuentos_por_metodo_pago'] ?? false);
        $lead->usa_cuentas_corrientes_proveedores = (bool) ($respuestas['usa_cuentas_corrientes_proveedores'] ?? false);
        $lead->usa_presupuestos                   = (bool) ($respuestas['usa_presupuestos'] ?? false);
        $lead->registra_compras                   = (bool) ($respuestas['registra_compras'] ?? false);
        $lead->usa_ecommerce                      = (bool) ($respuestas['usa_ecommerce'] ?? false);

        return $lead;
    }

    /**
     * Inverso de `to_lead()`: reconstruye las nueve respuestas del formulario a partir del estado
     * actual del lead, para que la página pueda mostrar el estado actual cuando el lead vuelve a
     * abrir el formulario.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed> Las nueve respuestas, en el mismo formato que recibe `to_lead()`.
     */
    public static function from_lead(Lead $lead): array
    {
        return [
            'tipo_precios' => $lead->use_price_lists ? 'listas' : 'unico',

            'usa_depositos' => (bool) $lead->use_deposits,

            // Misma inversión que en to_lead(), en sentido contrario: omitir = false → usa = true.
            // No es un error de tipeo (ver comentario en to_lead()).
            'usa_cuentas_corrientes_clientes' => ! (bool) $lead->omitir_cuentas_corrientes,

            'costos_en_dolares'                  => (bool) $lead->costos_en_dolares,
            'descuentos_por_metodo_pago'         => (bool) $lead->descuentos_por_metodo_pago,
            'usa_cuentas_corrientes_proveedores' => (bool) $lead->usa_cuentas_corrientes_proveedores,
            'usa_presupuestos'                   => (bool) $lead->usa_presupuestos,
            'registra_compras'                   => (bool) $lead->registra_compras,
            'usa_ecommerce'                      => (bool) $lead->usa_ecommerce,
        ];
    }
}
