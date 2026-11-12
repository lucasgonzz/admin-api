<?php

namespace App\Services\Prueba;

use App\Mail\ComercioCityMail;
use App\Mail\ComercioCityMailPayload;
use App\Mail\Helpers\RechazosDeCorreoHelper;
use App\Models\Client;
use App\Models\Lead;
use App\Models\PruebaWhatsappEnvio;
use App\Models\TrialInstance;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PruebaCorreoService
{
    /** Prefijo de `prueba_whatsapp_envios.plantilla` para los correos (`canal = correo`). */
    const PREFIJO = 'correo_';

    /** `clave` del correo «tu prueba está por terminar». */
    const CORREO_DIA_5 = 'dia_5';

    /**
     * El correo de cada paso de la prueba: asunto, título y el texto del paso (misión prueba-e2e-campana-imperio,
     * 11/11/2026). La clave es el hito de `PruebaDelClienteService::HITOS`.
     *
     * @var array<string, array{asunto: string, titulo: string, texto: string}>
     */
    const PASOS = [
        'ingreso' => [
            'asunto' => 'Tu sistema de prueba te está esperando',
            'titulo' => 'Entrá a tu sistema de prueba',
            'texto'  => 'Tu sistema ya está armado con datos de ejemplo y todavía no entraste. Entrá cuando puedas: en unos minutos ves cómo trabaja.',
        ],
        'importo' => [
            'asunto' => 'Te cargamos tu catálogo sin costo',
            'titulo' => 'Probá el sistema con tus artículos',
            'texto'  => 'Para que la prueba sea con lo tuyo, te cargamos tus artículos sin costo. Mandame el Excel de tu lista o el catálogo de tu proveedor en PDF desde Agentes, Soporte, en tu sistema, o por WhatsApp, y lo dejamos listo.',
        ],
        'articulo_creado' => [
            'asunto' => 'Un paso corto: cargá un artículo',
            'titulo' => 'Cargá tu primer artículo',
            'texto'  => 'Desde Artículos, con el botón Nuevo, cargás un artículo tuyo en un minuto. Después lo vendés desde Vender.',
        ],
        'articulo_actualizado' => [
            'asunto' => 'Probá cambiar un precio',
            'titulo' => 'Revisá y cambiá un precio',
            'texto'  => 'Entrá a Artículos, abrí uno, revisá el costo y el margen, y guardá. El precio de venta se actualiza al instante.',
        ],
        'venta' => [
            'asunto' => 'Hacé tu primera venta de prueba',
            'titulo' => 'Hacé tu primera venta',
            'texto'  => 'Desde Vender buscás el artículo, elegís cómo te pagan y guardás. Así ves cómo queda el ticket y cómo baja el stock.',
        ],
        'cliente_creado' => [
            'asunto' => 'Cargá un cliente para probar la cuenta corriente',
            'titulo' => 'Cargá un cliente',
            'texto'  => 'Desde Clientes cargás un cliente tuyo. Después le vendés en cuenta corriente y ves el saldo al instante.',
        ],
        'venta_cc' => [
            'asunto' => 'Probá una venta en cuenta corriente',
            'titulo' => 'Vendé en cuenta corriente',
            'texto'  => 'En Vender elegís al cliente que cargaste, sumás los artículos y guardás como cuenta corriente. Después ves el saldo en su ficha.',
        ],
        'pago_cc' => [
            'asunto' => 'Registrá un pago de cuenta corriente',
            'titulo' => 'Cobrá una cuenta corriente',
            'texto'  => 'Desde la ficha del cliente registrás un pago. Así ves cómo baja el saldo y queda el comprobante.',
        ],
        'compra' => [
            'asunto' => 'Cargá una compra a un proveedor',
            'titulo' => 'Cargá una compra',
            'texto'  => 'Desde Compras cargás una compra a un proveedor: el stock sube solo y el costo queda actualizado. También podés cargarla con una foto de la factura.',
        ],
        'gasto' => [
            'asunto' => 'Cargá un gasto y mirá la caja del día',
            'titulo' => 'Cargá un gasto',
            'texto'  => 'Desde Gastos cargás un gasto en un minuto y ves el resultado real del día.',
        ],
        'tienda_pedido' => [
            'asunto' => 'Tu prueba incluye una tienda online',
            'titulo' => 'Probá tu tienda online',
            'texto'  => 'Tu prueba incluye una tienda online conectada a tu sistema. Entrá desde el sistema, hacé un pedido de prueba y mirá cómo te llega.',
        ],
        'agente' => [
            'asunto' => 'Probá tu asistente personal',
            'titulo' => 'Hablá con tu asistente personal',
            'texto'  => 'En tu sistema, en Agentes, preguntale a tu Asistente personal cuánto vendiste hoy o pedile que cargue un gasto.',
        ],
    ];

    /** El video del catálogo de la prueba (04 a 09) que acompaña a cada paso, cuando lo hay. */
    const VIDEO_DEL_PASO = [
        'ingreso'              => '04',
        'importo'              => '05',
        'articulo_creado'      => '06',
        'articulo_actualizado' => '06',
        'compra'               => '07',
        'venta'                => '08',
        'agente'               => '09',
    ];

    /** Cuántas veces se intenta un mismo correo si el servidor lo rechaza. */
    const MAX_INTENTOS = 3;

    /** Se llama bajo el mismo lock de la bienvenida. */
    public function enviar(Client $client, TrialInstance $instancia, array $asistente): void
    {
        $email = trim((string) $client->email);
        if ($instancia->correo_bienvenida_at !== null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $hosts = PruebaNombres::hosts_del_slug((string) $instancia->alias_slug);
        /* El video de bienvenida y recorrido (04), si está configurado, va en el mismo correo. */
        $video = PruebaSettings::novedades_para($client) ? self::video_del_paso('ingreso') : null;
        $mail = new ComercioCityMail(new ComercioCityMailPayload([
            'subject' => 'Tu prueba de ComercioCity ya está lista',
            'title' => 'Tu prueba de siete días ya está lista',
            'paragraphs' => [
                'Tu sistema está listo para que lo pruebes con datos de ejemplo.',
                'Tu asistente personal te atiende por WhatsApp en el ' . $asistente['numero'] . '. ' . PruebaAsistenteService::USO,
            ],
            'cta' => ['text' => 'Entrar a mi sistema', 'url' => 'https://' . $hosts['erp_spa']],
            'links' => array_merge(
                [['text' => 'Escribirle a mi asistente personal por WhatsApp', 'url' => $asistente['url']]],
                $video !== null ? [['text' => 'Ver el video: «' . $video['titulo'] . '»', 'url' => $video['url']]] : []
            ),
            'closing' => 'Equipo de ComercioCity',
        ]));

        try {
            Mail::to($email)->send($mail);
            RechazosDeCorreoHelper::fallar_si_hubo_rechazos();
            $instancia->correo_bienvenida_at = now();
            $instancia->save();
        } catch (\Throwable $e) {
            Log::warning('No se pudo registrar el envío del correo de bienvenida de la prueba.', [
                'client_id' => (int) $client->id,
                'tipo' => get_class($e),
            ]);
        }
    }

    /**
     * Manda un correo de la prueba al dueño y lo deja registrado en `prueba_whatsapp_envios` con
     * `canal = correo` (misión prueba-e2e-campana-imperio, 11/11/2026). Uno solo por `$clave` y por
     * cliente: el registro va ANTES del envío, en `enviando`, igual que el video 04; un `enviado` o un
     * `enviando` cortan todo reintento, y un rechazo se reintenta hasta {@see self::MAX_INTENTOS} veces.
     *
     * @param Client                  $client
     * @param Lead|null               $lead
     * @param string                  $clave   Qué correo es (`fin_encuesta`, `dia_5`, `paso_venta`…).
     * @param ComercioCityMailPayload $payload
     * @param string|null             $hito    El hito que acompaña, si lo hay.
     *
     * @return array{estado: string, detalle: string|null}
     *   `estado`: `enviado`, `ya_enviado`, `sin_correo`, `en_curso` o `fallido`.
     */
    public function enviar_registrado(Client $client, ?Lead $lead, string $clave, ComercioCityMailPayload $payload, ?string $hito = null): array
    {
        $email = trim((string) $client->email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['estado' => 'sin_correo', 'detalle' => 'El cliente no tiene un correo válido en la ficha.'];
        }

        $plantilla = self::PREFIJO . $clave;
        $reserva   = Cache::lock('prueba-correo-' . $client->id . '-' . $clave, 60);
        if (! $reserva->get()) {
            return ['estado' => 'en_curso', 'detalle' => null];
        }

        try {
            $previos = PruebaWhatsappEnvio::query()
                ->where('client_id', (int) $client->id)
                ->where('canal', PruebaWhatsappEnvio::CANAL_CORREO)
                ->where('plantilla', $plantilla)
                ->pluck('estado');

            if ($previos->contains(PruebaWhatsappEnvio::ESTADO_ENVIADO) || $previos->contains(PruebaWhatsappEnvio::ESTADO_ENVIANDO)) {
                return ['estado' => 'ya_enviado', 'detalle' => null];
            }

            if ($previos->filter(function ($estado) {
                return $estado === PruebaWhatsappEnvio::ESTADO_FALLIDO;
            })->count() >= self::MAX_INTENTOS) {
                return ['estado' => 'fallido', 'detalle' => 'Se agotaron los intentos de este correo.'];
            }

            $registro = PruebaWhatsappEnvio::create([
                'client_id' => (int) $client->id,
                'lead_id'   => $lead !== null ? (int) $lead->id : null,
                'plantilla' => $plantilla,
                'canal'     => PruebaWhatsappEnvio::CANAL_CORREO,
                'hito'      => $hito,
                'estado'    => PruebaWhatsappEnvio::ESTADO_ENVIANDO,
                'dia'       => PruebaWhatsappService::hoy(),
            ]);

            try {
                Mail::to($email)->send(new ComercioCityMail($payload));
                RechazosDeCorreoHelper::fallar_si_hubo_rechazos();
            } catch (\Throwable $e) {
                $registro->estado  = PruebaWhatsappEnvio::ESTADO_FALLIDO;
                $registro->detalle = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 1000);
                $registro->save();

                Log::warning('PruebaCorreoService: no salió un correo de la prueba.', [
                    'client_id' => (int) $client->id,
                    'correo'    => $clave,
                    'tipo'      => get_class($e),
                ]);

                return ['estado' => 'fallido', 'detalle' => get_class($e)];
            }

            $registro->estado     = PruebaWhatsappEnvio::ESTADO_ENVIADO;
            $registro->enviado_at = now();
            $registro->save();

            return ['estado' => 'enviado', 'detalle' => null];
        } finally {
            $reserva->release();
        }
    }

    /**
     * El correo de un paso de la prueba, con su video si el catálogo lo tiene. Uno por paso y por cliente.
     *
     * @param Client    $client
     * @param Lead|null $lead
     * @param string    $hito
     *
     * @return array{estado: string, detalle: string|null} `sin_texto` si el hito no tiene correo.
     */
    public function enviar_paso(Client $client, ?Lead $lead, string $hito): array
    {
        if (! isset(self::PASOS[$hito])) {
            return ['estado' => 'sin_texto', 'detalle' => null];
        }

        $paso  = self::PASOS[$hito];
        $video = self::video_del_paso($hito);

        $parrafos = [self::saludo($client, $lead) . ', soy Martín, del equipo de ComercioCity.', $paso['texto']];
        if ($video !== null) {
            $parrafos[] = 'Te dejo un video corto que lo muestra paso a paso.';
        }

        $payload = new ComercioCityMailPayload([
            'subject'    => $paso['asunto'],
            'title'      => $paso['titulo'],
            'paragraphs' => $parrafos,
            'links'      => $video !== null ? [['text' => 'Ver el video: «' . $video['titulo'] . '»', 'url' => $video['url']]] : [],
            'cta'        => self::boton_del_sistema($client),
            'closing'    => 'Cualquier duda, escribime desde Agentes, Soporte, en tu sistema. Equipo de ComercioCity',
        ]);

        return $this->enviar_registrado($client, $lead, 'paso_' . $hito, $payload, $hito);
    }

    /**
     * El video del catálogo aprobado que acompaña a un paso, o null si no hay (o no está configurado).
     *
     * @param string $hito
     *
     * @return array<string, mixed>|null Fila de `PruebaVideoCatalogoService::videos()`.
     */
    public static function video_del_paso(string $hito): ?array
    {
        if (! isset(self::VIDEO_DEL_PASO[$hito])) {
            return null;
        }

        $catalogo = app(PruebaVideoCatalogoService::class)->videos();
        $clave    = 'prueba:' . self::VIDEO_DEL_PASO[$hito];

        return isset($catalogo[$clave]) ? $catalogo[$clave] : null;
    }

    /**
     * El correo «tu prueba termina en 2 días» (desde el día 5, una sola vez por cliente).
     *
     * @param Client    $client
     * @param Lead|null $lead
     * @param string    $falta «2 días», «1 día» o «menos de un día».
     *
     * @return array{estado: string, detalle: string|null}
     */
    public function enviar_por_terminar(Client $client, ?Lead $lead, string $falta): array
    {
        $payload = new ComercioCityMailPayload([
            'subject'    => 'Tu prueba de ComercioCity termina en ' . $falta,
            'title'      => 'Tu prueba termina en ' . $falta,
            'paragraphs' => [
                self::saludo($client, $lead) . ', soy Martín, del equipo de ComercioCity.',
                'Te aviso con tiempo: tu prueba gratuita termina en ' . $falta . '.',
                'Si te quedó algo por probar, entrá a tu sistema y abrí Agentes, Soporte: ahí te contesto, te paso los videos y te muestro cómo se hace en tu pantalla.',
                'Y si ya sabés que querés seguir, respondé este correo o escribime por WhatsApp y te cuento cómo. Todo lo que cargaste queda como está.',
            ],
            'cta'     => self::boton_del_sistema($client),
            'closing' => 'Equipo de ComercioCity',
        ]);

        return $this->enviar_registrado($client, $lead, self::CORREO_DIA_5, $payload, 'dia_5');
    }

    /**
     * «Hola Marcela» (o «Hola» a secas): el saludo de los correos, con el primer nombre del lead o de la ficha.
     *
     * @param Client    $client
     * @param Lead|null $lead
     *
     * @return string
     */
    public static function saludo(Client $client, ?Lead $lead): string
    {
        foreach ([$lead !== null ? (string) $lead->contact_name : '', (string) $client->name] as $candidato) {
            $primero = Lead::primer_nombre_de($candidato);
            if ($primero !== null && trim($primero) !== '') {
                return 'Hola ' . trim($primero);
            }
        }

        return 'Hola';
    }

    /**
     * El botón «Entrar a mi sistema» de los correos de la prueba, o null si el cliente no tiene instancia.
     *
     * @param Client $client
     *
     * @return array{text: string, url: string}|null
     */
    public static function boton_del_sistema(Client $client): ?array
    {
        $instancia = PruebaCicloService::instancia_de($client);
        if ($instancia === null || trim((string) $instancia->alias_slug) === '') {
            return null;
        }

        $hosts = PruebaNombres::hosts_del_slug((string) $instancia->alias_slug);

        return ['text' => 'Entrar a mi sistema', 'url' => 'https://' . $hosts['erp_spa']];
    }
}
