<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El registro de los mails de hito de la implementación (misión implementar-cliente, 5/10/2026).
 *
 * Durante una implementación el cliente recibe un mail por hito (bienvenida, sistema instalado,
 * acceso, fotos, categorías y sistema listo), cada uno con la línea de progreso de las ocho
 * etapas. Esta tabla es lo que dice QUÉ hito ya salió, A QUIÉN y CUÁNDO, y es lo que hace que un
 * mail no se duplique ni se pierda sin dejar rastro.
 *
 * Una fila por (implementación, hito). No es una bitácora de intentos: es el estado del hito.
 *
 *   enviado — el mail salió. `enviado_at` es el último envío exitoso y `reenvios` cuenta las veces
 *             que se volvió a mandar a propósito.
 *   error   — nunca salió; `error` dice por qué. Un hito en `error` se puede reintentar sin pedir
 *             reenvío, porque no hay nada que "re"-enviar.
 *
 * El único (`implementation_id`, `hito`) es la deduplicación: sin él, dos llamadas seguidas del
 * mismo hito serían dos mails al mismo dueño. El nombre va explícito porque el que autogenera
 * Laravel concatenando tabla y columnas pasa de los 64 caracteres que acepta MySQL (error 1059).
 *
 * Sin foreign keys, como el resto de las migraciones nuevas del admin: `implementation_id` apunta
 * a `implementations.id` por convención y se indexa porque es la columna por la que se consulta.
 *
 * `email` guarda la dirección a la que salió el mail y no se lee de `clients.email` al mirar: si el
 * dueño cambia la casilla el mes que viene, este registro tiene que seguir diciendo adónde fue.
 */
class CreateImplementationMailsTable extends Migration
{
    /**
     * Crea la tabla del registro de mails de la implementación.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('implementation_mails', function (Blueprint $table) {
            $table->id();

            // A qué implementación pertenece el mail. Sin FK (ver el docblock de arriba).
            $table->unsignedBigInteger('implementation_id')->index();

            // El hito: bienvenida | instalado | acceso | imagenes | categorias | listo. Los valores
            // válidos viven en `ImplementacionMailService::HITOS`; acá va suelto porque una
            // migración no importa clases del proyecto.
            $table->string('hito', 30);

            // Dirección a la que salió (o a la que se intentó mandar).
            $table->string('email', 190);

            // Asunto con el que salió, para que el registro se lea solo.
            $table->string('asunto', 255);

            // enviado | error. Ver el docblock de arriba.
            $table->string('estado', 20);

            // El motivo, en castellano. Para `error` es por qué no salió; en un hito `enviado` es
            // por qué falló el último REENVÍO (el mail original sí había salido).
            $table->text('error')->nullable();

            // Cuándo salió el último envío exitoso. Nulo = nunca salió.
            $table->timestamp('enviado_at')->nullable();

            // Cuántas veces se volvió a mandar un hito que ya había salido.
            $table->unsignedInteger('reenvios')->default(0);

            $table->timestamps();

            // La deduplicación. Nombre explícito por el límite de 64 caracteres de MySQL.
            $table->unique(['implementation_id', 'hito'], 'impl_mails_impl_hito_uq');
        });
    }

    /**
     * Borra la tabla entera.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('implementation_mails');
    }
}
