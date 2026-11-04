<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOpenaiConsumoToIaImagenPedidos extends Migration
{
    public function up()
    {
        Schema::table('ia_imagen_pedidos', function (Blueprint $table) {
            $table->string('proveedor', 16)->nullable();
            $table->string('calidad', 6)->nullable();
            $table->char('periodo_consumo', 7)->nullable();
            $table->unsignedBigInteger('costo_reservado_microusd')->nullable();
            $table->unsignedBigInteger('costo_cobrado_microusd')->nullable();
            $table->json('uso_openai')->nullable();
            $table->mediumText('referencia_base64')->nullable();
            $table->boolean('con_referencia')->default(false);
            $table->index(['client_id', 'periodo_consumo'], 'ia_imagenes_consumo_cliente');
            $table->index(['proveedor', 'estado', 'updated_at'], 'ia_imagenes_generaciones_pendientes');
        });
    }

    public function down()
    {
        Schema::table('ia_imagen_pedidos', function (Blueprint $table) {
            $table->dropIndex('ia_imagenes_consumo_cliente');
            $table->dropIndex('ia_imagenes_generaciones_pendientes');
            $table->dropColumn([
                'proveedor', 'calidad', 'periodo_consumo', 'costo_reservado_microusd',
                'costo_cobrado_microusd', 'uso_openai', 'referencia_base64', 'con_referencia',
            ]);
        });
    }
}
