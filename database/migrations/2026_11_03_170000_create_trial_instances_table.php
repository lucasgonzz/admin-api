<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El pool de instancias de la prueba gratuita (programa «Prueba gratuita de 7 días», misión
 * `prueba-alta-instancia`, 3/11/2026, decisión D1 del plan).
 *
 * Una fila por instancia `prueba-NN` del VPS: cuatro sitios de CloudPanel (ERP + tienda), una base y
 * un worker, armados de antemano por `pruebas:mantener-pool`. El alta desde la landing no arma nada:
 * toma una fila `libre`, le pega el slug del negocio como alias y dispara el demo setup.
 *
 * Sin FK (regla del repo): `client_id` y `lead_id` son referencias sueltas, como en el resto de las
 * tablas del admin.
 */
class CreateTrialInstancesTable extends Migration
{
    public function up()
    {
        Schema::create('trial_instances', function (Blueprint $table) {
            $table->id();

            // `prueba-NN`: el nombre de los sitios de CloudPanel y de la base. Único.
            $table->string('nombre', 20)->unique();

            // creando | libre | asignando | asignada | fallida | cliente | borrando | borrada.
            $table->string('estado', 20)->default('creando')->index();

            // Cliente en prueba que la ocupa (null mientras está libre).
            $table->unsignedBigInteger('client_id')->nullable()->index();

            // Lead que la pidió desde la landing (null mientras está libre).
            $table->unsignedBigInteger('lead_id')->nullable()->index();

            // USER_ID del dueño en el ERP de la instancia (bloque reservado al armarla).
            $table->unsignedBigInteger('user_id')->nullable();

            // Rutas del VPS: `/home/api-<erp_vps_path>/empresa-api` y `/home/api-<ecommerce_vps_path>/tienda-api`.
            $table->string('erp_vps_path', 40);
            $table->string('ecommerce_vps_path', 40);

            // Versiones publicadas con las que se armó (empresa y tienda).
            $table->string('version', 30)->nullable();
            $table->string('ecommerce_version', 30)->nullable();

            // Slug del negocio que la tiene como alias (`<slug>.comerciocity.com`).
            $table->string('alias_slug', 40)->nullable()->index();

            // Etapa en curso o en la que falló, y el progreso 0-100 para la animación de la landing.
            $table->string('etapa', 40)->nullable();
            $table->unsignedTinyInteger('progreso')->default(0);

            // Último error, en castellano, para el panel y para `mensaje` del estado público.
            $table->text('error')->nullable();

            // Contraseña del dueño, cifrada, SOLO entre el alta y el demo setup (después se borra).
            $table->text('password_cifrada')->nullable();

            // Dormida = supervisor parado (vencida). Asignada / lista / borrada: marcas del ciclo.
            $table->timestamp('dormida_at')->nullable();
            $table->timestamp('asignada_at')->nullable();
            $table->timestamp('lista_at')->nullable();
            $table->timestamp('borrada_at')->nullable();

            // Ruta del respaldo en el VPS que dejó `prueba-borrar.sh`.
            $table->string('respaldo_ruta', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('trial_instances');
    }
}
