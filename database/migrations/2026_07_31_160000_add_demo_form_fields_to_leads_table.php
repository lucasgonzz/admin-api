<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `leads` el perfil del lead y las respuestas del formulario de configuración de la
 * página inmersiva de demo (grupo 300, prompt 01 — `contexto/demo_experiencia.md` §3.16 A, §3.17
 * y §3.18; copy del formulario en `contexto/demo_catalogo.md` §2).
 *
 * IMPORTANTE — la tabla `leads` YA tiene tres columnas que son el espejo de tres de las nueve
 * preguntas del formulario (verificado contra `2026_04_20_100000_create_leads_table.php` el
 * 31/7/2026): `use_price_lists` (tipo_precios), `use_deposits` (usa_depositos) y
 * `omitir_cuentas_corrientes` (usa_cuentas_corrientes_clientes, INVERTIDA). Esta migración NO las
 * duplica: solo agrega las seis preguntas que todavía no tienen columna, más el perfil del lead
 * y el timestamp de envío del formulario. La traducción entre las respuestas del formulario y
 * estas columnas la hace exclusivamente `App\Services\LeadDemoFormMapper` — nadie más debe hacer
 * ese mapeo a mano.
 *
 * Sin foreign keys (regla del repo). Sin condicionar nombres/columnas por env(). Sin índices:
 * ninguna de estas columnas se usa para buscar.
 */
return new class extends Migration
{
    /**
     * Agrega las columnas nuevas de `leads`.
     *
     * Los defaults de los seis booleanos NO son todos `false`: se fijan igual al default
     * documentado en `demo_catalogo.md` §2 para que un lead que nunca completa el formulario
     * quede configurado igual que dice el catálogo (y no al revés). No hace falta backfill manual:
     * al agregar una columna con `->default()`, MySQL completa las filas existentes con ese mismo
     * valor.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Cargo del lead recolectado por el agente (§3.17): decide qué versión del scroll ve.
            // Nullable a propósito: si todavía no se recolectó, la página cae a la versión dueño
            // (ver Lead::perfil_lead_efectivo()).
            $table->string('perfil_lead', 20)->nullable()->after('demo_experiencia');

            // Seis preguntas del formulario (demo_catalogo.md §2) que todavía no tienen columna.
            // Default = default documentado en el catálogo, no `false` a secas.
            $table->boolean('costos_en_dolares')->default(false)->after('perfil_lead');
            $table->boolean('descuentos_por_metodo_pago')->default(true)->after('costos_en_dolares');
            $table->boolean('usa_cuentas_corrientes_proveedores')->default(true)->after('descuentos_por_metodo_pago');
            $table->boolean('usa_presupuestos')->default(false)->after('usa_cuentas_corrientes_proveedores');
            $table->boolean('registra_compras')->default(true)->after('usa_presupuestos');
            $table->boolean('usa_ecommerce')->default(true)->after('registra_compras');

            // Marca cuándo el lead envió el formulario. Null = no lo completó todavía, que es el
            // caso que activa el plan por defecto del demo setup (§3.16 B, junto con T-15).
            $table->dateTime('demo_form_completado_at')->nullable()->after('usa_ecommerce');
        });
    }

    /**
     * Revierte las columnas agregadas (vacío, como el resto de migraciones del repo).
     *
     * @return void
     */
    public function down()
    {
    }
};
