<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use Barryvdh\DomPDF\Facade as Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Genera el PDF del contrato ComercioCity a partir de los campos `contract_*`.
 *
 * Usa dompdf (barryvdh/laravel-dompdf) y la vista {@see resources/views/emails/lead/contract.blade.php}.
 *
 * Desde la misión modulo-cobranzas (18/9/2026) acepta un `Lead` O un `Client`: las columnas
 * `contract_*` existen con el mismo nombre y el mismo cast en los dos modelos, así que el mismo
 * código genera el contrato de un prospecto y el de un cliente ya promovido. Por eso `generate()`
 * y sus ayudantes NO tipan el modelo (PHP 7.4 no tiene union types) y lo documentan como
 * `Lead|Client`. El nombre de la clase se conserva por los llamadores existentes.
 *
 * Desde la misión precios-dos-modalidades (13/10/2026) el contrato tiene MODALIDAD
 * (`contract_modalidad`: 'licencia' | 'suscripcion'), sistemas contratados y plazo mínimo, y la
 * vista agrega la letra chica de cada modalidad. 🔴 Con `contract_modalidad` en null el PDF sale
 * EXACTAMENTE como salía antes de esa misión, carácter por carácter: es el contrato de todo lead y
 * cliente que ya existía, y lo que ya se firmó no puede cambiar por regenerarlo.
 */
class LeadContractPdfService
{
    /**
     * Meses entre actualizaciones por IPC cuando el modelo no trae el dato (o trae 0). Es el
     * "seis (6) meses" que el contrato tuvo siempre como texto fijo: un lead viejo sin la columna
     * cargada tiene que seguir generando exactamente el mismo contrato.
     */
    const MESES_ACTUALIZACION_DEFAULT = 6;

    /**
     * Plazo mínimo de la Suscripción, en meses, cuando el contrato no trae el dato (o trae 0).
     * Es el plazo con el que se diseñó la modalidad (misión precios-dos-modalidades, 13/10/2026).
     */
    const PERMANENCIA_MESES_DEFAULT = 12;

    /**
     * Hasta cuántos meses se escribe en letras el plazo mínimo. Es el mismo techo que valida el PUT
     * del contrato del cliente (1 a 60): todo plazo cargable sale en la forma notarial.
     */
    const PERMANENCIA_MESES_MAX_EN_LETRAS = 60;

    /**
     * Hasta cuántos meses escribe en letras `meses_en_letras()` cuando el llamador no dice otra
     * cosa. Es el techo histórico (los meses de IPC): pasado 24 sale la cifra sola, y así lo fija
     * `ContratoDelClienteTest` ("30 meses"). Cambiarlo cambiaría el texto de IPC de contratos ya
     * emitidos con 25 a 60 meses.
     */
    const MESES_EN_LETRAS_TOPE_DEFAULT = 24;

    /** Modalidad Licencia: entrada alta y mensualidad de por vida que solo se ajusta por IPC. */
    const MODALIDAD_LICENCIA = 'licencia';

    /** Modalidad Suscripción: entrada baja, plazo mínimo y mensualidad que después sigue la lista. */
    const MODALIDAD_SUSCRIPCION = 'suscripcion';

    /**
     * Construye datos del contrato, renderiza la vista y devuelve el PDF como string binario.
     *
     * @param Lead|Client $contrato       Modelo con los campos `contract_*` cargados.
     * @param bool        $incluir_firma  Si estampa la firma del PRESTADOR. Default true para que
     *                                    cualquier llamador viejo siga andando igual.
     *
     * @return string Contenido binario del PDF.
     */
    public static function generate($contrato, bool $incluir_firma = true): string
    {
        // Array de datos para la vista Blade del contrato.
        $datos = self::build_contract_data($contrato, $incluir_firma);

        // Instancia PDF en A4 con márgenes definidos en la vista (@page).
        $pdf = Pdf::loadView('emails.lead.contract', $datos);
        $pdf->setPaper('a4', 'portrait');

        return $pdf->output();
    }

    /**
     * Las variables que recibe la vista del contrato, sin renderizar nada.
     *
     * Existe para poder mirar QUÉ va a decir el contrato sin pasar por dompdf (los tests de la
     * misión modulo-cobranzas verifican el texto de los meses de actualización así, y una
     * pantalla de previsualización podría hacer lo mismo). Es `build_contract_data()` expuesto;
     * la firma va apagada por defecto porque acá nadie está imprimiendo.
     *
     * @param Lead|Client $contrato
     * @param bool        $incluir_firma
     *
     * @return array<string, mixed>
     */
    public static function datos_de_vista($contrato, bool $incluir_firma = false): array
    {
        return self::build_contract_data($contrato, $incluir_firma);
    }

    /**
     * Arma el array de variables del contrato a partir del modelo (Lead o Client).
     *
     * @param Lead|Client $contrato
     * @param bool        $incluir_firma Si estampa la firma del PRESTADOR en la celda de firmas.
     *
     * @return array<string, mixed>
     */
    protected static function build_contract_data($contrato, bool $incluir_firma = true): array
    {
        // El modelo se lee bajo el nombre histórico para no reescribir cada línea de abajo.
        $lead = $contrato;

        // Moneda y montos del pago único.
        $moneda = $lead->contract_currency ?? 'USD';
        $mensualidad_moneda = $lead->contract_mensualidad_moneda ?? 'ARS';

        // Cada cuántos meses se actualiza la mensualidad por IPC (sección 5). 0 o nulo = default.
        $meses_actualizacion = (int) ($lead->contract_meses_actualizacion ?? 0);
        if ($meses_actualizacion <= 0) {
            $meses_actualizacion = self::MESES_ACTUALIZACION_DEFAULT;
        }

        // Usuarios y perfiles ecommerce (enteros con default 0).
        $usuarios_extra = (int) ($lead->contract_usuarios_extra ?? 0);
        $perfiles_ecommerce = (int) ($lead->contract_perfiles_ecommerce ?? 0);

        // Cuotas de financiación con fechas legibles en español.
        $financiacion_raw = $lead->contract_financiacion ?? [];
        $financiacion_filas = self::normalize_financiacion_rows($financiacion_raw);

        // Cláusulas particulares del contrato (sección 8), normalizadas para la vista.
        $clausulas_raw = $lead->contract_clausulas_particulares ?? [];
        $clausulas_filas = self::normalize_clausulas_rows($clausulas_raw);

        // Total mensual: base + extras de usuarios + perfiles ecommerce.
        $total_mensual = self::calculate_monthly_total(
            $lead->contract_mensualidad_base,
            $usuarios_extra,
            $lead->contract_precio_usuario_extra,
            $perfiles_ecommerce,
            $lead->contract_precio_perfil_ecommerce
        );

        // Modalidad del contrato: null = contrato histórico (la vista no cambia ni un carácter).
        $modalidad = self::normalize_modalidad($lead->contract_modalidad);

        // Sistemas contratados, como etiquetas y en el orden canónico del catálogo del cotizador.
        $sistemas_raw = $lead->contract_sistemas;
        $sistemas_contratados = self::normalize_sistemas($sistemas_raw);
        $tiene_agentes = is_array($sistemas_raw) && in_array('agentes', $sistemas_raw, true);

        // Plazo mínimo de la Suscripción (sección 5.1). 0 o nulo = default de 12 meses.
        $permanencia_meses = (int) ($lead->contract_permanencia_meses ?? 0);
        if ($permanencia_meses <= 0) {
            $permanencia_meses = self::PERMANENCIA_MESES_DEFAULT;
        }

        $desglose = is_array($lead->contract_precios_sistemas)
            ? ContractPreciosService::resumen($lead->contract_precios_sistemas, is_array($sistemas_raw) ? $sistemas_raw : [], (float) ($lead->contract_descuento_conjunto ?? 300))
            : null;

        // El plan solo se muestra si el contrato tiene desglose y Gestión entre los sistemas elegidos.
        $plan_gestion = $desglose !== null && is_array($sistemas_raw) && in_array('gestion', $sistemas_raw, true)
            ? ContractPreciosService::plan_gestion($lead->contract_plan_gestion)
            : null;

        $pago_anual = (bool) $lead->contract_pago_anual;
        $base_numerica = $desglose !== null ? $desglose['mensualidad'] : ClientContratoService::monto_desde_texto($lead->contract_mensualidad_base);
        $precio_extra_numerico = ClientContratoService::monto_desde_texto($lead->contract_precio_usuario_extra);
        $total_usuarios_extra = round($usuarios_extra * $precio_extra_numerico, 2);
        $total_perfiles = round($perfiles_ecommerce * ClientContratoService::monto_desde_texto($lead->contract_precio_perfil_ecommerce), 2);
        $adicionales_mensuales = round($total_usuarios_extra + $total_perfiles, 2);
        if ($desglose !== null || $pago_anual) {
            $total_mensual = round($base_numerica + $adicionales_mensuales, 2);
        }
        $anual = $pago_anual ? ContractPreciosService::resumen_anual((float) $base_numerica, (float) ($lead->contract_descuento_anual ?? 20)) : null;

        return array_merge(self::build_signature_data($lead, $incluir_firma), [
            'desglose_precios' => $desglose,
            'plan_gestion' => $plan_gestion,
            'mes_gratis' => (bool) $lead->contract_mes_gratis,
            'pago_anual' => $pago_anual,
            'abono_anual' => $anual,
            'total_usuarios_extra' => $total_usuarios_extra,
            'total_perfiles' => $total_perfiles,
            'adicionales_mensuales' => $adicionales_mensuales,
            'precio_usuario_extra_formateado' => $lead->contract_precio_usuario_extra === null || $lead->contract_precio_usuario_extra === '' ? null : self::format_amount_for_display($precio_extra_numerico),
            'cc_nombre_fantasia'   => 'ComercioCity',
            'cc_razon_social'      => 'Lucas González',
            'cc_cuit'              => '20-42354898-4',

            'cliente_nombre'            => $lead->contract_client_name,
            'cliente_razon_social'      => $lead->contract_client_razon_social,
            'cliente_cuit'              => $lead->contract_client_cuit,

            'moneda'               => $moneda,
            'precio_licencia'      => $desglose !== null ? self::format_amount_for_display($desglose['total']) : $lead->contract_precio_licencia,
            'fecha_emision'        => self::format_contract_date($lead->contract_fecha_emision) ?? now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            'fecha_primer_pago_unico' => self::format_contract_date($lead->contract_fecha_primer_pago_unico),

            'financiacion'         => $financiacion_filas,

            'clausulas'            => $clausulas_filas,

            'mensualidad_moneda'          => $mensualidad_moneda,
            'mensualidad_base'            => $desglose !== null || $pago_anual ? self::format_amount_for_display($base_numerica) : $lead->contract_mensualidad_base,
            'usuarios_incluidos'          => $lead->contract_usuarios_incluidos ?? 1,
            'usuarios_extra'              => $usuarios_extra,
            'precio_usuario_extra'        => $lead->contract_precio_usuario_extra,
            'perfiles_ecommerce'          => $perfiles_ecommerce,
            'precio_perfil_ecommerce'     => $lead->contract_precio_perfil_ecommerce,
            'fecha_primer_pago_mensual'   => self::format_contract_date($lead->contract_fecha_primer_pago_mensual),

            'total_mensual'               => $total_mensual,
            'total_mensual_formateado'    => self::format_amount_for_display($total_mensual),

            // Sección 5: "cada seis (6) meses" pasa a ser variable. El texto va armado desde acá
            // (número en letras, cifra y unidad) para que la vista solo lo imprima.
            'meses_actualizacion'         => $meses_actualizacion,
            'meses_actualizacion_texto'   => self::meses_en_letras($meses_actualizacion),

            /* Modalidad del contrato (misión precios-dos-modalidades, 13/10/2026). Las seis claves
             * están SIEMPRE, aunque valgan null o vacío: la vista no usa `@isset` (ver
             * build_signature_data()), y una clave que falta tira `Undefined variable` y el
             * contrato entero deja de generarse. La vista decide con `modalidad`: en null no
             * imprime nada de esto, ni siquiera los sistemas contratados.
             *
             * `tiene_agentes` decide la cláusula 5.2 (consumos de terceros: los mensajes de
             * WhatsApp que cobra Meta), que solo tiene sentido si se contrató Agentes. */
            'modalidad'                   => $modalidad,
            'sistemas_contratados'        => $sistemas_contratados,
            'sistemas_contratados_texto'  => self::unir_en_castellano($sistemas_contratados),
            'tiene_agentes'               => $tiene_agentes,
            'permanencia_meses'           => $permanencia_meses,
            // En letras hasta 60, el mismo techo que valida el PUT ("treinta y seis (36) meses").
            'permanencia_meses_texto'     => self::meses_en_letras($permanencia_meses, self::PERMANENCIA_MESES_MAX_EN_LETRAS),
        ]);
    }

    /**
     * La modalidad del contrato, o null si no hay una válida.
     *
     * 🔴 Cualquier valor que no sea exactamente 'licencia' o 'suscripcion' (un texto editado a
     * mano contra la base, un PUT del lead con basura: el PUT genérico no valida) se trata como
     * null, o sea como el contrato histórico. Es la opción segura: el PDF de siempre es un contrato
     * que ya se firmó muchas veces; una modalidad adivinada sería letra chica que nadie eligió.
     *
     * @param mixed $modalidad_raw Lo guardado en `contract_modalidad`.
     *
     * @return string|null 'licencia' | 'suscripcion' | null.
     */
    protected static function normalize_modalidad($modalidad_raw): ?string
    {
        if ($modalidad_raw === self::MODALIDAD_LICENCIA || $modalidad_raw === self::MODALIDAD_SUSCRIPCION) {
            return $modalidad_raw;
        }

        return null;
    }

    /**
     * Las etiquetas de los sistemas contratados, en el orden del catálogo (gestión → e-commerce →
     * agentes) y sin repetidos.
     *
     * Recorre `CotizadorSettings::SISTEMAS` y no lo guardado: así el orden es siempre el mismo
     * aunque la SPA mande las keys en el orden en que se tildaron, una key repetida sale una vez y
     * una key desconocida (un sistema que ya no existe, basura) se ignora sin romper el contrato.
     * La etiqueta sale del catálogo, que es la única fuente de los nombres comerciales.
     *
     * @param mixed $sistemas_raw JSON decodificado (array de keys) o cualquier otra cosa.
     *
     * @return array<int, string> Etiquetas; `[]` si no hay ninguna válida.
     */
    protected static function normalize_sistemas($sistemas_raw): array
    {
        if (!is_array($sistemas_raw)) {
            return [];
        }

        $etiquetas = [];
        foreach (CotizadorSettings::SISTEMAS as $key => $etiqueta) {
            if (in_array($key, $sistemas_raw, true)) {
                $etiquetas[] = $etiqueta;
            }
        }

        return $etiquetas;
    }

    /**
     * Une una lista como se escribe en castellano: "A", "A y B", "A, B y C".
     *
     * @param array<int, string> $partes
     *
     * @return string Vacío si la lista está vacía.
     */
    protected static function unir_en_castellano(array $partes): string
    {
        if (count($partes) <= 1) {
            return implode('', $partes);
        }

        $ultima = array_pop($partes);

        return implode(', ', $partes) . ' y ' . $ultima;
    }

    /**
     * Cantidad de meses en letras y cifra, con la unidad, como se escribe en un contrato:
     * 1 → "un (1) mes", 6 → "seis (6) meses", 12 → "doce (12) meses", 31 → "treinta y un (31)
     * meses". Fuera del rango que se escribe en letras sale solo la cifra ("30 meses"), que es
     * legible aunque no sea la forma notarial.
     *
     * Sabe escribir de 1 a 60 (desde la misión precios-dos-modalidades, 13/10/2026: el plazo mínimo
     * de la Suscripción se valida hasta 60), pero por defecto escribe en letras solo hasta 24, que
     * es lo que hizo siempre: los meses de IPC de un contrato ya emitido no pueden cambiar de texto
     * al regenerarlo. El llamador que necesita más (el plazo mínimo) pasa `$tope_en_letras`.
     *
     * Delante de "meses" el uno se apocopa en todas las decenas, igual que "veintiún": "treinta y
     * un", "cuarenta y un", "cincuenta y un".
     *
     * @param int $meses
     * @param int $tope_en_letras Hasta cuántos meses se escribe en letras (máximo 60).
     *
     * @return string
     */
    public static function meses_en_letras(int $meses, int $tope_en_letras = self::MESES_EN_LETRAS_TOPE_DEFAULT): string
    {
        $letras = [
            1 => 'un', 2 => 'dos', 3 => 'tres', 4 => 'cuatro', 5 => 'cinco', 6 => 'seis',
            7 => 'siete', 8 => 'ocho', 9 => 'nueve', 10 => 'diez', 11 => 'once', 12 => 'doce',
            13 => 'trece', 14 => 'catorce', 15 => 'quince', 16 => 'dieciséis', 17 => 'diecisiete',
            18 => 'dieciocho', 19 => 'diecinueve', 20 => 'veinte', 21 => 'veintiún', 22 => 'veintidós',
            23 => 'veintitrés', 24 => 'veinticuatro', 25 => 'veinticinco', 26 => 'veintiséis',
            27 => 'veintisiete', 28 => 'veintiocho', 29 => 'veintinueve',
        ];

        // De 30 a 60: la decena sola, o "decena y unidad" con el uno apocopado ("treinta y un").
        $decenas = [30 => 'treinta', 40 => 'cuarenta', 50 => 'cincuenta', 60 => 'sesenta'];
        foreach ($decenas as $decena => $nombre_decena) {
            $letras[$decena] = $nombre_decena;
            if ($decena === 60) {
                break;
            }
            for ($unidad_numero = 1; $unidad_numero <= 9; $unidad_numero++) {
                $letras[$decena + $unidad_numero] = $nombre_decena . ' y ' . $letras[$unidad_numero];
            }
        }

        $unidad = $meses === 1 ? 'mes' : 'meses';

        if ($meses > $tope_en_letras || ! isset($letras[$meses])) {
            return $meses . ' ' . $unidad;
        }

        return $letras[$meses] . ' (' . $meses . ') ' . $unidad;
    }

    /**
     * Arma las cinco claves de la firma del PRESTADOR que consume la vista.
     *
     * Las cinco están SIEMPRE presentes, aunque valgan null. Nunca `@isset` en la vista: si un
     * día faltara una clave, dompdf tira `Undefined variable` y el contrato entero deja de
     * generarse. Una clave que siempre está y a veces vale null no puede producir eso.
     *
     * Y toda la lectura de la firma va envuelta en try/catch: si el archivo está corrupto, si
     * `getimagesize` devuelve false o si el disco no responde, se loguea y el contrato sale
     * SIN firma, no falla. Un contrato sin firma es un inconveniente; un contrato que no se
     * puede generar frena una venta.
     *
     * @param Lead|Client $contrato      Solo para poder identificar el contrato en el log.
     * @param bool        $incluir_firma
     *
     * @return array<string, mixed>
     */
    protected static function build_signature_data($contrato, bool $incluir_firma): array
    {
        // Para el log: de qué modelo es el contrato que salió sin firma.
        $contexto_log = [
            'lead_id'   => $contrato instanceof Lead ? $contrato->id : null,
            'client_id' => $contrato instanceof Client ? $contrato->id : null,
        ];

        $sin_firma = [
            'firma_prestador_src'                => null,
            'firma_prestador_ancho_pt'           => null,
            'firma_prestador_alto_pt'            => null,
            'firma_prestador_margen_superior_pt' => null,
            'firma_prestador_separacion_pt'      => ContractSignatureService::SEPARACION_PT,
        ];

        if (!$incluir_firma) {
            return $sin_firma;
        }

        try {
            if (!ContractSignatureService::existe()) {
                return $sin_firma;
            }

            $medidas = ContractSignatureService::medidas_en_puntos();
            $src = ContractSignatureService::data_uri();

            if ($medidas === null || $src === null) {
                // 🔴 Este return NO puede salir mudo. Los dos casos que el plan nombra —archivo
                // corrupto y getimagesize devolviendo false— no lanzan excepción, así que no
                // pasan por el catch de abajo: salen por acá. Sin este warning el contrato sale
                // sin firma, la generación devuelve 200 y en los logs no queda una sola línea,
                // que es exactamente la clase de error que APRENDER_NO_PARCHEAR.md ya tiene
                // documentada en este proyecto: la condición de error que nunca llega a nadie.
                Log::warning('LeadContractPdfService: la firma del PRESTADOR está cargada pero no se pudo leer (archivo corrupto, extensión no soportada o getimagesize sin datos). El contrato sale sin firma.', array_merge([
                    'ruta' => ContractSignatureService::ruta_relativa(),
                ], $contexto_log));

                return $sin_firma;
            }

            return [
                'firma_prestador_src'                => $src,
                'firma_prestador_ancho_pt'           => $medidas['ancho_pt'],
                'firma_prestador_alto_pt'            => $medidas['alto_pt'],
                'firma_prestador_margen_superior_pt' => $medidas['margen_superior_pt'],
                'firma_prestador_separacion_pt'      => ContractSignatureService::SEPARACION_PT,
            ];
        } catch (\Throwable $error) {
            Log::warning('LeadContractPdfService: no se pudo leer la firma del PRESTADOR, el contrato sale sin firma. ' . $error->getMessage(), $contexto_log);

            return $sin_firma;
        }
    }

    /**
     * Normaliza filas de financiación para la tabla del PDF.
     *
     * @param mixed $financiacion_raw JSON decodificado o array.
     *
     * @return array<int, array{monto: string, fecha: string}>
     */
    protected static function normalize_financiacion_rows($financiacion_raw): array
    {
        if (!is_array($financiacion_raw)) {
            return [];
        }

        $filas = [];
        foreach ($financiacion_raw as $cuota) {
            if (!is_array($cuota)) {
                continue;
            }
            $monto = isset($cuota['monto']) ? (string) $cuota['monto'] : '';
            $fecha_raw = $cuota['fecha'] ?? null;
            $fecha = $fecha_raw ? (self::format_contract_date($fecha_raw) ?? (string) $fecha_raw) : '';
            $filas[] = [
                'monto' => $monto,
                'fecha' => $fecha,
            ];
        }

        return $filas;
    }

    /**
     * Normaliza filas de cláusulas particulares para la sección 8 del PDF.
     *
     * Tolera datos sucios: descarta filas que no sean array y descarta también las filas
     * cuyo texto quede vacío después del trim (una cláusula sin cuerpo no tiene sentido en
     * el contrato y dejaría un número de sección huérfano). El título es opcional: una fila
     * con texto y sin título se conserva igual.
     *
     * @param mixed $clausulas_raw JSON decodificado o array.
     *
     * @return array<int, array{titulo: string, texto: string}>
     */
    protected static function normalize_clausulas_rows($clausulas_raw): array
    {
        if (!is_array($clausulas_raw)) {
            return [];
        }

        $filas = [];
        foreach ($clausulas_raw as $clausula) {
            if (!is_array($clausula)) {
                continue;
            }
            $titulo = isset($clausula['titulo']) ? trim((string) $clausula['titulo']) : '';
            $texto = isset($clausula['texto']) ? trim((string) $clausula['texto']) : '';

            // Descarta la fila si el texto quedó vacío: sin cuerpo, la cláusula no aporta nada.
            if ($texto === '') {
                continue;
            }

            $filas[] = [
                'titulo' => $titulo,
                'texto'  => $texto,
            ];
        }

        return $filas;
    }

    /**
     * Formatea una fecha de contrato en español (día de mes de año).
     *
     * @param mixed $value Fecha en string, Carbon o null.
     *
     * @return string|null
     */
    protected static function format_contract_date($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return Carbon::parse($value)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
    }

    /**
     * Suma mensualidad base + usuarios extra + perfiles ecommerce.
     *
     * @param string|null $mensualidad_base
     * @param int         $usuarios_extra
     * @param string|null $precio_usuario_extra
     * @param int         $perfiles_ecommerce
     * @param string|null $precio_perfil_ecommerce
     *
     * @return float
     */
    protected static function calculate_monthly_total(
        $mensualidad_base,
        int $usuarios_extra,
        $precio_usuario_extra,
        int $perfiles_ecommerce,
        $precio_perfil_ecommerce
    ): float {
        $total = self::parse_numeric_amount($mensualidad_base);

        if ($usuarios_extra > 0) {
            $total += $usuarios_extra * self::parse_numeric_amount($precio_usuario_extra);
        }

        if ($perfiles_ecommerce > 0) {
            $total += $perfiles_ecommerce * self::parse_numeric_amount($precio_perfil_ecommerce);
        }

        return $total;
    }

    /**
     * Convierte un monto almacenado como string a float para cálculos.
     *
     * ⚠️ Toma el punto como decimal ('1.500' → 1,5). Es la regla histórica del PDF y se deja como
     * está porque cambiarla toca contratos ya emitidos; las cuotas de licencia del módulo de
     * cobranzas NO la usan (ver `ClientContratoService::monto_desde_texto()`, que lee el formato
     * argentino). Corregirla acá es otra misión.
     *
     * @param mixed $value
     *
     * @return float
     */
    protected static function parse_numeric_amount($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^0-9.,]/', '', (string) $value);
        $normalized = str_replace(',', '.', $normalized);

        return (float) $normalized;
    }

    /**
     * Formatea un monto numérico para mostrar en el PDF (sin decimales si es entero).
     *
     * @param float $amount
     *
     * @return string
     */
    protected static function format_amount_for_display(float $amount): string
    {
        if (floor($amount) == $amount) {
            return number_format($amount, 0, ',', '.');
        }

        return number_format($amount, 2, ',', '.');
    }
}
