<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modalidad, sistemas contratados y plazo mínimo en el contrato del LEAD (misión
 * precios-dos-modalidades, 13/10/2026).
 *
 * ComercioCity pasa a vender en dos modalidades —Licencia (entrada alta, mensualidad de por vida
 * que solo se ajusta por IPC) y Suscripción (entrada baja, plazo mínimo, mensualidad que después
 * sigue la lista)— y el contrato PDF tiene que llevar la letra chica de la que se firmó. Las tres
 * columnas son lo que `LeadContractPdfService` necesita para eso:
 *
 *   - `contract_modalidad`: `'licencia'` | `'suscripcion'`. 🔴 NULL es significativo: es el
 *     contrato histórico, y el PDF sale EXACTAMENTE como salía antes de esta misión. Por eso no
 *     lleva default: todo lead que ya existe queda en NULL y ningún contrato emitido cambia.
 *   - `contract_sistemas`: JSON con las keys de `CotizadorSettings::SISTEMAS` (`gestion`,
 *     `ecommerce`, `agentes`). Sale en la sección 2 del PDF y decide si va la cláusula de consumos
 *     de terceros (solo con Agentes).
 *   - `contract_permanencia_meses`: el plazo mínimo de la Suscripción. NULL = el default del PDF
 *     (12 meses, `LeadContractPdfService::PERMANENCIA_MESES_DEFAULT`).
 *
 * Las mismas tres columnas, con el mismo nombre y tipo, se agregan a `clients` en la migración
 * siguiente: el PDF lee los atributos por nombre en los dos modelos. Todo nullable y sin FK, como
 * manda la convención del repo; `hasColumn` por columna para que se pueda volver a correr.
 */
class AddContractModalidadToLeadsTable extends Migration
{
    /**
     * Columnas a agregar, con su definición. Se recorre con hasColumn para que la migración sea
     * reejecutable sin romperse a mitad de camino.
     *
     * @return array<string, callable>
     */
    private function columnas(): array
    {
        return [
            // 'licencia' | 'suscripcion'. NULL = contrato histórico (el PDF de siempre). Sin default.
            'contract_modalidad' => function (Blueprint $table) {
                $table->string('contract_modalidad', 20)->nullable();
            },

            // Keys de CotizadorSettings::SISTEMAS contratadas: ["gestion", "ecommerce", "agentes"].
            'contract_sistemas' => function (Blueprint $table) {
                $table->json('contract_sistemas')->nullable();
            },

            // Plazo mínimo de la Suscripción, en meses. NULL = 12 (default del PDF).
            'contract_permanencia_meses' => function (Blueprint $table) {
                $table->unsignedSmallInteger('contract_permanencia_meses')->nullable();
            },
        ];
    }

    /**
     * Agrega a `leads` las columnas que todavía no existan.
     *
     * @return void
     */
    public function up()
    {
        /** Solo las que faltan, para que la migración se pueda volver a correr. */
        $faltantes = [];

        foreach ($this->columnas() as $columna => $definicion) {
            if (! Schema::hasColumn('leads', $columna)) {
                $faltantes[] = $definicion;
            }
        }

        if (count($faltantes) === 0) {
            return;
        }

        // Un solo ALTER con las tres: `leads` es una tabla ancha y cada ALTER la reconstruye.
        Schema::table('leads', function (Blueprint $table) use ($faltantes) {
            foreach ($faltantes as $definicion) {
                $definicion($table);
            }
        });
    }

    /**
     * Saca las tres columnas de `leads` (solo las que existan).
     *
     * @return void
     */
    public function down()
    {
        foreach (array_keys($this->columnas()) as $columna) {
            if (! Schema::hasColumn('leads', $columna)) {
                continue;
            }

            Schema::table('leads', function (Blueprint $table) use ($columna) {
                $table->dropColumn($columna);
            });
        }
    }
}
