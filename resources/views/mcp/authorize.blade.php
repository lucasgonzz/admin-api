<!DOCTYPE html>
{{-- Pantalla de consentimiento del conector MCP de sólo lectura (misión dots-openclaw, 25/11/2026).
     Autónoma (sin layout ni JS): la abre ChatGPT en el navegador de Lucas para que inicie sesión como admin y
     permita o niegue el acceso. Todo texto que viene del pedido o del cliente registrado se imprime escapado. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Conectar ComercioCity</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
               font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f4f5f7; color: #1f2933; }
        .tarjeta { width: 100%; max-width: 420px; background: #fff; border-radius: 12px; padding: 28px 24px;
                   box-shadow: 0 2px 12px rgba(0, 0, 0, .12); }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { margin: 0 0 12px; line-height: 1.45; }
        .solo-lectura { display: inline-block; font-size: 13px; font-weight: 600; padding: 3px 10px; border-radius: 999px;
                        background: #e3f6ea; color: #14683a; margin-bottom: 14px; }
        .destino { font-size: 14px; color: #52606d; }
        label { display: block; font-size: 14px; font-weight: 600; margin: 12px 0 4px; }
        input[type=text], input[type=password] { width: 100%; padding: 10px 12px; font-size: 16px; border: 1px solid #c4ccd4;
                        border-radius: 8px; background: #fff; color: inherit; }
        .error { background: #fde8e8; color: #9b1c1c; border-radius: 8px; padding: 10px 12px; margin: 0 0 14px; font-size: 14px; }
        .botones { display: flex; gap: 10px; margin-top: 20px; }
        button { flex: 1; padding: 12px; font-size: 16px; border-radius: 8px; border: 1px solid transparent; cursor: pointer; }
        .permitir { background: #1d4ed8; color: #fff; }
        .negar { background: transparent; color: inherit; border-color: #c4ccd4; }
        @media (prefers-color-scheme: dark) {
            body { background: #111418; color: #e4e7eb; }
            .tarjeta { background: #1c2128; box-shadow: none; }
            .destino { color: #9aa5b1; }
            input[type=text], input[type=password] { background: #111418; border-color: #3e4c59; }
            .solo-lectura { background: #123d28; color: #8fe0b0; }
            .error { background: #4a1d1d; color: #ffb3b3; }
            .negar { border-color: #3e4c59; }
        }
    </style>
</head>
<body>
<main class="tarjeta">
    @if($fatal !== null)
        <h1>No se puede continuar</h1>
        <p class="error">{{ $fatal }}</p>
        <p class="destino">Cerrá esta pestaña y volvé a intentar la conexión desde ChatGPT.</p>
    @else
        <h1>Conectar ComercioCity</h1>
        <span class="solo-lectura">Sólo lectura</span>
        <p><strong>{{ $cliente->client_name ?: 'Una aplicación' }}</strong> quiere leer el estado de tus misiones, del Workboard,
           de las tareas de OpenClaw y de tu Agenda. <strong>Sólo lectura</strong>: no puede cambiar nada.</p>
        <p class="destino">Al permitir, vas a volver a <strong>{{ $host }}</strong>.</p>

        @if($error)
            <p class="error" role="alert">{{ $error }}</p>
        @endif

        <form method="POST" action="{{ rtrim(config('mcp.issuer'), '/') }}/mcp/oauth/authorize">
            @csrf
            @foreach($parametros as $nombre => $valor)
                <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
            @endforeach

            <label for="email">Usuario del admin (el mismo con el que entrás al admin)</label>
            <input id="email" type="text" name="email" autocapitalize="none" spellcheck="false" value="{{ $email }}" autocomplete="username" required>

            <label for="password">Contraseña</label>
            <input id="password" type="password" name="password" autocomplete="current-password">

            <div class="botones">
                <button class="negar" type="submit" name="decision" value="deny" formnovalidate>No permitir</button>
                <button class="permitir" type="submit" name="decision" value="allow">Permitir</button>
            </div>
        </form>
    @endif
</main>
</body>
</html>
