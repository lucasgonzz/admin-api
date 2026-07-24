<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
{{-- No queremos esta página indexada ni rastreada por buscadores. --}}
<meta name="robots" content="noindex, nofollow">
<title>Tu demo de ComercioCity</title>
<style>
	/* Estética minimalista, misma paleta que el Mail 1 (#1A1A2E / #1A56C4 / #555555). */
	* {
		box-sizing: border-box;
	}
	body {
		margin: 0;
		padding: 0;
		background-color: #F5F7FA;
		font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, Helvetica, sans-serif;
		color: #1A1A2E;
	}
	.wrapper {
		max-width: 600px;
		margin: 0 auto;
		background-color: #ffffff;
	}
	.header {
		background-color: #1A1A2E;
		padding: 28px 24px;
		text-align: center;
	}
	.header img {
		max-width: 180px;
		height: auto;
	}
	.header .brand-fallback {
		color: #ffffff;
		font-size: 22px;
		font-weight: bold;
		letter-spacing: 1px;
	}
	.section {
		padding: 24px;
	}
	.section + .section {
		border-top: 1px solid #E8ECF0;
	}
	h1 {
		margin: 0 0 8px 0;
		font-size: 24px;
		font-weight: bold;
		line-height: 1.3;
	}
	h2 {
		margin: 0 0 12px 0;
		font-size: 17px;
		font-weight: bold;
		color: #1A1A2E;
	}
	p {
		margin: 0 0 12px 0;
		font-size: 15px;
		color: #555555;
		line-height: 1.6;
	}
	.demo-slot {
		background-color: #EBF3FF;
		border-left: 4px solid #1A56C4;
		border-radius: 4px;
		padding: 16px 18px;
	}
	.demo-slot .label {
		margin: 0;
		font-size: 12px;
		font-weight: bold;
		color: #1A56C4;
		text-transform: uppercase;
		letter-spacing: 0.5px;
	}
	.demo-slot .value {
		margin: 6px 0 0 0;
		font-size: 17px;
		font-weight: bold;
		color: #1A1A2E;
	}
	.btn {
		display: block;
		width: 100%;
		text-align: center;
		background-color: #1A56C4;
		border-radius: 8px;
		padding: 15px 20px;
		font-size: 15px;
		font-weight: bold;
		color: #ffffff !important;
		text-decoration: none;
		box-sizing: border-box;
	}
	.video-row {
		display: flex;
		align-items: center;
		background-color: #F5F7FA;
		border-radius: 6px;
		padding: 14px 16px;
		margin-bottom: 8px;
		text-decoration: none;
	}
	.video-row .icon {
		flex: 0 0 auto;
		width: 32px;
		height: 32px;
		background-color: #1A56C4;
		border-radius: 50%;
		text-align: center;
		line-height: 32px;
		font-size: 15px;
		color: #ffffff;
		margin-right: 12px;
	}
	.video-row .texts {
		flex: 1 1 auto;
		min-width: 0;
	}
	.video-row .title {
		display: block;
		font-size: 14px;
		font-weight: bold;
		color: #1A1A2E;
	}
	.video-row .desc {
		display: block;
		font-size: 13px;
		color: #666666;
	}
	.video-row .arrow {
		flex: 0 0 auto;
		font-size: 13px;
		color: #1A56C4;
		font-weight: bold;
		white-space: nowrap;
		margin-left: 10px;
	}
	.access-box {
		background-color: #1A1A2E;
		border-radius: 8px;
		padding: 20px 22px;
	}
	.access-box .label {
		margin: 0 0 6px 0;
		font-size: 12px;
		color: #8899AA;
		text-transform: uppercase;
		letter-spacing: 0.5px;
	}
	.access-box .value {
		margin: 0 0 16px 0;
		font-family: 'Courier New', Courier, monospace;
		font-size: 15px;
		color: #F0F4F8;
		word-break: break-word;
	}
	.access-box .value:last-child {
		margin-bottom: 0;
	}
	.access-pending {
		background-color: #FFF6E5;
		border-left: 4px solid #E0A800;
		border-radius: 4px;
		padding: 16px 18px;
	}
	.access-pending p {
		margin: 0;
		color: #7A5B00;
	}
	.footer {
		background-color: #1A1A2E;
		padding: 22px 24px;
		text-align: center;
	}
	.footer p {
		margin: 0 0 4px 0;
		color: #8899AA;
		font-size: 13px;
	}
	.footer .presenter-name {
		color: #ffffff;
		font-weight: bold;
		font-size: 14px;
	}
	.footer a {
		color: #56E0C0;
		text-decoration: none;
		font-size: 13px;
	}
	a.whatsapp-link {
		color: #1A56C4;
		font-weight: bold;
		text-decoration: none;
	}
</style>
</head>
<body>

<div class="wrapper">

	{{-- ================================================================
		 HEADER: logo sobre fondo oscuro
		 ================================================================ --}}
	<div class="header">
		@if($logo_url)
			<img src="{{ $logo_url }}" alt="ComercioCity">
		@else
			<span class="brand-fallback">ComercioCity</span>
		@endif
	</div>

	{{-- ================================================================
		 SALUDO + DÍA/HORARIO DE LA DEMO
		 ================================================================ --}}
	<div class="section">
		<h1>Hola {{ $nombre }},</h1>
		<p>Acá tenés todo lo que necesitás para recorrer tu demo de ComercioCity: los videos, el acceso al sistema y la tienda online.</p>

		@if($dia || $hora_inicio || $hora_fin)
		<div class="demo-slot">
			<p class="label">Tu demo asignada</p>
			<p class="value">
				@if($dia){{ ucfirst($dia) }}@endif
				@if($hora_inicio && $hora_fin)
					&nbsp;&middot;&nbsp;{{ $hora_inicio }} a {{ $hora_fin }} hs
				@elseif($hora_inicio)
					&nbsp;&middot;&nbsp;desde las {{ $hora_inicio }} hs
				@endif
			</p>
		</div>
		@endif
	</div>

	{{-- ================================================================
		 ANTES DE ENTRAR: video introductorio
		 ================================================================ --}}
	@if($video_intro)
	<div class="section">
		<h2>Antes de entrar</h2>
		<p>Te recomendamos ver este video corto antes de la sesión, te da contexto del sistema en pocos minutos.</p>
		<a href="{{ $video_intro }}" target="_blank" rel="noopener noreferrer" class="btn">&#9654;&nbsp; Ver video introductorio</a>
	</div>
	@endif

	{{-- ================================================================
		 VIDEOS TUTORIALES
		 ================================================================ --}}
	<div class="section">
		<h2>Videos tutoriales</h2>
		<p>Estos videos cortos te muestran las funciones clave del sistema.</p>

		@if($video_stock)
		<a href="{{ $video_stock }}" target="_blank" rel="noopener noreferrer" class="video-row">
			<span class="icon">&#128230;</span>
			<span class="texts">
				<span class="title">Gestión de stock</span>
				<span class="desc">cómo cargar y manejar tus productos</span>
			</span>
			<span class="arrow">Ver &rarr;</span>
		</a>
		@endif

		@if($video_ventas)
		<a href="{{ $video_ventas }}" target="_blank" rel="noopener noreferrer" class="video-row">
			<span class="icon">&#128176;</span>
			<span class="texts">
				<span class="title">Ventas y caja</span>
				<span class="desc">registrar ventas, tickets y cobros</span>
			</span>
			<span class="arrow">Ver &rarr;</span>
		</a>
		@endif

		@if($video_ecommerce)
		<a href="{{ $video_ecommerce }}" target="_blank" rel="noopener noreferrer" class="video-row">
			<span class="icon">&#128722;</span>
			<span class="texts">
				<span class="title">Tienda online</span>
				<span class="desc">tu ecommerce conectado al sistema</span>
			</span>
			<span class="arrow">Ver &rarr;</span>
		</a>
		@endif

		@if($video_cierre)
		<a href="{{ $video_cierre }}" target="_blank" rel="noopener noreferrer" class="video-row">
			<span class="icon">&#127919;</span>
			<span class="texts">
				<span class="title">Cómo dar el paso</span>
				<span class="desc">opciones y siguientes pasos</span>
			</span>
			<span class="arrow">Ver &rarr;</span>
		</a>
		@endif
	</div>

	{{-- ================================================================
		 TUTORIALES PERSONALIZADOS (solo si el lead tiene alguno cargado)
		 ================================================================ --}}
	@if(!empty($personalized_demo_videos))
	<div class="section">
		<h2>Tutoriales personalizados</h2>
		<p>Estos videos están hechos para tu caso en particular, {{ $nombre }}.</p>

		@foreach($personalized_demo_videos as $pv)
		<a href="{{ $pv['video_url'] }}" target="_blank" rel="noopener noreferrer" class="video-row">
			<span class="icon">&#127916;</span>
			<span class="texts">
				<span class="title">{{ $pv['title'] }}</span>
				@if(!empty($pv['description']))
				<span class="desc">{{ $pv['description'] }}</span>
				@endif
			</span>
			<span class="arrow">Ver &rarr;</span>
		</a>
		@endforeach
	</div>
	@endif

	{{-- ================================================================
		 TU ACCESO AL SISTEMA
		 ================================================================ --}}
	<div class="section">
		<h2>Tu acceso al sistema</h2>

		@if($acceso_listo)
		<div class="access-box">
			@if($url_demo)
			<p class="label">URL del sistema</p>
			<div style="margin-bottom:16px;">
				<a href="{{ $url_demo }}" target="_blank" rel="noopener noreferrer" class="btn">Ir al sistema demo</a>
			</div>
			@endif

			@if($doc_number)
			<p class="label">Número de documento</p>
			<p class="value">{{ $doc_number }}</p>
			@endif

			@if($usuario)
			<p class="label">Usuario</p>
			<p class="value">{{ $usuario }}</p>
			@endif

			@if($password)
			<p class="label">Contraseña</p>
			<p class="value">{{ $password }}</p>
			@endif
		</div>
		@else
		{{-- Sin demo asignada o sin documento cargado todavía: aviso corto en vez de una caja vacía. --}}
		<div class="access-pending">
			<p>Tu acceso se habilita el día del turno. Si necesitás algo antes, escribinos por WhatsApp.</p>
		</div>
		@endif
	</div>

	{{-- ================================================================
		 TIENDA DEMO
		 ================================================================ --}}
	@if($url_tienda)
	<div class="section">
		<h2>Tienda online demo</h2>
		<p>El sistema viene con una tienda online conectada. Podés verla desde acá:</p>
		<a href="{{ $url_tienda }}" target="_blank" rel="noopener noreferrer" class="btn">Abrir tienda demo</a>
	</div>
	@endif

	{{-- ================================================================
		 CONTACTO POR WHATSAPP
		 ================================================================ --}}
	<div class="section">
		<h2>&iquest;Tenés dudas?</h2>
		<p>
			Antes o después de la demo, escribinos por WhatsApp y te respondemos al toque.
			@if($url_whatsapp)
			<a href="{{ $url_whatsapp }}" target="_blank" rel="noopener noreferrer" class="whatsapp-link">Escribir por WhatsApp &rarr;</a>
			@endif
		</p>
	</div>

	{{-- ================================================================
		 FOOTER
		 ================================================================ --}}
	<div class="footer">
		<p class="presenter-name">{{ $presenter_name }}</p>
		<p>{{ $presenter_role }} &mdash; ComercioCity</p>
		@if($url_whatsapp)
		<p><a href="{{ $url_whatsapp }}" target="_blank" rel="noopener noreferrer">WhatsApp &rarr;</a></p>
		@endif
	</div>

</div>

</body>
</html>
