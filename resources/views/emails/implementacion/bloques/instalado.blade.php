{{--
  Hito "instalado": el sistema ya está instalado y se le piden al cliente los archivos para cargarlo.

  Todo el texto es fijo: la lista de lo que necesitamos (artículos, clientes, proveedores y fotos) y
  el aviso de que los catálogos tienen que mostrar el mismo código que el Excel, que es lo que
  permite asignar cada foto al producto correcto.

  Hereda de `hito.blade.php`: $font.
--}}
@php
    // Lo que se le pide al cliente: título y explicación. Constantes de este mismo archivo.
    $pedidos = [
        ['Tus artículos', 'Un Excel con código, nombre y costo o precio. Si tenés el código de barras, sumalo.'],
        ['Tus clientes', 'Un Excel con nombre, teléfono, CUIT y el saldo si tienen cuenta corriente.'],
        ['Tus proveedores', 'Un Excel con nombre, CUIT y saldo.'],
        ['Las fotos de tus productos', 'Un PDF con fotos, los catálogos de tus proveedores o el link a su página web.'],
    ];
@endphp
<tr>
  <td class="cc-lado" style="padding:36px 48px 0 48px;">
    <p style="margin:0 0 14px 0;font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#566078;">Lo que necesitamos</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
      @foreach($pedidos as $pedido)
      <tr>
        <td style="{{ $loop->first ? '' : 'border-top:1px solid #eceef4;' }}padding:16px 0;">
          <p style="margin:0;font-family:{!! $font !!};font-size:16px;line-height:22px;font-weight:600;color:#1c2333;">{{ $pedido[0] }}</p>
          <p style="margin:4px 0 0 0;font-family:{!! $font !!};font-size:15px;line-height:22px;color:#566078;">{{ $pedido[1] }}</p>
        </td>
      </tr>
      @endforeach
    </table>
    {{-- El dato que no hay que pasar por alto: sin el código en común no hay forma de asignar las fotos --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 0 0;">
      <tr>
        <td style="padding:16px 18px;background-color:#eef5ff;border-radius:12px;font-family:{!! $font !!};font-size:15px;line-height:22px;color:#1c2333;">Para que cada foto vaya al producto correcto, los catálogos tienen que mostrar <strong style="font-weight:600;">el mismo código que figura en tu Excel</strong>.</td>
      </tr>
    </table>
  </td>
</tr>
<tr>
  <td class="cc-lado" style="padding:28px 48px 0 48px;">
    <p style="margin:0;font-family:{!! $font !!};font-size:15px;line-height:24px;color:#566078;">Mandanos todo por WhatsApp, como te quede más cómodo: de a uno o todo junto. Si algo no lo tenés en Excel, avisanos y vemos cómo lo resolvemos.</p>
  </td>
</tr>
