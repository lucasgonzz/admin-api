{{--
  Hito "categorias": las tres formas de ordenar el catálogo, para que el cliente elija una.

  Cada opción es una tarjeta con su número y cuántas categorías tiene, su nombre, en qué se basa y,
  si hay, hasta cuatro ejemplos como fichas. No lleva botón: la elección se hace en el sistema
  del cliente (Alertas → Catálogo → Categorías). El cierre es el de siempre, salvo que el dato `como_elegir` lo reemplace.

  Variables de este hito: $opciones (indice, categorias_texto, categorias_leyenda, nombre, base,
  ejemplos) y $como_elegir. Hereda de `hito.blade.php`: $font.
--}}
<tr>
  <td class="cc-lado" style="padding:36px 48px 0 48px;">
    @foreach($opciones as $opcion)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:{{ $loop->first ? '0' : '14px' }} 0 0 0;background-color:#f8f9fc;border-radius:16px;">
      <tr>
        <td style="padding:22px 22px 20px 22px;">
          <p style="margin:0;font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#0b84f8;">Opción {{ $opcion['indice'] }} &middot; {{ $opcion['categorias_texto'] }} {{ $opcion['categorias_leyenda'] }}</p>
          <p style="margin:8px 0 0 0;font-family:{!! $font !!};font-size:19px;line-height:26px;font-weight:700;letter-spacing:-0.01em;color:#1c2333;word-break:break-word;">{{ $opcion['nombre'] }}</p>
          <p style="margin:6px 0 0 0;font-family:{!! $font !!};font-size:15px;line-height:23px;color:#566078;word-break:break-word;">{{ $opcion['base'] }}</p>
          @if(count($opcion['ejemplos']) > 0)
            <div style="margin:8px 0 0 0;">@foreach($opcion['ejemplos'] as $ejemplo)<span style="display:inline-block;margin:6px 6px 0 0;padding:5px 10px;border-radius:999px;background-color:#ffffff;border:1px solid #e1e5ef;font-family:{!! $font !!};font-size:13px;line-height:16px;color:#1c2333;max-width:100%;box-sizing:border-box;word-break:break-word;">{{ $ejemplo }}</span>@endforeach</div>
          @endif
        </td>
      </tr>
    </table>
    @endforeach
  </td>
</tr>
<tr>
  <td class="cc-lado" style="padding:28px 48px 0 48px;">
    <p style="margin:0;font-family:{!! $font !!};font-size:15px;line-height:24px;color:#566078;word-break:break-word;">{{ $como_elegir !== '' ? $como_elegir : 'Las ves completas y elegís desde tu sistema, en Alertas → Catálogo → Categorías: ahí ves los productos de cada una y cómo quedaría el menú de tu tienda. La elección la hace el dueño de la cuenta.' }}</p>
  </td>
</tr>
