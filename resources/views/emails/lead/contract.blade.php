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
            margin-top: 36px;
        }
        .firma-col {
            width: 50%;
            text-align: center;
            vertical-align: top;
            padding: 0 16px;
        }
        .firma-linea {
            border-top: 1px solid #333333;
            width: 80%;
            margin: 48px auto 8px auto;
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
    </style>
</head>
<body>

    {{-- Encabezado --}}
    <p class="header-title">COMERCIOCITY</p>
    <p class="header-subtitle">Contrato de Licencia, Implementación y Servicio</p>
    <hr class="header-line">
    <p class="fecha-emision">Fecha de emisión: {{ $fecha_emision }}</p>

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

    {{-- Sección 2 — Objeto --}}
    <h2 class="section-title">2. Objeto del contrato</h2>
    <p>
        El presente contrato tiene por objeto la concesión de una <strong>licencia de uso de por vida</strong> del software ComercioCity,
        junto con el <strong>servicio de implementación</strong> que permite poner en marcha la plataforma en las condiciones operativas
        del CLIENTE, y el <strong>servicio mensual continuo</strong> de soporte, mantenimiento y actualizaciones descrito en las cláusulas siguientes.
    </p>

    {{-- Sección 3 — Pago único --}}
    <h2 class="section-title">3. Pago único — Licencia e implementación</h2>
    <p>
        El CLIENTE abonará al PRESTADOR un pago único por la licencia de uso y el servicio de implementación, por un importe total de
        <strong>{{ $moneda }} {{ $precio_licencia ?? '—' }}</strong>, conforme al plan de cuotas que se detalla a continuación:
    </p>
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
    <h2 class="section-title">5. Servicio mensual continuo</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:28%;">Concepto</th>
                <th style="width:44%;">Detalle</th>
                <th style="width:28%;">Importe mensual</th>
            </tr>
        </thead>
        <tbody>
            <tr class="row-white">
                <td>Mensualidad base</td>
                <td>Servicio continuo de la plataforma ({{ $usuarios_incluidos }} usuario{{ $usuarios_incluidos == 1 ? '' : 's' }} incluido{{ $usuarios_incluidos == 1 ? '' : 's' }})</td>
                <td>{{ $mensualidad_moneda }} {{ $mensualidad_base ?? '—' }}</td>
            </tr>
            @if($usuarios_extra > 0)
                <tr class="row-alt">
                    <td>Usuarios adicionales</td>
                    <td>{{ $usuarios_extra }} usuario{{ $usuarios_extra == 1 ? '' : 's' }} extra × {{ $mensualidad_moneda }} {{ $precio_usuario_extra }}</td>
                    <td>{{ $mensualidad_moneda }} {{ number_format($usuarios_extra * (float) preg_replace('/[^0-9.,]/', '', (string) ($precio_usuario_extra ?? 0)), 0, ',', '.') }}</td>
                </tr>
            @endif
            @if($perfiles_ecommerce > 0)
                <tr class="{{ $usuarios_extra > 0 ? 'row-white' : 'row-alt' }}">
                    <td>Módulo ecommerce</td>
                    <td>{{ $perfiles_ecommerce }} perfil{{ $perfiles_ecommerce == 1 ? '' : 'es' }} × {{ $mensualidad_moneda }} {{ $precio_perfil_ecommerce }}</td>
                    <td>{{ $mensualidad_moneda }} {{ number_format($perfiles_ecommerce * (float) preg_replace('/[^0-9.,]/', '', (string) ($precio_perfil_ecommerce ?? 0)), 0, ',', '.') }}</td>
                </tr>
            @endif
            <tr class="total-row">
                <td colspan="2" style="text-align:right;"><strong>TOTAL MENSUAL</strong></td>
                <td><strong>{{ $mensualidad_moneda }} {{ $total_mensual_formateado }}</strong></td>
            </tr>
        </tbody>
    </table>
    @if($fecha_primer_pago_mensual)
        <p>El primer pago de la mensualidad deberá realizarse el <strong>{{ $fecha_primer_pago_mensual }}</strong>.</p>
    @endif
    <p>
        Los importes de la mensualidad podrán actualizarse cada <strong>seis (6) meses</strong> conforme al índice IPC publicado por el INDEC,
        con un aviso previo de <strong>quince (15) días</strong> al CLIENTE.
    </p>

    {{-- Sección 6 — Soporte --}}
    <h2 class="section-title">6. Soporte personalizado de por vida</h2>
    <p>
        El servicio mensual incluye soporte personalizado vía WhatsApp de lunes a sábados, atendido por personas reales del equipo de ComercioCity,
        sin costo adicional al abono mensual. El soporte tiene por objeto resolver consultas de uso, configuración y operación cotidiana de la plataforma.
    </p>

    {{-- Sección 7 — Condiciones generales --}}
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
    <p>
        <strong>Rescisión:</strong> Cualquiera de las partes podrá rescindir el servicio mensual con un preaviso de treinta (30) días. La licencia de uso
        de por vida permanecerá vigente. El pago único no será reembolsable una vez iniciada la implementación.
    </p>
    <p>
        <strong>Jurisdicción:</strong> Para cualquier controversia derivada del presente contrato, las partes se someten a los tribunales ordinarios de la
        Ciudad Autónoma de Buenos Aires.
    </p>

    {{-- Firmas --}}
    <table class="firmas-table">
        <tr>
            <td class="firma-col">
                <div class="firma-linea"></div>
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
