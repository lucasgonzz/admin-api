<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Aprovisionamiento del hosting del cliente desde el admin.
 *
 * Por ahora cubre solo las columnas nuevas (unidad U2 del plan). Los tests del pipeline —camino
 * feliz, idempotencia, guardas del DNS, un solo cron— se agregan acá mismo en las unidades
 * siguientes.
 */
class AprovisionamientoDeHostingDelClienteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_los_secretos_del_aprovisionamiento_quedan_cifrados_en_la_base(): void
    {
        $api = $this->crear_api_de_cliente();

        $api->provisioning_secrets = [
            'db_name'     => 'u767360347_lacava',
            'db_user'     => 'u767360347_lacava',
            'db_password' => 'Cl4ve-Secreta-De-La-Base',
        ];
        $api->hosting_provisioned_at = now();
        $api->save();

        /*
         * 🔴 Lo que este test protege es el cast. Si alguien lo saca —o lo cambia a 'array' porque
         * "el contenido es un array"—, la contraseña de la base de cada cliente queda en texto
         * plano en la base del admin, y nada más se pone en rojo: todo lo demás sigue funcionando
         * igual. Por eso se mira la columna cruda y no el modelo.
         */
        $crudo = DB::table('client_apis')->where('id', $api->id)->value('provisioning_secrets');

        $this->assertNotEmpty($crudo);
        $this->assertStringNotContainsString('Cl4ve-Secreta-De-La-Base', $crudo);
        $this->assertStringNotContainsString('u767360347_lacava', $crudo);

        /* Y el ida y vuelta tiene que devolver el array tal cual entró. */
        $recargada = ClientApi::find($api->id);

        $this->assertSame('Cl4ve-Secreta-De-La-Base', $recargada->provisioning_secrets['db_password']);
        $this->assertSame('u767360347_lacava', $recargada->provisioning_secrets['db_name']);
        $this->assertNotNull($recargada->hosting_provisioned_at);
        $this->assertSame('2026', $recargada->hosting_provisioned_at->format('Y'));
    }

    public function test_los_secretos_no_salen_nunca_serializados(): void
    {
        $api = $this->crear_api_de_cliente();

        $api->provisioning_secrets = ['db_password' => 'Cl4ve-Secreta-De-La-Base'];
        $api->save();

        /*
         * Esta relación viaja en el index y en el show de instalaciones, de upgrades y de clientes.
         * Si el $hidden no está, la contraseña sale descifrada en todos esos payloads.
         */
        $serializada = ClientApi::find($api->id)->toArray();

        $this->assertArrayNotHasKey('provisioning_secrets', $serializada);
        $this->assertStringNotContainsString('Cl4ve-Secreta-De-La-Base', json_encode($serializada));
    }

    public function test_la_columna_de_secretos_es_text_y_no_json(): void
    {
        /*
         * Mecánico a propósito: `json` parece el tipo correcto y no lo es. El cast encrypted:array
         * guarda el string de Laravel Crypt, que MySQL rechazaría en una columna json con "Invalid
         * JSON text" —y fallaría justo después de haber creado la base en Hostinger, que es el peor
         * momento para perder una contraseña que la API no deja volver a leer.
         */
        $columna = DB::selectOne("SHOW COLUMNS FROM client_apis LIKE 'provisioning_secrets'");

        $this->assertSame('text', strtolower($columna->Type));
        $this->assertSame('YES', $columna->Null);
    }

    public function test_una_instalacion_nueva_no_aprovisiona_nada_por_defecto(): void
    {
        $api = $this->crear_api_de_cliente();

        $instalacion = ClientInstallation::create([
            'client_id'     => $api->client_id,
            'client_api_id' => $api->id,
            'status'        => 'pendiente',
        ]);

        /*
         * El default null es lo que deja a todas las filas viejas —y a toda fila nueva creada por
         * un SPA que no manda el campo— corriendo el pipeline de siempre, sin backfill.
         */
        $this->assertNull($instalacion->fresh()->provision_hosting_type);

        $instalacion->provision_hosting_type = ClientInstallation::PROVISION_SHARED_HOSTING;
        $instalacion->save();

        $this->assertSame('shared_hosting', $instalacion->fresh()->provision_hosting_type);
        $this->assertSame(
            ['shared_hosting', 'vps'],
            ClientInstallation::PROVISION_HOSTING_TYPES
        );
        $this->assertSame(
            ['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
            ClientInstallation::CLAVES_ENV_APROVISIONADAS
        );
    }

    /**
     * Crea un cliente con una ClientApi propia.
     *
     * @return ClientApi
     */
    private function crear_api_de_cliente(): ClientApi
    {
        $sufijo = Str::random(8);

        $client                  = new Client();
        $client->name            = 'Cliente de prueba ' . $sufijo;
        $client->slug            = Str::slug('cliente-prueba-' . $sufijo);
        $client->api_url         = 'https://api-' . $sufijo . '.comerciocity.com';
        $client->api_key         = Str::random(20);
        $client->inbound_api_key = Str::random(20);
        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-' . $sufijo . '.comerciocity.com';
        $api->spa_url      = 'https://' . $sufijo . '.comerciocity.com';
        $api->path         = $sufijo . '/api';
        $api->hosting_type = 'shared_hosting';
        $api->save();

        return $api;
    }
}
