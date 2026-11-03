<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los WhatsApps de la prueba gratuita (misión cruzada prueba-martin-importacion, 3/11/2026): la
 * bienvenida de Martín, los empujones por hito faltante y los avisos a Tomás, por cliente en prueba.
 *
 * Sin claves foráneas (regla admin-api). `dia` es la fecha de Argentina del envío: es la columna
 * del tope de «como mucho un empujón por día».
 */
class CreatePruebaWhatsappEnviosTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('prueba_whatsapp_envios')) {
            return;
        }

        Schema::create('prueba_whatsapp_envios', function (Blueprint $table) {
            $table->id();

            // El negocio en prueba (clients) y su lead, si se pudo cruzar.
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('lead_id')->nullable()->index();

            // Nombre de la plantilla de Meta (cc_prueba_bienvenida, cc_prueba_paso_venta…) o `aviso_tomas`.
            $table->string('plantilla', 120);

            // El hito que empujó (venta, importo…) o el motivo del aviso a Tomás (dia_5, vencida, manual).
            $table->string('hito', 40)->nullable();

            // enviado | en_cola | fallido.
            $table->string('estado', 20)->index();

            // Por dónde salió y qué contestó Meta.
            $table->unsignedBigInteger('support_ticket_id')->nullable();
            $table->unsignedBigInteger('support_message_id')->nullable();
            $table->string('whatsapp_message_id', 191)->nullable();
            $table->text('detalle')->nullable();

            // Fecha de Argentina del envío (tope diario) y momento exacto.
            $table->date('dia')->nullable();
            $table->dateTime('enviado_at')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'plantilla'], 'prueba_envios_cliente_plantilla_idx');
            $table->index(['client_id', 'dia'], 'prueba_envios_cliente_dia_idx');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('prueba_whatsapp_envios');
    }
}
