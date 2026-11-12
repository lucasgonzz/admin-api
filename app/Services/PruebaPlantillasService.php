<?php

namespace App\Services;

use App\Models\FollowupTemplate;
use Illuminate\Support\Facades\Log;

/**
 * Las plantillas de WhatsApp de la prueba gratuita (misión cruzada prueba-martin-importacion, 3/11/2026):
 * la bienvenida de Martín (`cc_prueba_bienvenida`) y un empujón por cada hito del programa
 * (`cc_prueba_paso_<hito>`). Acá viven sus textos, y acá se crean en Meta y se registran en el admin.
 *
 * 🔴 SE REGISTRAN EN `followup_templates`, CON `estado = 'manual_prueba'`. Es la tabla que tiene
 * `meta_status`, `components` y `meta_template_id`, y la que barre `templates:sync-meta` cada hora para
 * activar sola la que Meta aprobó. El prefijo `manual_` es lo que impide que `LeadFollowupService` las
 * dispare solo (busca `where('estado', $lead->status)`); un `estado` que coincidiera con `prueba_activa`
 * haría que el cron de cada dos horas se las mandara a todos los leads en prueba.
 *
 * 🔴 SE CREAN POR LA API DE KAPSO con `MetaTemplateService::crear()` (la clave sale de `whatsapp_config`
 * y no se imprime ni se loguea), nacen con `activa = false` y `meta_status = PENDING`, y recién cuando
 * pasan a `APPROVED` se pueden mandar ({@see FollowupTemplate::estado_para_enviar()}). Mientras tanto
 * los envíos quedan `en_cola` (PruebaWhatsappService).
 *
 * UTILITY, `es_AR`, variables posicionales. La primera es el nombre y las que presentan al asistente
 * agregan su número como segunda variable.
 */
class PruebaPlantillasService
{
    /** La bienvenida de Martín al primer ingreso. */
    const BIENVENIDA = 'cc_prueba_bienvenida';

    const BIENVENIDA_CON_ASISTENTE = 'cc_prueba_bienvenida_asistente';

    const ASISTENTE_LISTO = 'cc_prueba_asistente_listo';

    /**
     * La bienvenida que además explica los agentes (misión prueba-e2e-campana-imperio, 11/11/2026). Son plantillas
     * NUEVAS en Meta (las aprobadas no se editan): mientras no estén `APPROVED` sale la anterior.
     */
    const BIENVENIDA_V2 = 'cc_prueba_bienvenida_v2';

    const BIENVENIDA_CON_ASISTENTE_V2 = 'cc_prueba_bienvenida_asistente_v2';

    /**
     * Variante CORTA de la bienvenida con asistente (12/11/2026), por si Meta no aprueba la v2 (larga, con tres
     * variables). Mismas variables: `{{2}}` la dirección del sistema y `{{3}}` el número del asistente.
     */
    const BIENVENIDA_CON_ASISTENTE_V3 = 'cc_prueba_bienvenida_asistente_v3';

    const BIENVENIDAS = [self::BIENVENIDA, self::BIENVENIDA_CON_ASISTENTE, self::BIENVENIDA_V2, self::BIENVENIDA_CON_ASISTENTE_V2, self::BIENVENIDA_CON_ASISTENTE_V3];

    /** Las bienvenidas que presentan el número del asistente personal. */
    const BIENVENIDAS_CON_ASISTENTE = [self::BIENVENIDA_CON_ASISTENTE, self::BIENVENIDA_CON_ASISTENTE_V2, self::BIENVENIDA_CON_ASISTENTE_V3];

    /** El pedido de la encuesta al que NO contrató, cuando la prueba terminó. `{{2}}` = el enlace. */
    const FIN_ENCUESTA = 'cc_prueba_fin_encuesta';

    /** El pedido de la encuesta al que contrató («terminó tu prueba» no vale para él). `{{2}}` = el enlace. */
    const ENCUESTA_CONTRATO = 'cc_prueba_encuesta_contrato';

    /** Las dos plantillas de la encuesta. */
    const ENCUESTAS = [self::FIN_ENCUESTA, self::ENCUESTA_CONTRATO];

    /** Sufijo de la variante corregida de un paso (`cc_prueba_paso_<hito>_v2`), que reemplaza a la aprobada cuando Meta la aprueba. */
    const SUFIJO_V2 = '_v2';

    /** El aviso al negocio de que la prueba está por terminar (día 5 en adelante). `{{2}}` = «2 días», «1 día»… */
    const DIA_5 = 'cc_prueba_dia_5';

    /** Prefijo de los empujones: `cc_prueba_paso_<hito>` (los hitos son los de PruebaDelClienteService::HITOS). */
    const PASO_PREFIJO = 'cc_prueba_paso_';

    /** `followup_templates.estado` de todas las plantillas de la prueba. Ver el 🔴 del docblock. */
    const ESTADO = 'manual_prueba';

    const CATEGORIA = 'UTILITY';

    const IDIOMA = 'es_AR';

    /**
     * Los textos. La clave es el nombre de la plantilla; `uso` es el texto corto que dice cuándo se manda.
     * `{{1}}` = primer nombre del contacto (ejemplo «Marcela»). `{{2}}`, cuando la hay, es el número del
     * asistente o lo que diga `ejemplo_2` (el ejemplo que se le manda a Meta para aprobarla).
     *
     * @var array<string, array{body: string, uso: string, ejemplo_2?: string}>
     */
    const PLANTILLAS = [
        self::BIENVENIDA => [
            'body' => 'Hola {{1}}, soy Martín, del equipo de ComercioCity. Ya tenés tu sistema armado con datos de ejemplo para que lo pruebes tranquilo. Si querés, te cargamos tu información sin costo: mandame por acá el Excel de tus artículos o el catálogo de tu proveedor en PDF y lo dejamos listo en tu sistema. Cualquier duda, escribime por acá.',
            'uso'  => 'Prueba gratuita: bienvenida de Martín cuando el sistema del negocio queda listo (una sola vez).',
        ],
        self::BIENVENIDA_CON_ASISTENTE => [
            'body' => 'Hola {{1}}, soy Martín, del equipo de ComercioCity. Tu prueba de siete días ya está lista con datos de ejemplo. Si querés que carguemos tu catálogo sin costo, mandame por acá tu Excel o el PDF del proveedor. Tu asistente personal te atiende por WhatsApp en el {{2}}. ' . \App\Services\Prueba\PruebaAsistenteService::USO,
            'uso'  => 'Prueba gratuita: bienvenida de Martín con el WhatsApp del asistente personal.',
        ],
        self::ASISTENTE_LISTO => [
            'body' => 'Hola {{1}}, soy Martín, de ComercioCity. Ya podés usar tu asistente personal de la prueba por WhatsApp en el {{2}}. ' . \App\Services\Prueba\PruebaAsistenteService::USO,
            'uso'  => 'Prueba gratuita: informa el WhatsApp del asistente personal después de la bienvenida.',
        ],
        'cc_prueba_paso_ingreso' => [
            'body' => 'Hola {{1}}, te escribo de ComercioCity. Tu sistema de prueba ya está listo y todavía no entraste. Entrá cuando puedas con el acceso que te mandamos: en unos minutos ves cómo trabaja. Si no lo encontrás, escribime por acá y lo vemos.',
            'uso'  => 'Prueba gratuita: el negocio no entró al sistema.',
        ],
        'cc_prueba_paso_importo' => [
            'body' => 'Hola {{1}}, para que la prueba sea con lo tuyo te cargamos tus artículos sin costo. Mandame por acá el Excel de tu lista o el catálogo de tu proveedor en PDF y lo dejamos listo en tu sistema.',
            'uso'  => 'Prueba gratuita: el negocio no importó su información.',
        ],
        'cc_prueba_paso_articulo_creado' => [
            'body' => 'Hola {{1}}, un paso corto para probar el sistema: cargá un artículo tuyo desde Artículos con el botón Nuevo. Lleva un minuto y después lo vendés desde Vender. Si querés te paso un video de cómo se hace.',
            'uso'  => 'Prueba gratuita: el negocio no creó ningún artículo.',
        ],
        'cc_prueba_paso_articulo_actualizado' => [
            'body' => 'Hola {{1}}, probá cambiarle el precio a un artículo: entrá a Listado, abrí un artículo, cambiale el costo o el margen y guardá. Así ves cómo se actualiza el precio de venta al instante. Si querés te paso un video de cómo se hace.',
            'uso'  => 'Prueba gratuita: el negocio no actualizó ningún artículo.',
        ],
        'cc_prueba_paso_venta' => [
            'body' => 'Hola {{1}}, probá hacer una venta desde Vender: buscás el artículo, elegís cómo te pagan y guardás. Así ves cómo queda el ticket y cómo baja el stock. Si algo no te cierra, escribime por acá.',
            'uso'  => 'Prueba gratuita: el negocio no hizo ninguna venta.',
        ],
        'cc_prueba_paso_cliente_creado' => [
            'body' => 'Hola {{1}}, cargá un cliente tuyo desde Clientes para probar la cuenta corriente: después le vendés en cuenta corriente y ves el saldo al instante. Te paso un video si lo necesitás.',
            'uso'  => 'Prueba gratuita: el negocio no cargó ningún cliente.',
        ],
        'cc_prueba_paso_venta_cc' => [
            'body' => 'Hola {{1}}, probá hacer una venta en cuenta corriente: en Vender elegís un cliente, sumás los artículos y guardás como cuenta corriente. Después ves el saldo en la ficha del cliente, en Clientes. Si algo no te cierra, escribime por acá.',
            'uso'  => 'Prueba gratuita: el negocio no hizo ninguna venta en cuenta corriente.',
        ],
        'cc_prueba_paso_pago_cc' => [
            'body' => 'Hola {{1}}, para cerrar el circuito de la cuenta corriente registrá un pago desde la ficha del cliente. Así ves cómo baja el saldo y queda el comprobante. Avisame si querés que te lo muestre.',
            'uso'  => 'Prueba gratuita: el negocio no registró un pago de cuenta corriente.',
        ],
        'cc_prueba_paso_compra' => [
            'body' => 'Hola {{1}}, probá cargar una compra a un proveedor desde Compras: el stock sube solo y queda el costo actualizado. Si querés te paso un video de cómo se hace.',
            'uso'  => 'Prueba gratuita: el negocio no cargó ninguna compra.',
        ],
        'cc_prueba_paso_gasto' => [
            'body' => 'Hola {{1}}, cargá un gasto desde Gastos para ver cómo queda la caja del día. Es un minuto y te muestra el resultado real del negocio.',
            'uso'  => 'Prueba gratuita: el negocio no cargó ningún gasto.',
        ],
        'cc_prueba_paso_tienda_pedido' => [
            'body' => 'Hola {{1}}, tu prueba incluye una tienda online conectada a tu sistema. Entrá desde el sistema, hacé un pedido de prueba y mirá cómo te llega. Si no la encontrás, escribime por acá y te guío.',
            'uso'  => 'Prueba gratuita: el negocio no recibió ningún pedido de la tienda.',
        ],
        'cc_prueba_paso_agente' => [
            'body' => 'Hola {{1}}, probá tu agente personal desde el sistema: preguntale cuánto vendiste hoy o pedile que cargue un gasto. Si no lo encontrás, escribime por acá y te guío.',
            'uso'  => 'Prueba gratuita: el negocio no habló con su agente personal.',
        ],
        self::DIA_5 => [
            'body' => 'Hola {{1}}, soy Martín, de ComercioCity. Te aviso que tu prueba gratuita termina en {{2}}. Si te quedó algo por probar o querés que te muestre cómo se hace, escribime por acá y lo vemos juntos. Y si ya sabés que querés seguir con el sistema, avisame y te cuento cómo.',
            'uso'  => 'Prueba gratuita: aviso al negocio de que la prueba está por terminar (desde el día 5, una sola vez). {{2}} es lo que falta («2 días»).',
            'ejemplo_2' => '2 días',
        ],
        self::FIN_ENCUESTA => [
            'body' => 'Hola {{1}}, soy Martín, de ComercioCity. Terminó tu prueba gratuita y quiero saber cómo te fue. ¿Me contás en esta encuesta? Te lleva un minuto: {{2}} Si querés seguir usando el sistema, escribime por acá.',
            'uso'  => 'Prueba gratuita: pedido de la encuesta cuando la prueba venció sin contratar. {{2}} es el enlace.',
            'ejemplo_2' => 'https://api-admin.comerciocity.com/public/prueba/encuesta/AbCdEfGhIjKlMnOpQrStUvWxYz0123456789AbCdEfGhIjKl',
        ],
        self::ENCUESTA_CONTRATO => [
            'body' => 'Hola {{1}}, soy Martín, de ComercioCity. Gracias por sumarte: tu sistema sigue tal cual lo dejaste. Quiero saber cómo te fue en la prueba. ¿Me contás en esta encuesta? Te lleva un minuto: {{2}} Tu opinión nos ayuda mucho a mejorar.',
            'uso'  => 'Prueba gratuita: pedido de la encuesta al negocio que contrató. {{2}} es el enlace.',
            'ejemplo_2' => 'https://api-admin.comerciocity.com/public/prueba/encuesta/AbCdEfGhIjKlMnOpQrStUvWxYz0123456789AbCdEfGhIjKl',
        ],
    ];

    /** Cómo se entra al sistema, en las bienvenidas v2. `{{2}}` = la dirección del sistema. */
    const COMO_ENTRAR = 'Entrás desde {{2}} con tu correo y la contraseña que elegiste.';

    /**
     * Las bienvenidas v2. Van aparte de {@see self::PLANTILLAS} porque no reemplazan a nadie hasta que Meta las
     * aprueba ({@see self::bienvenida_vigente()}); se crean con el mismo comando (`prueba:crear-plantillas`).
     *
     * El texto sirve para los dos canales: lo lee el negocio en WhatsApp y también en Soporte de su sistema.
     *
     * @var array<string, array{body: string, uso: string}>
     */
    const PLANTILLAS_V2 = [
        /* `{{2}}` = la dirección del sistema del negocio (sin token de ingreso). */
        self::BIENVENIDA_V2 => [
            'body' => 'Hola {{1}}, soy Martín, del equipo de ComercioCity. Ya tenés tu sistema armado con datos de ejemplo para que lo pruebes tranquilo. ' . self::COMO_ENTRAR . ' Adentro, en Agentes, tenés dos ayudas: Soporte, que soy yo (te contesto y te paso los videos; pedime un recorrido guiado y te lo muestro en tu pantalla), y tu Asistente personal, al que le preguntás por tu negocio, por ejemplo cuánto vendiste hoy. Si querés, te cargamos tu información sin costo: mandame el Excel de tus artículos o el catálogo de tu proveedor en PDF, por WhatsApp o desde Soporte, en tu sistema, y lo dejamos listo.',
            'uso'  => 'Prueba gratuita: bienvenida de Martín que dice cómo entrar y explica los agentes del sistema (Soporte y Asistente personal). Reemplaza a cc_prueba_bienvenida cuando Meta la aprueba. {{2}} es la dirección del sistema.',
            'ejemplo_2' => 'https://ferreteria-lopez.comerciocity.com',
        ],
        /* `{{2}}` = la dirección del sistema; `{{3}}` = el WhatsApp del asistente personal. */
        self::BIENVENIDA_CON_ASISTENTE_V2 => [
            'body' => 'Hola {{1}}, soy Martín, del equipo de ComercioCity. Tu prueba de siete días ya está lista con datos de ejemplo. ' . self::COMO_ENTRAR . ' Adentro, en Agentes, tenés dos ayudas: Soporte, que soy yo (te contesto y te paso los videos; pedime un recorrido guiado y te lo muestro en tu pantalla), y tu Asistente personal, al que le preguntás por tu negocio, por ejemplo cuánto vendiste hoy. Si querés que carguemos tu catálogo sin costo, mandame tu Excel o el PDF del proveedor, por WhatsApp o desde Soporte, en tu sistema. Tu Asistente personal también te atiende por WhatsApp en el {{3}}. ' . \App\Services\Prueba\PruebaAsistenteService::USO,
            'uso'  => 'Prueba gratuita: bienvenida de Martín que dice cómo entrar, explica los agentes y da el WhatsApp del asistente personal. Reemplaza a cc_prueba_bienvenida_asistente cuando Meta la aprueba. {{2}} es la dirección del sistema y {{3}} el número del asistente.',
            'ejemplo_2' => 'https://ferreteria-lopez.comerciocity.com',
            'ejemplo_3' => '+54 9 11 1234-5678',
        ],
        /* Variante corta de la anterior, por si Meta no la aprueba. `{{2}}` = la dirección; `{{3}}` = el número del asistente. */
        self::BIENVENIDA_CON_ASISTENTE_V3 => [
            'body' => 'Hola {{1}}, soy Martín, del equipo de ComercioCity. Tu prueba ya está lista con datos de ejemplo. ' . self::COMO_ENTRAR . ' Adentro, en Agentes, tenés a Soporte, que soy yo (pedime un recorrido guiado y te lo muestro en tu pantalla), y a tu Asistente personal, que también te atiende por WhatsApp en el {{3}}. Si querés que carguemos tu catálogo sin costo, mandame tu Excel o el PDF del proveedor.',
            'uso'  => 'Prueba gratuita: bienvenida corta de Martín con cómo entrar, los agentes y el WhatsApp del asistente personal. Alternativa a cc_prueba_bienvenida_asistente_v2. {{2}} es la dirección del sistema y {{3}} el número del asistente.',
            'ejemplo_2' => 'https://ferreteria-lopez.comerciocity.com',
            'ejemplo_3' => '+54 9 11 1234-5678',
        ],
        /* La plantilla aprobada dice «desde Artículos con el botón Nuevo», y esa pantalla se llama Listado. */
        'cc_prueba_paso_articulo_creado_v2' => [
            'body' => 'Hola {{1}}, un paso corto para probar el sistema: cargá un artículo tuyo desde Listado, con el botón Crear. Lleva un minuto y después lo vendés desde Vender. Si querés te paso un video de cómo se hace.',
            'uso'  => 'Prueba gratuita: el negocio no creó ningún artículo (texto corregido: Listado, botón Crear). Reemplaza a cc_prueba_paso_articulo_creado cuando Meta la aprueba.',
        ],
    ];

    /** El ejemplo de `{{1}}` que se le manda a Meta para aprobar. */
    const EJEMPLO_NOMBRE = 'Marcela';

    /**
     * Nombre de la plantilla del empujón de un hito.
     *
     * @param string $hito
     *
     * @return string
     */
    public static function plantilla_del_paso(string $hito): string
    {
        return self::PASO_PREFIJO . $hito;
    }

    /**
     * La plantilla del empujón de un paso que corresponde mandar HOY a este cliente: la variante corregida
     * (`cc_prueba_paso_<hito>_v2`) si existe, el cliente está dentro del alcance de las novedades y Meta ya la
     * aprobó; si no, la de siempre.
     *
     * @param string                  $hito
     * @param \App\Models\Client|null $client
     *
     * @return string
     */
    public function plantilla_del_paso_vigente(string $hito, ?\App\Models\Client $client = null): string
    {
        $v1 = self::plantilla_del_paso($hito);
        $v2 = $v1 . self::SUFIJO_V2;

        if (! isset(self::PLANTILLAS_V2[$v2]) || ($client !== null && ! \App\Services\Prueba\PruebaSettings::novedades_para($client))) {
            return $v1;
        }

        return $this->estado_para_enviar($v2)['motivo'] === null ? $v2 : $v1;
    }

    /**
     * Todas las plantillas de la prueba que se crean en Meta.
     *
     * @return array<string, array<string, string>>
     */
    public static function todas(): array
    {
        return self::PLANTILLAS + self::PLANTILLAS_V2;
    }

    /**
     * La plantilla de bienvenida que corresponde mandar HOY: la v2 si el setting no la apaga
     * (`prueba_bienvenida_version`, default `v2`) y Meta ya la aprobó; si no, la v1 de siempre.
     *
     * Con un cliente, además respeta el alcance de las novedades (`prueba_novedades_alcance`): fuera del alcance
     * sale siempre la v1.
     *
     * @param bool                    $con_asistente true = la variante que presenta el número del asistente personal.
     * @param \App\Models\Client|null $client
     *
     * @return string
     */
    public function bienvenida_vigente(bool $con_asistente, ?\App\Models\Client $client = null): string
    {
        $v1 = $con_asistente ? self::BIENVENIDA_CON_ASISTENTE : self::BIENVENIDA;
        $v2 = $con_asistente ? self::BIENVENIDA_CON_ASISTENTE_V2 : self::BIENVENIDA_V2;

        if (\App\Services\Prueba\PruebaSettings::bienvenida_version() !== 'v2'
            || ($client !== null && ! \App\Services\Prueba\PruebaSettings::novedades_para($client))
            /* La v2 dice desde dónde se entra: sin la dirección del sistema no se puede armar. */
            || ($client !== null && \App\Services\Prueba\PruebaCorreoService::url_del_sistema($client) === null)) {
            return $v1;
        }

        if ($this->estado_para_enviar($v2)['motivo'] === null) {
            return $v2;
        }

        if ($con_asistente) {
            /* La variante corta, si existe y está aprobada. */
            if ($this->estado_para_enviar(self::BIENVENIDA_CON_ASISTENTE_V3)['motivo'] === null) {
                return self::BIENVENIDA_CON_ASISTENTE_V3;
            }
            /* Ninguna v2 con asistente aprobada: antes que la bienvenida vieja (que no explica los agentes ni cómo
               entrar) va la v2 SIN asistente; el número del asistente sale después por `cc_prueba_asistente_listo`. */
            if ($this->estado_para_enviar(self::BIENVENIDA_V2)['motivo'] === null) {
                return self::BIENVENIDA_V2;
            }
        }

        return $v1;
    }

    /**
     * El texto de una plantilla tal como está escrito acá (vacío si no existe).
     *
     * @param string $nombre
     *
     * @return string
     */
    public static function cuerpo_de(string $nombre): string
    {
        $todas = self::todas();

        return isset($todas[$nombre]) ? (string) $todas[$nombre]['body'] : '';
    }

    /**
     * Si la plantilla se puede mandar hoy (registrada, activa y APPROVED).
     *
     * @param string $nombre
     *
     * @return array{fila: FollowupTemplate|null, motivo: string|null}
     */
    public function estado_para_enviar(string $nombre): array
    {
        return FollowupTemplate::estado_para_enviar($nombre);
    }

    /**
     * El cuerpo de una plantilla ya con el nombre puesto (lo que el cliente recibe), para dejarlo en el
     * hilo del ticket: el operador tiene que poder releer lo mismo que le llegó.
     *
     * @param string $nombre_de_plantilla
     * @param string $primer_nombre
     *
     * @return string
     */
    public function cuerpo_renderizado(string $nombre_de_plantilla, string $primer_nombre, $numero_asistente = null): string
    {
        /* `$numero_asistente` es lo que va en `{{2}}` (y, si es una lista, también en `{{3}}`). */
        $extras = is_array($numero_asistente) ? array_values($numero_asistente) : [$numero_asistente];

        $fila = FollowupTemplate::query()->where('template_name', $nombre_de_plantilla)->first();

        $cuerpo = $fila !== null && trim((string) $fila->body_template) !== ''
            ? (string) $fila->body_template
            : self::cuerpo_de($nombre_de_plantilla);

        return strtr($cuerpo, [
            '{{1}}' => $primer_nombre,
            '{{2}}' => isset($extras[0]) ? (string) $extras[0] : '',
            '{{3}}' => isset($extras[1]) ? (string) $extras[1] : '',
        ]);
    }

    /**
     * Crea en Meta y registra en el admin las plantillas que falten. Las que ya están registradas no se
     * vuelven a crear (crear dos veces el mismo nombre consume el tope de 100 por hora y deja a Meta
     * decidiendo cuál gana).
     *
     * @param MetaTemplateService $meta
     * @param bool                $crear false = solo dice qué haría, sin tocar Meta ni la base.
     *
     * @return array<int, array{plantilla: string, resultado: string, detalle: string|null, meta_status: string|null}>
     */
    public function crear_las_que_faltan(MetaTemplateService $meta, bool $crear): array
    {
        $resultados = [];

        foreach (self::todas() as $nombre => $definicion) {
            $existente = FollowupTemplate::query()->where('template_name', $nombre)->first();

            if ($existente !== null) {
                $resultados[] = [
                    'plantilla'   => $nombre,
                    'resultado'   => 'ya_registrada',
                    'detalle'     => 'activa=' . ((bool) $existente->activa ? '1' : '0'),
                    'meta_status' => $existente->meta_status,
                ];
                continue;
            }

            if (! $crear) {
                $resultados[] = ['plantilla' => $nombre, 'resultado' => 'se_crearia', 'detalle' => null, 'meta_status' => null];
                continue;
            }

            $componentes = $this->componentes(
                $definicion['body'],
                isset($definicion['ejemplo_2']) ? (string) $definicion['ejemplo_2'] : null,
                isset($definicion['ejemplo_3']) ? (string) $definicion['ejemplo_3'] : null
            );

            $respuesta = $meta->crear([
                'name'       => $nombre,
                'language'   => self::IDIOMA,
                'category'   => self::CATEGORIA,
                'components' => $componentes,
            ]);

            if ($respuesta['ok'] !== true) {
                $resultados[] = [
                    'plantilla'   => $nombre,
                    'resultado'   => 'meta_rechazo',
                    'detalle'     => (string) $respuesta['motivo'] . ($respuesta['mensaje'] !== null ? ': ' . $respuesta['mensaje'] : ''),
                    'meta_status' => null,
                ];

                /* Sin permiso de plantillas sobre la WABA, las que siguen fallarían igual: se corta. */
                if ($respuesta['motivo'] === 'sin_permiso_en_meta' || $respuesta['motivo'] === 'sin_configuracion' || $respuesta['motivo'] === 'sin_waba_id') {
                    break;
                }

                continue;
            }

            $estado_de_meta = isset($respuesta['datos']['status']) && trim((string) $respuesta['datos']['status']) !== ''
                ? strtoupper(trim((string) $respuesta['datos']['status']))
                : 'PENDING';

            $fila = FollowupTemplate::create([
                'template_name'              => $nombre,
                'estado'                     => self::ESTADO,
                'dia_numero'                 => 1,
                'body_template'              => $definicion['body'],
                'language_code'              => self::IDIOMA,
                /* `activa = false` SIEMPRE: la enciende el sync al confirmar APPROVED contra Meta. */
                'activa'                     => false,
                'meta_status'                => $estado_de_meta,
                'meta_category'              => self::CATEGORIA,
                'meta_template_id'           => isset($respuesta['datos']['id']) ? (string) $respuesta['datos']['id'] : null,
                'meta_rechazo'               => null,
                'meta_synced_at'             => now(),
                'components'                 => $componentes,
                'uso'                        => $definicion['uso'],
                'solo_si_ingreso_confirmado' => false,
                'no_marca_error_de_entrega'  => false,
            ]);

            Log::channel('daily')->info('PruebaPlantillasService: plantilla de la prueba creada en Meta y registrada.', [
                'template_name' => $nombre,
                'meta_status'   => $estado_de_meta,
                'id'            => (int) $fila->id,
            ]);

            $resultados[] = ['plantilla' => $nombre, 'resultado' => 'creada', 'detalle' => null, 'meta_status' => $estado_de_meta];
        }

        return $resultados;
    }

    /**
     * El componente BODY tal como lo espera Meta, con el ejemplo de `{{1}}`.
     *
     * @param string      $cuerpo
     * @param string|null $ejemplo_2 Ejemplo de `{{2}}`; null = el de un número de WhatsApp.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function componentes(string $cuerpo, ?string $ejemplo_2 = null, ?string $ejemplo_3 = null): array
    {
        $ejemplos = [self::EJEMPLO_NOMBRE];
        if (strpos($cuerpo, '{{2}}') !== false) {
            $ejemplos[] = $ejemplo_2 !== null ? $ejemplo_2 : '+54 9 11 1234-5678';
        }
        if (strpos($cuerpo, '{{3}}') !== false) {
            $ejemplos[] = $ejemplo_3 !== null ? $ejemplo_3 : '+54 9 11 1234-5678';
        }

        return [[
            'type'    => 'BODY',
            'text'    => $cuerpo,
            'example' => ['body_text' => [$ejemplos]],
        ]];
    }
}
