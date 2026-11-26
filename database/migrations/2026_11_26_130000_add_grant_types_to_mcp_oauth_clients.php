<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misión dots-openclaw, ronda 2 (D7): el cliente OAuth del conector guarda los `grant_types` con los que se
 * registró, para que el endpoint de token respete lo que pidió (sin la columna, null = los dos grants soportados).
 */
class AddGrantTypesToMcpOauthClients extends Migration
{
    /**
     * Agrega la columna (lista JSON, nullable, sin foreign keys).
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('mcp_oauth_clients') && ! Schema::hasColumn('mcp_oauth_clients', 'grant_types')) {
            Schema::table('mcp_oauth_clients', function (Blueprint $table) {
                // Lista JSON de grants registrados (`authorization_code`, `refresh_token`); null = ambos.
                $table->text('grant_types')->nullable()->after('redirect_uris');
            });
        }
    }

    /**
     * Quita la columna.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('mcp_oauth_clients', 'grant_types')) {
            Schema::table('mcp_oauth_clients', function (Blueprint $table) {
                $table->dropColumn('grant_types');
            });
        }
    }
}
