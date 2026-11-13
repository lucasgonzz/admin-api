<?php

namespace App\Services\Prueba;

use App\Helpers\AppTime;
use App\Helpers\WhatsappNormalizer;
use App\Jobs\ArmarPruebaJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientEcommerce;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use App\Models\TrialInstance;
use App\Models\Version;
use App\Services\BuscadorDeLeadPorTelefono;
use App\Services\DemoIndustriaCatalogo;
use App\Services\LeadAtribucionService;
use App\Services\LeadDocNumberGenerator;
use App\Services\LeadOrigenService;
use App\Services\MetaConversionesService;
use App\Services\SystemErrorWhatsappService;
use App\Services\UserIdBlockAllocatorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * El alta de una prueba gratuita desde la landing (contrato C6, decisiones D1 y D5 del plan de
 * `prueba-alta-instancia`, 3/11/2026).
 *
 * En UNA transacción, y en este orden:
 *  1. Los 409: `ya_tiene_prueba` (ese teléfono ya tiene una prueba en curso o vencida sin contratar),
 *     `ya_es_cliente` (es el teléfono de un cliente activo), `sin_capacidad` (no hay instancia libre o se
 *     superó `prueba_max_activas`).
 *  2. El `Lead`: el que ya exista con ese teléfono o uno nuevo; `status = prueba_activa`, origen
 *     `landing`, los tokens de ingreso y de eventos (los mismos de la demo), `trial_days`.
 *  3. La instancia: una `libre` del pool, bajo lock, pasa a `asignando` con el slug como alias.
 *  4. El `Client`: `estado = prueba`, `trial_days`, `trial_ends_at`, el `user_id` del bloque que la
 *     instancia reservó, dos `ClientApi` (slug y slug2, VPS, solo la primera aprovisionada) y la
 *     tienda en `client_ecommerces` (`hosting_type = vps`). `lead.promoted_client_id` apunta al cliente.
 *  5. La contraseña del dueño queda cifrada en la instancia hasta que corra el demo setup.
 *  6. Meta: `Lead` + `StartTrial` por el canal web, con el contexto del navegador (los `event_id` vuelven en
 *     el 201 para que el píxel de la landing deduplique).
 * Después del commit se despacha `ArmarPruebaJob`, que hace todo lo lento (DNS, `.env`, alias, demo setup,
 * SSL) con etapas y progreso que la landing lee por `GET landing/prueba/{uuid}/estado`.
 *
 * 🔴 El estado del lead y `promoted_client_id` se escriben por query builder, como hace la agenda de la
 * landing: así el observer de Meta no registra las conversiones SIN el contexto del navegador antes de que
 * este servicio las registre CON él.
 */
class AltaDePruebaService
{
    const CODIGO_YA_TIENE_PRUEBA = 'ya_tiene_prueba';
    const CODIGO_YA_ES_CLIENTE   = 'ya_es_cliente';
    const CODIGO_SIN_CAPACIDAD   = 'sin_capacidad';

    const MENSAJE_SIN_CAPACIDAD = 'Por ahora no tenemos lugar para una prueba más. Escribinos y te avisamos apenas haya.';

    /** @var PruebaPoolService */
    private $pool;

    public function __construct(PruebaPoolService $pool)
    {
        $this->pool = $pool;
    }

    /**
     * @param array<string, mixed>       $datos        Validados por el controlador: nombre, telefono (E.164),
     *                                                 email, empresa, password, rubro?, atribucion[].
     * @param array<string, string>|null $contexto_web IP, user agent, fbp, fbc y URL de la página.
     *
     * @return array{http: int, cuerpo: array<string, mixed>}
     */
    public function alta(array $datos, ?array $contexto_web = null): array
    {
        $this->hosts_tomados = null;
        $telefono = WhatsappNormalizer::normalize((string) $datos['telefono']);
        $lead     = BuscadorDeLeadPorTelefono::buscar($telefono);

        $rechazo = $this->motivo_de_rechazo($lead, $telefono);
        if ($rechazo !== null) {
            return $this->respuesta(409, ['code' => $rechazo['code'], 'codigo' => $rechazo['code'], 'mensaje' => $rechazo['mensaje']]);
        }

        LeadPipelineStatus::ensure_exists(LeadPipelineStatus::PRUEBA_ACTIVA, 'Prueba activa');

        try {
            $resultado = DB::transaction(function () use ($lead, $telefono, $datos, $contexto_web) {
                return $this->escribir_alta($lead, $telefono, $datos, $contexto_web);
            });
        } catch (SinCapacidadDePruebaException $e) {
            /* La excepción deshizo la transacción: no queda lead, cliente ni instancia a medias. */
            $this->avisar_sin_capacidad();

            return $this->respuesta(409, ['code' => self::CODIGO_SIN_CAPACIDAD, 'codigo' => self::CODIGO_SIN_CAPACIDAD, 'mensaje' => $e->getMessage()]);
        } catch (SlugDePruebaImposibleException $e) {
            /* También deshizo la transacción. Es un 422 sobre `empresa`, con la forma del C6. */
            return $this->respuesta(422, ['mensaje' => $e->getMessage(), 'errores' => ['empresa' => [$e->getMessage()]]]);
        }

        /** @var Lead $lead_listo */
        $lead_listo = $resultado['lead'];
        /** @var TrialInstance $instancia */
        $instancia = $resultado['instancia'];

        // El worker puede tomar el Lead inmediatamente: el fbclid debe estar persistido antes.
        $this->dejar_evento_en_el_hilo($lead_listo, $datos, $instancia);
        $meta_eventos = $this->registrar_conversiones($lead_listo, $contexto_web);

        /* A la cola (`database` en producción), NO `afterResponse()`: eso correría los ~3 minutos del armado adentro
           del proceso del request, después de contestar. La transacción ya commiteó. */
        ArmarPruebaJob::dispatch($instancia->id);

        Log::info('AltaDePruebaService: prueba creada.', [
            'lead_id'   => $lead_listo->id,
            'client_id' => $lead_listo->promoted_client_id,
            'instancia' => $instancia->nombre,
            'slug'      => $instancia->alias_slug,
        ]);

        /* El uuid PROPIO de la prueba (`trial_instances.seguimiento_uuid`), no el del lead: el del lead ya viaja en los
           links públicos de la demo (`demo/{uuid}`) y no tiene por qué abrir el seguimiento de la prueba. */
        /* `ensayo` (misión ensayos-sin-eventos-meta, 13/11/2026): con `true` la landing no dispara ningún evento del
           píxel y lo apaga para el resto de la visita; el servidor ya dejó sus filas `omitido`. `meta_eventos` se manda
           igual que siempre a propósito: una landing anterior a la marca, con la lista vacía, caería al id derivado del
           uuid y mandaría `Lead` y `StartTrial` desde el navegador. */
        try {
            $ensayo = MetaConversionesService::es_de_ensayo($lead_listo);
        } catch (\Throwable $e) {
            /* La prueba ya está creada: una lectura caída no puede convertir el 201 en un error. */
            $ensayo = false;
            Log::error('AltaDePrueba: no se pudo saber si el alta es de ensayo.', ['lead_id' => $lead_listo->id, 'error' => $e->getMessage()]);
        }

        return $this->respuesta(201, [
            'uuid'         => (string) $instancia->seguimiento_uuid,
            'meta_eventos' => $meta_eventos,
            'ensayo'       => $ensayo,
        ]);
    }

    /**
     * Los dos primeros 409, ANTES de la transacción (no escriben nada).
     *
     * @return array{code: string, mensaje: string}|null
     */
    private function motivo_de_rechazo(?Lead $lead, string $telefono): ?array
    {
        if ($lead !== null) {
            $client = $lead->promoted_client_id ? Client::find($lead->promoted_client_id) : null;

            if ((string) $lead->status === LeadPipelineStatus::PRUEBA_ACTIVA || PruebaCicloService::esta_en_prueba($client)) {
                return ['code' => self::CODIGO_YA_TIENE_PRUEBA, 'mensaje' => 'Ese WhatsApp ya tiene una prueba. Escribinos y la retomamos.'];
            }

            if ($client !== null && (string) $client->estado === PruebaCicloService::CLIENTE_ACTIVO && (bool) $client->is_active) {
                return ['code' => self::CODIGO_YA_ES_CLIENTE, 'mensaje' => 'Ese WhatsApp ya es de un cliente de ComercioCity. Escribinos por soporte.'];
            }
        }

        $cliente_por_telefono = $this->cliente_con_ese_telefono($telefono);
        if ($cliente_por_telefono !== null) {
            if (PruebaCicloService::esta_en_prueba($cliente_por_telefono)) {
                return ['code' => self::CODIGO_YA_TIENE_PRUEBA, 'mensaje' => 'Ese WhatsApp ya tiene una prueba. Escribinos y la retomamos.'];
            }
            if ((string) $cliente_por_telefono->estado !== PruebaCicloService::CLIENTE_PRUEBA_BORRADA) {
                return ['code' => self::CODIGO_YA_ES_CLIENTE, 'mensaje' => 'Ese WhatsApp ya es de un cliente de ComercioCity. Escribinos por soporte.'];
            }
        }

        return null;
    }

    /** El cliente activo o en prueba cuyo `phone` es este número (comparación normalizada). */
    private function cliente_con_ese_telefono(string $telefono): ?Client
    {
        $clientes = Client::whereNotNull('phone')->where('phone', '!=', '')->orderByDesc('id')->get();

        foreach ($clientes as $client) {
            if (WhatsappNormalizer::phones_match((string) $client->phone, $telefono)) {
                return $client;
            }
        }

        return null;
    }

    /**
     * El cuerpo de la transacción: el lead, la instancia y el cliente. Sin capacidad tira
     * `SinCapacidadDePruebaException`, que deshace todo lo escrito hasta ahí.
     *
     * @return array<string, mixed>
     *
     * @throws SinCapacidadDePruebaException
     */
    private function escribir_alta(?Lead $lead, string $telefono, array $datos, ?array $contexto_web): array
    {
        if (! PruebaPoolService::hay_capacidad() || ! TrialInstance::where('estado', TrialInstance::ESTADO_LIBRE)->exists()) {
            throw new SinCapacidadDePruebaException(self::MENSAJE_SIN_CAPACIDAD);
        }

        $ahora  = AppTime::now();
        $dias   = PruebaSettings::dias_default();
        $vence  = $ahora->copy()->addDays($dias);
        $rubro  = isset($datos['rubro']) && $datos['rubro'] !== null && $datos['rubro'] !== '' ? DemoIndustriaCatalogo::normalizar($datos['rubro']) : null;
        $nombre = trim((string) $datos['nombre']);
        $email  = trim((string) $datos['email']);
        $empresa = trim((string) $datos['empresa']);

        $lead_nuevo = $lead === null;
        if ($lead_nuevo) {
            $lead = Lead::create([
                'phone'               => $telefono,
                'contact_name'        => $nombre,
                'email'               => $email,
                'company_name'        => $empresa,
                'business_type'       => $rubro !== null ? DemoIndustriaCatalogo::OPCIONES[$rubro] : null,
                'demo_industria'      => $rubro,
                'origen'              => LeadOrigenService::ORIGEN_LANDING,
                'origen_calculado_at' => $ahora,
                'status'              => 'nuevo',
            ]);
        }

        try {
            LeadDocNumberGenerator::assign_to_lead_if_empty($lead);
        } catch (\Throwable $e) {
            Log::error('AltaDePrueba: no se pudo asignar el documento al lead.', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
        }

        $slug      = $this->proponer_slug($empresa !== '' ? $empresa : (string) $lead->company_name, $nombre);
        $instancia = $this->pool->tomar_libre((int) $lead->id, $slug);

        if ($instancia === null) {
            throw new SinCapacidadDePruebaException(self::MENSAJE_SIN_CAPACIDAD);
        }

        $client = $this->crear_cliente($lead, $instancia, $slug, $empresa !== '' ? $empresa : (string) $lead->company_name, $nombre, $email, $telefono, $dias, $vence);

        /* Lo que la prueba le escribe al lead. Por query builder (ver el docblock de la clase). Un lead que ya
           existía conserva su nombre, correo y empresa si los tenía; lo demás es de ESTA prueba. */
        $campos = [
            'status'                         => LeadPipelineStatus::PRUEBA_ACTIVA,
            'promoted_client_id'             => $client->id,
            'trial_days'                     => $dias,
            'demo_industria'                 => $rubro,
            'demo_ingreso_token'             => Str::random(64),
            'demo_ingreso_token_expira_at'   => $vence,
            'demo_ingreso_token_revocado_at' => null,
            'demo_eventos_token'             => trim((string) $lead->demo_eventos_token) !== '' ? $lead->demo_eventos_token : Str::random(64),
            'demo_setup_status'              => 'pendiente',
            'demo_setup_last_error'          => null,
            'updated_at'                     => $ahora,
        ];
        if (! $lead_nuevo) {
            if (trim((string) $lead->contact_name) === '') {
                $campos['contact_name'] = $nombre;
            }
            if (trim((string) $lead->email) === '') {
                $campos['email'] = $email;
            }
            if (trim((string) $lead->company_name) === '') {
                $campos['company_name'] = $empresa;
            }
            if (trim((string) $lead->business_type) === '' && $rubro !== null) {
                $campos['business_type'] = DemoIndustriaCatalogo::OPCIONES[$rubro];
            }
            if ($lead->origen === null || (string) $lead->origen === '') {
                $campos['origen']              = LeadOrigenService::ORIGEN_LANDING;
                $campos['origen_calculado_at'] = $ahora;
            }
        }
        Lead::query()->whereKey($lead->id)->update($campos);

        $allocator = app(UserIdBlockAllocatorService::class);
        $allocator->reserve_block_for_lead($lead, (int) $instancia->user_id, 'prueba', 'Prueba gratuita en ' . $instancia->nombre);
        $allocator->attach_client_to_lead_block($lead->id, $client->id);

        $instancia->client_id        = $client->id;
        $instancia->password_cifrada = Crypt::encryptString((string) $datos['password']);
        $instancia->contexto_meta    = MetaConversionesService::contexto_web_limpio($contexto_web);
        $instancia->etapa            = 'encolada';
        $instancia->progreso         = 5;
        $instancia->save();

        return ['lead' => $lead->fresh(), 'instancia' => $instancia];
    }

    /**
     * El `Client` en prueba, con las mismas claves que `RunUserSetupService::ensure_production_client()`
     * salvo el `user_id` (es el bloque que la instancia ya reservó) y el estado.
     */
    private function crear_cliente(Lead $lead, TrialInstance $instancia, string $slug, string $empresa, string $nombre, string $email, string $telefono, int $dias, Carbon $vence): Client
    {
        $latest_version = Version::where('status', 'published')->orderByDesc('id')->first();
        $hosts          = PruebaNombres::hosts_del_slug($slug);

        $client = Client::create([
            'name'               => $nombre !== '' ? $nombre : 'Cliente',
            'company_name'       => $empresa !== '' ? $empresa : null,
            'user_id'            => (int) $instancia->user_id,
            'slug'               => $slug, // el mismo que el alias, siempre: proponer_slug() ya lo cotejó con clients.slug
            'api_url'            => 'https://' . $hosts['erp_api'],
            'api_key'            => Str::random(40),
            'inbound_api_key'    => Str::random(40),
            'is_active'          => true,
            'estado'             => PruebaCicloService::CLIENTE_PRUEBA,
            'trial_days'         => $dias,
            'trial_ends_at'      => $vence,
            'phone'              => $telefono,
            'email'              => $email !== '' ? $email : null,
            'tiene_ecommerce'    => true,
            'current_version_id' => $latest_version ? $latest_version->id : null,
        ]);

        /* Un alta con un correo de la lista `prueba_ensayo_correos` nace marcada como ensayo, ANTES de que se arme la
           instancia y salga la bienvenida: así ya recibe la bienvenida con las reglas del alcance nuevo. */
        if (PruebaSettings::es_correo_de_ensayo($email)) {
            $client->es_ensayo = true;
            $client->save();
        }

        /* ClientApi 1: el frente de la prueba, aprovisionado (VPS, sitio `prueba-NN`). */
        $principal = ClientApi::create([
            'client_id'              => $client->id,
            'url'                    => 'https://' . $hosts['erp_api'],
            'path'                   => $slug . '/api',
            'spa_url'                => 'https://' . $hosts['erp_spa'],
            'hosting_type'           => 'vps',
            'vps_path'               => $instancia->erp_vps_path,
            'hosting_provisioned_at' => now(),
        ]);

        /* ClientApi 2: la paralela, como en toda promoción. No existe en el VPS (nadie la aprovisiona): es el
           lugar que el admin espera para un segundo frente. `vps_path` con el sufijo 2 para que la regla del
           frente principal (prefijo estricto) elija a la primera. */
        ClientApi::create([
            'client_id'    => $client->id,
            'url'          => 'https://api-' . $slug . '2.' . PruebaNombres::dominio(),
            'path'         => $slug . '2/api',
            'spa_url'      => 'https://' . $slug . '2.' . PruebaNombres::dominio(),
            'hosting_type' => 'vps',
            'vps_path'     => $instancia->erp_vps_path . '2',
        ]);

        $client->update(['active_client_api_id' => $principal->id]);

        ClientEcommerce::create([
            'client_id'            => $client->id,
            'domain'               => $hosts['tienda_spa'],
            'api_url'              => 'https://' . $hosts['tienda_api'],
            'spa_url'              => 'https://' . $hosts['tienda_spa'],
            'status'               => 'active',
            'ecommerce_setup_data' => ['hosting_type' => 'vps', 'prueba' => true, 'instancia' => $instancia->nombre],
        ]);

        return $client;
    }

    /**
     * El slug de la prueba desde el nombre de la empresa (o del contacto): `Str::slug` acotado a la
     * expresión de `PruebaNombres`, sin prefijos de host, y libre entre todo lo que ya ocupa un host.
     * Sin IA: el alta es público y sincrónico, y una sugerencia de Haiku tarda segundos y sale a la red.
     *
     * @throws SlugDePruebaImposibleException Si del nombre no queda nada con qué armar un slug.
     */
    public function proponer_slug(string $empresa, string $contacto = ''): string
    {
        $base = Str::slug($empresa !== '' ? $empresa : $contacto);
        $base = (string) preg_replace('/[^a-z0-9-]/', '', $base);
        /* B1 (chequeo independiente, 3/11/2026): «Tienda Lola» → `lola`, «API Ferretotal» → `ferretotal`. Un slug con
           prefijo de host sería la tienda o la API de OTRA prueba o de un cliente. Si no queda nada («Tienda API»), 422. */
        $base = PruebaNombres::sin_prefijos_de_host($base);
        $base = trim(substr($base, 0, 20), '-');
        /* «Tienda API» → `api`, «Tienda» → `tienda`: la palabra pelada tampoco sirve (`api-2` volvería a ser un prefijo). */
        if ($base === '' || preg_match(PruebaNombres::REGEX_SLUG, $base) !== 1 || PruebaNombres::es_palabra_de_prefijo($base)) {
            throw new SlugDePruebaImposibleException($empresa !== '' ? $empresa : $contacto);
        }

        $candidato = $base;
        for ($i = 2; $i <= 99; $i++) {
            if (! $this->slug_tomado($candidato)) {
                return $candidato;
            }
            $sufijo    = '-' . $i;
            $candidato = substr($base, 0, 20 - strlen($sufijo)) . $sufijo;
        }

        throw new \RuntimeException('No se pudo armar un slug libre para "' . $empresa . '".');
    }

    /**
     * ¿Alguien ya usa alguno de los CUATRO hosts del candidato (`<slug>`, `api-<slug>`, `tienda-<slug>`,
     * `api-tienda-<slug>`)? Se cotejan contra los cuatro de cada instancia viva (nombre y alias), los cuatro de
     * cada `clients.slug`, y los hosts de `client_apis`, `client_ecommerces` y `demos`. Un slug reservado, con
     * prefijo de host o con nombre de pool cuenta como tomado. Una sola regla: el slug del cliente ES el alias.
     */
    private function slug_tomado(string $slug): bool
    {
        if (! PruebaNombres::es_slug_valido($slug)) {
            return true;
        }

        $tomados = $this->hosts_tomados();
        foreach (PruebaNombres::hosts($slug) as $host) {
            if (isset($tomados[$host])) {
                return true;
            }
        }

        return false;
    }

    /** @var array<string, true>|null Memo de hosts_tomados() durante UN alta (el candidato prueba hasta 99 sufijos). */
    private $hosts_tomados = null;

    /**
     * Todos los hosts ocupados hoy, como claves del array.
     *
     * @return array<string, true>
     */
    private function hosts_tomados(): array
    {
        if ($this->hosts_tomados !== null) {
            return $this->hosts_tomados;
        }

        $hosts = [];
        $sumar_base = function ($base) use (&$hosts) {
            $base = strtolower(trim((string) $base));
            if ($base === '') {
                return;
            }
            foreach (PruebaNombres::hosts($base) as $host) {
                $hosts[$host] = true;
            }
        };
        $sumar_url = function ($url) use (&$hosts) {
            $host = self::host_de((string) $url);
            if ($host !== '') {
                $hosts[$host] = true;
            }
        };

        foreach (TrialInstance::where('estado', '!=', TrialInstance::ESTADO_BORRADA)->get(['nombre', 'alias_slug']) as $instancia) {
            $sumar_base($instancia->nombre);
            $sumar_base($instancia->alias_slug);
        }
        foreach (Client::query()->whereNotNull('slug')->pluck('slug') as $slug_cliente) {
            $sumar_base($slug_cliente);
        }
        foreach (ClientApi::query()->get(['url', 'spa_url']) as $api) {
            $sumar_url($api->url);
            $sumar_url($api->spa_url);
        }
        foreach (DB::table('client_ecommerces')->get(['domain', 'api_url', 'spa_url']) as $tienda) {
            $sumar_url($tienda->domain);
            $sumar_url($tienda->api_url);
            $sumar_url($tienda->spa_url);
        }
        foreach (DB::table('demos')->get(['erp_spa_url', 'erp_api_url', 'ecommerce_spa_url', 'ecommerce_api_url']) as $demo) {
            $sumar_url($demo->erp_spa_url);
            $sumar_url($demo->erp_api_url);
            $sumar_url($demo->ecommerce_spa_url);
            $sumar_url($demo->ecommerce_api_url);
        }

        return $this->hosts_tomados = $hosts;
    }

    /** El host de una URL o de un dominio pelado: minúsculas, sin esquema, puerto ni ruta. */
    private static function host_de(string $valor): string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return '';
        }
        $host = parse_url(strpos($valor, '//') === false ? 'https://' . $valor : $valor, PHP_URL_HOST);

        return strtolower(trim((string) $host, '. '));
    }

    /**
     * `Lead` por el canal web, con el contexto del navegador. StartTrial espera el primer ingreso.
     * Devuelve `[{nombre, event_id}]` para que la landing dispare el píxel con los mismos ids.
     *
     * @return array<int, array<string, string>>
     */
    private function registrar_conversiones(Lead $lead, ?array $contexto_web): array
    {
        $salida = [];

        try {
            $filas = app(MetaConversionesService::class)->registrar_alcanzados($lead, null, true, $contexto_web);
            // No reemitir un Lead histórico: la deduplicación del navegador tiene una ventana limitada.
            foreach ($filas as $fila) {
                if ($fila instanceof LeadEventoMeta && $fila->canal === LeadEventoMeta::CANAL_WEB
                    && $fila->evento === LeadEventoMeta::EVENTO_WEB_LEAD) {
                    $salida[] = ['nombre' => (string) $fila->evento, 'event_id' => (string) $fila->event_id];
                }
            }
        } catch (\Throwable $e) {
            Log::error('AltaDePrueba: no se pudieron registrar las conversiones de Meta.', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
        }

        return $salida;
    }

    /** El evento visible en el hilo del lead y la atribución del contacto (como la reserva de la agenda). */
    private function dejar_evento_en_el_hilo(Lead $lead, array $datos, TrialInstance $instancia): void
    {
        try {
            $evento = LeadMessage::create([
                'lead_id'               => $lead->id,
                'sender'                => 'sistema',
                'content'               => 'Empezó su prueba gratis desde la web: ' . (string) $instancia->alias_slug . '.' . PruebaNombres::dominio()
                    . ' (instancia ' . $instancia->nombre . ', ' . (int) $lead->trial_days . ' días).',
                'status'                => 'enviado',
                'is_followup'           => false,
                'is_status_event'       => true,
                'requiere_verificacion' => false,
                'sent_at'               => AppTime::now(),
                'admin_notifications'   => [['evento' => 'Prueba gratis iniciada desde la web', 'admins' => []]],
            ]);

            $atribucion = isset($datos['atribucion']) && is_array($datos['atribucion']) ? $datos['atribucion'] : [];
            app(LeadAtribucionService::class)->registrar_contacto_de_reserva($evento, $atribucion);
        } catch (\Throwable $e) {
            Log::error('AltaDePrueba: no se pudo dejar el evento en el hilo del lead.', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
        }
    }

    /** Lucas se entera de que el pool se quedó sin lugar (WhatsApp a los admins, con el freno de 10 minutos). */
    private function avisar_sin_capacidad(): void
    {
        Log::channel('daily')->error('AltaDePrueba: alta rechazada por falta de capacidad del pool de pruebas.', [
            'libres'  => PruebaPoolService::libres_o_creando(),
            'activas' => PruebaPoolService::activas(),
            'tope'    => PruebaSettings::max_activas(),
        ]);

        try {
            app(SystemErrorWhatsappService::class)->notify_send_error(
                'Prueba gratuita sin capacidad',
                'Un negocio pidió su prueba y no había instancia libre (activas: ' . PruebaPoolService::activas() . ', tope: ' . PruebaSettings::max_activas() . '). Corré pruebas:mantener-pool o subí el tope.'
            );
        } catch (\Throwable $e) {
            // El aviso es accesorio.
        }
    }

    /**
     * @return array{http: int, cuerpo: array<string, mixed>}
     */
    private function respuesta(int $http, array $cuerpo): array
    {
        return ['http' => $http, 'cuerpo' => $cuerpo];
    }
}
