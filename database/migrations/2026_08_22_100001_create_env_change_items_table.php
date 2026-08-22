<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un renglón por (cliente, API, variable) dentro de un lote de cambio masivo de .env.
 *
 * Es previsualización y auditoría a la vez: al previsualizar guarda qué había y qué se propone, y
 * al aplicar guarda cómo terminó.
 *
 * 🔴 Los valores de variables sensibles (KEY, SECRET, PASSWORD, TOKEN) se guardan ENMASCARADOS.
 * Son API keys y contraseñas de base de datos de los clientes: alcanza con los primeros caracteres
 * más un sha256 para auditar qué cambió y verificar que no se pisó nada, y no hay ninguna razón
 * para dejar el secreto en claro en la base del admin.
 */
class CreateEnvChangeItemsTable extends Migration
{
    /**
     * Crea la tabla env_change_items.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('env_change_items', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            // Lote al que pertenece este renglón.
            $table->unsignedBigInteger('env_change_batch_id')->index();

            // Cliente y API destino sobre los que se opera.
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('client_api_id');

            // Nombre de la variable de entorno (ej: ANTHROPIC_API_KEY).
            $table->string('env_key');

            // Valor que tenía y que va a pasar a tener, enmascarados si la variable es sensible.
            // 🔴 `text` y no `string`: cuando la variable NO es sensible se guarda el valor entero,
            // y un .env real tiene valores largos (SANCTUM_STATEFUL_DOMAINS con varios dominios,
            // una lista de orígenes CORS, un DATABASE_URL). Con varchar(255) y el modo estricto de
            // MySQL que usa este proyecto, un valor largo tira "Data too long" en medio del
            // recorrido y se lleva puesto el lote entero en vez de a ese solo cliente.
            $table->text('old_value_masked')->nullable();
            $table->text('new_value_masked')->nullable();

            // sha256 del valor nuevo completo: permite verificar sin exponer el secreto.
            $table->string('new_value_sha256', 64);

            // Valor nuevo real, cifrado (mismo patrón que ClientSshCredential::password). Es lo
            // único que se escribe en el servidor, así que tiene que sobrevivir entre la
            // previsualización y su aplicación. Se borra apenas el lote se aplica: el secreto no
            // queda guardado más allá de la ventana de 30 minutos.
            $table->text('new_value_encrypted')->nullable();

            // sha256 del .env completo al momento de previsualizar. Si al aplicar no coincide,
            // el archivo cambió por otra vía y ese renglón no se escribe.
            $table->string('env_hash', 64)->nullable();

            // Estado del renglón: previewed | applied | stale | failed | unchanged.
            $table->string('status')->default('previewed');

            // Motivo de la falla, si la hubo.
            $table->text('error')->nullable();

            // Path del backup del .env creado antes de escribir.
            $table->string('backup_path')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla env_change_items.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('env_change_items');
    }
}
