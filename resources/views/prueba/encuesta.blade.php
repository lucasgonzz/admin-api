<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>¿Cómo te fue con tu prueba? · ComercioCity</title>
{{-- Página pública de la encuesta de fin de prueba (misión prueba-e2e-campana-imperio, 11/11/2026).
     Una sola pantalla, pensada primero para celular. Sin recursos de terceros: ni fuentes, ni scripts, ni imágenes. --}}
<style>
	*, *::before, *::after { box-sizing: border-box; }
	html { -webkit-text-size-adjust: 100%; }
	body {
		margin: 0;
		background: #F5F7FA;
		color: #1A1A2E;
		font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, Helvetica, sans-serif;
		font-size: 16px;
		line-height: 1.45;
	}
	.cabecera {
		background: #1A1A2E;
		color: #ffffff;
		padding: 18px 20px;
		text-align: center;
		font-size: 20px;
		font-weight: 700;
		letter-spacing: .2px;
	}
	.cabecera span { color: #6FA3FF; }
	.pagina { max-width: 620px; margin: 0 auto; padding: 20px 16px 40px; }
	.tarjeta {
		background: #ffffff;
		border-radius: 14px;
		box-shadow: 0 1px 3px rgba(26, 26, 46, .10);
		padding: 22px 18px;
	}
	h1 { font-size: 22px; line-height: 1.25; margin: 0 0 8px; }
	.bajada { margin: 0 0 22px; color: #4A5568; }
	fieldset { border: 0; margin: 0 0 22px; padding: 0; min-width: 0; }
	legend { padding: 0; margin: 0 0 10px; font-weight: 600; font-size: 16px; }
	.oculto {
		position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0;
		overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
	}

	/* Estrellas: cinco radios de verdad (se recorren con las flechas del teclado) y su estrella como etiqueta. */
	.estrellas { display: flex; gap: 6px; }
	.estrellas label {
		flex: 1 1 0;
		max-width: 64px;
		min-height: 52px;
		display: flex; align-items: center; justify-content: center;
		font-size: 38px; line-height: 1;
		color: #CBD2DC;
		cursor: pointer;
		border-radius: 10px;
		-webkit-tap-highlight-color: transparent;
		transition: color .12s ease, transform .12s ease;
	}
	.estrellas label.prendida,
	.estrellas input:checked + label { color: #F5A623; }
	.estrellas input:focus-visible + label { outline: 3px solid #1A56C4; outline-offset: 2px; }
	.estrellas label:active { transform: scale(.92); }
	.estrellas-texto { margin: 8px 0 0; min-height: 22px; color: #4A5568; font-size: 15px; }

	/* Sí / No */
	.pregunta { margin: 0 0 18px; }
	.opciones { display: flex; gap: 10px; }
	.opciones label {
		flex: 1 1 0;
		min-height: 48px;
		display: flex; align-items: center; justify-content: center;
		border: 2px solid #D5DCE6;
		border-radius: 10px;
		background: #ffffff;
		font-weight: 600;
		cursor: pointer;
		-webkit-tap-highlight-color: transparent;
	}
	.opciones input:checked + label { border-color: #1A56C4; background: #EAF1FD; color: #1A56C4; }
	.opciones input:focus-visible + label { outline: 3px solid #1A56C4; outline-offset: 2px; }

	textarea {
		display: block; width: 100%; min-height: 110px;
		padding: 12px; border: 2px solid #D5DCE6; border-radius: 10px;
		font: inherit; color: inherit; resize: vertical;
	}
	textarea:focus { outline: none; border-color: #1A56C4; }
	.ayuda { margin: 6px 0 0; font-size: 14px; color: #6B7280; }
	.error {
		margin: 0 0 16px; padding: 10px 12px; border-radius: 10px;
		background: #FDECEC; color: #9B1C1C; font-size: 15px;
	}
	button {
		display: block; width: 100%; min-height: 52px;
		border: 0; border-radius: 12px;
		background: #1A56C4; color: #ffffff;
		font: inherit; font-size: 17px; font-weight: 700;
		cursor: pointer;
	}
	button:focus-visible { outline: 3px solid #1A1A2E; outline-offset: 2px; }
	.gracias { text-align: center; padding: 34px 18px; }
	.gracias .marca { font-size: 48px; line-height: 1; color: #1E9E5A; margin: 0 0 10px; }
	.pie { margin: 18px 0 0; text-align: center; color: #8899AA; font-size: 13px; }

	@media (min-width: 600px) {
		.pagina { padding: 32px 24px 56px; }
		.tarjeta { padding: 32px 36px; }
		h1 { font-size: 26px; }
		.estrellas { gap: 10px; }
		.estrellas label { font-size: 44px; }
	}
</style>
</head>
<body>

<div class="cabecera">Comercio<span>City</span></div>

<main class="pagina">
@if($respondida)
	<div class="tarjeta gracias" role="status">
		<p class="marca" aria-hidden="true">✓</p>
		<h1>¡Gracias por contarnos!</h1>
		<p class="bajada" style="margin-bottom:0">Ya recibimos tu respuesta. Nos ayuda mucho a mejorar.</p>
	</div>
@else
	<form class="tarjeta" method="post" action="" novalidate>
		<h1>¿Cómo te fue con tu prueba?</h1>
		<p class="bajada">Soy Martín, de ComercioCity. Son unas pocas preguntas{{ $negocio !== '' ? ' sobre la prueba de ' . $negocio : '' }} y te lleva un minuto.</p>

		@if(! empty($errores))
			<div class="error" role="alert">
				@foreach($errores as $error)
					<div>{{ $error }}</div>
				@endforeach
			</div>
		@endif

		<fieldset>
			<legend id="titulo-estrellas">En general, ¿qué puntaje le ponés a la prueba?</legend>
			<div class="estrellas" id="estrellas">
				@for($n = 1; $n <= 5; $n++)
					<input class="oculto" type="radio" name="estrellas" id="estrella-{{ $n }}" value="{{ $n }}"
						{{ (int) ($datos['estrellas'] ?? 0) === $n ? 'checked' : '' }}>
					<label for="estrella-{{ $n }}" data-valor="{{ $n }}">
						<span aria-hidden="true">★</span>
						<span class="oculto">{{ $n }} {{ $n === 1 ? 'estrella' : 'estrellas' }}</span>
					</label>
				@endfor
			</div>
			<p class="estrellas-texto" id="estrellas-texto" aria-live="polite"></p>
		</fieldset>

		@foreach($preguntas as $campo => $texto)
			@php $elegido = array_key_exists($campo, $datos) ? $datos[$campo] : null; @endphp
			<fieldset class="pregunta">
				<legend>{{ $texto }}</legend>
				<div class="opciones">
					<input class="oculto" type="radio" name="{{ $campo }}" id="{{ $campo }}-si" value="si" {{ $elegido === true ? 'checked' : '' }}>
					<label for="{{ $campo }}-si">Sí</label>
					<input class="oculto" type="radio" name="{{ $campo }}" id="{{ $campo }}-no" value="no" {{ $elegido === false ? 'checked' : '' }}>
					<label for="{{ $campo }}-no">No</label>
				</div>
			</fieldset>
		@endforeach

		<fieldset>
			<legend><label for="comentario">¿Querés contarnos algo más?</label></legend>
			<textarea id="comentario" name="comentario" maxlength="{{ $max }}" placeholder="Lo que te gustó, lo que te faltó, lo que cambiarías…">{{ $datos['comentario'] ?? '' }}</textarea>
			<p class="ayuda">Es opcional.</p>
		</fieldset>

		<button type="submit">Enviar mi opinión</button>
	</form>

	{{-- Script propio y mínimo: pinta las estrellas hasta la elegida. Sin él la encuesta funciona igual
	     (queda marcada solo la estrella elegida). --}}
	<script>
	(function () {
		var grupo = document.getElementById('estrellas');
		var texto = document.getElementById('estrellas-texto');
		if (!grupo) { return; }
		var etiquetas = grupo.querySelectorAll('label');
		var palabras = ['', 'Muy mala', 'Mala', 'Regular', 'Buena', 'Excelente'];

		function elegido() {
			var marcado = grupo.querySelector('input:checked');
			return marcado ? parseInt(marcado.value, 10) : 0;
		}
		function pintar(hasta) {
			for (var i = 0; i < etiquetas.length; i++) {
				var valor = parseInt(etiquetas[i].getAttribute('data-valor'), 10);
				etiquetas[i].className = valor <= hasta ? 'prendida' : '';
			}
		}
		function refrescar() {
			var valor = elegido();
			pintar(valor);
			texto.textContent = valor ? valor + ' de 5 · ' + palabras[valor] : '';
		}
		grupo.addEventListener('change', refrescar);
		for (var i = 0; i < etiquetas.length; i++) {
			etiquetas[i].addEventListener('mouseenter', function () { pintar(parseInt(this.getAttribute('data-valor'), 10)); });
			etiquetas[i].addEventListener('mouseleave', refrescar);
		}
		refrescar();
	})();
	</script>
@endif
	<p class="pie">ComercioCity · sistema de gestión para comercios</p>
</main>

</body>
</html>
