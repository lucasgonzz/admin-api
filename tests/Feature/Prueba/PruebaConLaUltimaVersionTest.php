<?php

namespace Tests\Feature\Prueba;

use App\Jobs\CrearInstanciaDePoolJob;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\EcommerceVersion;
use App\Models\Lead;
use App\Models\TrialInstance;
use App\Models\Version;
use App\Services\Prueba\PruebaPoolService;
use Illuminate\Support\Facades\Queue;

/**
 * Misión `prueba-ultima-version` (27/11/2026): cuando un lead completa el formulario de la prueba de 7 días,
 * se le instala la versión más nueva publicada hasta la fecha. Tests de aceptación CA1 a CA11 del PLAN.
 *
 * Escenario base (reproduce el caso de Dani): empresa 4.3.65 y luego 4.3.87 publicadas (la de id más alto es la
 * 4.3.87) y tienda 2.0.1 y 2.0.2 publicadas (2.0.2 supera en orden semántico a la 1.9.99 que deja `setUp`).
 * Instancia vieja `prueba-13` (4.3.65 / 2.0.1, id menor) e instancia al día `prueba-14` (4.3.87 / 2.0.2).
 *
 * 🔴 Estos tests se escribieron ANTES de la implementación: hoy el alta toma la libre más vieja, la ficha guarda
 * «la última publicada» en vez de lo instalado, el pool no renueva libres viejas, no hay tope de certificados,
 * no hay retiro de viejas y la plantilla se arma a mano. Cada test dice cuál de esos comportamientos prueba.
 *
 * El teléfono del alta es 1155550101 y no el de `BaseDePruebaGratuita::TELEFONO`: la base de testing trae un
 * cliente sembrado con ese (`+543444622139`) y el alta daría 409 `ya_es_cliente` por un motivo ajeno a la misión.
 *
 * PHP 7.4.
 */
class PruebaConLaUltimaVersionTest extends BaseDePruebaGratuita
{
    /** Teléfono móvil válido que no existe en la base de testing (ver el docblock de la clase). */
    const TELEFONO_NUEVO = '1155550101';

    const TELEFONO_NUEVO_E164 = '+5491155550101';

    /** Perilla de presupuesto de certificados por semana (la implementación la expone en `PruebaSettings`). */
    const KEY_TOPE = 'prueba_tope_certificados_semana';

    /** Sufijo para que `user_id` no se repita entre las instancias de un mismo test. */
    private $siguiente_user_id = 910100;

    /**
     * Deja publicadas las versiones del escenario base: empresa 4.3.65 y después 4.3.87 (la de id más alto), y
     * tienda 2.0.1 y 2.0.2. Las 4.3.99 y 1.9.99 de `setUp` quedan con id menor / semántica menor.
     *
     * @return void
     */
    private function publicar_versiones_del_escenario(): void
    {
        Version::create(['version' => '4.3.65', 'title' => 'Vieja', 'status' => 'published', 'published_at' => now()]);
        Version::create(['version' => '4.3.87', 'title' => 'Nueva', 'status' => 'published', 'published_at' => now()]);
        EcommerceVersion::create(['version' => '2.0.1', 'title' => 'Tienda vieja', 'status' => 'published', 'published_at' => now()]);
        EcommerceVersion::create(['version' => '2.0.2', 'title' => 'Tienda nueva', 'status' => 'published', 'published_at' => now()]);
    }

    /**
     * Crea una instancia del pool con la versión de empresa y de tienda indicadas.
     *
     * @param string $nombre            `prueba-NN`.
     * @param string $version           Versión de empresa instalada.
     * @param string $version_tienda    Versión de tienda instalada.
     * @param string $estado            Estado inicial (default `libre`).
     * @param array  $extra             Columnas extra (client_id, lead_id, etapa, alias_slug...).
     *
     * @return TrialInstance
     */
    private function instancia(string $nombre, string $version, string $version_tienda, string $estado = TrialInstance::ESTADO_LIBRE, array $extra = []): TrialInstance
    {
        $this->siguiente_user_id += 100;

        return TrialInstance::create(array_merge([
            'nombre'             => $nombre,
            'estado'             => $estado,
            'user_id'            => $this->siguiente_user_id,
            'erp_vps_path'       => $nombre,
            'ecommerce_vps_path' => 'tienda-' . $nombre,
            'version'            => $version,
            'ecommerce_version'  => $version_tienda,
            'etapa'              => $estado === TrialInstance::ESTADO_LIBRE ? 'libre' : 'encolada',
            'progreso'           => $estado === TrialInstance::ESTADO_LIBRE ? 100 : 0,
        ], $extra));
    }

    /**
     * Mueve el `updated_at` de una instancia (las guardas G1 y G2 miran la última vez que se movió).
     *
     * @param TrialInstance $instancia Instancia a mover.
     * @param int           $minutos   Hace cuántos minutos se movió por última vez.
     *
     * @return void
     */
    private function mover_hace(TrialInstance $instancia, int $minutos): void
    {
        TrialInstance::query()->whereKey($instancia->id)->update(['updated_at' => now()->subMinutes($minutos)]);
    }

    /**
     * El alta con el teléfono que no está sembrado.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function alta_con_telefono_nuevo()
    {
        return $this->postear_alta(['telefono' => self::TELEFONO_NUEVO]);
    }

    /**
     * Los nombres de las instancias `creando` ordenados, con su versión y la de tienda.
     *
     * @return array<int, array<string, string>>
     */
    private function creando(): array
    {
        return TrialInstance::where('estado', TrialInstance::ESTADO_CREANDO)->orderBy('nombre')->get()->map(function ($fila) {
            return ['nombre' => $fila->nombre, 'version' => (string) $fila->version, 'tienda' => (string) $fila->ecommerce_version];
        })->all();
    }

    /**
     * Dispara `pruebas:mantener-pool` y exige exit 0.
     *
     * @return void
     */
    private function mantener_pool(): void
    {
        $this->artisan('pruebas:mantener-pool')->assertExitCode(0);
    }

    /**
     * Deja el fake de Hostinger con una zona vacía: el borrado de una libre sin A records en la zona no escribe DNS.
     *
     * @return void
     */
    private function zona_vacia_para_el_borrado(): void
    {
        $this->hostinger->responder('/api/dns/v1/zones/', [], 'GET');
        $this->hostinger->responder('/api/dns/v1/snapshots/', [], 'GET');
    }

    /* ------------------------------------------------------------------ CA1 y CA2 */

    /**
     * CA1 + CA2 (D1, D2): con una libre vieja (id menor) y una al día, el alta se lleva la AL DÍA y deja la vieja
     * libre; la ficha del cliente guarda la versión de ERP y de tienda de la instancia asignada.
     * Hoy el alta toma la de id menor (`prueba-13`): rojo.
     *
     * @return void
     */
    public function test_el_alta_toma_la_libre_al_dia_y_la_ficha_guarda_su_version(): void
    {
        $this->publicar_versiones_del_escenario();
        $vieja  = $this->instancia('prueba-13', '4.3.65', '2.0.1');
        $al_dia = $this->instancia('prueba-14', '4.3.87', '2.0.2');
        $this->assertLessThan($al_dia->id, $vieja->id, 'La vieja tiene el id menor: hoy es la que se toma.');

        $this->alta_con_telefono_nuevo()->assertStatus(201);

        /* CA1: la asignada es la al día; la vieja sigue libre y sin lead. */
        $al_dia->refresh();
        $vieja->refresh();
        $this->assertSame(TrialInstance::ESTADO_ASIGNANDO, $al_dia->estado, 'El alta tiene que tomar prueba-14, la de la versión publicada.');
        $this->assertNotNull($al_dia->lead_id);
        $this->assertSame(TrialInstance::ESTADO_LIBRE, $vieja->estado);
        $this->assertNull($vieja->lead_id);

        /* CA2: la ficha dice lo que hay instalado (ERP y tienda), no «la última publicada» por casualidad. */
        $client = Client::where('phone', self::TELEFONO_NUEVO_E164)->firstOrFail();
        $this->assertSame((int) Version::where('version', '4.3.87')->value('id'), (int) $client->current_version_id);
        $tienda = ClientEcommerce::where('client_id', $client->id)->firstOrFail();
        $this->assertSame((int) EcommerceVersion::where('version', '2.0.2')->value('id'), (int) $tienda->ecommerce_version_id, 'La tienda hoy nace con ecommerce_version_id null.');
    }

    /* ------------------------------------------------------------------ CA3 */

    /**
     * CA3 (D1 respaldo + D2): si sólo hay una libre vieja, el alta la toma igual (no se pierde al lead) y la ficha
     * queda con la versión REAL (4.3.65 y tienda 2.0.1), no con la última publicada.
     * Hoy guarda 4.3.87 y tienda null: rojo.
     *
     * @return void
     */
    public function test_sin_libre_al_dia_toma_la_vieja_y_la_ficha_dice_la_version_real(): void
    {
        $this->publicar_versiones_del_escenario();
        $vieja = $this->instancia('prueba-13', '4.3.65', '2.0.1');

        $this->alta_con_telefono_nuevo()->assertStatus(201);

        $this->assertSame(TrialInstance::ESTADO_ASIGNANDO, $vieja->fresh()->estado, 'El respaldo toma la vieja en vez de dar 409.');

        $client = Client::where('phone', self::TELEFONO_NUEVO_E164)->firstOrFail();
        $this->assertSame((int) Version::where('version', '4.3.65')->value('id'), (int) $client->current_version_id, 'La ficha no puede mentir diciendo 4.3.87.');
        $tienda = ClientEcommerce::where('client_id', $client->id)->firstOrFail();
        $this->assertSame((int) EcommerceVersion::where('version', '2.0.1')->value('id'), (int) $tienda->ecommerce_version_id);
    }

    /* ------------------------------------------------------------------ CA4 */

    /**
     * CA4 (D2 fallback): una instancia cuya versión no existe en `versions` deja `current_version_id` en NULL
     * (desconocida), nunca el id de la última publicada. La tienda sí existe, así que se guarda.
     * Hoy guarda el id de la última publicada: rojo.
     *
     * @return void
     */
    public function test_una_version_que_no_esta_en_versions_queda_null_y_no_la_ultima(): void
    {
        $this->publicar_versiones_del_escenario();
        $this->instancia('prueba-13', '4.3.50', '2.0.2');

        $this->alta_con_telefono_nuevo()->assertStatus(201);

        $client = Client::where('phone', self::TELEFONO_NUEVO_E164)->firstOrFail();
        $this->assertNull($client->current_version_id, 'Una versión desconocida es null, no «la última publicada».');
        $tienda = ClientEcommerce::where('client_id', $client->id)->firstOrFail();
        $this->assertSame((int) EcommerceVersion::where('version', '2.0.2')->value('id'), (int) $tienda->ecommerce_version_id);
    }

    /* ------------------------------------------------------------------ CA5 */

    /**
     * CA5 (D3): con mínimo 2 y dos libres VIEJAS, `pruebas:mantener-pool` encola dos reemplazos con la versión
     * publicada (4.3.87 / 2.0.2); una segunda corrida no encola más (los `creando` ya están al día). Las viejas
     * no se tocan en esta etapa (siguen libres como respaldo) y las asignadas tampoco (CA11).
     * Hoy encola 0 porque las viejas cubren el mínimo: rojo.
     *
     * @return void
     */
    public function test_mantener_pool_rearma_las_libres_viejas_con_la_version_publicada(): void
    {
        $this->publicar_versiones_del_escenario();
        $this->instancia('prueba-01', '4.3.65', '2.0.1');
        $this->instancia('prueba-02', '4.3.65', '2.0.1');
        $asignada = $this->instancia('prueba-09', '4.3.65', '2.0.1', TrialInstance::ESTADO_ASIGNADA, ['client_id' => 777001, 'lead_id' => 777001, 'alias_slug' => 'otro-negocio']);

        $this->mantener_pool();

        $this->assertSame(
            [
                ['nombre' => 'prueba-03', 'version' => '4.3.87', 'tienda' => '2.0.2'],
                ['nombre' => 'prueba-04', 'version' => '4.3.87', 'tienda' => '2.0.2'],
            ],
            $this->creando(),
            'Cada libre vieja se reemplaza por una armada con la versión publicada.'
        );
        Queue::assertPushed(CrearInstanciaDePoolJob::class, 2);

        /* Una segunda corrida: las dos nuevas ya cuentan como al día, no se encola nada más. */
        $this->mantener_pool();
        Queue::assertPushed(CrearInstanciaDePoolJob::class, 2);
        $this->assertCount(2, $this->creando());

        /* CA11: lo que ya estaba (libres viejas y la asignada) no cambia de estado ni de versión. */
        $this->assertSame(TrialInstance::ESTADO_LIBRE, TrialInstance::where('nombre', 'prueba-01')->value('estado'));
        $this->assertSame(TrialInstance::ESTADO_LIBRE, TrialInstance::where('nombre', 'prueba-02')->value('estado'));
        $asignada->refresh();
        $this->assertSame(TrialInstance::ESTADO_ASIGNADA, $asignada->estado);
        $this->assertSame('4.3.65', $asignada->version);
        $this->assertSame('2.0.1', $asignada->ecommerce_version);
    }

    /* ------------------------------------------------------------------ CA6 */

    /**
     * CA6 (D3 tope de certificados): con el presupuesto agotado (tope 8 y dos filas creadas hoy = 8 usados) no se
     * encolan reemplazos y el comando sale 0; con el MISMO escenario y presupuesto de sobra (tope 40) sí se
     * encolan los dos (control positivo: sin él, el «0» pasaría hoy por el motivo equivocado). Y con NINGUNA libre
     * (ni vieja) y el presupuesto agotado, el faltante real se arma igual: el tope sólo frena las renovaciones.
     * Hoy el control positivo encola 0: rojo.
     *
     * @return void
     */
    public function test_el_tope_de_certificados_frena_la_renovacion_pero_no_el_faltante_real(): void
    {
        $this->publicar_versiones_del_escenario();
        $this->instancia('prueba-01', '4.3.65', '2.0.1');
        $this->instancia('prueba-02', '4.3.65', '2.0.1');

        /* Presupuesto agotado: 2 filas creadas en los últimos 7 días × 4 certificados = 8 = tope. */
        AdminSetting::set(self::KEY_TOPE, '8');
        $this->mantener_pool();
        $this->assertSame([], $this->creando(), 'Sin presupuesto no se renueva: las viejas siguen sirviendo de respaldo.');
        Queue::assertNothingPushed();

        /* Control positivo: el mismo escenario con presupuesto de sobra renueva las dos. */
        AdminSetting::set(self::KEY_TOPE, '40');
        $this->mantener_pool();
        $this->assertCount(2, $this->creando(), 'Con presupuesto, las dos viejas se reemplazan.');
        Queue::assertPushed(CrearInstanciaDePoolJob::class, 2);

        /* El faltante real (ninguna libre, ni vieja) NO se frena por el tope: dos filas borradas hoy gastan el presupuesto. */
        TrialInstance::query()->delete();
        $this->instancia('prueba-01~aaaaaa', '4.3.65', '2.0.1', TrialInstance::ESTADO_BORRADA);
        $this->instancia('prueba-02~bbbbbb', '4.3.65', '2.0.1', TrialInstance::ESTADO_BORRADA);
        AdminSetting::set(self::KEY_TOPE, '8');
        $this->mantener_pool();
        $this->assertCount(2, $this->creando(), 'Sin ninguna libre el pool se arma igual aunque el tope esté agotado.');
        Queue::assertPushed(CrearInstanciaDePoolJob::class, 4);
    }

    /* ------------------------------------------------------------------ CA7 */

    /**
     * CA7 (D4): con el mínimo cubierto por libres al día, `pruebas:mantener-pool` retira UNA libre vieja
     * (`prueba-borrar.sh 'prueba-01' 'prueba-01'`), la deja `borrada` y no toca la asignada vieja. Casos que NO
     * retiran nada: (a) sólo una libre al día con mínimo 2, (b) un alta en curso (G1), (c) una creación en curso
     * (G2). Con dos viejas libres y suficientes al día, una corrida retira sólo una.
     * Hoy el comando no retira nada: rojo.
     *
     * @return void
     */
    public function test_mantener_pool_retira_una_libre_vieja_cuando_hay_reemplazo_y_respeta_g1_g2(): void
    {
        $this->publicar_versiones_del_escenario();
        $this->zona_vacia_para_el_borrado();
        $this->vps->responder('prueba-borrar.sh', ['ok' => true, 'respaldo' => '/root/respaldos-pruebas/prueba-01-20261127', 'etapas' => []]);

        /* Caso principal. */
        $this->instancia('prueba-03', '4.3.87', '2.0.2');
        $this->instancia('prueba-04', '4.3.87', '2.0.2');
        $vieja    = $this->instancia('prueba-01', '4.3.65', '2.0.1');
        $asignada = $this->instancia('prueba-02', '4.3.65', '2.0.1', TrialInstance::ESTADO_ASIGNADA, ['client_id' => 777002, 'lead_id' => 777002, 'alias_slug' => 'otro-negocio']);

        $this->mantener_pool();

        $this->assertSame(["bash /root/prueba-gratuita/prueba-borrar.sh 'prueba-01' 'prueba-01'"], $this->vps->comandos_de('prueba-borrar.sh'), 'Se retira la vieja una sola vez, con el nombre como slug.');
        $this->assertSame(TrialInstance::ESTADO_BORRADA, $vieja->fresh()->estado);
        $asignada->refresh();
        $this->assertSame(TrialInstance::ESTADO_ASIGNADA, $asignada->estado, 'Una asignada nunca se toca.');
        $this->assertSame('4.3.65', $asignada->version);
        $this->assertSame(777002, (int) $asignada->client_id);
        $this->assertSame(TrialInstance::ESTADO_LIBRE, TrialInstance::where('nombre', 'prueba-03')->value('estado'));
        $this->assertSame(TrialInstance::ESTADO_LIBRE, TrialInstance::where('nombre', 'prueba-04')->value('estado'));

        /* Dos viejas libres y suficientes al día: una corrida retira UNA sola (la más vieja). */
        TrialInstance::query()->delete();
        $this->vps->comandos = [];
        $this->instancia('prueba-03', '4.3.87', '2.0.2');
        $this->instancia('prueba-04', '4.3.87', '2.0.2');
        $primera = $this->instancia('prueba-01', '4.3.65', '2.0.1');
        $segunda = $this->instancia('prueba-05', '4.3.65', '2.0.1');
        $this->mantener_pool();
        $this->assertCount(1, $this->vps->comandos_de('prueba-borrar.sh'), 'De a una por corrida.');
        $this->assertSame(TrialInstance::ESTADO_BORRADA, $primera->fresh()->estado);
        $this->assertSame(TrialInstance::ESTADO_LIBRE, $segunda->fresh()->estado);

        /* (a) Sólo una libre al día con mínimo 2: el pool quedaría sin respaldo, no se retira nada. */
        TrialInstance::query()->delete();
        $this->vps->comandos = [];
        $this->instancia('prueba-03', '4.3.87', '2.0.2');
        $vieja_a = $this->instancia('prueba-01', '4.3.65', '2.0.1');
        $this->mantener_pool();
        $this->assertSame([], $this->vps->comandos_de('prueba-borrar.sh'), '(a) sin suficientes libres al día no se retira.');
        $this->assertSame(TrialInstance::ESTADO_LIBRE, $vieja_a->fresh()->estado);

        /* (b) G1: un alta en curso (asignando con lead, movida hace 1 minuto) frena el retiro. */
        TrialInstance::query()->delete();
        $this->vps->comandos = [];
        $this->instancia('prueba-03', '4.3.87', '2.0.2');
        $this->instancia('prueba-04', '4.3.87', '2.0.2');
        $vieja_b = $this->instancia('prueba-01', '4.3.65', '2.0.1');
        $alta    = $this->instancia('prueba-06', '4.3.87', '2.0.2', TrialInstance::ESTADO_ASIGNANDO, ['lead_id' => 777003, 'alias_slug' => 'en-curso', 'etapa' => 'reservada']);
        $this->mover_hace($alta, 1);
        $this->mantener_pool();
        $this->assertSame([], $this->vps->comandos_de('prueba-borrar.sh'), '(b) G1: con un alta en curso no se retira.');
        $this->assertSame(TrialInstance::ESTADO_LIBRE, $vieja_b->fresh()->estado);

        /* (c) G2: una creación en curso (creando en `crear`, movida hace 1 minuto) frena el retiro. */
        TrialInstance::query()->delete();
        $this->vps->comandos = [];
        $this->instancia('prueba-03', '4.3.87', '2.0.2');
        $this->instancia('prueba-04', '4.3.87', '2.0.2');
        $vieja_c  = $this->instancia('prueba-01', '4.3.65', '2.0.1');
        $creacion = $this->instancia('prueba-07', '4.3.87', '2.0.2', TrialInstance::ESTADO_CREANDO, ['etapa' => 'crear', 'progreso' => 20]);
        $this->mover_hace($creacion, 1);
        $this->mantener_pool();
        $this->assertSame([], $this->vps->comandos_de('prueba-borrar.sh'), '(c) G2: con una creación en curso no se retira.');
        $this->assertSame(TrialInstance::ESTADO_LIBRE, $vieja_c->fresh()->estado);
    }

    /* ------------------------------------------------------------------ CA8 */

    /**
     * CA8 (D4, G2 ampliado): una fila `borrando` SIN client_id movida hace 1 minuto cuenta como creación en curso
     * (el retiro recarga nginx/fpm igual que una creación); una `borrando` CON client_id (el borrado de un cliente
     * vencido) no. Hoy `creacion_en_curso()` sólo mira `creando`: rojo.
     *
     * @return void
     */
    public function test_un_retiro_en_curso_cuenta_como_creacion_para_g2(): void
    {
        /* Con client_id: no cuenta. */
        $de_cliente = $this->instancia('prueba-01', '4.3.65', '2.0.1', TrialInstance::ESTADO_BORRANDO, ['client_id' => 777004, 'etapa' => 'borrando']);
        $this->mover_hace($de_cliente, 1);
        $this->assertNull(PruebaPoolService::creacion_en_curso(), 'Una borrando de un cliente no frena al pool.');

        /* Sin client_id (el retiro de una libre vieja): cuenta. */
        $retiro = $this->instancia('prueba-02', '4.3.65', '2.0.1', TrialInstance::ESTADO_BORRANDO, ['etapa' => 'borrando']);
        $this->mover_hace($retiro, 1);
        $en_curso = PruebaPoolService::creacion_en_curso();
        $this->assertNotNull($en_curso, 'Un retiro en curso tiene que contar como creación para G2.');
        $this->assertSame($retiro->id, $en_curso->id);

        /* Pasada la ventana (movida hace 60 minutos) ya no cuenta: una colgada no frena al pool para siempre. */
        $this->mover_hace($retiro, 60);
        $this->assertNull(PruebaPoolService::creacion_en_curso());
    }

    /* ------------------------------------------------------------------ CA9 */

    /**
     * CA9 (D5): el job corre `pool-plantilla.sh` con las versiones de la fila ANTES de `pool-crear.sh`.
     * Hoy no la corre: rojo.
     *
     * @return void
     */
    public function test_el_job_arma_la_plantilla_antes_de_crear(): void
    {
        $this->publicar_versiones_del_escenario();
        $instancia = app(PruebaPoolService::class)->crear_fila('4.3.87', '2.0.2');
        $this->zona_antes_y_despues(['prueba-01', 'api-prueba-01', 'tienda-prueba-01', 'api-tienda-prueba-01']);
        $this->vps->responder('pool-crear.sh', ['ok' => true, 'nombre' => 'prueba-01', 'db' => ['name' => 'prueba_01', 'user' => 'prueba_01', 'pass' => 'Clave-Generada-En-El-Vps-01'], 'hosts' => []]);

        (new CrearInstanciaDePoolJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_LIBRE, $instancia->estado, (string) $instancia->error);
        $this->assertSame(['pool-plantilla.sh', 'pool-crear.sh', 'pool-configurar.sh'], $this->vps->scripts_corridos());
        $this->assertSame(["bash /root/prueba-gratuita/pool-plantilla.sh '4.3.87' '2.0.2'"], $this->vps->comandos_de('pool-plantilla.sh'));
    }

    /**
     * CA9 (D5, falla): si `pool-plantilla.sh` falla, la fila queda `fallida` en etapa `plantilla` con el texto del
     * error, NO se corrió `pool-crear.sh` y `mantener()` no encola otra (hay una fallida sin asignar).
     * Hoy la fila falla recién en `crear`: rojo.
     *
     * @return void
     */
    public function test_si_la_plantilla_falla_la_fila_queda_fallida_en_plantilla_y_frena_al_pool(): void
    {
        $this->publicar_versiones_del_escenario();
        $instancia = app(PruebaPoolService::class)->crear_fila('4.3.87', '2.0.2');
        $this->zona_antes_y_despues(['prueba-01', 'api-prueba-01', 'tienda-prueba-01', 'api-tienda-prueba-01']);
        $this->vps->responder('pool-crear.sh', ['ok' => true, 'nombre' => 'prueba-01', 'db' => ['name' => 'prueba_01', 'user' => 'prueba_01', 'pass' => 'Clave-Generada-En-El-Vps-01'], 'hosts' => []]);
        $this->vps->fallar('pool-plantilla.sh', 'composer install: no se pudo bajar el release de GitHub');

        (new CrearInstanciaDePoolJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_FALLIDA, $instancia->estado);
        $this->assertSame('plantilla', $instancia->etapa);
        $this->assertStringContainsString('no se pudo bajar el release de GitHub', (string) $instancia->error);
        $this->assertNotContains('pool-crear.sh', $this->vps->scripts_corridos(), 'Sin plantilla no se crea nada.');

        /* El pool no arma otra al lado: hay una fallida sin asignar. */
        $filas_antes = TrialInstance::count();
        $this->mantener_pool();
        $this->assertSame($filas_antes, TrialInstance::count());
        $this->assertSame(0, TrialInstance::where('estado', TrialInstance::ESTADO_CREANDO)->count());
        $this->assertSame(
            'hay una instancia fallida sin asignar (' . $instancia->nombre . ', etapa plantilla): reintentala o borrala desde /pruebas antes de armar otra.',
            app(PruebaPoolService::class)->motivo_para_no_encolar()
        );
    }

    /* ------------------------------------------------------------------ CA10 */

    /**
     * CA10 (D5, refresco): una fila encolada ANTES de un release (etapa `encolada`) se arma con la versión
     * publicada al momento de correr: la plantilla y `pool-crear.sh` reciben 4.3.87 / 2.0.2 y la fila lo refleja.
     * Hoy se arma con la versión vieja de la fila y sin plantilla: rojo.
     *
     * @return void
     */
    public function test_una_fila_encolada_antes_del_release_se_arma_con_la_version_nueva(): void
    {
        $this->publicar_versiones_del_escenario();
        $instancia = app(PruebaPoolService::class)->crear_fila('4.3.65', '2.0.1');
        $this->assertSame('encolada', $instancia->etapa);
        $this->zona_antes_y_despues(['prueba-01', 'api-prueba-01', 'tienda-prueba-01', 'api-tienda-prueba-01']);
        $this->vps->responder('pool-crear.sh', ['ok' => true, 'nombre' => 'prueba-01', 'db' => ['name' => 'prueba_01', 'user' => 'prueba_01', 'pass' => 'Clave-Generada-En-El-Vps-01'], 'hosts' => []]);

        (new CrearInstanciaDePoolJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_LIBRE, $instancia->estado, (string) $instancia->error);
        $this->assertSame(["bash /root/prueba-gratuita/pool-plantilla.sh '4.3.87' '2.0.2'"], $this->vps->comandos_de('pool-plantilla.sh'), 'La plantilla se arma con la versión publicada ahora, no con la de la fila vieja.');
        $this->assertCount(1, $this->vps->comandos_de('pool-crear.sh'));
        $this->assertStringContainsString("pool-crear.sh 'prueba-01' '4.3.87' '2.0.2' '" . $instancia->user_id . "'", $this->vps->comandos_de('pool-crear.sh')[0]);
        $this->assertSame('4.3.87', $instancia->version);
        $this->assertSame('2.0.2', $instancia->ecommerce_version);
    }

    /* ------------------------------------------------------------------ CA11 */

    /**
     * CA11 (regresión): sin ninguna libre el alta sigue dando 409 `sin_capacidad` y no escribe nada (con el
     * teléfono nuevo). Una instancia asignada a otro negocio no cuenta como libre aunque sea la más nueva.
     * ✅ Este test pasa HOY (es una regresión a custodiar, no un comportamiento nuevo): debe seguir verde.
     *
     * @return void
     */
    public function test_sin_ninguna_libre_sigue_dando_409(): void
    {
        $this->publicar_versiones_del_escenario();
        $this->instancia('prueba-14', '4.3.87', '2.0.2', TrialInstance::ESTADO_ASIGNADA, ['client_id' => 777005, 'lead_id' => 777005, 'alias_slug' => 'otro-negocio']);

        $this->alta_con_telefono_nuevo()->assertStatus(409)->assertJson(['code' => 'sin_capacidad']);

        $this->assertSame(0, Lead::where('phone', self::TELEFONO_NUEVO_E164)->count());
        $this->assertSame(0, Client::where('phone', self::TELEFONO_NUEVO_E164)->count());
        $asignada = TrialInstance::where('nombre', 'prueba-14')->firstOrFail();
        $this->assertSame(TrialInstance::ESTADO_ASIGNADA, $asignada->estado);
        $this->assertSame(777005, (int) $asignada->client_id);
    }
}
