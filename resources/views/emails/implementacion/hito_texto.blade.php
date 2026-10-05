{{--
  La versión de texto plano del mail de cada hito de la implementación.

  Va junto a la HTML en el mismo mail (multipart): la ven los clientes de mail que no dibujan HTML y
  la lee el filtro antispam, que desconfía de un mail que es solo imagen y estilos. Dice lo mismo que
  la tarjeta, sin diseño.

  Todo lo que viene de datos se imprime con {{ }}, escapado, igual que en la HTML. En un texto plano
  el escape sale como "&amp;", así que `ImplementacionMail::texto_plano()` lo deshace con
  html_entity_decode DESPUÉS de renderizar esta vista: por eso acá no hay entidades propias.

  Mismas variables que `hito.blade.php`. Los saltos de línea de este archivo son los del mail.
--}}
Hola{{ $nombre !== '' ? ', ' . $nombre : '' }}.

{{ $titular }}

{{ $intro }}
@foreach($nota as $parrafo)

{{ $parrafo }}
@endforeach

@if($hito === 'bienvenida')
Te lleva unos cinco minutos: precios, stock, ventas, tu empresa y tu equipo.
@if($form_link !== '')

Completar el formulario: {{ $form_link }}

Podés hacerlo desde el celular. Si lo dejás por la mitad, se guarda solo y lo seguís después.
@endif

Cuando lo completes, instalamos tu sistema con esa configuración y te avisamos.
@elseif($hito === 'instalado')
Lo que necesitamos:

- Tus artículos: un Excel con código, nombre y costo o precio. Si tenés el código de barras, sumalo.
- Tus clientes: un Excel con nombre, teléfono, CUIT y el saldo si tienen cuenta corriente.
- Tus proveedores: un Excel con nombre, CUIT y saldo.
- Las fotos de tus productos: un PDF con fotos, los catálogos de tus proveedores o el link a su página web.

Para que cada foto vaya al producto correcto, los catálogos tienen que mostrar el mismo código que figura en tu Excel.

Mandanos todo por WhatsApp, como te quede más cómodo: de a uno o todo junto. Si algo no lo tenés en Excel, avisanos y vemos cómo lo resolvemos.
@elseif($hito === 'acceso')
{{ $articulos_texto }} {{ $articulos_leyenda }}
@if($url_sistema !== '')

Dirección: {{ $direccion_sistema }}
Usuario: tu número de documento
Contraseña: la que te mandamos por WhatsApp

Entrar a mi sistema: {{ $url_sistema }}
Cuando entres, cambiá la contraseña desde la configuración.
@endif

Mientras tanto seguimos con las fotos de tus productos y con el orden de tu catálogo.
@elseif($hito === 'imagenes')
{{ $con_foto_texto }} de {{ $total_texto }} {{ $fotos_leyenda }}.
El {{ $porcentaje }} % de tu catálogo ya tiene foto.
@if($fuentes !== '')
Fuentes: {{ $fuentes }}
@endif
@if($aviso_titulo !== '')

{{ $aviso_titulo }} {{ $aviso_detalle }}
@endif
@if($mostrar_boton && $url_fotos !== '')

Revisar las fotos: {{ $url_fotos }}
Las que apruebes quedan en tu sistema y en tu tienda online.
@endif
@elseif($hito === 'categorias')
@foreach($opciones as $opcion)
Opción {{ $opcion['indice'] }} - {{ $opcion['categorias_texto'] }} {{ $opcion['categorias_leyenda'] }}: {{ $opcion['nombre'] }}
{{ $opcion['base'] }}
@if(count($opcion['ejemplos']) > 0)
Por ejemplo: {{ implode(', ', $opcion['ejemplos']) }}
@endif

@endforeach
{{ $como_elegir !== '' ? $como_elegir : 'Contestanos por WhatsApp con el número de la que elegís. Si te gustan partes de dos, también se pueden combinar.' }}
@elseif($hito === 'listo')
@if(count($resumen) > 0)
Lo que quedó cargado:
@foreach($resumen as $rubro)
- {{ $rubro['numero'] }} {{ $rubro['leyenda'] }}
@endforeach

@endif
@if($url_sistema !== '')
Entrar a mi sistema: {{ $url_sistema }}
@endif
Ver los tutoriales: {{ $recursos_url }}

Lo que sigue:
- Una videollamada con vos y tu equipo para resolver dudas y dejarlos operando.
- Conectamos la facturación electrónica con ARCA.
- Soporte por WhatsApp, siempre con una persona del otro lado.

Gracias por elegirnos para acompañar a tu negocio.
@endif

TU IMPLEMENTACIÓN - Etapa {{ $progreso['etapa_actual'] }} de 8
@foreach($progreso['etapas'] as $etapa)
@if($etapa['estado'] === 'completada')
[x] {{ $etapa['nombre'] }}
@elseif($etapa['estado'] === 'en_curso')
[>] {{ $etapa['nombre'] }} (en curso)
@foreach($etapa['subpasos'] as $subpaso)
      {{ $subpaso['estado'] === 'hecho' ? '[x]' : ($subpaso['estado'] === 'en_curso' ? '[>]' : '[ ]') }} {{ $subpaso['texto'] }}
@endforeach
@elseif($etapa['estado'] === 'salteada')
[-] {{ $etapa['nombre'] }} (no aplica)
@else
[ ] {{ $etapa['nombre'] }}
@endif
@endforeach

Cualquier duda, escribinos por WhatsApp, en el mismo chat donde venimos hablando.

{{ $firma_nombre }}
{{ $firma_rol }}

Te escribimos porque estamos implementando ComercioCity en {{ $negocio !== '' ? $negocio : 'tu negocio' }}.
comerciocity.com
