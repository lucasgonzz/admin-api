<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modalidad, sistemas contratados y plazo mínimo en el contrato del CLIENTE (misión
 * precios-dos-modalidades, 13/10/2026).
 *
 * Son las mismas tres columnas que la migración anterior agrega a `leads`, con el mismo nombre y
 * el mismo tipo, por la misma razón que el resto del contrato está duplicado en `clients`
 * (ver 2026_09_18_170000_add_contrato_to_clients_table): `LeadContractPdfService` lee los
 * atributos por nombre y así genera el PDF de un Lead o de un Client sin una rama por modelo, y
 * `ClientContratoService::copiar_desde_lead()` las copia del lead al cliente en la promoción.
 *
 * 🔴 NULL en `contract_modalidad` es el contrato histórico (el PDF sale como siempre), así que
 * ninguna lleva default: los clientes ya promovidos quedan en NULL y su contrato no cambia.
 * Todo nullable y sin FK; `hasColumn` por columna para que se pueda volver a correr.
 */
class AddContractModalidadToClientsTable extends Migration
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
     * Agrega a `clients` las columnas que todavía no existan.
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

        // Un solo ALTER con las tres, igual que la migración del contrato del cliente.
        Schema::table('clients', function (Blueprint $table) use ($faltantes) {
            foreach ($faltantes as $definicion) {
                $definicion($table);
            }
        });
    }

    /**
     * Saca las tres columnas de `clients` (solo las que existan).
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
