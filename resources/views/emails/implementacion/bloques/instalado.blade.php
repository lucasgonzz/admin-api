{{--
  Hito "instalado": el sistema ya está instalado y se le piden al cliente los archivos para cargarlo.

  🔴 Desde el orden nuevo de `/implementar` (Lucas, 14/10/2026) la skill no lo manda más: dice «ya está
  instalado», y eso se le dice al cliente recién en la entrega. En ese momento ahora sale `instalando`.
  Queda aceptado por compatibilidad: ya salieron mails con él.

  El cuerpo —lo que necesitamos, el código en común y cómo mandarlo— vive en
  `partials/pedido_de_archivos.blade.php`, que comparte con `instalando`.

  Hereda de `hito.blade.php`: $font.
--}}
@include('emails.implementacion.partials.pedido_de_archivos')
