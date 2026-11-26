<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea las tres tablas del servidor OAuth del conector MCP de sólo lectura (misión dots-openclaw, 25/11/2026):
 * clientes registrados (DCR), códigos de autorización y tokens.
 *
 * 🔴 Códigos y tokens se guardan SÓLO como sha256 (`code_hash`, `access_hash`, `refresh_hash`): ni el código ni el
 * token en claro existen en la base. Sin claves foráneas ni unique, con índices de nombre corto y strings acotados
 * (las relaciones entre tablas se resuelven en el servicio). Idempotente: si la tabla ya existe no hace nada.
 */
class CreateMcpOauthTables extends Migration
{
    /**
     * Crea las tres tablas.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('mcp_oauth_clients')) {
            Schema::create('mcp_oauth_clients', function (Blueprint $table) {
                $table->id();

                // Identificador público del cliente (`ccm_c_` + 32 hex), lo entrega el registro dinámico.
                $table->string('client_id', 60);

                // Nombre que dio el cliente al registrarse (se muestra en la pantalla de consentimiento).
                $table->string('client_name', 120)->nullable();

                // Lista JSON de redirect_uri registrados (https, host de ChatGPT, sin fragmento).
                $table->text('redirect_uris');

                // Última vez que el cliente pidió autorización o canjeó un código.
                $table->dateTime('last_used_at')->nullable();

                $table->timestamps();

                $table->index('client_id', 'mcpc_client_idx');
            });
        }

        if (! Schema::hasTable('mcp_oauth_codes')) {
            Schema::create('mcp_oauth_codes', function (Blueprint $table) {
                $table->id();

                // sha256 (hex) del código de autorización: el código en claro nunca se guarda.
                $table->char('code_hash', 64);

                // Cliente y admin a los que quedó atado el código.
                $table->string('client_id', 60);
                $table->unsignedBigInteger('admin_id');

                // Lo que el canje tiene que repetir idéntico: redirect_uri, desafío PKCE, scope y recurso.
                $table->string('redirect_uri', 500);
                $table->string('code_challenge', 128);
                $table->string('scope', 120);
                $table->string('resource', 255);

                // Vence a los pocos minutos; `used_at` se marca al canjearlo (un solo uso).
                $table->dateTime('expires_at');
                $table->dateTime('used_at')->nullable();

                $table->timestamps();

                $table->index('code_hash', 'mcpcode_hash_idx');
                $table->index('admin_id', 'mcpcode_admin_idx');
            });
        }

        if (! Schema::hasTable('mcp_oauth_tokens')) {
            Schema::create('mcp_oauth_tokens', function (Blueprint $table) {
                $table->id();

                // sha256 (hex) del token de acceso y del refresh: nunca el token en claro.
                $table->char('access_hash', 64);
                $table->char('refresh_hash', 64)->nullable();

                // Familia de la cadena de refresh (si se reusa un refresh viejo se revoca toda la familia).
                $table->char('family', 32);

                // Código con el que se emitió el primer par de la familia (para revocar si se reusa el código).
                $table->unsignedBigInteger('code_id')->nullable();

                // Cliente, admin, scope y audiencia del token.
                $table->string('client_id', 60);
                $table->unsignedBigInteger('admin_id');
                $table->string('scope', 120);
                $table->string('resource', 255);

                // Vencimientos y estado.
                $table->dateTime('access_expires_at');
                $table->dateTime('refresh_expires_at')->nullable();
                $table->dateTime('revoked_at')->nullable();
                $table->dateTime('last_used_at')->nullable();

                $table->timestamps();

                $table->index('access_hash', 'mcpt_access_idx');
                $table->index('refresh_hash', 'mcpt_refresh_idx');
                $table->index('family', 'mcpt_family_idx');
                $table->index('admin_id', 'mcpt_admin_idx');
            });
        }
    }

    /**
     * Borra las tres tablas.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mcp_oauth_tokens');
        Schema::dropIfExists('mcp_oauth_codes');
        Schema::dropIfExists('mcp_oauth_clients');
    }
}
