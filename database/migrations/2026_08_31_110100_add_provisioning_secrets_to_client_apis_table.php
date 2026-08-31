<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a client_apis el blob cifrado con las credenciales que generó el aprovisionamiento.
 *
 * Guarda la contraseña de la base de datos del cliente y, en VPS, las de los sitios de CloudPanel.
 * El contenido se cifra con el cast `encrypted:array` del modelo ClientApi.
 *
 * 🔴 Por qué en client_apis y no en client_installations, que es donde la decisión original las
 * pedía: ClientInstallationController::destroy() existe y es el flujo normal de reintento ("borrá
 * la fila fallida y creá otra"). Una contraseña de base que solo viviera en la fila borrada dejaría
 * una base huérfana e irrecuperable: la API de Hostinger no tiene endpoint para leer ni resetear la
 * contraseña de una base ya creada. Acá los secretos sobreviven al borrado de la instalación y la
 * corrida siguiente los reusa.
 */
class AddProvisioningSecretsToClientApisTable extends Migration
{
    /**
     * Agrega la columna provisioning_secrets.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_apis', function (Blueprint $table) {
            // 🔴 `text` y NO `json`, aunque adentro viaje un array. Si alguien viene a "arreglar"
            // esto pensando que el tipo correcto es json, rompe la columna: el cast
            // `encrypted:array` de ClientApi no guarda JSON, guarda el string de Laravel Crypt
            // (base64 de un payload cifrado). MySQL valida el contenido de toda columna json y
            // rechaza ese string con "Invalid JSON text", así que la primera escritura falla —y
            // falla justo después de haber creado la base en Hostinger, que es el peor momento
            // posible para perder una contraseña que la API no deja volver a leer.
            //
            // `text` y no `string`: el payload cifrado de ~7 claves ronda los 600–900 bytes, cerca
            // del límite de 255 de un varchar y por encima si mañana se agrega una clave.
            //
            // null = esta API nunca se aprovisionó. Es el estado de todas las filas de hoy, así que
            // no hace falta backfill.
            $table->text('provisioning_secrets')->nullable()->after('vps_path');
        });
    }

    /**
     * Saca la columna provisioning_secrets.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_apis', function (Blueprint $table) {
            $table->dropColumn('provisioning_secrets');
        });
    }
}
