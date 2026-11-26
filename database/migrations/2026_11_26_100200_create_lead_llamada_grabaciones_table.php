<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La grabación de una llamada de Juan con un lead (misión leads-referidos-compromisos, etapa E3, 26/11/2026).
 *
 * Juan graba la llamada con el celular y sube el archivo en «Registrar llamada». El archivo se sube por partes de 4 MB (no depende
 * del límite de subida del servidor), se une, se transcribe con OpenAI (partido en tramos si el servidor tiene ffmpeg), se resume
 * con IA y de ahí puede salir el próximo compromiso de contacto. Esta tabla guarda el estado de todo ese camino y el resultado.
 *
 * 🔴 Reglas de la tabla (restricciones de migraciones de admin-api):
 *  - Sin claves foráneas: `lead_llamada_id`, `lead_id`, `compromiso_id` y `created_by_admin_id` son enteros sueltos; el borrado en
 *    cascada (filas y archivos) lo hace Eloquent (`Lead::deleting`).
 *  - Índices con nombre corto: `lgrab_llam_idx` (la grabación de una llamada), `lgrab_lead_idx` (las de un lead) y
 *    `lgrab_sha_idx` (buscar el mismo archivo ya subido).
 *  - Todas las columnas nuevas llevan default o son nullable; los strings tienen largo acotado.
 *  - La transcripción COMPLETA vive acá (`mediumText`, hasta 16 MB): `lead_llamadas.transcripcion` sigue con su tope de 20.000.
 *
 * Se puede volver a correr sobre una base que quedó a medias (`Schema::hasTable`).
 */
class CreateLeadLlamadaGrabacionesTable extends Migration
{
    /**
     * Crea la tabla `lead_llamada_grabaciones`.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('lead_llamada_grabaciones')) {
            return;
        }

        Schema::create('lead_llamada_grabaciones', function (Blueprint $table) {
            $table->bigIncrements('id');

            // La llamada y el lead a los que pertenece la grabación (una grabación por llamada).
            $table->unsignedBigInteger('lead_llamada_id');
            $table->unsignedBigInteger('lead_id');

            // Lo que declaró el celular al arrancar la subida: nombre del archivo, extensión en minúsculas, tipo y tamaño total.
            $table->string('nombre_original', 190)->default('');
            $table->string('extension', 10)->default('');
            $table->string('mime', 80)->nullable();
            $table->unsignedBigInteger('bytes_declarados')->default(0);

            // Lo recibido hasta ahora y qué partes llegaron (JSON `{indice: bytes}`: repetir una parte no la duplica).
            $table->unsignedBigInteger('bytes_recibidos')->default(0);
            $table->text('partes_recibidas')->nullable();

            // Huella del archivo unido (detecta el mismo audio subido dos veces al mismo lead) y dónde quedó en el disco `local`.
            $table->string('sha256', 64)->nullable();
            $table->string('ruta', 255)->nullable();

            // subiendo | pendiente | transcribiendo | lista | error.
            $table->string('status', 15)->default('subiendo');
            $table->text('error_text')->nullable();

            // Lo que midió la transcripción: duración (si hubo ffmpeg), cuántos pedidos a OpenAI y con qué modelo.
            $table->unsignedInteger('duracion_segundos')->nullable();
            $table->unsignedSmallInteger('tramos')->nullable();
            $table->string('modelo', 60)->nullable();

            // El resultado: la transcripción completa, el resumen de la IA (hasta 1.500 caracteres) y lo que la IA propuso como
            // próximo contacto (texto, para la ficha) con el compromiso que nació de ahí, si nació alguno.
            $table->mediumText('transcripcion')->nullable();
            $table->text('resumen')->nullable();
            $table->text('compromiso_propuesto')->nullable();
            $table->unsignedBigInteger('compromiso_id')->nullable();

            // Cuándo empezó y terminó el procesamiento (el «reintentar» de una grabación colgada mira `started_at`).
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();

            // Quién subió el archivo.
            $table->unsignedBigInteger('created_by_admin_id')->nullable();

            $table->timestamps();

            $table->index('lead_llamada_id', 'lgrab_llam_idx');
            $table->index('lead_id', 'lgrab_lead_idx');
            $table->index('sha256', 'lgrab_sha_idx');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('lead_llamada_grabaciones');
    }
}
