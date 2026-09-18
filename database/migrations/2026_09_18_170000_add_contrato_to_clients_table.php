<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El contrato del cliente, en el cliente (misión modulo-cobranzas, 18/9/2026).
 *
 * Hasta hoy el contrato ComercioCity vivía SOLO en el lead (`leads.contract_*`, migraciones
 * 2026_06_04_100000 y 2026_07_29_120000). Cuando el lead se promueve, el contrato queda atrás en
 * una ficha que nadie vuelve a abrir, y la pregunta de todos los meses —"¿cuánto y desde cuándo le
 * cobramos a este cliente?"— no tiene dónde mirarse desde el cliente.
 *
 * Son exactamente las mismas 17 columnas que tiene `leads`, con el mismo tipo y el mismo nombre,
 * a propósito: `LeadContractPdfService` lee los atributos por nombre y así el mismo servicio
 * genera el PDF de un Lead o de un Client sin una rama por modelo. Se duplican los datos en vez de
 * apuntar al lead porque el contrato del cliente se sigue editando después de la promoción (una
 * cláusula nueva, una cuota renegociada) y esas ediciones no tienen por qué reescribir la historia
 * del lead.
 *
 * Las dos columnas que NO tiene el lead:
 *   - `contract_meses_actualizacion`: cada cuántos meses se actualiza la mensualidad por IPC.
 *     Default 6 porque es lo que dice el texto fijo del contrato desde siempre ("cada seis (6)
 *     meses"), y ahora ese número pasa a ser variable en el PDF.
 *   - `contract_copiado_desde_lead_at`: cuándo se copió del lead. Nulo = nunca se copió, que es
 *     lo que mira el backfill (`cobranzas:copiar-contratos-de-leads`) para no pisar un contrato
 *     que ya se editó a mano en el cliente.
 *
 * Todo nullable y sin FK, como manda la convención del repo. `Schema::hasColumn` por columna
 * porque esta migración puede correr sobre una base donde alguien ya agregó alguna a mano.
 */
class AddContratoToClientsTable extends Migration
{
    /**
     * Columnas a agregar, en orden, con su definición. Se recorre con hasColumn para que la
     * migración sea reejecutable sin romperse a mitad de camino.
     *
     * @return array<string, callable>
     */
    private function columnas(): array
    {
        return [
            // Datos del cliente tal como figuran en el contrato (pueden diferir de clients.name).
            'contract_client_name' => function (Blueprint $table) {
                $table->string('contract_client_name')->nullable();
            },
            'contract_client_razon_social' => function (Blueprint $table) {
                $table->string('contract_client_razon_social')->nullable();
            },
            'contract_client_cuit' => function (Blueprint $table) {
                $table->string('contract_client_cuit')->nullable();
            },

            // Pago único (licencia): moneda, precio, fecha de emisión y fecha del primer pago.
            'contract_currency' => function (Blueprint $table) {
                $table->string('contract_currency')->nullable();
            },
            'contract_precio_licencia' => function (Blueprint $table) {
                $table->string('contract_precio_licencia')->nullable();
            },
            'contract_fecha_emision' => function (Blueprint $table) {
                $table->date('contract_fecha_emision')->nullable();
            },
            'contract_fecha_primer_pago_unico' => function (Blueprint $table) {
                $table->date('contract_fecha_primer_pago_unico')->nullable();
            },

            // Financiación de la licencia: JSON [{monto, fecha}]. Es de donde salen las cuotas.
            'contract_financiacion' => function (Blueprint $table) {
                $table->json('contract_financiacion')->nullable();
            },

            // Mensualidad: moneda, base, usuarios incluidos/extra y sus precios, perfiles ecommerce.
            'contract_mensualidad_moneda' => function (Blueprint $table) {
                $table->string('contract_mensualidad_moneda')->nullable();
            },
            'contract_mensualidad_base' => function (Blueprint $table) {
                $table->string('contract_mensualidad_base')->nullable();
            },
            'contract_usuarios_incluidos' => function (Blueprint $table) {
                $table->integer('contract_usuarios_incluidos')->nullable();
            },
            'contract_usuarios_extra' => function (Blueprint $table) {
                $table->integer('contract_usuarios_extra')->nullable()->default(0);
            },
            'contract_precio_usuario_extra' => function (Blueprint $table) {
                $table->string('contract_precio_usuario_extra')->nullable();
            },
            'contract_perfiles_ecommerce' => function (Blueprint $table) {
                $table->integer('contract_perfiles_ecommerce')->nullable()->default(0);
            },
            'contract_precio_perfil_ecommerce' => function (Blueprint $table) {
                $table->string('contract_precio_perfil_ecommerce')->nullable();
            },
            'contract_fecha_primer_pago_mensual' => function (Blueprint $table) {
                $table->date('contract_fecha_primer_pago_mensual')->nullable();
            },

            // Cláusulas particulares (sección 8 del PDF): JSON [{titulo, texto}].
            'contract_clausulas_particulares' => function (Blueprint $table) {
                $table->json('contract_clausulas_particulares')->nullable();
            },

            // Cada cuántos meses se actualiza la mensualidad por IPC. Default 6 (el texto histórico).
            'contract_meses_actualizacion' => function (Blueprint $table) {
                $table->unsignedTinyInteger('contract_meses_actualizacion')->nullable()->default(6);
            },

            // Cuándo se copió del lead. Nulo = nunca; es la guarda del backfill.
            'contract_copiado_desde_lead_at' => function (Blueprint $table) {
                $table->timestamp('contract_copiado_desde_lead_at')->nullable();
            },
        ];
    }

    /**
     * Agrega a `clients` las columnas del contrato que todavía no existan.
     *
     * @return void
     */
    public function up()
    {
        /** Solo las que faltan, para que la migración se pueda volver a correr. */
        $faltantes = [];

        foreach ($this->columnas() as $columna => $definicion) {
            if (! Schema::hasColumn('clients', $columna)) {
                $faltantes[] = $definicion;
            }
        }

        if (count($faltantes) === 0) {
            return;
        }

        // Un solo ALTER con todas las columnas: 19 ALTER separados sobre la misma tabla son 19
        // reconstrucciones, y medido en la base de testing eso tarda más de un minuto.
        Schema::table('clients', function (Blueprint $table) use ($faltantes) {
            foreach ($faltantes as $definicion) {
                $definicion($table);
            }
        });
    }

    /**
     * Saca las columnas del contrato de `clients` (solo las que existan).
     *
     * @return void
     */
    public function down()
    {
        foreach (array_keys($this->columnas()) as $columna) {
            if (! Schema::hasColumn('clients', $columna)) {
                continue;
            }

            Schema::table('clients', function (Blueprint $table) use ($columna) {
                $table->dropColumn($columna);
            });
        }
    }
}
