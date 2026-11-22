<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el flag `solo_leads` a los admins (misión embudo-whatsapp-precios, etapa E5).
 *
 * Marca el perfil restringido: el admin que lo tiene sólo ve el módulo de Leads (conversaciones en sólo
 * lectura, estado y nota) y Cuenta. Lo hace cumplir el middleware `admin.solo_leads` con la lista de
 * `AdminSoloLeadsPolicy`.
 *
 * Nace apagado (default false) y la migración no toca ninguna fila: ningún admin existente cambia de
 * perfil. Sin índice y sin foreign key (no se consulta por esta columna).
 */
class AddSoloLeadsToAdminsTable extends Migration
{
    /**
     * Agrega la columna, apagada para todos.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->boolean('solo_leads')->default(false)->after('es_vendedor');
        });
    }

    /**
     * Saca la columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('solo_leads');
        });
    }
}
