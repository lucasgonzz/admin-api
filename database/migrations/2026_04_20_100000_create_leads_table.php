<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla `leads` que concentra:
 * - datos de contacto del prospecto,
 * - configuración técnica del sistema (espejo de demo/setup.blade.php),
 * - campos de integración con el empresa-api elegido (target_client),
 * - trazabilidad del mail de presentación y del setup remoto.
 */
class CreateLeadsTable extends Migration
{
    public function up()
    {
        Schema::create('leads', function (Blueprint $table) {
            // Identidad + auditoría básica
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();

            // Bloque de contacto del prospecto
            $table->string('contact_name', 150)->nullable();
            $table->string('company_name', 150)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('doc_number', 50)->nullable();
            $table->timestamp('meeting_scheduled_at')->nullable();
            $table->text('notes')->nullable();

            // Estado del pipeline comercial
            // nuevo | contactado | reunion_agendada | demo_enviada | cliente | descartado
            $table->string('status', 40)->default('nuevo');

            // Empresa-api elegido para disparar la demo remota (FK a clients)
            $table->unsignedBigInteger('target_client_id')->nullable();

            // ---- Campos técnicos espejo de demo/setup.blade.php ----
            // User creado por DemoSetupHelper: datos visibles
            $table->string('user_name', 150)->nullable();
            $table->string('user_id', 80)->nullable();
            $table->string('total_a_pagar', 40)->nullable();

            // Tipo de negocio y direcciones de sucursales
            $table->string('business_type', 80)->nullable();
            $table->boolean('use_deposits')->default(false);
            $table->string('address_1', 255)->nullable();
            $table->string('address_2', 255)->nullable();
            $table->string('address_3', 255)->nullable();

            // Listas de precios y nombres asociados
            $table->boolean('use_price_lists')->default(false);
            $table->string('price_type_1', 120)->nullable();
            $table->string('price_type_2', 120)->nullable();
            $table->string('price_type_3', 120)->nullable();

            // Flags varias de setup
            $table->boolean('iva_included')->default(false);
            $table->boolean('ventas_con_fecha_de_entrega')->default(false);
            $table->boolean('cajas')->default(false);
            $table->boolean('usar_codigos_de_barra')->default(false);
            $table->boolean('codigos_de_barra_por_defecto')->default(false);
            $table->boolean('consultora_de_precios')->default(false);
            $table->boolean('imagenes')->default(false);
            $table->boolean('produccion')->default(false);
            $table->boolean('ask_amount_in_vender')->default(false);
            $table->boolean('redondear_centenas_en_vender')->default(false);
            $table->boolean('omitir_cuentas_corrientes')->default(false);

            // ---- Trazabilidad de envío de mail de presentación ----
            $table->timestamp('presentation_mail_sent_at')->nullable();
            $table->text('presentation_mail_last_error')->nullable();

            // ---- Trazabilidad del setup demo remoto ----
            // Estados: pendiente | ejecutandose | exitoso | fallido
            $table->string('demo_setup_status', 20)->default('pendiente');
            $table->text('demo_setup_last_error')->nullable();
            $table->timestamp('demo_setup_last_run_at')->nullable();

            $table->timestamps();

            // FKs: si se borra el admin o el client, no perdemos el histórico del lead
            $table->foreign('created_by_admin_id')->references('id')->on('admins')->onDelete('set null');
            $table->foreign('target_client_id')->references('id')->on('clients')->onDelete('set null');

            // Índices para búsquedas habituales del panel
            $table->index('status');
            $table->index('target_client_id');
            $table->index('demo_setup_status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('leads');
    }
}
