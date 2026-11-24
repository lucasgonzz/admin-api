<?php

namespace Tests\Feature\WhatsappPersonal;

use App\Models\WhatsappConfig;
use App\Services\ClientWhatsappConexionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * CA19 a CA21 — Los comandos de operación del número personal (cargar, registrar webhook, estado,
 * depurar) y la migración de sus dos tablas.
 */
class ComandosDelNumeroPersonalTest extends BaseDelNumeroPersonal
{
    /**
     * Corre un comando y devuelve código de salida y salida de texto.
     *
     * @param string               $comando    Nombre del comando.
     * @param array<string, mixed> $parametros Argumentos y opciones.
     *
     * @return array{0: int, 1: string}
     */
    private function correr(string $comando, array $parametros = []): array
    {
        $codigo = Artisan::call($comando, $parametros);

        return [$codigo, Artisan::output()];
    }

    /**
     * CA19 — `whatsapp:personal-cargar` crea la fila copiando clave y WABA del principal, sin activarla
     * salvo `--activar`, sin tocar el secreto del webhook ni mostrar la clave.
     *
     * @return void
     */
    public function test_cargar_copia_la_clave_del_principal(): void
    {
        $this->crear_principal();

        list($codigo, $salida) = $this->correr('whatsapp:personal-cargar', [
            '--phone-number-id' => self::PNI_PERSONAL,
            '--display-phone'   => '+54 9 3444 62-2139',
        ]);

        $this->assertSame(0, $codigo, $salida);
        $fila = WhatsappConfig::where('proposito', 'personal')->first();
        $this->assertNotNull($fila);
        $this->assertSame(self::CLAVE_KAPSO, $fila->kapso_api_key);
        $this->assertSame(self::WABA_ID, $fila->waba_id);
        $this->assertSame(self::PNI_PERSONAL, $fila->phone_number_id);
        $this->assertFalse((bool) $fila->is_active, 'Sin --activar queda apagada.');
        $this->assertFalse((bool) $fila->test_mode);
        $this->assertTrue($fila->webhook_secret === null || $fila->webhook_secret === '');

        // Segunda corrida con --activar: la misma fila, activa; el secreto ya cargado no se toca.
        $fila->forceFill(['webhook_secret' => 'secreto-ya-registrado'])->save();
        list($codigo2, $salida2) = $this->correr('whatsapp:personal-cargar', [
            '--phone-number-id' => self::PNI_PERSONAL,
            '--activar'         => true,
        ]);

        $this->assertSame(0, $codigo2, $salida2);
        $this->assertSame(1, WhatsappConfig::where('proposito', 'personal')->count());
        $fila = WhatsappConfig::where('proposito', 'personal')->first();
        $this->assertTrue((bool) $fila->is_active);
        $this->assertSame('secreto-ya-registrado', $fila->webhook_secret);

        $this->assertStringNotContainsString(self::CLAVE_KAPSO, $salida . $salida2, 'Nunca imprime la clave.');
        Http::assertNothingSent();
    }

    /**
     * CA19 — Un `phone_number_id` que ya usa otra fila se rechaza sin escribir nada.
     *
     * @return void
     */
    public function test_cargar_rechaza_phone_number_id_repetido(): void
    {
        $this->crear_principal();

        list($codigo, $salida) = $this->correr('whatsapp:personal-cargar', ['--phone-number-id' => self::PNI_PRINCIPAL]);

        $this->assertNotSame(0, $codigo, $salida);
        $this->assertSame(0, WhatsappConfig::where('proposito', 'personal')->count());
        $this->assertSame(1, WhatsappConfig::count());
    }

    /**
     * CA19 — Con `--dry-run` no escribe y no muestra la clave.
     *
     * @return void
     */
    public function test_cargar_dry_run_no_escribe(): void
    {
        $this->crear_principal();

        list($codigo, $salida) = $this->correr('whatsapp:personal-cargar', [
            '--phone-number-id' => self::PNI_PERSONAL,
            '--activar'         => true,
            '--dry-run'         => true,
        ]);

        $this->assertSame(0, $codigo, $salida);
        $this->assertSame(0, WhatsappConfig::where('proposito', 'personal')->count());
        $this->assertStringNotContainsString(self::CLAVE_KAPSO, $salida);
    }

    /**
     * CA19 — `whatsapp:registrar-webhook --proposito=personal --dry-run` lista los cinco eventos sin salir
     * a la red; `--reemplazar` con personal falla; un propósito desconocido se rechaza nombrando los válidos.
     *
     * @return void
     */
    public function test_registrar_webhook_acepta_personal_y_no_reemplaza(): void
    {
        $this->crear_principal();
        $this->crear_personal(['webhook_secret' => null]);

        list($codigo, $salida) = $this->correr('whatsapp:registrar-webhook', ['--proposito' => 'personal', '--dry-run' => true]);

        $this->assertSame(0, $codigo, $salida);
        foreach (['received', 'sent', 'delivered', 'read', 'failed'] as $evento) {
            $this->assertStringContainsString('whatsapp.message.' . $evento, $salida);
        }
        $this->assertStringContainsString(self::PNI_PERSONAL, $salida);
        Http::assertNothingSent();

        list($codigo_reemplazo, $salida_reemplazo) = $this->correr('whatsapp:registrar-webhook', [
            '--proposito' => 'personal', '--dry-run' => true, '--reemplazar' => true,
        ]);
        $this->assertNotSame(0, $codigo_reemplazo, $salida_reemplazo);

        list($codigo_raro, $salida_raro) = $this->correr('whatsapp:registrar-webhook', ['--proposito' => 'inventado', '--dry-run' => true]);
        $this->assertNotSame(0, $codigo_raro);
        $this->assertStringContainsString('principal, asistente, personal', $salida_raro);
    }

    /**
     * CA19 🔴 — Dar de alta la fila personal NO rompe la provisión de conexiones de clientes: sigue habiendo
     * UNA clave de Kapso distinta (la fila personal copia la del principal).
     *
     * @return void
     */
    public function test_la_clave_del_proyecto_sigue_siendo_una_sola(): void
    {
        $this->crear_principal();
        $this->correr('whatsapp:personal-cargar', ['--phone-number-id' => self::PNI_PERSONAL, '--activar' => true]);
        $this->assertSame(1, WhatsappConfig::where('proposito', 'personal')->count());

        $servicio = new class extends ClientWhatsappConexionService {
            /**
             * Expone el método protegido para medirlo.
             *
             * @return array<string, mixed>
             */
            public function clave_expuesta()
            {
                return $this->clave_de_kapso_del_proyecto();
            }
        };

        $this->assertSame(self::CLAVE_KAPSO, $servicio->clave_expuesta()['clave']);
    }

    /**
     * CA16/CA19 — `whatsapp:estado` muestra la sección del número personal sin clave ni secreto.
     *
     * @return void
     */
    public function test_estado_no_imprime_secretos(): void
    {
        $this->crear_principal();
        $this->crear_personal();

        list($codigo, $salida) = $this->correr('whatsapp:estado', ['--sin-red' => true]);

        $this->assertStringContainsString('personal', strtolower($salida));
        $this->assertStringContainsString(self::PNI_PERSONAL, $salida);
        $this->assertStringNotContainsString(self::CLAVE_KAPSO, $salida);
        $this->assertStringNotContainsString(self::SECRETO_PERSONAL, $salida);
        $this->assertStringNotContainsString(self::SECRETO_PRINCIPAL, $salida);
        Http::assertNothingSent();
    }

    /**
     * CA20 — `whatsapp:personal-depurar` borra los mensajes de más de N días (90 por defecto, configurable) y
     * los chats que quedan vacíos, conserva lo reciente y no toca ninguna otra tabla.
     *
     * @return void
     */
    public function test_depurar_borra_viejos_y_conserva_recientes(): void
    {
        $this->sembrar_mensaje(['chat_telefono' => '+5491100000010', 'sent_at' => now()->subDays(100)]);
        $this->sembrar_mensaje(['chat_telefono' => '+5491100000011', 'sent_at' => now()->subDays(100)]);
        $this->sembrar_mensaje(['chat_telefono' => '+5491100000011', 'sent_at' => now()->subDays(2)]);
        $this->sembrar_mensaje(['chat_telefono' => '+5491100000012', 'sent_at' => now()->subDays(89)]);
        $this->sembrar_mensaje(['chat_telefono' => '+5491100000012', 'sent_at' => now()->subDays(40)]);
        $antes = $this->foto_de_la_base();

        list($codigo, $salida) = $this->correr('whatsapp:personal-depurar');

        $this->assertSame(0, $codigo, $salida);
        $this->assertSame(3, DB::table('whatsapp_personal_messages')->count());
        $this->assertSame(0, DB::table('whatsapp_personal_chats')->where('chat_telefono', '+5491100000010')->count(), 'El chat vacío se borra.');
        $this->assertSame(1, DB::table('whatsapp_personal_messages')->where('chat_telefono', '+5491100000011')->count());
        $this->assertSame(2, DB::table('whatsapp_personal_chats')->count());
        $this->assertSame($antes, $this->foto_de_la_base(), 'Ninguna otra tabla se toca.');

        // Configurable: con 30 días se va también el de hace 40.
        config(['panel_agent.whatsapp_personal_retencion_dias' => 30]);
        $this->correr('whatsapp:personal-depurar');
        $this->assertSame(2, DB::table('whatsapp_personal_messages')->count());

        // Y por opción: con 1 día no queda nada.
        $this->correr('whatsapp:personal-depurar', ['--dias' => 1]);
        $this->assertSame(0, DB::table('whatsapp_personal_messages')->count());
        $this->assertSame(0, DB::table('whatsapp_personal_chats')->count());
    }

    /**
     * CA20 — `--todo-del-chat` borra sólo ese chat, aunque se pase el teléfono en otro formato.
     *
     * @return void
     */
    public function test_depurar_un_chat(): void
    {
        $this->sembrar_mensaje(['chat_telefono' => self::TEL_CLIENTE, 'sent_at' => now()->subMinutes(5)]);
        $this->sembrar_mensaje(['chat_telefono' => self::TEL_CLIENTE, 'sent_at' => now()->subMinutes(3)]);
        $this->sembrar_mensaje(['chat_telefono' => '+5491100000099', 'sent_at' => now()->subMinutes(2)]);

        list($codigo, $salida) = $this->correr('whatsapp:personal-depurar', ['--todo-del-chat' => '3412345678']);

        $this->assertSame(0, $codigo, $salida);
        $this->assertSame(0, DB::table('whatsapp_personal_messages')->where('chat_telefono', self::TEL_CLIENTE)->count());
        $this->assertSame(0, DB::table('whatsapp_personal_chats')->where('chat_telefono', self::TEL_CLIENTE)->count());
        $this->assertSame(1, DB::table('whatsapp_personal_messages')->where('chat_telefono', '+5491100000099')->count());
        $this->assertSame(1, DB::table('whatsapp_personal_chats')->count());
    }

    /**
     * CA21 — La migración crea las dos tablas con las columnas y los índices del plan, sin claves foráneas,
     * es idempotente (`hasTable`) y su `down()` las borra.
     *
     * No se ejecuta `down()` contra la base: en MySQL un DDL hace commit implícito y se llevaría puesto el
     * aislamiento de la transacción de la prueba. Se verifica el código.
     *
     * @return void
     */
    public function test_migracion_crea_tablas_sin_fk_y_con_indices(): void
    {
        $archivos = glob(base_path('database/migrations/2026_11_25_110000_create_whatsapp_personal_tables.php'));
        $this->assertCount(1, $archivos, 'Falta la migración 2026_11_25_110000_create_whatsapp_personal_tables.php');

        $codigo = file_get_contents($archivos[0]);
        foreach (['->foreign(', 'foreignId', 'constrained(', '->references('] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $codigo);
        }
        $this->assertStringContainsString("Schema::hasTable('whatsapp_personal_messages')", $codigo);
        $this->assertStringContainsString("Schema::hasTable('whatsapp_personal_chats')", $codigo);
        $this->assertStringContainsString("Schema::dropIfExists('whatsapp_personal_messages')", $codigo);
        $this->assertStringContainsString("Schema::dropIfExists('whatsapp_personal_chats')", $codigo);

        $columnas_mensajes = [
            'id', 'phone_number_id', 'chat_telefono', 'direccion', 'origen', 'whatsapp_message_id', 'tipo', 'cuerpo', 'media_id',
            'media_mime', 'media_filename', 'contact_name', 'reply_to_wamid', 'estado', 'estado_error', 'op_id', 'op_hash',
            'sent_at', 'created_at', 'updated_at',
        ];
        $columnas_chats = ['id', 'chat_telefono', 'contact_name', 'last_message_at', 'last_inbound_at', 'leido_hasta', 'created_at', 'updated_at'];

        $this->assertTrue(\Schema::hasTable('whatsapp_personal_messages'));
        $this->assertTrue(\Schema::hasTable('whatsapp_personal_chats'));
        foreach ($columnas_mensajes as $columna) {
            $this->assertTrue(\Schema::hasColumn('whatsapp_personal_messages', $columna), $columna);
        }
        foreach ($columnas_chats as $columna) {
            $this->assertTrue(\Schema::hasColumn('whatsapp_personal_chats', $columna), $columna);
        }

        $indices = [];
        foreach (DB::select('SHOW INDEX FROM whatsapp_personal_messages') as $fila) {
            $indices[$fila->Key_name][(int) $fila->Seq_in_index] = ['col' => $fila->Column_name, 'unico' => (int) $fila->Non_unique === 0];
        }
        $this->assertTrue($indices['wpm_wamid_uq'][1]['unico']);
        $this->assertSame('whatsapp_message_id', $indices['wpm_wamid_uq'][1]['col']);
        $this->assertTrue($indices['wpm_op_uq'][1]['unico']);
        $this->assertSame('op_id', $indices['wpm_op_uq'][1]['col']);
        $this->assertFalse($indices['wpm_chat_sent_idx'][1]['unico']);
        $this->assertSame(['chat_telefono', 'sent_at'], [$indices['wpm_chat_sent_idx'][1]['col'], $indices['wpm_chat_sent_idx'][2]['col']]);

        $indices_chats = [];
        foreach (DB::select('SHOW INDEX FROM whatsapp_personal_chats') as $fila) {
            $indices_chats[$fila->Key_name] = (int) $fila->Non_unique === 0;
        }
        $this->assertTrue($indices_chats['wpc_tel_uq']);

        $con_fk = DB::select(
            'SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() '
            . "AND TABLE_NAME IN ('whatsapp_personal_messages', 'whatsapp_personal_chats') AND REFERENCED_TABLE_NAME IS NOT NULL"
        );
        $this->assertCount(0, $con_fk, 'Sin claves foráneas.');

        // Idempotente: correr `up()` con las tablas ya creadas no falla ni cambia nada.
        require_once $archivos[0];
        $migracion = new \CreateWhatsappPersonalTables();
        $migracion->up();
        $this->assertTrue(\Schema::hasTable('whatsapp_personal_messages'));
    }
}
