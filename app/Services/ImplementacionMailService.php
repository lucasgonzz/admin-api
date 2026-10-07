<?php

namespace App\Services;

use App\Exceptions\ImplementacionMailException;
use App\Mail\Helpers\ImplementacionMailHelper;
use App\Mail\Helpers\RechazosDeCorreoHelper;
use App\Mail\ImplementacionMail;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationMail;
use App\Models\Lead;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * El mail de cada hito de la implementación (misión implementar-cliente, 5/10/2026): valida los
 * datos del hito, arma la vista previa, lo manda y deja el registro en `implementation_mails`.
 *
 * Es la punta que llama el endpoint `POST claude/implementations/{id}/mail` y, a través de él, la
 * skill `/implementar`. Tiene TRES métodos públicos y ese es el contrato con quien lo llama:
 *
 *   - `validar_datos()` — errores por campo de los datos de un hito. No toca nada.
 *   - `previa()`        — el mail armado, sin mandar ni escribir NADA. Es el dry-run.
 *   - `enviar()`        — lo manda de verdad, síncrono, y lo registra.
 *
 * Los hitos son seis, en el orden en que pasan en una implementación:
 *
 *   bienvenida — arrancamos; lleva el link del formulario.
 *   instalado  — el sistema ya está instalado; pide los archivos.
 *   acceso     — la primera tanda de artículos ya está cargada; lleva el link para entrar.
 *   imagenes   — cuántos artículos ya tienen foto y cuántas esperan el visto bueno del cliente.
 *   categorias — las opciones de categorías (una, dos o tres): con varias elige una, y con una sola
 *                (la lista que pasó el dueño) la confirma.
 *   listo      — el sistema está listo para operar.
 *
 * OJO: todo mail lleva además la línea de progreso con el estado real de las ocho etapas al momento
 * de mandarlo (`ImplementacionMailHelper::progreso()`): el mail no inventa en qué etapa está la
 * implementación, lo lee de `implementation_stages`.
 *
 * Qué se manda y qué sale solo de la implementación:
 *
 *   - De los datos del hito (lo pasa quien llama): cantidades, las opciones de categorías (una, dos o tres),
 *     la nota personal. Se validan en `validar_datos()`.
 *   - De la implementación (no se pasa): el nombre y el negocio del cliente, el link del formulario,
 *     la dirección de su sistema y el estado de las etapas.
 *   - De la configuración (`commerciocity.implementacion_mail`): quién firma y la carpeta de recursos.
 *
 * A quién le llega, en este orden (el primero que sirve corta):
 *   1. el `email` que se pasó (pisa a todo y, si el mail sale, queda guardado en la ficha);
 *   2. `clients.email`;
 *   3. `setup_data.email`, que lo carga el formulario de la implementación;
 *   4. el email del lead que se promovió a este cliente.
 * Sin ninguna, no se manda: `sin_mail`. A propósito NO se le pregunta al `empresa-api` del cliente
 * (lo que hace `ClientContactEmailResolver` para el aviso de actualización): en las primeras etapas
 * el sistema del cliente todavía no existe, y esa consulta es una llamada HTTP de hasta 15 s.
 *
 * OJO: el envío es SÍNCRONO, por el mailer `admin` (`admin@comerciocity.com`), y no pasa por la cola:
 * quien lo pide (Lucas, desde la skill) quiere saber en el momento si salió. Todo lo que falle queda
 * escrito en la fila con su motivo.
 */
class ImplementacionMailService
{
    /**
     * Los seis hitos, en el orden en que pasan en una implementación.
     */
    const HITOS = ['bienvenida', 'instalado', 'acceso', 'imagenes', 'categorias', 'listo'];

    /**
     * El mailer por el que salen: el de `admin@comerciocity.com`. El mismo que el aviso de
     * actualización, y por el mismo motivo (Hostinger exige autenticarse con la casilla que figura
     * como remitente). Se toma de allá para que no puedan separarse.
     */
    const MAILER = AvisoDeActualizacionService::MAILER;

    /**
     * Máximo de caracteres de la nota personal.
     */
    const MAX_NOTA = 600;

    /**
     * Cuántos segundos vive el lock de un envío. El peor caso real de un SMTP es de decenas de
     * segundos; esto cubre eso con holgura y, si el proceso muere con el lock tomado, se libera
     * solo.
     */
    const SEGUNDOS_DE_LOCK = 120;

    /**
     * Largo máximo de una casilla para que entre en `clients.email`, que es varchar(150).
     * El endpoint acepta hasta 190 (lo que entra en `implementation_mails.email`), pero una casilla
     * más larga que esto no se podría guardar en la ficha y no existe en la práctica.
     */
    const MAX_CASILLA = 150;

    /**
     * Tope de cualquier cantidad (artículos, fotos, categorías...): siete dígitos. Es un resguardo
     * de la maqueta y no una regla de negocio: la grilla de cifras del mail "listo" muestra dos por
     * fila en 30 px, y en un teléfono de 375 px "99.999.999" ya no entra en media columna (medido).
     * Ningún catálogo se le acerca: 9.999.999 artículos son diez millones.
     */
    const MAX_CANTIDAD = 9999999;

    /**
     * Datos que acepta cada hito, además de `nota` (que lo aceptan todos). Cualquier otra clave es
     * un error: una lista cerrada, como los parámetros de claude/*, para que un nombre mal escrito
     * no se ignore en silencio.
     *
     * @var array<string, array<int, string>>
     */
    const CLAVES_POR_HITO = [
        'bienvenida' => [],
        'instalado'  => [],
        'acceso'     => ['articulos'],
        'imagenes'   => ['con_foto', 'total', 'a_revisar', 'fuentes'],
        'categorias' => ['opciones', 'como_elegir'],
        'listo'      => ['resumen', 'recursos_url', 'arca'],
    ];

    /**
     * Cuántas opciones de categorías acepta el mail "categorias": como mínimo una y como máximo tres
     * (las que armó `/categorizar` cuando eran tres; más es ruido).
     *
     * El mínimo era dos (con una sola no hay nada que ELEGIR). Bajó a una el 7/10/2026 (misión
     * `categorias-del-dueno`): cuando el dueño trae su propia lista de categorías se le ofrece SOLO
     * su sistema, y el mail ya no le pide que elija entre formas sino que revise y confirme la suya.
     * Con dos o tres el mail sale idéntico a como salía.
     */
    const MIN_OPCIONES_DE_CATEGORIAS = 1;
    const MAX_OPCIONES_DE_CATEGORIAS = 3;

    /**
     * Rubros que acepta el resumen del mail "listo".
     *
     * @var array<int, string>
     */
    const CLAVES_DEL_RESUMEN = ['articulos', 'con_foto', 'categorias', 'marcas', 'clientes', 'proveedores'];

    /**
     * Errores por campo de los datos de un hito. Lista vacía = los datos sirven.
     *
     * Valida tipos y máximos exactamente como el plan, y además lo mínimo para que el mail no salga
     * absurdo: un total en cero, más artículos con foto que artículos, una opción sin nombre. Las
     * claves que el hito no conoce también son error.
     *
     * No toca nada: ni la base ni el mail. Es lo que llama el endpoint ANTES de armar la vista
     * previa, y también `previa()` y `enviar()` por su cuenta.
     *
     * @param string               $hito  Uno de HITOS.
     * @param array<string, mixed> $datos Los datos del hito tal como llegaron.
     *
     * @return array<string, string> Campo => qué está mal. Los campos anidados van con puntos:
     *                               `opciones.1.nombre`. Un hito inexistente da {hito: ...}.
     */
    public static function validar_datos(string $hito, array $datos): array
    {
        if (! in_array($hito, self::HITOS, true)) {
            return ['hito' => 'El hito "' . $hito . '" no existe. Los hitos son: ' . implode(', ', self::HITOS) . '.'];
        }

        $procesado = self::procesar($hito, $datos);

        return $procesado['errores'];
    }

    /**
     * El mail armado, listo para mostrar. NO manda nada y NO escribe nada: ni en
     * `implementation_mails` ni en la ficha del cliente.
     *
     * Lo que le falte para poder mandarse no corta la vista previa: va en `faltan`. Así Lucas ve el
     * mail aunque todavía no se pueda mandar, y sabe qué hay que resolver. Los motivos son
     * `email` (no hay casilla), `form_link` (la implementación no tiene link de formulario, solo
     * `bienvenida`) y `url_sistema` (el cliente no tiene dirección de sistema, en `acceso`,
     * `imagenes` y `listo`).
     *
     * Lo único que tira es lo que impide armar el mail de cero: un hito que no existe o datos que
     * no pasan `validar_datos()`. Quien llama los valida antes.
     *
     * @param Implementation       $impl  Implementación a la que pertenece el mail.
     * @param string               $hito  Uno de HITOS.
     * @param array<string, mixed> $datos Datos del hito.
     * @param string|null          $email Casilla explícita; pisa a las demás.
     *
     * @return array{asunto: string, para: string|null, para_enmascarado: string|null, html: string, faltan: array<int, string>}
     *
     * @throws ImplementacionMailException hito_invalido | faltan_datos (datos que no sirven).
     */
    public static function previa(Implementation $impl, string $hito, array $datos, ?string $email): array
    {
        $datos_ok = self::datos_validos_o_excepcion($hito, $datos);

        $armado = self::armar($impl, $hito, $datos_ok, $email);

        return [
            'asunto'           => $armado['asunto'],
            'para'             => $armado['para'],
            'para_enmascarado' => $armado['para'] === null ? null : ImplementacionMailHelper::enmascarar($armado['para']),
            // OJO: se renderiza la vista y NO `$mailable->render()`: esa pasa por el mailer del
            // contenedor, y con `Mail::fake()` (que es como se prueba todo lo que manda mails)
            // el mailer es el falso, que no sabe renderizar. La previa no tiene que depender de eso.
            'html'             => view('emails.implementacion.hito', $armado['vista'])->render(),
            'faltan'           => $armado['faltan'],
        ];
    }

    /**
     * Manda el mail de un hito de verdad y lo registra.
     *
     * Orden de lo que se mira, y el primero que falla corta SIN mandar nada:
     *   1. hito y datos (hito_invalido, faltan_datos);
     *   2. que no haya otro envío del mismo hito en vuelo (envio_en_curso, 409);
     *   3. que ese hito no haya salido ya, salvo que `$reenviar` (ya_enviado);
     *   4. que haya casilla (sin_mail);
     *   5. que no falte nada de la implementación (faltan_datos: form_link, url_sistema).
     * Después, que el mailer `admin` tenga credencial: sin ella no se intenta y el hito queda en
     * `error` con el mismo mensaje que el aviso de actualización.
     *
     * Un envío fallido NO tira excepción: se contesta con `estado: error` y el motivo, y queda en la
     * fila. Un hito en `error` se puede reintentar sin `$reenviar`. Cuenta como fallido también el
     * mail que el servidor SMTP rechazó sin que `send()` tirara (casilla inexistente): ver
     * `RechazosDeCorreoHelper`.
     *
     * Si el mail salió: la fila queda `enviado`; si el cliente no tenía casilla en la ficha, se la
     * guarda; y si se pasó `$email`, esa casilla queda guardada en la ficha aunque tuviera otra.
     * Nada de eso se escribe si el mail no salió.
     *
     * @param Implementation       $impl      Implementación a la que pertenece el mail.
     * @param string               $hito      Uno de HITOS.
     * @param array<string, mixed> $datos     Datos del hito.
     * @param string|null          $email     Casilla explícita; pisa a las demás.
     * @param bool                 $reenviar  true para volver a mandar un hito que ya salió.
     *
     * @return array{estado: string, para_enmascarado: string, enviado_at: string|null, reenvios: int, error: string|null}
     *
     * @throws ImplementacionMailException hito_invalido | faltan_datos | envio_en_curso | ya_enviado | sin_mail.
     */
    public static function enviar(Implementation $impl, string $hito, array $datos, ?string $email, bool $reenviar): array
    {
        $datos_ok = self::datos_validos_o_excepcion($hito, $datos);

        /*
         * OJO: el chequeo "este hito ya salió" y el envío van adentro de un lock, y no es paranoia:
         * entre el SELECT que dice "todavía no salió" y el `send()` no hay nada que impida que otra
         * request lea lo mismo. Una llamada que se corta por timeout y se reintenta —que es
         * exactamente lo que hace quien llama cuando el SMTP tarda— manda el mail DOS veces, y un
         * mail a un cliente real no se deshace.
         *
         * Lock y no transacción con lockForUpdate: una transacción de MySQL abierta durante todo el
         * SMTP es peor que el problema. Mismo criterio que el envío de mensajes de
         * `ClaudeLeadsOutboundController`. Si no se puede tomar, hay otro envío del mismo hito en
         * vuelo: se contesta sin mandar nada.
         */
        $lock = Cache::lock('implementacion-mail-' . (int) $impl->id . '-' . $hito, self::SEGUNDOS_DE_LOCK);

        if (! $lock->get()) {
            throw ImplementacionMailException::envio_en_curso($hito);
        }

        try {
            return self::enviar_bajo_lock($impl, $hito, $datos_ok, $email, $reenviar);
        } finally {
            $lock->release();
        }
    }

    /**
     * Dice qué le falta al mailer `admin` para poder mandar, o `null` si no le falta nada.
     *
     * OJO: es una COPIA de `AvisoDeActualizacionService::que_le_falta_al_mailer()`, que es privado y
     * está en una feature que esta misión no toca. El texto tiene que ser idéntico —el que lee
     * Lucas cuando falta la credencial es el mismo en los dos canales— y hay un test que compara
     * los dos por reflexión para que no se separen.
     *
     * Solo mira la credencial cuando el transporte es SMTP: en los tests el transporte es `array` y
     * no autentica contra nadie.
     *
     * @return string|null
     */
    public static function que_le_falta_al_mailer(): ?string
    {
        $mailer = (array) config('mail.mailers.' . self::MAILER, []);

        if (empty($mailer)) {
            return 'el mailer `' . self::MAILER . '` no está definido en config/mail.php.';
        }

        if ((string) ($mailer['transport'] ?? '') !== 'smtp') {
            return null;
        }

        if (trim((string) ($mailer['username'] ?? '')) === '' || trim((string) ($mailer['password'] ?? '')) === '') {
            return 'el mailer `' . self::MAILER . '` no tiene credencial: cargar MAIL_ADMIN_USERNAME y '
                . 'MAIL_ADMIN_PASSWORD (la casilla admin@comerciocity.com) en el .env del admin.';
        }

        return null;
    }

    /**
     * El cuerpo de `enviar()`, ya con el lock tomado.
     *
     * @param Implementation       $impl
     * @param string               $hito
     * @param array<string, mixed> $datos    Datos ya validados.
     * @param string|null          $email
     * @param bool                 $reenviar
     *
     * @return array<string, mixed>
     *
     * @throws ImplementacionMailException
     */
    private static function enviar_bajo_lock(Implementation $impl, string $hito, array $datos, ?string $email, bool $reenviar): array
    {
        $fila = ImplementationMail::where('implementation_id', $impl->id)
            ->where('hito', $hito)
            ->first();

        if ($fila instanceof ImplementationMail && $fila->esta_enviado() && ! $reenviar) {
            throw ImplementacionMailException::ya_enviado(
                $hito,
                self::fecha_legible($fila),
                ImplementacionMailHelper::enmascarar((string) $fila->email)
            );
        }

        $armado = self::armar($impl, $hito, $datos, $email);

        if ($armado['para'] === null) {
            $aclaracion = $email !== null && trim($email) !== ''
                ? 'La dirección que se pasó en `email` no es una casilla válida.'
                : '';

            throw ImplementacionMailException::sin_mail($aclaracion);
        }

        // Sin la casilla en la lista (ya se resolvió), lo que queda es de la implementación.
        if (! empty($armado['faltan'])) {
            throw ImplementacionMailException::faltan_datos($armado['faltan']);
        }

        $para = $armado['para'];

        /* Antes de intentarlo, que el mailer tenga con qué autenticarse: sin la credencial el SMTP
           contestaría con un error críptico y la fila quedaría con ese texto. Así queda escrito qué
           falta y dónde. */
        $sin_credencial = self::que_le_falta_al_mailer();

        if ($sin_credencial !== null) {
            Log::channel('daily')->error('ImplementacionMail: el mailer no tiene credencial.', [
                'implementation_id' => $impl->id,
                'hito'              => $hito,
                'motivo'            => $sin_credencial,
            ]);

            return self::registrar_fallo($fila, $impl, $hito, $para, $armado['asunto'], $sin_credencial);
        }

        try {
            Mail::mailer(self::MAILER)->to($para)->send($armado['mailable']);
        } catch (\Throwable $excepcion) {
            Log::channel('daily')->error('ImplementacionMail: no se pudo mandar el mail.', [
                'implementation_id' => $impl->id,
                'hito'              => $hito,
                'email'             => $para,
                'error'             => $excepcion->getMessage(),
            ]);

            return self::registrar_fallo($fila, $impl, $hito, $para, $armado['asunto'], self::sin_la_casilla_entera($excepcion->getMessage(), $para));
        }

        /*
         * OJO: que `send()` no haya tirado NO quiere decir que el mail salió. Si el servidor rechaza
         * la casilla (550 en el RCPT TO: "User unknown", un dominio que no existe, un buzón lleno)
         * SwiftMailer no tira excepción: `send()` vuelve normal y las rechazadas quedan en
         * `failures()` del mailer. Sin mirarlas, el hito quedaba `enviado`, la casilla rechazada se
         * guardaba en la ficha del cliente y la skill le avisaba por WhatsApp "te mandé un mail" a
         * alguien que nunca lo recibió (hallazgo ALTO-4 del revisor, con un SMTP real que contesta 550).
         *
         * Se registra como fallo —no cuenta como enviado ni se guarda la casilla en la ficha— y el
         * motivo lleva la casilla enmascarada: la entera ya está en la columna `email` de la fila.
         */
        $rechazadas = RechazosDeCorreoHelper::del_ultimo_envio(self::MAILER);

        if (! empty($rechazadas)) {
            Log::channel('daily')->error('ImplementacionMail: el servidor de correo rechazó la casilla.', [
                'implementation_id' => $impl->id,
                'hito'              => $hito,
                'email'             => $para,
                'rechazadas'        => $rechazadas,
            ]);

            return self::registrar_fallo(
                $fila,
                $impl,
                $hito,
                $para,
                $armado['asunto'],
                'el servidor de correo rechazó la casilla ' . ImplementacionMailHelper::enmascarar($para) . '.'
            );
        }

        /* 🔴 El mail YA SALIÓ. Si anotarlo falla, NO se deja pasar la excepción (un 500): quien llama reintentaría y el dueño
           recibiría el mail dos veces. El índice único `(implementation_id, hito)` no evita el doble mail: solo impide dos filas. */
        try {
            $resultado = self::registrar_envio($fila, $impl, $hito, $para, $armado['asunto']);
        } catch (\Throwable $excepcion) {
            $resultado = self::envio_que_salio_sin_registrarse($impl, $hito, $para, $armado['asunto'], $excepcion);
        }

        self::guardar_la_casilla_en_el_cliente($armado['client'], $para, $email);

        return $resultado;
    }

    /**
     * El mail SALIÓ —el servidor lo aceptó— pero no se pudo anotar en `implementation_mails`.
     *
     * 🔴 Devuelve `estado: enviado` y NO un error ni una excepción: lo que se pidió ya pasó, y un error invita a reintentar y a
     * mandarlo dos veces. Los dos casos que llegan acá:
     *   - Otra llamada ya escribió la fila de este hito mientras ésta mandaba (el lock de 120 s venció con el SMTP colgado, o se
     *     vació el caché): el índice único rechaza el INSERT. Se la relee y se la deja `enviado` con esta fecha.
     *   - La base falló justo entre el SMTP y el INSERT: no hay dónde anotarlo. Queda un log CRÍTICO diciendo que el mail salió, y la
     *     respuesta lleva un `aviso` para que nadie lo reenvíe.
     *
     * @param Implementation $impl
     * @param string         $hito
     * @param string         $para
     * @param string         $asunto
     * @param \Throwable     $excepcion Lo que falló al anotar.
     *
     * @return array<string, mixed>
     */
    private static function envio_que_salio_sin_registrarse(Implementation $impl, string $hito, string $para, string $asunto, \Throwable $excepcion): array
    {
        Log::channel('daily')->critical('ImplementacionMail: el mail SALIÓ pero no se pudo anotar en implementation_mails.', [
            'implementation_id' => $impl->id,
            'hito'              => $hito,
            'email'             => ImplementacionMailHelper::enmascarar($para),
            'error'             => self::mensaje_sin_valores($excepcion),
        ]);

        $enviado_at = now();
        $reenvios   = 0;
        $anotado    = false;

        try {
            $fila = ImplementationMail::where('implementation_id', $impl->id)->where('hito', $hito)->first();

            if ($fila instanceof ImplementationMail) {
                $fila->email      = $para;
                $fila->asunto     = $asunto;
                $fila->estado     = ImplementationMail::ESTADO_ENVIADO;
                $fila->enviado_at = $enviado_at;
                $fila->error      = null;
                $fila->save();

                $reenvios = (int) $fila->reenvios;
                $anotado  = true;
            }
        } catch (\Throwable $segunda) {
            // La base sigue sin responder: quedan el log crítico y el aviso de la respuesta.
        }

        $resultado = [
            'estado'           => ImplementationMail::ESTADO_ENVIADO,
            'para_enmascarado' => ImplementacionMailHelper::enmascarar($para),
            'enviado_at'       => $enviado_at->toIso8601String(),
            'reenvios'         => $reenvios,
            'error'            => null,
        ];

        if (! $anotado) {
            $resultado['aviso'] = 'El mail SALIÓ, pero no se pudo anotar en implementation_mails (' . self::recortar_texto(self::mensaje_sin_valores($excepcion), 160)
                . '). NO lo reenvíes: el dueño ya lo tiene. Mirá el log de la aplicación (crítico) y, si hace falta, dejalo registrado a mano.';
        }

        return $resultado;
    }

    /**
     * El mensaje de una excepción SIN el SQL ni sus valores.
     *
     * Un `QueryException` trae la consulta con sus bindings (`... (SQL: insert into implementation_mails (...) values (..., la
     * casilla entera, ...))`): ni el log ni la respuesta tienen por qué repetir la dirección entera, que ya está en la columna
     * `email` de la fila.
     *
     * @param \Throwable $excepcion Lo que falló.
     *
     * @return string
     */
    private static function mensaje_sin_valores(\Throwable $excepcion): string
    {
        $mensaje = (string) $excepcion->getMessage();
        $corte   = strpos($mensaje, ' (SQL:');

        return $corte === false ? $mensaje : substr($mensaje, 0, $corte);
    }

    /**
     * Cambia la casilla entera por su versión enmascarada dentro de un texto (el motivo de un error del SMTP suele repetir la
     * dirección). La entera ya está en la columna `email` de la fila: no tiene por qué estar también en el motivo ni en las respuestas.
     *
     * @param string $texto El texto del error.
     * @param string $para  La casilla.
     *
     * @return string
     */
    private static function sin_la_casilla_entera(string $texto, string $para): string
    {
        return $para === '' ? $texto : str_ireplace($para, ImplementacionMailHelper::enmascarar($para), $texto);
    }

    /**
     * Recorta un texto a un largo máximo, en caracteres (con puntos suspensivos si se cortó).
     *
     * @param string $texto
     * @param int    $maximo
     *
     * @return string
     */
    private static function recortar_texto(string $texto, int $maximo): string
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));

        return mb_strlen($texto, 'UTF-8') > $maximo ? mb_substr($texto, 0, $maximo, 'UTF-8') . '…' : $texto;
    }

    /**
     * Arma el mail de un hito: resuelve la casilla y el contexto de la implementación, arma la vista
     * y el Mailable. No manda ni escribe nada.
     *
     * @param Implementation       $impl
     * @param string               $hito
     * @param array<string, mixed> $datos           Datos ya validados.
     * @param string|null          $email_explicito
     *
     * @return array{client: Client, vista: array<string, mixed>, mailable: ImplementacionMail, asunto: string, para: string|null, faltan: array<int, string>}
     *
     * @throws ImplementacionMailException faltan_datos si la implementación no tiene cliente.
     */
    private static function armar(Implementation $impl, string $hito, array $datos, ?string $email_explicito): array
    {
        $client = Client::find($impl->client_id);

        if (! $client instanceof Client) {
            throw ImplementacionMailException::faltan_datos(
                ['cliente'],
                [],
                'La implementación #' . (int) $impl->id . ' no tiene un cliente que exista, así que no hay a quién mandarle el mail.'
            );
        }

        $para = self::resolver_la_casilla($client, $email_explicito);

        // La dirección de su sistema: por dónde entra una persona. Sale de la API activa del
        // cliente y, si esa no la tiene, de cualquier otra suya.
        $url_sistema = (new ClientEmpresaApiUrlResolver())->resolve_spa_url($client);

        $contexto = [
            'nombre'       => self::nombre_para_el_saludo($client),
            'negocio'      => self::nombre_del_negocio($client),
            'logo_url'     => (string) config('commerciocity.logo_url', ''),
            'firma_nombre' => (string) config('commerciocity.implementacion_mail.firma_nombre', ''),
            'firma_rol'    => (string) config('commerciocity.implementacion_mail.firma_rol', ''),
            'recursos_url' => (string) config('commerciocity.implementacion_mail.recursos_url', ''),
            'form_link'    => trim((string) $impl->form_link),
            'url_sistema'  => $url_sistema,
            'progreso'     => ImplementacionMailHelper::progreso($impl, $hito),
        ];

        $vista  = ImplementacionMailHelper::vista($contexto, $hito, $datos);
        $faltan = ImplementacionMailHelper::faltan($hito, $contexto);

        if ($para === null) {
            $faltan[] = 'email';
        }

        return [
            'client'   => $client,
            'vista'    => $vista,
            'mailable' => new ImplementacionMail($hito, $vista['asunto'], $vista),
            'asunto'   => $vista['asunto'],
            'para'     => $para,
            'faltan'   => $faltan,
        ];
    }

    /**
     * Valida los datos y devuelve los normalizados, o tira la excepción que corresponde.
     *
     * @param string               $hito
     * @param array<string, mixed> $datos
     *
     * @return array<string, mixed> Los datos del hito normalizados (enteros como int, textos sin espacios de más).
     *
     * @throws ImplementacionMailException hito_invalido | faltan_datos.
     */
    private static function datos_validos_o_excepcion(string $hito, array $datos): array
    {
        if (! in_array($hito, self::HITOS, true)) {
            throw ImplementacionMailException::hito_invalido($hito);
        }

        $procesado = self::procesar($hito, $datos);

        if (! empty($procesado['errores'])) {
            throw ImplementacionMailException::faltan_datos(array_keys($procesado['errores']), $procesado['errores']);
        }

        return $procesado['datos'];
    }

    /**
     * Valida y normaliza los datos de un hito en una sola pasada.
     *
     * Valida y normaliza juntos a propósito: son la misma lectura de cada campo, y separarlas es la
     * manera de que lo que se valida y lo que se imprime se desacoplen.
     *
     * @param string               $hito  Uno de HITOS (ya comprobado).
     * @param array<string, mixed> $datos Lo que llegó.
     *
     * @return array{datos: array<string, mixed>, errores: array<string, string>}
     */
    private static function procesar(string $hito, array $datos): array
    {
        $errores = [];
        $limpio  = [];

        // 1. Claves que el hito no conoce.
        $permitidas = array_merge(['nota'], self::CLAVES_POR_HITO[$hito]);

        foreach (array_keys($datos) as $clave) {
            if (! in_array((string) $clave, $permitidas, true)) {
                $errores[(string) $clave] = 'No es un dato del hito "' . $hito . '".'
                    . (count($permitidas) > 1 ? ' Los que acepta: ' . implode(', ', $permitidas) . '.' : ' Solo acepta `nota`.');
            }
        }

        // 2. La nota personal, común a todos los hitos. Se parte en párrafos por los saltos de línea.
        $nota = self::leer_texto($datos, 'nota', 'nota', self::MAX_NOTA, false, true, $errores);

        $limpio['nota'] = $nota === null ? [] : self::en_parrafos($nota);

        // 3. Lo propio de cada hito.
        switch ($hito) {
            case 'acceso':
                $articulos = self::leer_entero($datos, 'articulos', 'articulos', 0, true, $errores);

                if ($articulos !== null) {
                    $limpio['articulos'] = $articulos;
                }
                break;

            case 'imagenes':
                $con_foto  = self::leer_entero($datos, 'con_foto', 'con_foto', 0, true, $errores);
                $total     = self::leer_entero($datos, 'total', 'total', 0, true, $errores);
                $a_revisar = self::leer_entero($datos, 'a_revisar', 'a_revisar', 0, false, $errores);
                $fuentes   = self::leer_texto($datos, 'fuentes', 'fuentes', 160, false, false, $errores);

                // Un mail de fotos sobre un catálogo vacío no tiene qué decir, y sin total no hay
                // porcentaje.
                if ($total !== null && $total < 1) {
                    $errores['total'] = 'Tiene que ser mayor que cero: no hay porcentaje de fotos de un catálogo vacío.';
                }

                if ($con_foto !== null && $total !== null && $total >= 1 && $con_foto > $total) {
                    $errores['con_foto'] = 'No puede ser mayor que el total (' . $total . '): saldría "más del 100 %".';
                }

                if ($a_revisar !== null && $total !== null && $total >= 1 && $a_revisar > $total) {
                    $errores['a_revisar'] = 'No puede ser mayor que el total (' . $total . '): saldría "más fotos por revisar que artículos".';
                }

                if ($con_foto !== null) {
                    $limpio['con_foto'] = $con_foto;
                }

                if ($total !== null) {
                    $limpio['total'] = $total;
                }

                if ($a_revisar !== null) {
                    $limpio['a_revisar'] = $a_revisar;
                }

                if ($fuentes !== null) {
                    $limpio['fuentes'] = $fuentes;
                }
                break;

            case 'categorias':
                $opciones = self::leer_las_opciones($datos, $errores);

                if ($opciones !== null) {
                    $limpio['opciones'] = $opciones;
                }

                $como_elegir = self::leer_texto($datos, 'como_elegir', 'como_elegir', 220, false, false, $errores);

                if ($como_elegir !== null) {
                    $limpio['como_elegir'] = $como_elegir;
                }
                break;

            case 'listo':
                $resumen = self::leer_el_resumen($datos, $errores);

                if ($resumen !== null) {
                    $limpio['resumen'] = $resumen;
                }

                if ($resumen !== null && isset($resumen['con_foto'], $resumen['articulos']) && $resumen['con_foto'] > $resumen['articulos']) {
                    $errores['resumen.con_foto'] = 'No puede ser mayor que los artículos (' . $resumen['articulos'] . '): saldría "más fotos que artículos".';
                }

                /* `arca: false` saca del mail la línea de la facturación electrónica: a un cliente que no factura así no se le
                   dice que se la conectamos. Sin el dato (o en true) la línea va. */
                if (array_key_exists('arca', $datos) && $datos['arca'] !== null) {
                    if (is_bool($datos['arca'])) {
                        $limpio['arca'] = $datos['arca'];
                    } else {
                        $errores['arca'] = 'Tiene que ser verdadero o falso (false saca la línea de ARCA del mail).';
                    }
                }

                $recursos_url = self::leer_texto($datos, 'recursos_url', 'recursos_url', 500, false, false, $errores);

                if ($recursos_url !== null) {
                    if (self::es_una_url_web($recursos_url)) {
                        $limpio['recursos_url'] = $recursos_url;
                    } else {
                        $errores['recursos_url'] = 'Tiene que ser una URL http o https válida.';
                    }
                }
                break;

            default:
                // bienvenida e instalado no llevan datos propios.
                break;
        }

        return ['datos' => $limpio, 'errores' => $errores];
    }

    /**
     * Lee y valida las opciones de categorías del mail "categorias".
     *
     * Una, dos o tres (`MIN_OPCIONES_DE_CATEGORIAS` y `MAX_OPCIONES_DE_CATEGORIAS`): desde el 6/10/2026
     * `/categorizar` arma DOS sistemas por defecto y tres solo si Lucas lo pide, y desde el 7/10/2026
     * (misión `categorias-del-dueno`) UNA sola cuando el dueño trae su propia lista de categorías.
     * Dos y tres entran igual que antes (compatible hacia atrás); cero o cuatro o más no, porque sin
     * ninguna no hay nada que mostrar y más de tres es ruido. Cada una con nombre (hasta 60 caracteres),
     * base (hasta 280, en qué se basa), categorias (entero de 1 en adelante) y, si hay, hasta cuatro
     * ejemplos de hasta 40 caracteres. El texto del mail cambia según cuántas lleguen: "dos" o "tres"
     * formas para elegir, o el texto de la lista única si llega una sola.
     *
     * @param array<string, mixed>  $datos
     * @param array<string, string> $errores
     *
     * @return array<int, array<string, mixed>>|null Las opciones normalizadas, o null si algo falló.
     */
    private static function leer_las_opciones(array $datos, array &$errores): ?array
    {
        if (! array_key_exists('opciones', $datos) || $datos['opciones'] === null) {
            $errores['opciones'] = 'Es obligatorio: las opciones de categorías (una, dos o tres).';

            return null;
        }

        if (! is_array($datos['opciones'])) {
            $errores['opciones'] = 'Tiene que ser una lista con una, dos o tres opciones.';

            return null;
        }

        if (count($datos['opciones']) < self::MIN_OPCIONES_DE_CATEGORIAS || count($datos['opciones']) > self::MAX_OPCIONES_DE_CATEGORIAS) {
            $errores['opciones'] = 'Tienen que ser una, dos o tres opciones (llegaron ' . count($datos['opciones']) . ').';

            return null;
        }

        $salida  = [];
        $valido  = true;

        foreach (array_values($datos['opciones']) as $posicion => $opcion) {
            $ruta = 'opciones.' . $posicion;

            if (! is_array($opcion)) {
                $errores[$ruta] = 'Cada opción tiene que ser un objeto con nombre, base, categorias y ejemplos.';
                $valido = false;
                continue;
            }

            foreach (array_keys($opcion) as $clave) {
                if (! in_array((string) $clave, ['nombre', 'base', 'categorias', 'ejemplos'], true)) {
                    $errores[$ruta . '.' . $clave] = 'No es un dato de la opción. Los que acepta: nombre, base, categorias, ejemplos.';
                    $valido = false;
                }
            }

            $nombre     = self::leer_texto($opcion, 'nombre', $ruta . '.nombre', 60, true, false, $errores);
            $base       = self::leer_texto($opcion, 'base', $ruta . '.base', 280, true, false, $errores);
            $categorias = self::leer_entero($opcion, 'categorias', $ruta . '.categorias', 1, true, $errores);
            $ejemplos   = self::leer_los_ejemplos($opcion, $ruta, $errores);

            if ($nombre === null || $base === null || $categorias === null || $ejemplos === null) {
                $valido = false;
                continue;
            }

            $salida[] = [
                'nombre'     => $nombre,
                'base'       => $base,
                'categorias' => $categorias,
                'ejemplos'   => $ejemplos,
            ];
        }

        if ($valido) {
            $nombres = array_map(function ($opcion) {
                return mb_strtolower($opcion['nombre'], 'UTF-8');
            }, $salida);

            if (count(array_unique($nombres)) !== count($nombres)) {
                $errores['opciones'] = 'Las opciones tienen que tener nombres distintos: son formas distintas de ordenar el catálogo.';
                $valido = false;
            }
        }

        return $valido ? $salida : null;
    }

    /**
     * Lee los ejemplos de una opción de categorías: hasta cuatro textos de hasta 40 caracteres.
     * Sin ejemplos es válido (la opción sale sin fichas).
     *
     * @param array<string, mixed>  $opcion
     * @param string                $ruta   Prefijo de los errores (`opciones.0`).
     * @param array<string, string> $errores
     *
     * @return array<int, string>|null La lista (posiblemente vacía), o null si algo falló.
     */
    private static function leer_los_ejemplos(array $opcion, string $ruta, array &$errores): ?array
    {
        if (! array_key_exists('ejemplos', $opcion) || $opcion['ejemplos'] === null) {
            return [];
        }

        if (! is_array($opcion['ejemplos'])) {
            $errores[$ruta . '.ejemplos'] = 'Tiene que ser una lista de textos.';

            return null;
        }

        if (count($opcion['ejemplos']) > 4) {
            $errores[$ruta . '.ejemplos'] = 'Hasta cuatro ejemplos (llegaron ' . count($opcion['ejemplos']) . ').';

            return null;
        }

        $salida = [];
        $valido = true;

        foreach (array_values($opcion['ejemplos']) as $posicion => $ejemplo) {
            $texto = self::leer_texto(['ejemplo' => $ejemplo], 'ejemplo', $ruta . '.ejemplos.' . $posicion, 40, true, false, $errores);

            if ($texto === null) {
                $valido = false;
                continue;
            }

            $salida[] = $texto;
        }

        return $valido ? $salida : null;
    }

    /**
     * Lee el resumen del mail "listo": un objeto con los rubros de lo que quedó cargado, todos
     * enteros de cero en adelante y todos opcionales.
     *
     * @param array<string, mixed>  $datos
     * @param array<string, string> $errores
     *
     * @return array<string, int>|null Los rubros que vinieron, o null si no vino resumen o algo falló.
     */
    private static function leer_el_resumen(array $datos, array &$errores): ?array
    {
        if (! array_key_exists('resumen', $datos) || $datos['resumen'] === null) {
            return null;
        }

        if (! is_array($datos['resumen'])) {
            $errores['resumen'] = 'Tiene que ser un objeto con ' . implode(', ', self::CLAVES_DEL_RESUMEN) . '.';

            return null;
        }

        $valido = true;

        foreach (array_keys($datos['resumen']) as $clave) {
            if (! in_array((string) $clave, self::CLAVES_DEL_RESUMEN, true)) {
                $errores['resumen.' . $clave] = 'No es un rubro del resumen. Los que acepta: ' . implode(', ', self::CLAVES_DEL_RESUMEN) . '.';
                $valido = false;
            }
        }

        $salida = [];

        foreach (self::CLAVES_DEL_RESUMEN as $clave) {
            $cantidad = self::leer_entero($datos['resumen'], $clave, 'resumen.' . $clave, 0, false, $errores);

            if ($cantidad !== null) {
                $salida[$clave] = $cantidad;
            }
        }

        return $valido ? $salida : null;
    }

    /**
     * Lee un entero de un arreglo: acepta un int o un string de dígitos ("12", que es como llega de
     * un formulario) y nada más. Los decimales, los negativos y los booleanos son error.
     *
     * @param array<string, mixed>  $origen      Arreglo del que se lee.
     * @param string                $campo       Clave dentro de `$origen`.
     * @param string                $ruta        Nombre del campo en los errores.
     * @param int                   $minimo      Valor mínimo aceptado.
     * @param bool                  $obligatorio Si falta, es error.
     * @param array<string, string> $errores     Se agregan acá.
     *
     * @return int|null El entero, o null si no vino o no sirve (en ese caso queda el error).
     */
    private static function leer_entero(array $origen, string $campo, string $ruta, int $minimo, bool $obligatorio, array &$errores): ?int
    {
        if (! array_key_exists($campo, $origen) || $origen[$campo] === null || $origen[$campo] === '') {
            if ($obligatorio) {
                $errores[$ruta] = 'Es obligatorio.';
            }

            return null;
        }

        $valor = $origen[$campo];

        if (is_string($valor) && preg_match('/^[0-9]{1,9}$/', $valor) === 1) {
            $valor = (int) $valor;
        }

        if (! is_int($valor)) {
            $errores[$ruta] = 'Tiene que ser un número entero.';

            return null;
        }

        if ($valor < $minimo) {
            $errores[$ruta] = 'Tiene que ser ' . $minimo . ' o más.';

            return null;
        }

        if ($valor > self::MAX_CANTIDAD) {
            $errores[$ruta] = 'Es demasiado grande (el máximo es ' . self::MAX_CANTIDAD . ').';

            return null;
        }

        return $valor;
    }

    /**
     * Lee un texto de un arreglo: sin espacios de más, en UTF-8 válido y dentro del máximo.
     *
     * Un texto vacío cuenta como que no vino. En los de una sola línea, los saltos de línea y los
     * espacios repetidos se vuelven un espacio; en los multilínea (la nota) se conservan los saltos.
     *
     * @param array<string, mixed>  $origen      Arreglo del que se lee.
     * @param string                $campo       Clave dentro de `$origen`.
     * @param string                $ruta        Nombre del campo en los errores.
     * @param int                   $maximo      Largo máximo, en caracteres.
     * @param bool                  $obligatorio Si falta o está vacío, es error.
     * @param bool                  $multilinea  Si conserva los saltos de línea.
     * @param array<string, string> $errores     Se agregan acá.
     *
     * @return string|null El texto limpio, o null si no vino o no sirve (en ese caso queda el error).
     */
    private static function leer_texto(array $origen, string $campo, string $ruta, int $maximo, bool $obligatorio, bool $multilinea, array &$errores): ?string
    {
        if (! array_key_exists($campo, $origen) || $origen[$campo] === null) {
            if ($obligatorio) {
                $errores[$ruta] = 'Es obligatorio.';
            }

            return null;
        }

        if (! is_string($origen[$campo])) {
            $errores[$ruta] = 'Tiene que ser un texto.';

            return null;
        }

        // Un texto que no es UTF-8 válido sale vacío del escape de HTML, sin avisar: se frena acá.
        if (! mb_check_encoding($origen[$campo], 'UTF-8')) {
            $errores[$ruta] = 'Tiene que estar codificado en UTF-8.';

            return null;
        }

        $texto = trim(self::sin_caracteres_de_control($origen[$campo]));

        if (! $multilinea) {
            $texto = (string) preg_replace('/\s+/u', ' ', $texto);
        }

        if ($texto === '') {
            if ($obligatorio) {
                $errores[$ruta] = 'No puede estar vacío.';
            }

            return null;
        }

        $largo = mb_strlen($texto, 'UTF-8');

        if ($largo > $maximo) {
            $errores[$ruta] = 'Admite hasta ' . $maximo . ' caracteres y tiene ' . $largo . '.';

            return null;
        }

        return $texto;
    }

    /**
     * Parte un texto en párrafos por sus saltos de línea. Cada párrafo queda sin espacios de más y
     * los vacíos se descartan.
     *
     * @param string $texto
     *
     * @return array<int, string>
     */
    private static function en_parrafos(string $texto): array
    {
        $parrafos = [];

        foreach ((array) preg_split('/\R/u', $texto) as $linea) {
            $linea = trim((string) preg_replace('/\s+/u', ' ', (string) $linea));

            if ($linea !== '') {
                $parrafos[] = $linea;
            }
        }

        return $parrafos;
    }

    /**
     * Indica si un texto es una URL web: http o https, con host. Es lo único que entra en un
     * `href` del mail: un `javascript:` o un `data:` no es un link, es un problema.
     *
     * @param string $url
     *
     * @return bool
     */
    private static function es_una_url_web(string $url): bool
    {
        if (preg_match('#^https?://#i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // Nada de comillas, ángulos ni espacios (es un `href`), y nada de usuario:clave@ en un link que ve el dueño.
        if (preg_match('#[\s"\'<>`]#u', $url) === 1) {
            return false;
        }

        $partes = parse_url($url);

        return is_array($partes) && ! empty($partes['host']) && ! isset($partes['user']) && ! isset($partes['pass']);
    }

    /**
     * Saca de un texto los caracteres que no tienen lugar en un mail: los de control (menos el salto de línea y el retorno de
     * carro, que los textos multilínea conservan), el DEL, los de ancho cero y los que cambian la dirección del texto (los
     * RTL/LTR override de U+202A a U+202E y los aislantes de U+2066 a U+2069, que dan vuelta lo que se lee).
     *
     * @param string $texto
     *
     * @return string
     */
    private static function sin_caracteres_de_control(string $texto): string
    {
        return (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}]/u', '', $texto);
    }

    /**
     * A quién se le manda: el primero que sirve de la lista, o null.
     *
     * Con `$email_explicito` se manda a esa y a ninguna otra: si es inválida, es null (no se cae a
     * otra fuente, porque mandarle a una dirección que nadie pidió es peor que no mandar).
     *
     * @param Client      $client
     * @param string|null $email_explicito
     *
     * @return string|null
     */
    private static function resolver_la_casilla(Client $client, ?string $email_explicito): ?string
    {
        if ($email_explicito !== null && trim($email_explicito) !== '') {
            return self::casilla_valida($email_explicito);
        }

        $setup_data = is_array($client->setup_data) ? $client->setup_data : [];

        $candidatas = [
            $client->email,
            isset($setup_data['email']) ? $setup_data['email'] : null,
        ];

        foreach ($candidatas as $candidata) {
            $casilla = self::casilla_valida($candidata);

            if ($casilla !== null) {
                return $casilla;
            }
        }

        // El lead que se promovió a este cliente: su email es el que dejó en la demo.
        $lead = Lead::where('promoted_client_id', $client->id)->orderBy('id')->first();

        return $lead instanceof Lead ? self::casilla_valida($lead->email) : null;
    }

    /**
     * Una casilla bien formada y que entra en `clients.email`, recortada; o null.
     *
     * @param mixed $valor Lo que haya.
     *
     * @return string|null
     */
    private static function casilla_valida($valor): ?string
    {
        $limpia = ClientContactEmailResolver::mail_valido($valor);

        if ($limpia === null || mb_strlen($limpia, 'UTF-8') > self::MAX_CASILLA) {
            return null;
        }

        return $limpia;
    }

    /**
     * El primer nombre del contacto, para el saludo. Vacío = el mail saluda "Hola." a secas.
     *
     * "Cliente" es el relleno con el que se crea un cliente cuando el lead no traía nombre
     * (`RunUserSetupService::ensure_production_client()`): saludar "Hola, Cliente." es peor que no
     * poner nombre.
     *
     * @param Client $client
     *
     * @return string
     */
    private static function nombre_para_el_saludo(Client $client): string
    {
        $primer_nombre = Lead::primer_nombre_de((string) $client->name);
        $primer_nombre = $primer_nombre === null ? '' : $primer_nombre;

        return mb_strtolower($primer_nombre, 'UTF-8') === 'cliente' ? '' : $primer_nombre;
    }

    /**
     * El nombre del negocio: el que escribió el cliente en el formulario y, si no, el de la ficha.
     * Vacío si no hay ninguno (las vistas lo resuelven con "tu negocio").
     *
     * @param Client $client
     *
     * @return string
     */
    private static function nombre_del_negocio(Client $client): string
    {
        $setup_data = is_array($client->setup_data) ? $client->setup_data : [];

        $candidatos = [
            isset($setup_data['company_name']) ? $setup_data['company_name'] : null,
            $client->company_name,
        ];

        foreach ($candidatos as $candidato) {
            if (is_string($candidato) && trim($candidato) !== '') {
                /* El del formulario es texto libre del cliente, sin tope ni saneo: una sola línea y hasta 80 caracteres. */
                $limpio = trim((string) preg_replace('/\s+/u', ' ', self::sin_caracteres_de_control($candidato)));

                return mb_substr($limpio, 0, 80, 'UTF-8');
            }
        }

        return '';
    }

    /**
     * Anota en `implementation_mails` que el mail salió y devuelve el resultado.
     *
     * - Sin fila: se crea `enviado`.
     * - Fila en `error`: pasa a `enviado`; no cuenta como reenvío, porque nunca había salido.
     * - Fila `enviado` (se pidió reenviar): se actualiza la fecha y suma un reenvío.
     *
     * @param ImplementationMail|null $fila
     * @param Implementation          $impl
     * @param string                  $hito
     * @param string                  $para
     * @param string                  $asunto
     *
     * @return array<string, mixed>
     */
    private static function registrar_envio(?ImplementationMail $fila, Implementation $impl, string $hito, string $para, string $asunto): array
    {
        if ($fila === null) {
            $fila                    = new ImplementationMail();
            $fila->implementation_id = (int) $impl->id;
            $fila->hito              = $hito;
            $fila->reenvios          = 0;
        } elseif ($fila->esta_enviado()) {
            $fila->reenvios = (int) $fila->reenvios + 1;
        }

        $fila->email      = $para;
        $fila->asunto     = $asunto;
        $fila->estado     = ImplementationMail::ESTADO_ENVIADO;
        $fila->enviado_at = now();
        $fila->error      = null;
        $fila->save();

        return [
            'estado'           => ImplementationMail::ESTADO_ENVIADO,
            'para_enmascarado' => ImplementacionMailHelper::enmascarar($para),
            'enviado_at'       => $fila->enviado_at->toIso8601String(),
            'reenvios'         => (int) $fila->reenvios,
            'error'            => null,
        ];
    }

    /**
     * Anota en `implementation_mails` que el mail NO salió y devuelve el resultado.
     *
     * - Sin fila: se crea en `error`.
     * - Fila en `error`: se actualiza el motivo.
     * - Fila `enviado` (se pidió reenviar y falló): el hito sigue `enviado` con su fecha y sus
     *   reenvíos, porque el mail original sí salió; el motivo queda en `error` aclarando eso. La
     *   respuesta igual es `estado: error`, porque lo que se pidió ahora no salió.
     *
     * @param ImplementationMail|null $fila
     * @param Implementation          $impl
     * @param string                  $hito
     * @param string                  $para
     * @param string                  $asunto
     * @param string                  $detalle Por qué no salió, sin prefijo.
     *
     * @return array<string, mixed>
     */
    private static function registrar_fallo(?ImplementationMail $fila, Implementation $impl, string $hito, string $para, string $asunto, string $detalle): array
    {
        if ($fila instanceof ImplementationMail && $fila->esta_enviado()) {
            // El motivo puede venir de un SMTP ("Timeout") sin punto final: se lo agrega para que la
            // aclaración que sigue no quede pegada.
            $detalle     = rtrim($detalle);
            $detalle     = preg_match('/[.!?]$/', $detalle) === 1 ? $detalle : $detalle . '.';
            $motivo      = 'No se pudo reenviar el mail: ' . $detalle . ' El mail original sí salió el ' . self::fecha_legible($fila) . '.';
            $fila->error = $motivo;
            $fila->save();
        } else {
            // El mismo texto que escribe el aviso de actualización: "No se pudo mandar el mail: ...".
            $motivo = 'No se pudo mandar el mail: ' . $detalle;

            if ($fila === null) {
                $fila                    = new ImplementationMail();
                $fila->implementation_id = (int) $impl->id;
                $fila->hito              = $hito;
                $fila->reenvios          = 0;
                $fila->enviado_at        = null;
            }

            $fila->email  = $para;
            $fila->asunto = $asunto;
            $fila->estado = ImplementationMail::ESTADO_ERROR;
            $fila->error  = $motivo;
            $fila->save();
        }

        return [
            'estado'           => ImplementationMail::ESTADO_ERROR,
            'para_enmascarado' => ImplementacionMailHelper::enmascarar($para),
            'enviado_at'       => $fila->enviado_at === null ? null : $fila->enviado_at->toIso8601String(),
            'reenvios'         => (int) $fila->reenvios,
            'error'            => $motivo,
        ];
    }

    /**
     * Guarda en la ficha del cliente la casilla a la que SALIÓ el mail.
     *
     * Se guarda si la ficha no tenía casilla (así el aviso de actualización tampoco queda
     * `sin_mail`) o si se pasó una a propósito. Si la ficha ya tenía una y no se pidió otra, no se
     * toca: el mail pudo haber salido a la del formulario y la de la ficha es la fuente de verdad.
     *
     * Va por el query builder y no con `$client->save()` por el mismo motivo que
     * `ClientContactEmailResolver::recordar()`: no persistir de rebote atributos que otro haya
     * tocado en la instancia. Y nada de acá puede hacer fallar el envío: el mail ya salió.
     *
     * @param Client      $client
     * @param string      $para            Casilla a la que salió.
     * @param string|null $email_explicito Lo que se pasó en `email`.
     *
     * @return void
     */
    private static function guardar_la_casilla_en_el_cliente(Client $client, string $para, ?string $email_explicito): void
    {
        $se_pidio_esa = $email_explicito !== null && trim($email_explicito) !== '';
        $ficha_vacia  = trim((string) $client->email) === '';

        if (! $se_pidio_esa && ! $ficha_vacia) {
            return;
        }

        if (trim((string) $client->email) === $para) {
            return;
        }

        try {
            Client::where('id', $client->id)->update(['email' => $para]);

            Log::channel('daily')->info('ImplementacionMail: se guardó la casilla del dueño en la ficha.', [
                'client_id' => $client->id,
                'email'     => $para,
            ]);
        } catch (\Throwable $excepcion) {
            Log::channel('daily')->warning('ImplementacionMail: el mail salió pero no se pudo guardar la casilla en la ficha.', [
                'client_id' => $client->id,
                'error'     => $excepcion->getMessage(),
            ]);
        }
    }

    /**
     * La fecha del último envío de una fila, legible: "05/10/2026 11:32".
     *
     * @param ImplementationMail $fila
     *
     * @return string
     */
    private static function fecha_legible(ImplementationMail $fila): string
    {
        return $fila->enviado_at === null ? 'sin fecha' : $fila->enviado_at->format('d/m/Y H:i');
    }
}
