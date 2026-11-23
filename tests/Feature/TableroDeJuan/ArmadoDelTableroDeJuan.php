<?php

namespace Tests\Feature\TableroDeJuan;

use App\Models\Admin;
use App\Models\AdminTask;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineActivity;
use App\Models\PipelineOpportunity;
use App\Models\PipelineStage;
use App\Services\Pipelines\PipelineOpportunityService;
use Carbon\Carbon;
use Database\Seeders\PipelineLeadSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Armado común de los tests de aceptación de la etapa E1 de la misión embudo-juan-pipeline (tablero de Juan,
 * «Registrar llamada», contexto de los agentes, estados simplificados y acceso del perfil «Sólo Leads»).
 *
 * No es un test (el nombre no termina en `Test`, PHPUnit no lo corre solo). Es un trait y no una clase base
 * porque lo usan tests que extienden bases distintas: `BaseDelTableroDeJuan` (alta de la prueba, flujo de
 * entrada) y `PruebaGratuita\BaseDeLaPrueba` (contexto de soporte).
 *
 * Qué deja resuelto:
 *  - Sembrar el pipeline «Leads» como queda DESPUÉS de E1 (`PipelineLeadSeeder` + `PipelineLeadTableroJuanSeeder`),
 *    partiendo siempre de una base sin el pipeline `lead` previo (la base de testing puede traer uno sembrado).
 *  - Dar de alta una tarjeta en la columna que pida el test, con el mismo servicio que usa el CRM real.
 *  - Leer el estado del tablero por la clave estable de la etapa (`stage_key`), no por el nombre visible.
 *  - Admins (comunes y con el perfil Sólo Leads) y el login por Sanctum.
 *  - Insertar llamadas en `lead_llamadas` por query builder: así un test del contexto de los agentes no depende
 *    del nombre del modelo ni del servicio que las registra.
 */
trait ArmadoDelTableroDeJuan
{
    /** @var string Zona horaria de la app: todas las fechas de los tests son hora de Argentina. */
    protected static $ZONA_DEL_TABLERO = 'America/Argentina/Buenos_Aires';

    /** @var string Instante fijo de los tests de E1 (lunes 23/11/2026, mediodía de Argentina). */
    protected static $AHORA_DEL_TABLERO = '2026-11-23 12:00:00';

    /** Las 8 claves del tablero de Juan, en el orden de las columnas. */
    protected static $CLAVES_DEL_TABLERO = [
        'interesados',
        'esperando_llamada',
        'en_prueba_sola',
        'implementacion_acordada',
        'en_implementacion',
        'en_seguimiento',
        'cerrado',
        'no_avanza',
    ];

    // ------------------------------------------------------------------------------------------
    // Reloj
    // ------------------------------------------------------------------------------------------

    /**
     * Clava el reloj en un momento (hora de Argentina).
     *
     * @param string $momento `Y-m-d H:i:s`.
     *
     * @return void
     */
    protected function clavar_el_reloj(string $momento): void
    {
        Carbon::setTestNow(Carbon::parse($momento, self::$ZONA_DEL_TABLERO));
    }

    // ------------------------------------------------------------------------------------------
    // Pipelines
    // ------------------------------------------------------------------------------------------

    /**
     * Borra un pipeline por slug con todo lo que le cuelga (etapas, oportunidades y su historial). Todo adentro de
     * la transacción del test: se revierte solo.
     *
     * @param string $slug Slug del pipeline (`lead`, `agentes`).
     *
     * @return void
     */
    protected function borrar_pipeline_por_slug(string $slug): void
    {
        $previos = Pipeline::query()->where('slug', $slug)->pluck('id')->all();
        if ($previos === []) {
            return;
        }

        $oportunidades = PipelineOpportunity::query()->whereIn('pipeline_id', $previos)->pluck('id')->all();
        PipelineActivity::query()->whereIn('opportunity_id', $oportunidades)->delete();
        PipelineOpportunity::query()->whereIn('id', $oportunidades)->delete();
        PipelineStage::query()->whereIn('pipeline_id', $previos)->delete();
        Pipeline::query()->whereIn('id', $previos)->delete();
    }

    /**
     * Siembra SÓLO el pipeline «Leads» viejo (las 5 etapas de la misión anterior), sin el tablero de Juan.
     *
     * @return Pipeline
     */
    protected function sembrar_pipeline_de_leads_viejo(): Pipeline
    {
        $this->borrar_pipeline_por_slug(PipelineLeadSeeder::SLUG);
        $this->seed(PipelineLeadSeeder::class);

        return $this->pipeline_de_leads();
    }

    /**
     * Siembra el pipeline «Leads» como queda después de E1: el viejo y encima el seeder del tablero de Juan.
     *
     * @return Pipeline
     */
    protected function sembrar_el_tablero(): Pipeline
    {
        $this->sembrar_pipeline_de_leads_viejo();

        return $this->correr_el_seeder_del_tablero();
    }

    /**
     * Corre SÓLO el seeder del tablero de Juan sobre lo que haya sembrado (no borra ni recrea el pipeline).
     *
     * Si el seeder todavía no existe, el test falla acá con un mensaje claro (es el motivo del rojo de todo lo que
     * depende del tablero), no con un error de reflexión de PHPUnit.
     *
     * @return Pipeline
     */
    protected function correr_el_seeder_del_tablero(): Pipeline
    {
        $clase = 'Database\\Seeders\\PipelineLeadTableroJuanSeeder';
        $this->assertTrue(class_exists($clase), 'Falta el seeder ' . $clase . ' (CA1): el tablero de Juan no se puede sembrar.');
        $this->seed($clase);

        return $this->pipeline_de_leads();
    }

    /**
     * El pipeline «Leads» (slug `lead`) con sus etapas.
     *
     * @return Pipeline
     */
    protected function pipeline_de_leads(): Pipeline
    {
        return Pipeline::query()->where('slug', PipelineLeadSeeder::SLUG)->with('stages')->firstOrFail();
    }

    /**
     * La etapa del pipeline de leads por su clave estable.
     *
     * @param string $clave `stage_key` (ej. `esperando_llamada`).
     *
     * @return PipelineStage
     */
    protected function etapa_por_clave(string $clave): PipelineStage
    {
        $etapa = PipelineStage::query()
            ->where('pipeline_id', $this->pipeline_de_leads()->id)
            ->where('stage_key', $clave)
            ->first();

        $this->assertNotNull($etapa, 'El pipeline de leads no tiene la etapa con clave «' . $clave . '».');

        return $etapa;
    }

    /**
     * Da de alta la tarjeta de un lead en la columna pedida, con el mismo servicio del CRM real (queda la
     * actividad `created`, como en producción). Si el sujeto ya tiene una tarjeta abierta, la lleva a esa columna.
     *
     * @param Lead|Client $sujeto Lead o cliente.
     * @param string      $clave  `stage_key` de la columna inicial.
     * @param Pipeline|null $pipeline Null = el pipeline de leads.
     *
     * @return PipelineOpportunity
     */
    protected function dar_de_alta_en($sujeto, string $clave = 'interesados', ?Pipeline $pipeline = null): PipelineOpportunity
    {
        $pipeline = $pipeline !== null ? $pipeline : $this->pipeline_de_leads();
        $etapa    = PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('stage_key', $clave)->firstOrFail();
        $tipo     = $sujeto instanceof Client ? 'client' : 'lead';

        /* Un lead que nace en `link_enviado` o `implementacion_pedida` ya puede traer su tarjeta abierta (la entrada
           automática de E1): en ese caso se la lleva a la columna pedida en lugar de intentar un alta que se saltearía. */
        $abierta = PipelineOpportunity::query()
            ->where('pipeline_id', $pipeline->id)
            ->where($tipo . '_id', (int) $sujeto->id)
            ->whereNull('closed_at')
            ->orderBy('id')
            ->first();

        if ($abierta !== null) {
            if ((int) $abierta->stage_id !== (int) $etapa->id) {
                app(PipelineOpportunityService::class)->mover($abierta, ['stage_id' => (int) $etapa->id], null);
            }

            return $abierta->fresh();
        }

        $resultado = app(PipelineOpportunityService::class)->alta_masiva(
            $pipeline,
            [['type' => $tipo, 'id' => (int) $sujeto->id]],
            (int) $etapa->id,
            null,
            'Alta de prueba',
            null
        );

        $this->assertCount(1, $resultado['creadas'], 'El alta de la tarjeta de prueba tendría que crear una oportunidad.');

        return PipelineOpportunity::query()->findOrFail((int) $resultado['creadas'][0]);
    }

    /**
     * Todas las tarjetas (abiertas o cerradas) de un lead en el pipeline «Leads».
     *
     * @param Lead $lead
     *
     * @return \Illuminate\Support\Collection Colección de PipelineOpportunity.
     */
    protected function tarjetas_del_lead(Lead $lead)
    {
        return PipelineOpportunity::query()
            ->where('pipeline_id', $this->pipeline_de_leads()->id)
            ->where('lead_id', (int) $lead->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * La tarjeta del lead (la última). Falla el test si no tiene ninguna.
     *
     * @param Lead $lead
     *
     * @return PipelineOpportunity
     */
    protected function tarjeta_del_lead(Lead $lead): PipelineOpportunity
    {
        $tarjetas = $this->tarjetas_del_lead($lead);
        $this->assertGreaterThan(0, $tarjetas->count(), 'El lead #' . $lead->id . ' tendría que tener una tarjeta en el tablero.');

        return $tarjetas->last()->fresh();
    }

    /**
     * La clave de la columna donde está parada una tarjeta.
     *
     * @param PipelineOpportunity $tarjeta
     *
     * @return string|null
     */
    protected function clave_de_la_columna(PipelineOpportunity $tarjeta): ?string
    {
        $etapa = PipelineStage::query()->find((int) $tarjeta->fresh()->stage_id);

        return $etapa !== null ? $etapa->stage_key : null;
    }

    /**
     * La próxima acción de la tarjeta como `Y-m-d H:i:s` (hora de Argentina), o null si no tiene.
     *
     * @param PipelineOpportunity $tarjeta
     *
     * @return string|null
     */
    protected function proxima_accion_de(PipelineOpportunity $tarjeta): ?string
    {
        $fecha = $tarjeta->fresh()->next_action_at;

        return $fecha === null ? null : Carbon::parse($fecha)->setTimezone(self::$ZONA_DEL_TABLERO)->format('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------------------------------
    // Sujetos y admins
    // ------------------------------------------------------------------------------------------

    /**
     * Un lead con nombre, empresa y teléfono únicos, en el estado pedido.
     *
     * @param string               $estado    Estado del lead.
     * @param array<string, mixed> $atributos Pisan los defaults.
     *
     * @return Lead
     */
    protected function crear_lead_del_tablero(string $estado = 'contactado', array $atributos = []): Lead
    {
        $sufijo = Str::random(6);

        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Persona ' . $sufijo;
        $lead->company_name = 'Negocio ' . $sufijo;
        $lead->phone        = '549351' . random_int(1000000, 9999999);
        $lead->status       = $estado;

        foreach ($atributos as $campo => $valor) {
            $lead->{$campo} = $valor;
        }

        $lead->save();

        return $lead->fresh();
    }

    /**
     * Un cliente mínimo (para tarjetas de cliente y para pruebas en curso).
     *
     * @param array<string, mixed> $atributos Pisan los defaults.
     *
     * @return Client
     */
    protected function crear_cliente_del_tablero(array $atributos = []): Client
    {
        $sufijo = Str::random(6);

        $client               = new Client();
        $client->name         = 'Contacto ' . $sufijo;
        $client->company_name = 'Comercio ' . $sufijo . ' SRL';
        $client->slug         = 'tablero-' . strtolower($sufijo);
        $client->is_active    = true;
        $client->phone        = '+54 9 11 5555-' . random_int(1000, 9999);

        foreach ($atributos as $campo => $valor) {
            $client->{$campo} = $valor;
        }

        $client->save();

        return $client->fresh();
    }

    /**
     * Un admin. Con `$solo_leads` true es el perfil de Juan.
     *
     * @param string $nombre
     * @param bool   $solo_leads
     *
     * @return Admin
     */
    protected function crear_admin_del_tablero(string $nombre = 'Lucas', bool $solo_leads = false): Admin
    {
        return Admin::create([
            'name'       => $nombre,
            'email'      => 'tablero-' . strtolower(Str::random(10)) . '@test.local',
            'password'   => bcrypt('secret'),
            'solo_leads' => $solo_leads,
        ]);
    }

    /**
     * Deja a todos los admins de la base de testing sin ningún rol de asignación y crea a Juan (perfil «Sólo Leads»),
     * así `AdminTaskAssignmentResolver::for_embudo_task()` devuelve sólo a Juan y el responsable de las tarjetas que
     * nacen solas es él. Se revierte con la transacción del test.
     *
     * @return Admin Juan.
     */
    protected function crear_a_juan(): Admin
    {
        Admin::query()->update(['es_setter' => false, 'es_vendedor' => false, 'solo_leads' => false, 'is_closer' => false]);

        return $this->crear_admin_del_tablero('Juan', true);
    }

    /**
     * Loguea a un admin por Sanctum. `forgetGuards()` porque el guard cachea el usuario entre requests del mismo test.
     *
     * @param Admin $admin
     *
     * @return void
     */
    protected function loguear_a(Admin $admin): void
    {
        Auth::forgetGuards();
        Sanctum::actingAs($admin);
    }

    // ------------------------------------------------------------------------------------------
    // Tareas y llamadas
    // ------------------------------------------------------------------------------------------

    /**
     * Crea una tarea del admin (opcionalmente asignada y ligada a un lead).
     *
     * @param array<string, mixed> $atributos Pisan los defaults (`lead_id`, `created_via`, `is_done`, `title`…).
     * @param array<int, int>      $asignados Ids de admins asignados por el pivote.
     *
     * @return AdminTask
     */
    protected function crear_tarea(array $atributos = [], array $asignados = []): AdminTask
    {
        $tarea = AdminTask::create(array_merge([
            'created_by_admin_id' => 0,
            'assigned_admin_id'   => null,
            'lead_id'             => null,
            'title'               => 'Tarea ' . Str::random(5),
            'content'             => 'Contenido de la tarea',
            'todos'               => null,
            'is_done'             => false,
            'sort_order'          => 0,
            'created_via'         => 'admin',
        ], $atributos));

        if ($asignados !== []) {
            $tarea->assigned_admins()->sync($asignados);
        }

        return $tarea->fresh();
    }

    /**
     * Inserta una llamada en `lead_llamadas` por query builder (sin pasar por el servicio que las registra).
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $campos Pisan los defaults.
     *
     * @return int Id de la fila.
     */
    protected function insertar_llamada(Lead $lead, array $campos = []): int
    {
        $ahora = Carbon::now();

        return (int) DB::table('lead_llamadas')->insertGetId(array_merge([
            'lead_id'    => (int) $lead->id,
            'atendio'    => 1,
            'resultado'  => 'sigue_solo',
            'notas'      => 'Nota de la llamada',
            'created_at' => $ahora->format('Y-m-d H:i:s'),
            'updated_at' => $ahora->format('Y-m-d H:i:s'),
        ], $campos));
    }
}
