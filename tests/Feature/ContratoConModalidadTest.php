<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Services\ClientContratoService;
use App\Services\LeadContractPdfService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cobranzas\BaseDeCobranzas;

/**
 * El contrato con modalidad: Licencia o Suscripción, sistemas contratados y plazo mínimo (misión
 * precios-dos-modalidades, 13/10/2026).
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **Que sin modalidad el contrato salga como siempre.** `contract_modalidad` en null es el
 *     contrato de todo lead y cliente que ya existía; regenerarlo no puede agregarle letra chica
 *     que nadie firmó. Por eso la prueba carga sistemas y plazo mínimo en ese lead: aunque estén,
 *     sin modalidad no se imprimen.
 *  2. **Que cada modalidad diga SU letra chica, con el texto literal del plan.** Los párrafos se
 *     comparan enteros (con los espacios normalizados, porque la vista los parte en varias
 *     líneas): reescribir una cláusula legal "por prolijidad" tiene que dar rojo.
 *  3. **Que la cláusula de consumos de terceros dependa de Agentes**, y que una key de sistema
 *     desconocida no rompa el contrato.
 *  4. **Que los tres datos lleguen y se guarden** por los tres caminos: el PUT genérico del lead,
 *     el PUT del contrato del cliente (con validación) y la copia del lead al cliente al promover;
 *     y que el endpoint de análisis de Claude las devuelva con `include=contrato`.
 *
 * Extiende `BaseDeCobranzas` por sus ayudantes (admin por Sanctum, cliente armado a mano, reloj
 * clavado y `Http::fake()`): la respuesta del contrato del cliente incluye el resumen de la
 * actualización de precios, que compara contra "el mes corriente".
 *
 * 🔴 `Storage::fake('local')` en el `setUp()`: el PDF lee la firma del PRESTADOR del disco `local`,
 * y sin el fake la suite dependería de lo que haya en el entorno de Lucas.
 */
class ContratoConModalidadTest extends BaseDeCobranzas
{
    /** Texto literal de la cláusula 5.1 de Licencia (plan de la misión). */
    const TEXTO_LICENCIA_5_1 = 'En la modalidad Licencia, la mensualidad base pactada en este contrato es de por vida: solo podrá '
        . 'modificarse por la actualización por IPC prevista en el párrafo anterior, y no por cambios en la lista de precios de '
        . 'ComercioCity. Incluye las versiones nuevas de los sistemas contratados, el soporte y la infraestructura en la nube. No '
        . 'incluye: (a) sistemas o módulos que el CLIENTE no haya contratado en este acuerdo, ni los que ComercioCity lance en el '
        . 'futuro; (b) los usuarios adicionales que se sumen después de la firma, que se cobran al precio de lista vigente en ese '
        . 'momento; (c) los consumos de terceros que se detallan en este contrato. Si el servicio mensual se da de baja —por '
        . 'rescisión del CLIENTE o por falta de pago— y luego se retoma, la mensualidad pasa a ser la de la lista vigente al '
        . 'momento de retomarlo.';

    /** Texto literal de la cláusula 5.1 de Suscripción, con el plazo por defecto (plan de la misión). */
    const TEXTO_SUSCRIPCION_5_1 = 'En la modalidad Suscripción, el servicio mensual tiene un plazo mínimo de <strong>doce (12) '
        . 'meses</strong> contados desde el primer pago de la mensualidad. Si el CLIENTE rescinde antes de cumplirse ese plazo, '
        . 'deberá abonar las mensualidades que resten hasta completarlo. Cumplido el plazo mínimo, la suscripción continúa mes a '
        . 'mes. Durante el plazo mínimo la mensualidad solo se actualiza por IPC; después podrá ajustarse además a la lista de '
        . 'precios vigente de ComercioCity, con un aviso previo de treinta (30) días.';

    /** Texto literal de la cláusula 5.2, consumos de terceros (plan de la misión). */
    const TEXTO_CONSUMOS_5_2 = 'Los mensajes de WhatsApp que los sistemas envíen a clientes, proveedores u otros terceros del '
        . 'CLIENTE (por ejemplo, recordatorios de cobro) tienen un costo por mensaje que cobra Meta Platforms. Ese costo no está '
        . 'incluido en la mensualidad: el PRESTADOR podrá trasladarlo al CLIENTE al valor que cobre Meta, con un aviso previo de '
        . 'quince (15) días. Las consultas que el CLIENTE le haga a su sistema por WhatsApp y los informes que reciba no tienen '
        . 'costo adicional. El uso de herramientas de inteligencia artificial propias del CLIENTE conectadas a su sistema corre '
        . 'por cuenta del CLIENTE.';

    /** Texto literal del objeto (sección 2) en Suscripción (plan de la misión). */
    const TEXTO_SUSCRIPCION_OBJETO = 'El presente contrato tiene por objeto la concesión de una <strong>licencia de uso</strong> '
        . 'del software ComercioCity, <strong>vigente mientras se mantenga activa la suscripción mensual</strong>, junto con el '
        . '<strong>servicio de implementación</strong> que permite poner en marcha la plataforma en las condiciones operativas del '
        . 'CLIENTE, y el <strong>servicio mensual continuo</strong> de soporte, mantenimiento y actualizaciones descrito en las '
        . 'cláusulas siguientes.';

    /** Texto literal del pago inicial (sección 3) en Suscripción, con USD 1200 (plan de la misión). */
    const TEXTO_SUSCRIPCION_PAGO_INICIAL = 'El CLIENTE abonará al PRESTADOR un pago inicial por el servicio de implementación y '
        . 'puesta en marcha, por un importe total de <strong>USD 1200</strong>, conforme al plan de cuotas que se detalla a '
        . 'continuación:';

    /** Primera oración del soporte (sección 6) en Suscripción: dura mientras la suscripción esté activa. */
    const TEXTO_SUSCRIPCION_SOPORTE = 'El servicio mensual incluye, mientras la suscripción esté activa, soporte personalizado vía '
        . 'WhatsApp de lunes a sábados, atendido por personas reales del equipo de ComercioCity, sin costo adicional al abono '
        . 'mensual.';

    /** Primera oración del soporte (sección 6) de siempre: Licencia y contrato sin modalidad. */
    const TEXTO_SOPORTE_DE_SIEMPRE = 'El servicio mensual incluye soporte personalizado vía WhatsApp de lunes a sábados, atendido '
        . 'por personas reales del equipo de ComercioCity, sin costo adicional al abono mensual.';

    /** Texto literal de la rescisión (sección 7) en Suscripción (plan de la misión). */
    const TEXTO_SUSCRIPCION_RESCISION = '<strong>Rescisión:</strong> Cumplido el plazo mínimo de la cláusula 5.1, cualquiera de '
        . 'las partes podrá rescindir el servicio mensual con un preaviso de treinta (30) días. Con la rescisión finaliza la '
        . 'licencia de uso. El pago inicial no será reembolsable una vez iniciada la implementación.';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * Un lead con el contrato cargado, en memoria (las pruebas de la vista no necesitan la base).
     *
     * @param array<string, mixed> $atributos Pisan los defaults.
     *
     * @return Lead
     */
    private function lead_con_contrato(array $atributos = []): Lead
    {
        $lead = new Lead();
        $lead->contact_name                       = 'Prospecto con modalidad';
        $lead->company_name                       = 'Comercio con modalidad ' . uniqid();
        $lead->status                             = 'cerrado_ganado';
        $lead->contract_client_name               = 'Juan Pérez';
        $lead->contract_client_razon_social       = 'Juan Pérez S.A.';
        $lead->contract_client_cuit               = '20-11111111-1';
        $lead->contract_currency                  = 'USD';
        $lead->contract_precio_licencia           = '1200';
        $lead->contract_fecha_emision             = '2026-10-13';
        $lead->contract_fecha_primer_pago_unico   = '2026-10-20';
        $lead->contract_financiacion              = [['monto' => '1200', 'fecha' => '2026-10-20']];
        $lead->contract_mensualidad_moneda        = 'ARS';
        $lead->contract_mensualidad_base          = '140000';
        $lead->contract_usuarios_incluidos        = 3;
        $lead->contract_fecha_primer_pago_mensual = '2026-11-01';
        $lead->contract_meses_actualizacion       = 6;

        foreach ($atributos as $campo => $valor) {
            $lead->{$campo} = $valor;
        }

        return $lead;
    }

    /**
     * El HTML de la vista del contrato para un modelo, sin pasar por dompdf.
     *
     * @param Lead|Client $contrato
     *
     * @return string
     */
    private function html($contrato): string
    {
        return view('emails.lead.contract', LeadContractPdfService::datos_de_vista($contrato))->render();
    }

    /**
     * El HTML con cada tramo de espacios (saltos de línea y sangría incluidos) llevado a un solo
     * espacio: la vista parte los párrafos en varias líneas y el texto se compara entero.
     *
     * @param string $html
     *
     * @return string
     */
    private function normalizado(string $html): string
    {
        return preg_replace('/\s+/u', ' ', $html);
    }

    /**
     * 1. Sin modalidad, el contrato de siempre: ni modalidad, ni 5.1/5.2, ni sistemas, ni la palabra
     * Suscripción — aunque el lead tenga sistemas y plazo mínimo cargados. Y una modalidad que no
     * sea una de las dos se trata igual que null.
     *
     * @return void
     */
    public function test_sin_modalidad_el_contrato_sale_como_siempre(): void
    {
        foreach ([null, 'otra-cosa', ''] as $modalidad) {
            $lead = $this->lead_con_contrato([
                'contract_modalidad'         => $modalidad,
                'contract_sistemas'          => ['gestion', 'ecommerce', 'agentes'],
                'contract_permanencia_meses' => 24,
            ]);

            $html = $this->html($lead);
            $mensaje = 'Modalidad: ' . var_export($modalidad, true);

            $this->assertStringNotContainsString('Modalidad:', $html, $mensaje);
            $this->assertStringNotContainsString('5.1 —', $html, $mensaje);
            $this->assertStringNotContainsString('5.2 —', $html, $mensaje);
            $this->assertStringNotContainsString('Sistemas contratados', $html, $mensaje);
            $this->assertStringNotContainsString('Suscripción', $html, $mensaje);
            $this->assertStringNotContainsString('veinticuatro', $html, $mensaje);

            $this->assertStringContainsString('Contrato de Licencia, Implementación y Servicio', $html, $mensaje);
            $this->assertStringContainsString('3. Pago único — Licencia e implementación', $html, $mensaje);
            $this->assertStringContainsString('licencia de uso de por vida', $html, $mensaje);
            // La rescisión de siempre: la licencia sigue vigente después de rescindir.
            $this->assertStringContainsString('La licencia de uso de por vida permanecerá vigente', $this->normalizado($html), $mensaje);
            // El soporte de siempre: "de por vida".
            $this->assertStringContainsString('6. Soporte personalizado de por vida</h2>', $html, $mensaje);
            $this->assertStringContainsString(self::TEXTO_SOPORTE_DE_SIEMPRE, $this->normalizado($html), $mensaje);
            $this->assertStringNotContainsString('mientras la suscripción esté activa', $html, $mensaje);
        }

        // Las claves nuevas están siempre en los datos de la vista, aunque el modelo no traiga nada.
        $datos = LeadContractPdfService::datos_de_vista(new Lead());
        foreach (['modalidad', 'sistemas_contratados', 'sistemas_contratados_texto', 'tiene_agentes', 'permanencia_meses', 'permanencia_meses_texto'] as $clave) {
            $this->assertArrayHasKey($clave, $datos, 'La vista no usa @isset: la clave tiene que estar siempre.');
        }
        $this->assertNull($datos['modalidad']);
        $this->assertSame([], $datos['sistemas_contratados']);
        $this->assertSame('', $datos['sistemas_contratados_texto']);
        $this->assertFalse($datos['tiene_agentes']);
        $this->assertSame(12, $datos['permanencia_meses']);
        $this->assertSame('doce (12) meses', $datos['permanencia_meses_texto']);
    }

    /**
     * 2a. Licencia: el encabezado y las secciones 2, 3 y 7 de siempre, más la modalidad debajo de
     * la fecha y la cláusula 5.1 de la mensualidad de por vida. Sin plazo mínimo.
     *
     * @return void
     */
    public function test_licencia_lleva_la_mensualidad_de_por_vida(): void
    {
        $lead = $this->lead_con_contrato([
            'contract_modalidad' => 'licencia',
            'contract_sistemas'  => ['gestion', 'ecommerce'],
        ]);

        $html = $this->html($lead);
        $plano = $this->normalizado($html);

        $this->assertStringContainsString('Modalidad: <strong>Licencia</strong>', $html);
        $this->assertStringContainsString('Contrato de Licencia, Implementación y Servicio', $html);
        $this->assertStringContainsString('5.1 — Mensualidad de por vida', $html);
        $this->assertStringContainsString('no por cambios en la lista', $plano);
        $this->assertStringContainsString(self::TEXTO_LICENCIA_5_1, $plano, 'La cláusula 5.1 de Licencia va literal, como la escribió el plan.');
        $this->assertStringNotContainsString('Plazo mínimo', $html);
        $this->assertStringNotContainsString('Suscripción', $html);

        // Lo que no cambia en Licencia: objeto, pago único y rescisión de siempre.
        $this->assertStringContainsString('licencia de uso de por vida', $html);
        $this->assertStringContainsString('3. Pago único — Licencia e implementación', $html);
        $this->assertStringContainsString('La licencia de uso de por vida permanecerá vigente', $plano);
        $this->assertStringContainsString('6. Soporte personalizado de por vida</h2>', $html, 'En Licencia el soporte sigue siendo de por vida.');
        $this->assertStringContainsString(self::TEXTO_SOPORTE_DE_SIEMPRE, $plano);
        $this->assertStringNotContainsString('mientras la suscripción esté activa', $plano);

        // Los sistemas, en la sección 2.
        $this->assertStringContainsString('Sistemas contratados: <strong>ComercioCity Gestión y ComercioCity E-Commerce</strong>.', $html);
    }

    /**
     * 6.1 Comunidad y adaptaciones: sale en las tres variantes del contrato; en Licencia y sin
     * modalidad dice "de por vida", en Suscripción dura mientras la suscripción esté activa.
     *
     * @return void
     */
    public function test_la_clausula_de_comunidad_y_adaptaciones_sale_en_todas_las_variantes(): void
    {
        foreach (['licencia', null, 'suscripcion'] as $modalidad) {
            $html = $this->html($this->lead_con_contrato(['contract_modalidad' => $modalidad]));
            $plano = $this->normalizado($html);
            $mensaje = 'Modalidad: ' . var_export($modalidad, true);

            $this->assertStringContainsString('6.1 — Comunidad ComercioCity y adaptaciones a medida', $html, $mensaje);
            $this->assertStringContainsString('adaptaciones o modificaciones del sistema para las necesidades de su negocio', $plano, $mensaje);
            $this->assertStringContainsString('nuevos módulos que el PRESTADOR considere ajenos a la gestión del negocio', $plano, $mensaje);

            if ($modalidad === 'suscripcion') {
                $this->assertStringContainsString('Mientras la suscripción esté activa, el CLIENTE integra la comunidad ComercioCity', $plano, $mensaje);
                $this->assertStringNotContainsString('integra de por vida la comunidad', $plano, $mensaje);
            } else {
                $this->assertStringContainsString('El CLIENTE integra de por vida la comunidad ComercioCity', $plano, $mensaje);
            }
        }
    }

    /**
     * 2b. Suscripción: encabezado, objeto, pago inicial, plazo mínimo (12 por defecto, el que diga
     * el contrato si lo dice), IPC con los meses del contrato y la rescisión que termina la licencia.
     *
     * @return void
     */
    public function test_suscripcion_lleva_plazo_minimo_y_su_rescision(): void
    {
        $lead = $this->lead_con_contrato([
            'contract_modalidad'           => 'suscripcion',
            'contract_sistemas'            => ['gestion'],
            'contract_permanencia_meses'   => null,
            'contract_meses_actualizacion' => 3,
        ]);

        $html = $this->html($lead);
        $plano = $this->normalizado($html);

        $this->assertStringContainsString('Contrato de Suscripción, Implementación y Servicio', $html);
        $this->assertStringNotContainsString('Contrato de Licencia, Implementación y Servicio', $html);
        $this->assertStringContainsString('Modalidad: <strong>Suscripción</strong>', $html);
        $this->assertStringContainsString('3. Pago inicial — Puesta en marcha', $html);
        $this->assertStringNotContainsString('3. Pago único', $html);
        $this->assertStringContainsString('5.1 — Plazo mínimo', $html);
        $this->assertStringContainsString('plazo mínimo de <strong>doce (12) meses</strong>', $plano, 'Sin el dato, 12 meses.');
        $this->assertStringContainsString('cada <strong>tres (3) meses</strong>', $html, 'El IPC con los meses del contrato.');
        $this->assertStringContainsString('Con la rescisión finaliza la licencia de uso', $plano);
        $this->assertStringNotContainsString('Mensualidad de por vida', $html);
        $this->assertStringNotContainsString('de uso de por vida', $plano, 'En Suscripción la licencia no es de por vida en ningún lado.');

        // El soporte dura mientras la suscripción esté activa: ni el título ni el texto dicen "de por vida".
        $this->assertStringContainsString('6. Soporte personalizado</h2>', $html);
        $this->assertStringNotContainsString('6. Soporte personalizado de por vida', $html);
        $this->assertStringContainsString(self::TEXTO_SUSCRIPCION_SOPORTE, $plano);
        $this->assertStringNotContainsString('de por vida', $plano, 'Nada de "de por vida" en un contrato de Suscripción.');

        // Los párrafos enteros, literales.
        $this->assertStringContainsString(self::TEXTO_SUSCRIPCION_OBJETO, $plano);
        $this->assertStringContainsString(self::TEXTO_SUSCRIPCION_PAGO_INICIAL, $plano);
        $this->assertStringContainsString(self::TEXTO_SUSCRIPCION_5_1, $plano);
        $this->assertStringContainsString(self::TEXTO_SUSCRIPCION_RESCISION, $plano);

        // Con plazo mínimo cargado, el del contrato.
        $con_plazo = $this->lead_con_contrato([
            'contract_modalidad'         => 'suscripcion',
            'contract_permanencia_meses' => 24,
        ]);
        $this->assertStringContainsString('plazo mínimo de <strong>veinticuatro (24) meses</strong>', $this->normalizado($this->html($con_plazo)));

        // Y un 0 cae al default, igual que los meses de IPC.
        $con_cero = $this->lead_con_contrato([
            'contract_modalidad'         => 'suscripcion',
            'contract_permanencia_meses' => 0,
        ]);
        $this->assertStringContainsString('plazo mínimo de <strong>doce (12) meses</strong>', $this->normalizado($this->html($con_cero)));
    }

    /**
     * 2c. El plazo mínimo se escribe en letras hasta 60 meses (el techo que valida el PUT), con el
     * uno apocopado en todas las decenas. Los meses de IPC siguen como siempre: en letras hasta 24
     * y la cifra sola después, para que ningún contrato ya emitido cambie de texto.
     *
     * @return void
     */
    public function test_el_plazo_minimo_sale_en_letras_hasta_60(): void
    {
        // El helper con el tope del plazo mínimo.
        $casos = [
            25 => 'veinticinco (25) meses',
            29 => 'veintinueve (29) meses',
            30 => 'treinta (30) meses',
            31 => 'treinta y un (31) meses',
            36 => 'treinta y seis (36) meses',
            41 => 'cuarenta y un (41) meses',
            48 => 'cuarenta y ocho (48) meses',
            51 => 'cincuenta y un (51) meses',
            59 => 'cincuenta y nueve (59) meses',
            60 => 'sesenta (60) meses',
            61 => '61 meses',
        ];
        foreach ($casos as $meses => $esperado) {
            $this->assertSame($esperado, LeadContractPdfService::meses_en_letras($meses, LeadContractPdfService::PERMANENCIA_MESES_MAX_EN_LETRAS), 'Meses: ' . $meses);
        }

        // De 1 a 24 da lo mismo con cualquier tope: los valores de siempre no cambian.
        for ($meses = 1; $meses <= 24; $meses++) {
            $this->assertSame(
                LeadContractPdfService::meses_en_letras($meses),
                LeadContractPdfService::meses_en_letras($meses, LeadContractPdfService::PERMANENCIA_MESES_MAX_EN_LETRAS),
                'Meses: ' . $meses
            );
        }
        $this->assertSame('un (1) mes', LeadContractPdfService::meses_en_letras(1));
        $this->assertSame('veintiún (21) meses', LeadContractPdfService::meses_en_letras(21));
        $this->assertSame('veinticuatro (24) meses', LeadContractPdfService::meses_en_letras(24));

        // Sin tope explícito, pasado 24 sigue saliendo la cifra (los meses de IPC de siempre).
        $this->assertSame('36 meses', LeadContractPdfService::meses_en_letras(36));

        // En el contrato: plazo de 36 en letras, IPC de 36 como siempre (la cifra sola).
        $html = $this->normalizado($this->html($this->lead_con_contrato([
            'contract_modalidad'           => 'suscripcion',
            'contract_permanencia_meses'   => 36,
            'contract_meses_actualizacion' => 36,
        ])));
        $this->assertStringContainsString('plazo mínimo de <strong>treinta y seis (36) meses</strong>', $html);
        $this->assertStringContainsString('cada <strong>36 meses</strong>', $html);

        $this->assertStringContainsString(
            'plazo mínimo de <strong>sesenta (60) meses</strong>',
            $this->normalizado($this->html($this->lead_con_contrato([
                'contract_modalidad'         => 'suscripcion',
                'contract_permanencia_meses' => 60,
            ])))
        );
    }

    /**
     * 3. La cláusula 5.2 (consumos de terceros) va solo con Agentes, en las dos modalidades. Los
     * sistemas salen en el orden del catálogo, sin repetidos, y una key desconocida se ignora.
     *
     * @return void
     */
    public function test_consumos_de_terceros_solo_con_agentes_y_sistemas_en_orden(): void
    {
        foreach (['licencia', 'suscripcion'] as $modalidad) {
            $con_agentes = $this->html($this->lead_con_contrato([
                'contract_modalidad' => $modalidad,
                'contract_sistemas'  => ['gestion', 'agentes'],
            ]));

            $this->assertStringContainsString('5.2 — Consumos de terceros', $con_agentes, $modalidad);
            $this->assertStringContainsString(self::TEXTO_CONSUMOS_5_2, $this->normalizado($con_agentes), $modalidad);
            $this->assertStringContainsString('Sistemas contratados: <strong>ComercioCity Gestión y ComercioCity Agentes</strong>.', $con_agentes, $modalidad);

            $sin_agentes = $this->html($this->lead_con_contrato([
                'contract_modalidad' => $modalidad,
                'contract_sistemas'  => ['gestion', 'ecommerce'],
            ]));

            $this->assertStringNotContainsString('5.2 —', $sin_agentes, $modalidad);
            $this->assertStringNotContainsString('Meta Platforms', $sin_agentes, $modalidad);
        }

        // Orden canónico y sin repetidos, aunque se hayan tildado en otro orden.
        $desordenado = $this->html($this->lead_con_contrato([
            'contract_modalidad' => 'suscripcion',
            'contract_sistemas'  => ['agentes', 'ecommerce', 'gestion', 'agentes'],
        ]));
        $this->assertStringContainsString(
            'Sistemas contratados: <strong>ComercioCity Gestión, ComercioCity E-Commerce y ComercioCity Agentes</strong>.',
            $desordenado
        );

        // Una key desconocida se ignora y no rompe el contrato.
        $con_basura = $this->html($this->lead_con_contrato([
            'contract_modalidad' => 'licencia',
            'contract_sistemas'  => ['gestion', 'inventado'],
        ]));
        $this->assertStringContainsString('Sistemas contratados: <strong>ComercioCity Gestión</strong>.', $con_basura);
        $this->assertStringNotContainsString('inventado', $con_basura);

        // Con modalidad y sin sistemas, no hay renglón de sistemas (ni la cláusula 5.2).
        $sin_sistemas = $this->html($this->lead_con_contrato([
            'contract_modalidad' => 'licencia',
            'contract_sistemas'  => [],
        ]));
        $this->assertStringNotContainsString('Sistemas contratados', $sin_sistemas);
        $this->assertStringNotContainsString('5.2 —', $sin_sistemas);
        $this->assertStringContainsString('5.1 — Mensualidad de por vida', $sin_sistemas);
    }

    /**
     * 4. El PDF real (dompdf incluido) de una Suscripción con los tres sistemas se genera, para un
     * lead y para un cliente.
     *
     * @return void
     */
    public function test_el_pdf_de_suscripcion_con_los_tres_sistemas_se_genera(): void
    {
        $lead = $this->lead_con_contrato([
            'contract_modalidad'         => 'suscripcion',
            'contract_sistemas'          => ['gestion', 'ecommerce', 'agentes'],
            'contract_permanencia_meses' => 12,
        ]);

        $pdf = LeadContractPdfService::generate($lead, false);
        $this->assertStringStartsWith('%PDF', $pdf, 'dompdf no generó un PDF.');

        $client = $this->crear_cliente([
            'contract_client_name'      => 'María López',
            'contract_currency'         => 'USD',
            'contract_precio_licencia'  => '2200',
            'contract_mensualidad_base' => '30000',
            'contract_modalidad'        => 'licencia',
            'contract_sistemas'         => ['gestion', 'ecommerce'],
        ]);
        $this->assertSame('licencia', LeadContractPdfService::datos_de_vista($client)['modalidad']);
        $this->assertStringStartsWith('%PDF', LeadContractPdfService::generate($client, false));
    }

    /**
     * 5a. El PUT genérico del lead (el de la pestaña Contrato) persiste las tres. Una SPA vieja que
     * no las manda no las toca, y una clave que la API no conoce se ignora sin error.
     *
     * @return void
     */
    public function test_el_put_del_lead_persiste_las_tres(): void
    {
        $this->admin_logueado();

        $lead = $this->lead_con_contrato();
        $lead->save();

        $this->putJson('/api/admin/lead/' . $lead->id, [
            'contract_modalidad'         => 'suscripcion',
            'contract_sistemas'          => ['gestion', 'agentes'],
            'contract_permanencia_meses' => 18,
        ])->assertStatus(200);

        $lead->refresh();
        $this->assertSame('suscripcion', $lead->contract_modalidad);
        $this->assertSame(['gestion', 'agentes'], $lead->contract_sistemas);
        $this->assertSame(18, $lead->contract_permanencia_meses);

        // Una SPA vieja: guarda el contrato sin las claves nuevas y con una que no existe.
        $this->putJson('/api/admin/lead/' . $lead->id, [
            'contract_client_name'      => 'Otro nombre',
            'contract_clave_que_no_hay' => 'basura',
        ])->assertStatus(200);

        $lead->refresh();
        $this->assertSame('Otro nombre', $lead->contract_client_name);
        $this->assertSame('suscripcion', $lead->contract_modalidad, 'Lo que no vino no se toca.');
        $this->assertSame(['gestion', 'agentes'], $lead->contract_sistemas);
        $this->assertSame(18, $lead->contract_permanencia_meses);

        // Volver a "sin modalidad" es posible: null explícito.
        $this->putJson('/api/admin/lead/' . $lead->id, ['contract_modalidad' => null])->assertStatus(200);
        $this->assertNull($lead->refresh()->contract_modalidad);
    }

    /**
     * 5b. El PUT del contrato del cliente valida las tres contra listas cerradas y el GET las
     * devuelve.
     *
     * @return void
     */
    public function test_el_put_del_contrato_del_cliente_valida_y_guarda_las_tres(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();
        $url = '/api/admin/client/' . $client->id . '/contrato';

        // Antes de cargar nada, las tres vienen en null.
        $vacio = $this->getJson($url);
        $vacio->assertStatus(200);
        $this->assertNull($vacio->json('contrato.contract_modalidad'));
        $this->assertNull($vacio->json('contrato.contract_sistemas'));
        $this->assertNull($vacio->json('contrato.contract_permanencia_meses'));

        // Validación.
        $this->putJson($url, ['contract_modalidad' => 'otra'])->assertStatus(422);
        $this->putJson($url, ['contract_sistemas' => ['gestion', 'inventado']])->assertStatus(422);
        $this->putJson($url, ['contract_sistemas' => 'gestion'])->assertStatus(422);
        $this->putJson($url, ['contract_permanencia_meses' => 0])->assertStatus(422);
        $this->putJson($url, ['contract_permanencia_meses' => 61])->assertStatus(422);
        $this->assertNull($client->fresh()->contract_modalidad, 'Un 422 no escribe nada.');

        // Válido.
        $response = $this->putJson($url, [
            'contract_modalidad'         => 'suscripcion',
            'contract_sistemas'          => ['gestion', 'ecommerce', 'agentes'],
            'contract_permanencia_meses' => 24,
        ]);
        $response->assertStatus(200);
        $this->assertSame('suscripcion', $response->json('contrato.contract_modalidad'));
        $this->assertSame(['gestion', 'ecommerce', 'agentes'], $response->json('contrato.contract_sistemas'));
        $this->assertSame(24, $response->json('contrato.contract_permanencia_meses'));

        $get = $this->getJson($url);
        $get->assertStatus(200);
        $this->assertSame('suscripcion', $get->json('contrato.contract_modalidad'));
        $this->assertSame(['gestion', 'ecommerce', 'agentes'], $get->json('contrato.contract_sistemas'));
        $this->assertSame(24, $get->json('contrato.contract_permanencia_meses'));

        // Un guardado de la SPA vieja (sin las claves) no las toca.
        $this->putJson($url, ['contract_client_name' => 'María López'])->assertStatus(200);
        $client->refresh();
        $this->assertSame('suscripcion', $client->contract_modalidad);
        $this->assertSame(24, $client->contract_permanencia_meses);

        // Y el PDF del cliente sale con la modalidad guardada.
        $this->assertStringContainsString('Modalidad: <strong>Suscripción</strong>', $this->html($client));
    }

    /**
     * 5c. Al promover, la copia del lead al cliente lleva las tres. Un `[]` de sistemas solo no
     * cuenta como contrato cargado; una modalidad elegida sí.
     *
     * @return void
     */
    public function test_copiar_desde_lead_copia_las_tres(): void
    {
        $service = app(ClientContratoService::class);

        $lead = $this->lead_con_contrato([
            'contract_modalidad'         => 'licencia',
            'contract_sistemas'          => ['gestion', 'ecommerce'],
            'contract_permanencia_meses' => 12,
        ]);
        $lead->save();
        $lead->refresh();

        $client = $this->crear_cliente();

        $this->assertTrue($service->copiar_desde_lead($lead, $client));

        $client->refresh();
        $this->assertSame('licencia', $client->contract_modalidad);
        $this->assertSame(['gestion', 'ecommerce'], $client->contract_sistemas);
        $this->assertSame(12, $client->contract_permanencia_meses);

        // Lo que cuenta como "contrato cargado".
        $solo_sistemas_vacios = new Lead();
        $solo_sistemas_vacios->contact_name = 'Sin contrato';
        $solo_sistemas_vacios->company_name = 'Sin contrato ' . uniqid();
        $solo_sistemas_vacios->status = 'cerrado_ganado';
        $solo_sistemas_vacios->contract_sistemas = [];
        $solo_sistemas_vacios->save();
        $this->assertFalse($service->lead_tiene_contrato($solo_sistemas_vacios->refresh()), 'Un [] de sistemas no es un contrato.');

        $solo_modalidad = new Lead();
        $solo_modalidad->contact_name = 'Solo modalidad';
        $solo_modalidad->company_name = 'Solo modalidad ' . uniqid();
        $solo_modalidad->status = 'cerrado_ganado';
        $solo_modalidad->contract_modalidad = 'suscripcion';
        $solo_modalidad->save();
        $this->assertTrue($service->lead_tiene_contrato($solo_modalidad->refresh()), 'Una modalidad elegida es un contrato empezado.');
    }

    /**
     * 6. El endpoint de análisis de Claude (`GET claude/leads?include=contrato`) devuelve las tres,
     * con los sistemas como JSON de verdad y el plazo como entero.
     *
     * @return void
     */
    public function test_claude_leads_con_include_contrato_trae_las_tres(): void
    {
        config(['services.claude_task_ingest.key' => 'clave-de-prueba-modalidad']);

        $lead = $this->lead_con_contrato([
            'contract_modalidad'         => 'suscripcion',
            'contract_sistemas'          => ['gestion', 'agentes'],
            'contract_permanencia_meses' => 24,
        ]);
        $lead->save();

        $respuesta = $this->withHeaders([
            'X-Claude-Task-Key' => 'clave-de-prueba-modalidad',
            'Accept'            => 'application/json',
        ])->getJson('/api/claude/leads?lead_ids[]=' . $lead->id . '&include=contrato');

        $respuesta->assertStatus(200);
        $fila = $respuesta->json('data.0');

        $this->assertSame('suscripcion', $fila['contract_modalidad']);
        $this->assertSame(['gestion', 'agentes'], $fila['contract_sistemas'], 'El JSON se decodifica, no viaja como string.');
        $this->assertSame(24, $fila['contract_permanencia_meses']);
    }
}
