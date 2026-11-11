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

    const BIENVENIDAS = [self::BIENVENIDA, self::BIENVENIDA_CON_ASISTENTE];

    /** El pedido de la encuesta de fin de prueba (misión prueba-e2e-campana-imperio, 11/11/2026). `{{2}}` = el enlace. */
    const FIN_ENCUESTA = 'cc_prueba_fin_encuesta';

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
        'cc_prueba_paso_venta' => [
            'body' => 'Hola {{1}}, probá hacer una venta desde Vender: buscás el artículo, elegís cómo te pagan y guardás. Así ves cómo queda el ticket y cómo baja el stock. Si algo no te cierra, escribime por acá.',
            'uso'  => 'Prueba gratuita: el negocio no hizo ninguna venta.',
        ],
        'cc_prueba_paso_cliente_creado' => [
            'body' => 'Hola {{1}}, cargá un cliente tuyo desde Clientes para probar la cuenta corriente: después le vendés en cuenta corriente y ves el saldo al instante. Te paso un video si lo necesitás.',
            'uso'  => 'Prueba gratuita: el negocio no cargó ningún cliente.',
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
        self::FIN_ENCUESTA => [
            'body' => 'Hola {{1}}, soy Martín, de ComercioCity. Quiero saber cómo te fue con la prueba del sistema. ¿Me contás en esta encuesta? Te lleva un minuto: {{2}} Tu opinión nos ayuda mucho a mejorar.',
            'uso'  => 'Prueba gratuita: pedido de la encuesta al terminar la prueba (venció o contrató). {{2}} es el enlace.',
            'ejemplo_2' => 'https://api-admin.comerciocity.com/public/prueba/encuesta/AbCdEfGhIjKlMnOpQrStUvWxYz0123456789AbCdEfGhIjKl',
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
     * Todas las plantillas de la prueba que se crean en Meta.
     *
     * @return array<string, array<string, string>>
     */
    public static function todas(): array
    {
        return self::PLANTILLAS;
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
    public function cuerpo_renderizado(string $nombre_de_plantilla, string $primer_nombre, ?string $numero_asistente = null): string
    {
        $fila = FollowupTemplate::query()->where('template_name', $nombre_de_plantilla)->first();

        $cuerpo = $fila !== null && trim((string) $fila->body_template) !== ''
            ? (string) $fila->body_template
            : self::cuerpo_de($nombre_de_plantilla);

        return strtr($cuerpo, ['{{1}}' => $primer_nombre, '{{2}}' => $numero_asistente ?? '']);
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

            $componentes = $this->componentes($definicion['body'], isset($definicion['ejemplo_2']) ? (string) $definicion['ejemplo_2'] : null);

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
    protected function componentes(string $cuerpo, ?string $ejemplo_2 = null): array
    {
        $ejemplos = [self::EJEMPLO_NOMBRE];
        if (strpos($cuerpo, '{{2}}') !== false) {
            $ejemplos[] = $ejemplo_2 !== null ? $ejemplo_2 : '+54 9 11 1234-5678';
        }

        return [[
            'type'    => 'BODY',
            'text'    => $cuerpo,
            'example' => ['body_text' => [$ejemplos]],
        ]];
    }
}
