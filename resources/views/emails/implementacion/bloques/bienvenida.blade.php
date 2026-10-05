{{--
  Hito "bienvenida": arrancamos la implementación y el cliente tiene que completar el formulario.

  Lleva el botón con el link del formulario ($form_link, que sale de la implementación). Sin link no
  hay botón: la previa lo avisa en `faltan` y el envío real se frena.

  Hereda de `hito.blade.php`: $font, $form_link y el resto de la vista.
--}}
<tr>
  <td class="cc-lado" style="padding:36px 48px 0 48px;">
    <p style="margin:0 0 14px 0;font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#566078;">Te lleva unos cinco minutos</p>
    <p style="margin:0;font-family:{!! $font !!};font-size:15px;line-height:22px;color:#1c2333;">Precios &middot; Stock &middot; Ventas &middot; Tu empresa &middot; Tu equipo</p>
    @if($form_link !== '')
      <div style="height:24px;line-height:24px;font-size:0;">&nbsp;</div>
      @include('emails.implementacion.partials.boton', ['texto' => 'Completar el formulario', 'url' => $form_link])
      <p style="margin:14px 0 0 0;font-family:{!! $font !!};font-size:14px;line-height:22px;color:#566078;">Podés hacerlo desde el celular. Si lo dejás por la mitad, se guarda solo y lo seguís después.</p>
    @endif
  </td>
</tr>
<tr>
  <td class="cc-lado" style="padding:28px 48px 0 48px;">
    <p style="margin:0;font-family:{!! $font !!};font-size:15px;line-height:24px;color:#566078;">Cuando lo completes, instalamos tu sistema con esa configuración y te avisamos.</p>
  </td>
</tr>
