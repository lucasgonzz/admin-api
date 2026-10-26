<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Contrato ComercioCity</title>
    <style>
        @page {
            margin: 2cm 2.5cm 2.5cm 2.5cm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            color: #1a1a1a;
            line-height: 1.45;
        }
        .header-title {
            font-size: 22pt;
            font-weight: bold;
            color: #1a3a5c;
            letter-spacing: 2px;
            margin: 0 0 4px 0;
        }
        .header-subtitle {
            font-size: 12pt;
            color: #333333;
            margin: 0 0 12px 0;
        }
        .header-line {
            border: none;
            border-top: 3px solid #1a3a5c;
            margin: 0 0 16px 0;
        }
        .fecha-emision {
            text-align: right;
            font-size: 10pt;
            color: #444444;
            margin-bottom: 20px;
        }
        h2.section-title {
            font-size: 12pt;
            color: #1a3a5c;
            margin: 22px 0 10px 0;
            padding-bottom: 4px;
            border-bottom: 1px solid #1a3a5c;
        }
        p {
            margin: 0 0 10px 0;
            text-align: justify;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0 14px 0;
            font-size: 10pt;
        }
        table.data-table th {
            background-color: #1a3a5c;
            color: #ffffff;
            padding: 8px 10px;
            text-align: left;
            font-weight: bold;
        }
        table.data-table td {
            padding: 7px 10px;
            vertical-align: top;
            border: 1px solid #c5d4e3;
        }
        table.data-table tr.row-alt td {
            background-color: #e8f0f7;
        }
        table.data-table tr.row-white td {
            background-color: #ffffff;
        }
        .etapa-title {
            font-weight: bold;
            color: #1a3a5c;
            margin: 12px 0 4px 0;
        }
        .etapa-desc {
            margin: 0 0 8px 0;
            padding-left: 0;
        }
        .firmas-table {
            width: 100%;
            /* fixed obliga a dompdf a respetar el 50% de cada celda. Sin esto reparte las columnas
               segun el contenido y, con una razon social larga del cliente, las dos lineas de firma
               quedan de distinto largo y descentradas una respecto de la otra. */
            table-layout: fixed;
            margin-top: 36px;
            page-break-inside: avoid;
        }
        .firma-col {
            width: 50%;
            text-align: center;
            vertical-align: top;
            padding: 0 16px;
        }
        .firma-linea {
            /* 1pt y no 1px: un trazo de 0.75pt es el mas fino que dibuja un visor de PDF y a zooms
               no enteros el antialiasing lo parte en tramos, y se lee como una linea torcida. */
            border-top: 1pt solid #333333;
            width: 80%;
            margin: 64px auto 8px auto;
        }
        .firma-imagen-caja {
            text-align: center;
        }
        .firma-rol {
            font-weight: bold;
            color: #1a3a5c;
            margin-top: 8px;
        }
        .footer {
            margin-top: 32px;
            padding-top: 12px;
            border-top: 2px solid #1a3a5c;
            text-align: center;
            font-size: 9pt;
            color: #666666;
        }
        .total-row td {
            font-weight: bold;
            background-color: #d0e0ef !important;
        }
        .clausula-particular {
            page-break-inside: avoid;
            margin-bottom: 12px;
        }
        .clausula-titulo {
            font-weight: bold;
            color: #1a3a5c;
            margin: 14px 0 4px 0;
        }
        .clausula-prelacion {
            font-style: italic;
            color: #444444;
        }
@if($desglose_precios !== null || $pago_anual)
        h2.section-title, .etapa-title { page-break-after: avoid; }
@endif
    </style>
</head>
<body>

    {{-- Encabezado.
         🔴 Modalidad del contrato (misión precios-dos-modalidades, 13/10/2026): con `$modalidad`
         en null este archivo tiene que imprimir EXACTAMENTE lo mismo que antes de esa misión,
         carácter por carácter, porque es el contrato de todo lead y cliente que ya existía. Por eso
         las directivas de la modalidad (if / elseif / else / endif) van en la columna 0 y solas en
         su línea: Blade las convierte en código PHP y PHP se come el salto de línea que sigue a la
         etiqueta de cierre, así que una directiva sin sangría no deja rastro en la salida. Con
         sangría dejaría los espacios, y un comentario nuevo en una línea propia dejaría una línea en
         blanco: por eso las notas de esa misión van dentro de los comentarios que ya existían.
         Al construirla se renderizaron las dos versiones de la vista (la anterior y ésta) con los
         mismos datos y modalidad null: el HTML dio idéntico byte a byte. ContratoConModalidadTest
         cuida que sin modalidad no aparezca nada de la letra chica nueva. --}}
    <p class="header-title">COMERCIOCITY</p>
@if($modalidad === 'suscripcion')
    <p class="header-subtitle">Contrato de Suscripción, Implementación y Servicio</p>
@else
    <p class="header-subtitle">Contrato de Licencia, Implementación y Servicio</p>
@endif
    <hr class="header-line">
    <p class="fecha-emision">Fecha de emisión: {{ $fecha_emision }}</p>
@if($modalidad !== null)
    <p class="fecha-emision">Modalidad: <strong>{{ $modalidad === 'suscripcion' ? 'Suscripción' : 'Licencia' }}</strong></p>
@endif

    {{-- Sección 1 — Partes --}}
    <h2 class="section-title">1. Partes del contrato</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:22%;"></th>
                <th style="width:39%;">PRESTADOR</th>
                <th style="width:39%;">CLIENTE</th>
            </tr>
        </thead>
        <tbody>
            <tr class="row-white">
                <td><strong>Nombre / fantasía</strong></td>
                <td>{{ $cc_nombre_fantasia }}</td>
                <td>{{ $cliente_nombre ?? '—' }}</td>
            </tr>
            <tr class="row-alt">
                <td><strong>Razón social</strong></td>
                <td>{{ $cc_razon_social }}</td>
                <td>{{ $cliente_razon_social ?? '—' }}</td>
            </tr>
            <tr class="row-white">
                <td><strong>CUIT</strong></td>
                <td>{{ $cc_cuit }}</td>
                <td>{{ $cliente_cuit ?? '—' }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Sección 2 — Objeto.
         En Suscripción la licencia de uso no es de por vida: vale mientras la suscripción mensual
         esté activa. Licencia y el contrato sin modalidad dicen el texto de siempre. Los sistemas
         contratados se nombran en las dos modalidades y nunca sin modalidad: el contrato histórico
         no los nombraba. --}}
    <h2 class="section-title">2. Objeto del contrato</h2>
@if($modalidad === 'suscripcion')
    <p>
        El presente contrato tiene por objeto la concesión de una <strong>licencia de uso</strong> del software ComercioCity,
        <strong>vigente mientras se mantenga activa la suscripción mensual</strong>, junto con el <strong>servicio de implementación</strong>
        que permite poner en marcha la plataforma en las condiciones operativas del CLIENTE, y el <strong>servicio mensual continuo</strong>
        de soporte, mantenimiento y actualizaciones descrito en las cláusulas siguientes.
    </p>
@else
    <p>
        El presente contrato tiene por objeto la concesión de una <strong>licencia de uso de por vida</strong> del software ComercioCity,
        junto con el <strong>servicio de implementación</strong> que permite poner en marcha la plataforma en las condiciones operativas
        del CLIENTE, y el <strong>servicio mensual continuo</strong> de soporte, mantenimiento y actualizaciones descrito en las cláusulas siguientes.
    </p>
@endif
@if($modalidad !== null && count($sistemas_contratados) > 0)
    <p>Sistemas contratados: <strong>{{ $sistemas_contratados_texto }}</strong>.</p>
@endif

    {{-- Sección 3 — Pago único.
         En Suscripción no hay licencia que pagar: el pago inicial es la puesta en marcha (el monto
         sigue saliendo de contract_precio_licencia, que es el campo que carga quien vende). --}}
@if($modalidad === 'suscripcion')
    <h2 class="section-title">3. Pago inicial — Puesta en marcha</h2>
    <p>
        El CLIENTE abonará al PRESTADOR un pago inicial por el servicio de implementación y puesta en marcha, por un importe total de
        <strong>{{ $moneda }} {{ $precio_licencia ?? '—' }}</strong>, conforme al plan de cuotas que se detalla a continuación:
    </p>
@else
    <h2 class="section-title">3. Pago único — Licencia e implementación</h2>
    <p>
        El CLIENTE abonará al PRESTADOR un pago único por la licencia de uso y el servicio de implementación, por un importe total de
        <strong>{{ $moneda }} {{ $precio_licencia ?? '—' }}</strong>, conforme al plan de cuotas que se detalla a continuación:
    </p>
@endif
@if($desglose_precios !== null)
    <table class="data-table" style="page-break-inside: avoid;">
        <thead><tr><th>Sistema contratado</th><th>Licencia individual</th></tr></thead>
        <tbody>
        @foreach($desglose_precios['filas'] as $fila)
            <tr><td>{{ $fila['label'] }}</td><td>{{ $moneda }} {{ number_format($fila['licencia'], 2, ',', '.') }}</td></tr>
        @endforeach
            <tr><td>Subtotal de licencias individuales</td><td>{{ $moneda }} {{ number_format($desglose_precios['subtotal'], 2, ',', '.') }}</td></tr>
        @if(count($desglose_precios['filas']) === 3)
            <tr><td>Descuento por contratar los tres sistemas</td><td>- {{ $moneda }} {{ number_format($desglose_precios['descuento'], 2, ',', '.') }}</td></tr>
        @endif
            <tr class="total-row"><td>Total de licencia e implementación{{ count($desglose_precios['filas']) === 3 ? ' con descuento aplicado' : '' }}</td><td>{{ $moneda }} {{ number_format($desglose_precios['total'], 2, ',', '.') }}</td></tr>
        </tbody>
    </table>
@endif
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:10%; text-align:center;">#</th>
                <th style="width:40%;">Importe</th>
                <th style="width:50%;">Fecha de vencimiento</th>
            </tr>
        </thead>
        <tbody>
            @forelse($financiacion as $index => $cuota)
                <tr class="{{ $loop->iteration % 2 === 0 ? 'row-alt' : 'row-white' }}">
                    <td style="text-align:center;">{{ $loop->iteration }}</td>
                    <td>{{ $moneda }} {{ $cuota['monto'] }}</td>
                    <td>{{ $cuota['fecha'] }}</td>
                </tr>
            @empty
                <tr class="row-white">
                    <td colspan="3" style="text-align:center; color:#666;">Sin cuotas de financiación cargadas.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    @if($fecha_primer_pago_unico)
        <p>El primer pago deberá realizarse el <strong>{{ $fecha_primer_pago_unico }}</strong>.</p>
    @endif

    {{-- Sección 4 — Implementación (7 etapas) --}}
    <h2 class="section-title">4. Servicio de implementación</h2>
    <p>El servicio de implementación comprende las siguientes etapas, que el PRESTADOR ejecutará en coordinación con el CLIENTE:</p>

    <p class="etapa-title">Etapa 1 — Relevamiento de información</p>
    <p class="etapa-desc">
        Solicitud de datos de la empresa del CLIENTE: razón social, CUIT, datos fiscales, logotipo e información operativa necesaria
        para configurar el sistema según su actividad.
    </p>

    <p class="etapa-title">Etapa 2 — Designación del responsable de migración</p>
    <p class="etapa-desc">
        El CLIENTE designa un responsable interno que proveerá archivos e información en los plazos acordados, facilitando la
        continuidad del proceso de implementación.
    </p>

    <p class="etapa-title">Etapa 3 — Instalación del sistema</p>
    <p class="etapa-desc">
        Instalación y configuración completa de la plataforma, incluyendo subdominio y entorno de trabajo listo para operar.
    </p>

    <p class="etapa-title">Etapa 4 — Migración de datos</p>
    <p class="etapa-desc">
        Importación de artículos, clientes y proveedores desde archivos Excel u otras fuentes. El equipo del PRESTADOR se encarga del
        mapeo e importación completa de los datos provistos por el CLIENTE.
    </p>

    <p class="etapa-title">Etapa 5 — Capacitación mediante el Centro de Recursos</p>
    <p class="etapa-desc">
        Acceso a videos tutoriales organizados por módulo para capacitación autogestionada del personal del CLIENTE.
    </p>

    <p class="etapa-title">Etapa 6 — Vinculación con ARCA (ex-AFIP)</p>
    <p class="etapa-desc">
        Asistencia en la vinculación para facturación electrónica. El CLIENTE o su contador completa los pasos ante el organismo;
        el equipo del PRESTADOR configura la integración dentro de la plataforma.
    </p>

    <p class="etapa-title">Etapa 7 — Videollamada de cierre de implementación</p>
    <p class="etapa-desc">
        Una vez dados los primeros usos del sistema, videollamada para resolver dudas concretas surgidas durante los primeros días de operación.
    </p>

    {{-- Sección 5 — Mensualidad --}}
    <h2 class="section-title">{{ $pago_anual ? '5. Abono anual y adicionales mensuales' : '5. Servicio mensual continuo' }}</h2>
@if($pago_anual)
    <table class="data-table" style="page-break-inside: avoid;">
        <thead><tr><th>Abono base por doce meses</th><th>Importe</th></tr></thead>
        <tbody>
            <tr><td>Base mensual de referencia de los sistemas contratados</td><td>{{ $mensualidad_moneda }} {{ number_format($abono_anual['base_mensual'], 2, ',', '.') }}</td></tr>
            <tr><td>Doce (12) meses de abono base, antes del descuento</td><td>{{ $mensualidad_moneda }} {{ number_format($abono_anual['bruto'], 2, ',', '.') }}</td></tr>
            <tr><td>Descuento por pago anual anticipado: {{ number_format($abono_anual['porcentaje'], 2, ',', '.') }}%</td><td>- {{ $mensualidad_moneda }} {{ number_format($abono_anual['descuento'], 2, ',', '.') }}</td></tr>
            <tr class="total-row"><td>TOTAL ANUAL ANTICIPADO DEL ABONO BASE</td><td>{{ $mensualidad_moneda }} {{ number_format($abono_anual['total'], 2, ',', '.') }}</td></tr>
        </tbody>
    </table>
    <p>El pago anual anticipado cubre doce (12) meses del abono base de los sistemas contratados. Durante esos doce meses no se vuelve a cobrar mensualmente ese abono base ni se aplica IPC sobre el importe ya pagado. Los valores mensuales que se muestran a continuación son de referencia para la base cubierta; los adicionales se cobran por separado.</p>
@endif
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:28%;">Concepto</th>
                <th style="width:44%;">Detalle</th>
                <th style="width:28%;">{{ $pago_anual ? 'Referencia / adicional mensual' : 'Importe mensual' }}</th>
            </tr>
        </thead>
        <tbody>
@if($desglose_precios !== null)
            @foreach($desglose_precios['filas'] as $fila)
            <tr><td>{{ $fila['label'] }}</td><td>{{ $pago_anual ? 'Referencia mensual; cubierta en el pago anual' : 'Abono del sistema contratado' }}</td><td>{{ $mensualidad_moneda }} {{ number_format($fila['mensualidad'], 2, ',', '.') }}</td></tr>
            @endforeach
@endif
            <tr class="row-white">
                <td>{{ $pago_anual ? 'Base mensual de referencia' : ($desglose_precios !== null ? 'Subtotal del abono base' : 'Mensualidad base') }}</td>
                <td>Servicio continuo de la plataforma ({{ $usuarios_incluidos }} usuario{{ $usuarios_incluidos == 1 ? '' : 's' }} incluido{{ $usuarios_incluidos == 1 ? '' : 's' }}){{ $pago_anual ? '; cubierto en el pago anual' : '' }}</td>
                <td>{{ $mensualidad_moneda }} {{ $mensualidad_base ?? '—' }}</td>
            </tr>
            @if($usuarios_extra > 0 || $desglose_precios !== null || $pago_anual)
                <tr class="row-alt">
                    <td>Usuarios adicionales</td>
                    <td>{{ $usuarios_extra }} usuario{{ $usuarios_extra == 1 ? '' : 's' }} extra × {{ $mensualidad_moneda }} {{ $desglose_precios !== null || $pago_anual ? ($precio_usuario_extra_formateado ?? 'sin precio pactado') : $precio_usuario_extra }}</td>
                    <td>{{ $mensualidad_moneda }} {{ $desglose_precios !== null || $pago_anual ? number_format($total_usuarios_extra, 2, ',', '.') : number_format($usuarios_extra * (float) preg_replace('/[^0-9.,]/', '', (string) ($precio_usuario_extra ?? 0)), 0, ',', '.') }}</td>
                </tr>
            @endif
            @if($perfiles_ecommerce > 0)
                <tr class="{{ $usuarios_extra > 0 ? 'row-white' : 'row-alt' }}">
                    <td>Módulo ecommerce</td>
                    <td>{{ $perfiles_ecommerce }} perfil{{ $perfiles_ecommerce == 1 ? '' : 'es' }} × {{ $mensualidad_moneda }} {{ $precio_perfil_ecommerce }}</td>
                    <td>{{ $mensualidad_moneda }} {{ $desglose_precios !== null || $pago_anual ? number_format($total_perfiles, 2, ',', '.') : number_format($perfiles_ecommerce * (float) preg_replace('/[^0-9.,]/', '', (string) ($precio_perfil_ecommerce ?? 0)), 0, ',', '.') }}</td>
                </tr>
            @endif
            <tr class="total-row">
                <td colspan="2" style="text-align:right;"><strong>{{ $pago_anual ? 'ADICIONALES A ABONAR MES A MES' : 'TOTAL MENSUAL' }}</strong></td>
                <td><strong>{{ $mensualidad_moneda }} {{ $pago_anual ? number_format($adicionales_mensuales, 2, ',', '.') : $total_mensual_formateado }}</strong></td>
            </tr>
        </tbody>
    </table>
    <p><strong>Precio por cada usuario extra:</strong> {{ $precio_usuario_extra_formateado !== null ? $mensualidad_moneda . ' ' . $precio_usuario_extra_formateado : 'sin precio pactado en este contrato' }} por mes. Los usuarios extra se abonan mes a mes según la cantidad existente en cada período.</p>
@if($pago_anual)
    <p>Los usuarios extra quedan fuera del pago anual del abono base: no se cobran por adelantado, no reciben descuento anual y no quedan congelados por ese adelanto. Los importes de los adicionales mensuales indicados se pagan separadamente.</p>
@endif
@if($mes_gratis)
    <p><strong>Un mes gratis:</strong> el CLIENTE tendrá bonificado el primer mes del servicio mensual continuo de los sistemas contratados, contado desde la puesta en marcha. {{ $pago_anual ? 'Los doce (12) meses pagos del abono anual comienzan en el período siguiente al mes bonificado; el mes gratis no se resta del cálculo de las doce mensualidades del adelanto.' : 'El primer pago de la mensualidad corresponderá al período siguiente al mes bonificado.' }}</p>
@endif
    @if($fecha_primer_pago_mensual)
        <p>{{ $pago_anual ? 'El primer pago anual anticipado deberá realizarse el' : 'El primer pago de la mensualidad deberá realizarse el' }} <strong>{{ $fecha_primer_pago_mensual }}</strong>.</p>
    @endif
    {{-- Cada cuántos meses se actualiza por IPC: variable desde la misión modulo-cobranzas (18/9/2026).
         El texto ("seis (6) meses") lo arma LeadContractPdfService::meses_en_letras(); el `??` es la red
         para un llamador que renderice la vista con datos armados por otro camino y sin esa clave.
         Después del párrafo de IPC, solo con modalidad (misión precios-dos-modalidades, 13/10/2026):
         5.1 es la letra chica de la modalidad (Licencia: la mensualidad es de por vida y solo se
         ajusta por IPC; Suscripción: plazo mínimo y después la lista vigente) y 5.2 los consumos de
         terceros (los mensajes de WhatsApp que cobra Meta), que solo van si se contrató Agentes. --}}
@if($pago_anual)
    <p>El abono base pagado por adelantado no se ajusta por IPC durante los doce (12) meses cubiertos. Los usuarios extra y los demás adicionales mensuales quedan fuera de esa cobertura y podrán actualizarse cada <strong>{{ $meses_actualizacion_texto }}</strong> conforme al índice IPC publicado por el INDEC, con un aviso previo de <strong>quince (15) días</strong> al CLIENTE. Al terminar la cobertura anual, el abono base continúa sujeto a las condiciones de actualización por IPC pactadas en este contrato.</p>
@else
    <p>
        Los importes de la mensualidad podrán actualizarse cada <strong>{{ $meses_actualizacion_texto ?? 'seis (6) meses' }}</strong> conforme al índice IPC publicado por el INDEC,
        con un aviso previo de <strong>quince (15) días</strong> al CLIENTE.
    </p>
@endif
@if($modalidad === 'licencia')
    <div class="clausula-particular">
        <p class="clausula-titulo">5.1 — Mensualidad de por vida</p>
        <p>
            En la modalidad Licencia, la mensualidad base pactada en este contrato es de por vida: solo podrá modificarse por la actualización
            por IPC prevista en el párrafo anterior, y no por cambios en la lista de precios de ComercioCity. Incluye las versiones nuevas de los
            sistemas contratados, el soporte y la infraestructura en la nube. No incluye: (a) sistemas o módulos que el CLIENTE no haya contratado
            en este acuerdo, ni los que ComercioCity lance en el futuro; (b) {{ $desglose_precios !== null || $pago_anual ? 'los usuarios adicionales, que se cobran por separado al precio por usuario pactado en este contrato, según la cantidad existente cada mes y con la actualización por IPC prevista;' : 'los usuarios adicionales que se sumen después de la firma, que se cobran al precio de lista vigente en ese momento;' }} (c) los consumos de terceros que se detallan en este contrato. Si el servicio
            mensual se da de baja —por rescisión del CLIENTE o por falta de pago— y luego se retoma, la mensualidad pasa a ser la de la lista
            vigente al momento de retomarlo.
        </p>
    </div>
@elseif($modalidad === 'suscripcion')
    <div class="clausula-particular">
        <p class="clausula-titulo">5.1 — Plazo mínimo</p>
        <p>
            En la modalidad Suscripción, el servicio mensual tiene un plazo mínimo de <strong>{{ $permanencia_meses_texto }}</strong>
            contados desde el primer pago de la mensualidad. Si el CLIENTE rescinde antes de cumplirse ese plazo, deberá abonar las
            mensualidades que resten hasta completarlo. Cumplido el plazo mínimo, la suscripción continúa mes a mes. Durante el plazo mínimo
            la mensualidad solo se actualiza por IPC; después podrá ajustarse además a la lista de precios vigente de ComercioCity, con un
            aviso previo de treinta (30) días.
        </p>
    </div>
@endif
@if($modalidad !== null && $tiene_agentes)
    <div class="clausula-particular">
        <p class="clausula-titulo">5.2 — Consumos de terceros</p>
        <p>
            Los mensajes de WhatsApp que los sistemas envíen a clientes, proveedores u otros terceros del CLIENTE (por ejemplo,
            recordatorios de cobro) tienen un costo por mensaje que cobra Meta Platforms. Ese costo no está incluido en la mensualidad: el
            PRESTADOR podrá trasladarlo al CLIENTE al valor que cobre Meta, con un aviso previo de quince (15) días. Las consultas que el
            CLIENTE le haga a su sistema por WhatsApp y los informes que reciba no tienen costo adicional. El uso de herramientas de
            inteligencia artificial propias del CLIENTE conectadas a su sistema corre por cuenta del CLIENTE.
        </p>
    </div>
@endif

    {{-- Sección 6 — Soporte.
         En Suscripción el soporte no es "de por vida": dura mientras la suscripción esté activa
         (misión precios-dos-modalidades, 13/10/2026). Licencia y el contrato sin modalidad dicen
         el texto de siempre. --}}
@if($modalidad === 'suscripcion')
    <h2 class="section-title">6. Soporte personalizado</h2>
    <p>
        El servicio mensual incluye, mientras la suscripción esté activa, soporte personalizado vía WhatsApp de lunes a sábados, atendido por
        personas reales del equipo de ComercioCity, sin costo adicional al abono mensual. El soporte tiene por objeto resolver consultas de uso,
        configuración y operación cotidiana de la plataforma.
    </p>
@else
    <h2 class="section-title">6. Soporte personalizado de por vida</h2>
    <p>
        El servicio mensual incluye soporte personalizado vía WhatsApp de lunes a sábados, atendido por personas reales del equipo de ComercioCity,
        sin costo adicional al abono mensual. El soporte tiene por objeto resolver consultas de uso, configuración y operación cotidiana de la plataforma.
    </p>
@endif
    {{-- 6.1 — Comunidad ComercioCity y adaptaciones (Lucas, 23/10/2026). Es el mismo texto en las dos modalidades (sin nombrarlas: los tests exigen que Licencia no diga Suscripción) y en el
         contrato sin modalidad: la comunidad dura lo que la licencia de uso (Licencia: de por vida; Suscripción: mientras esté activa). La política que se comunica a leads y clientes vive en
         manual_sistema/general/comunidad-y-acompanamiento.md: si cambia allá, se revisa acá. --}}
    <div class="clausula-particular">
        <p class="clausula-titulo">6.1 — Comunidad ComercioCity y adaptaciones a medida</p>
@if($desglose_precios !== null && $modalidad !== 'suscripcion')
        <p>La pertenencia a la comunidad y las adaptaciones o modificaciones del sistema incluidas en esta cláusula se mantienen <strong>de por vida</strong>, con el alcance y las exclusiones que se detallan a continuación.</p>
@endif
        <p>
            Cualquiera sea la modalidad de contratación, el CLIENTE integra la comunidad ComercioCity durante la vigencia de su
            licencia de uso y podrá solicitar al PRESTADOR, en cualquier momento,
            adaptaciones o modificaciones del sistema para las necesidades de su negocio. El PRESTADOR se compromete a incorporarlas, sin costo
            adicional a la mensualidad, siempre que sean razonables y respondan a la gestión del negocio del CLIENTE. Quedan fuera de este compromiso
            los pedidos que, a criterio razonable del PRESTADOR, (a) resulten desproporcionados o requieran recursos de desarrollo excesivos, o (b)
            consistan en el desarrollo de nuevos módulos que el PRESTADOR considere ajenos a la gestión del negocio; esos pedidos se evalúan por
            separado y el PRESTADOR le informará su decisión al CLIENTE. El PRESTADOR prioriza los pedidos según las necesidades del conjunto de la
            comunidad, y las adaptaciones que realice integran la plataforma ComercioCity y quedan alcanzadas por lo dispuesto en las condiciones
            generales sobre propiedad intelectual, pudiendo ponerse a disposición de otros clientes sin que ello implique compartir información del
            CLIENTE. Este compromiso no incluye sistemas o servicios de terceros.
        </p>
    </div>

    {{-- Sección 7 — Condiciones generales.
         La rescisión cambia con la Suscripción (misión precios-dos-modalidades, 13/10/2026): rige
         después del plazo mínimo de la cláusula 5.1 y con ella termina la licencia de uso. Licencia
         y el contrato sin modalidad dicen el texto de siempre. --}}
    <h2 class="section-title">7. Condiciones generales</h2>
    <p>
        <strong>Licencia de uso:</strong> La licencia otorgada es personal, intransferible, sin derecho a sublicenciar ni comercializar el software
        o sus componentes fuera del ámbito de la actividad del CLIENTE.
    </p>
    <p>
        <strong>Propiedad intelectual:</strong> La plataforma, su código fuente y diseño son propiedad exclusiva de Lucas González. El presente contrato
        no transfiere al CLIENTE ningún derecho de propiedad intelectual sobre el software.
    </p>
    <p>
        <strong>Confidencialidad:</strong> Ambas partes se comprometen a mantener la confidencialidad de la información intercambiada y a no divulgarla
        a terceros sin consentimiento previo por escrito de la otra parte.
    </p>
@if($modalidad === 'suscripcion')
    <p>
        <strong>Rescisión:</strong> Cumplido el plazo mínimo de la cláusula 5.1, cualquiera de las partes podrá rescindir el servicio mensual
        con un preaviso de treinta (30) días. Con la rescisión finaliza la licencia de uso. El pago inicial no será reembolsable una vez
        iniciada la implementación.
    </p>
@else
    <p>
        <strong>Rescisión:</strong> Cualquiera de las partes podrá rescindir el servicio mensual con un preaviso de treinta (30) días. La licencia de uso
        de por vida permanecerá vigente. El pago único no será reembolsable una vez iniciada la implementación.
    </p>
@endif
    <p>
        <strong>Jurisdicción:</strong> Para cualquier controversia derivada del presente contrato, las partes se someten a los tribunales ordinarios de la
        Ciudad Autónoma de Buenos Aires.
    </p>

    {{-- Seccion 8 — Clausulas particulares (solo si hay alguna cargada) --}}
    @if(count($clausulas) > 0)
        <h2 class="section-title">8. Cláusulas particulares</h2>
        <p class="clausula-prelacion">
            Las cláusulas particulares que se detallan a continuación forman parte integrante del presente contrato
            y prevalecen sobre las condiciones generales en caso de discrepancia entre unas y otras.
        </p>
        @foreach($clausulas as $clausula)
            <div class="clausula-particular">
                @if($clausula['titulo'] !== '')
                    <p class="clausula-titulo">8.{{ $loop->iteration }} — {{ $clausula['titulo'] }}</p>
                @endif
                <p>{!! nl2br(e($clausula['texto'])) !!}</p>
            </div>
        @endforeach
    @endif

    {{-- Firmas --}}
    <table class="firmas-table">
        <tr>
            <td class="firma-col">
                @if($firma_prestador_src !== null)
                    {{-- Ancho, alto y margen vienen calculados en puntos desde ContractSignatureService,
                         no de CSS: dompdf 1.2 no aplica max-width/max-height sobre <img>, así que si la
                         firma se dejara escalar sola, una imagen apaisada se comería el ancho de la celda.
                         El margen superior es el que mantiene el hueco total en HUECO_PT y las dos líneas
                         de firma alineadas. --}}
                    <div class="firma-imagen-caja">
                        <img src="{{ $firma_prestador_src }}" alt=""
                             style="width: {{ $firma_prestador_ancho_pt }}pt;
                                    height: {{ $firma_prestador_alto_pt }}pt;
                                    margin-top: {{ $firma_prestador_margen_superior_pt }}pt;" />
                    </div>
                    {{-- margin-top inline y no una clase modificadora: el modificador tendría la misma
                         especificidad que .firma-linea y solo ganaría por estar declarado después en la
                         hoja de estilos. Es una dependencia del orden del archivo, invisible, que se
                         rompe sola la próxima vez que alguien ordene el CSS, y el síntoma serían las dos
                         líneas de firma desalineadas en un contrato ya enviado. --}}
                    <div class="firma-linea" style="margin-top: {{ $firma_prestador_separacion_pt }}pt;"></div>
                @else
                    <div class="firma-linea"></div>
                @endif
                <p><strong>{{ $cc_razon_social }}</strong></p>
                <p>CUIT {{ $cc_cuit }}</p>
                <p>{{ $cc_nombre_fantasia }}</p>
                <p class="firma-rol">PRESTADOR</p>
            </td>
            <td class="firma-col">
                <div class="firma-linea"></div>
                <p><strong>{{ $cliente_razon_social ?? $cliente_nombre ?? '________________________' }}</strong></p>
                <p>CUIT {{ $cliente_cuit ?? '________________________' }}</p>
                <p>{{ $cliente_nombre ?? '________________________' }}</p>
                <p class="firma-rol">CLIENTE</p>
            </td>
        </tr>
    </table>

    <div class="footer">
        ComercioCity · Lucas González · CUIT 20-42354898-4
    </div>

</body>
</html>
