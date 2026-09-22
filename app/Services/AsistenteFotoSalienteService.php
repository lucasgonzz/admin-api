<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Las fotos que el asistente del sistema de un cliente le manda al dueño por WhatsApp.
 *
 * Es la mitad de vuelta de {@see AsistenteImagenesService}: ahí las fotos van del dueño al
 * asistente, acá vuelven del asistente al dueño. El `empresa-api` las devuelve como `adjuntos`
 * —`[{tipo:'imagen', url, texto}]`— y son las imágenes del catálogo de artículos de ese cliente,
 * URLs públicas de su hosting o de R2.
 *
 * 🔴 **Por qué existe esta clase: Meta descarta el webp y no lo dice.** La Cloud API acepta en un
 * mensaje de tipo `image` únicamente **jpeg y png**; el webp lo reserva para stickers. Y valida el
 * link de forma **asíncrona**, después de haber contestado 200 con un `wamid` — así que el admin
 * registra "foto enviada", el log queda en verde y al dueño no le llega nada, sin un solo error en
 * ningún lado. Medido en producción el 22/9/2026: el dueño escribió *"la foto nunca me llegó"* un
 * minuto después de que el log dijera `imagenes_enviadas: 1, imagenes_fallidas: 0`. Y no es un caso
 * de borde: en demo3 **las 2.663 fotos de artículos son webp, el 100 %** — o sea que mientras el
 * ERP guarde webp, por link no llega ninguna.
 *
 * 🔴 **Acá tampoco se guarda nada en disco.** Los bytes se bajan, se convierten en memoria y se
 * pasan al envío. Son las fotos del catálogo de un cliente: una copia en el storage del admin no le
 * sirve a nadie. Es la misma regla que ya sigue el servicio de las fotos entrantes.
 *
 * 🔴 **Y a la descarga no se le pega NINGUNA credencial.** La URL es del hosting del cliente, no de
 * Kapso: mandarle el `X-API-Key` de Kapso —como sí hace `WhatsappInboundMediaService` con la media
 * entrante, que sale contra `api.kapso.ai`— sería regalarle la clave de la plataforma al servidor
 * de un tercero. El pedido va pelado.
 *
 * 🔴 **Y no va a cualquier lado.** La URL la elige el `empresa-api` de un cliente y el que ahora la
 * visita es este proceso, adentro del VPS. Antes la visitaba Meta desde afuera, así que el destino
 * no importaba; ahora sí. El control está en {@see motivo_para_no_bajar()}, con el porqué completo.
 */
class AsistenteFotoSalienteService
{
    /**
     * Extensiones con las que un link se manda tal cual, sin bajar nada.
     *
     * Son las que Meta baja sola y acepta. Cualquier otra —y la ausencia de extensión— manda la
     * foto por el carril largo, que es el único que puede afirmar algo sobre los bytes.
     */
    const EXTENSIONES_QUE_META_BAJA = ['jpg', 'jpeg', 'png'];

    /**
     * Mimes que Meta acepta en un mensaje `image`. Webp NO está, y esa ausencia es todo el bug.
     */
    const MIMES_QUE_META_ACEPTA = ['image/jpeg', 'image/png'];

    /**
     * Tope de Meta para la imagen de un mensaje `image`: 5 MB.
     */
    const MAXIMO_DE_BYTES_PARA_META = 5242880;

    /**
     * Tope de lo que se baja del hosting del cliente, en bytes.
     *
     * Más alto que el de Meta a propósito: un original de 8 MB se puede achicar hasta entrar, y
     * descartarlo en la descarga sería perder una foto que se podía mandar. Lo que sí corta es el
     * disparate — un archivo de 50 MB en la memoria del worker del admin, que además corre
     * deployments.
     */
    const MAXIMO_DE_BYTES_DE_DESCARGA = 12582912;

    /**
     * Lado mayor con el que arranca la conversión, en píxeles.
     *
     * Una foto de catálogo en WhatsApp se ve en un teléfono: 1600 px de lado mayor es de sobra y
     * deja el JPEG bien por debajo del tope en la primera pasada, sin una segunda vuelta.
     */
    const LADO_MAXIMO = 1600;

    /**
     * Lado por debajo del cual ya no se sigue achicando: si a esa altura no entra, no es una foto.
     *
     * ⚠️ Es un piso del ACHICADO, no un mínimo de entrada: una foto que ya mide menos que esto se
     * convierte igual, en una sola pasada y sin tocarle el tamaño.
     */
    const LADO_MINIMO = 400;

    /**
     * Calidades de JPEG que se prueban, en orden, antes de achicar otra vez.
     */
    const CALIDADES_DE_JPEG = [82, 68, 55];

    /**
     * Segundos de espera de la descarga.
     *
     * 🔴 **Sale de una cuenta, no de un número redondo.** Esto corre adentro del job del turno, que
     * tiene `$timeout = 60`, y el peor caso de UNA foto por media_id es la pausa del 409 (1,2 s) +
     * esta descarga + la subida a `/media` + el mensaje. Con 20 segundos acá —que es lo que decía
     * antes— una sola foto llegaba a ~82 s y mataba el ingreso: el turno quedaba sin la línea de
     * log del final, justo la que trae los contadores. Ocho, más los topes de
     * `EnviarMensajeAlAsistenteJob::SEGUNDOS_POR_ENVIO_DE_FOTO`, dejan el peor caso de una foto
     * cerca de los 30 s; que no se pasen de ahí varias fotos lo garantiza el presupuesto acumulado
     * del job, no este número.
     */
    const SEGUNDOS_DE_DESCARGA = 8;

    /**
     * Saltos de redirect que se siguen, revalidando el destino en cada uno.
     *
     * Dos alcanzan para el caso real —`http://` que el hosting normaliza a `https://`, y de ahí a
     * la ruta final— y ponen un techo a la cadena.
     */
    const MAXIMO_DE_SALTOS = 2;

    /**
     * Tamaño de cada pedazo que se lee del cuerpo de la respuesta, en bytes.
     */
    const BYTES_POR_PEDAZO = 262144;

    /**
     * Destinos a los que este servicio NO sale, resueltos sobre la IP.
     *
     * Los primeros los tapa también `filter_var()` con `NO_PRIV_RANGE | NO_RES_RANGE` y están
     * igual: si una versión de PHP cambia lo que esas banderas cubren, el agujero se abre solo y en
     * silencio. Los que `filter_var` **no** cubre —medido contra el PHP 7.4.33 el 22/9/2026— son
     * `100.64.0.0/10` (el CGNAT de los hostings), `192.0.0.0/24`, `198.18.0.0/15`, el multicast y,
     * el peor de todos, `::ffff:0:0/96`: ahí viven las IPv4 mapeadas en IPv6, o sea que
     * `::ffff:127.0.0.1` **pasaba** el filtro de PHP. Es el bypass de manual.
     */
    const RANGOS_NO_RUTEABLES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
    ];

    /**
     * Tope de resolución de una foto, en píxeles.
     *
     * 🔴 **Esto es la guarda contra la bomba de píxeles, y no se puede reemplazar por el tope de
     * bytes.** `MAXIMO_DE_BYTES_DE_DESCARGA` mide el archivo COMPRIMIDO; lo que GD reserva es la
     * imagen DESCOMPRIMIDA, y la cuenta es `ancho × alto × 4` bytes por el truecolor. Un PNG de
     * color plano de 12000×12000 pesa **446.516 bytes** —pasa cualquier tope de archivo sin
     * despeinarse— y le pide a GD **549 MB**. Con un webp bien comprimido se llega a varios GB.
     *
     * Y el `memory_limit` no salva de nada, en ninguno de los dos sentidos. Medido en el VPS de
     * producción el 22/9/2026: el PHP 7.4 del CLI tiene `memory_limit = 4048M` y el worker corre
     * con `--memory=1024`, que Laravel sólo chequea ENTRE jobs, nunca durante. O sea que una
     * imagen de 3 GB entra en el límite y se come la RAM de una máquina de 16 GB donde también
     * viven MySQL, Redis y los 40+ clientes migrados — y si en cambio se pasa del límite, agotar
     * la memoria en PHP 7 es un **error fatal, no un `Throwable`**: no lo agarra ningún `catch` y
     * se lleva puesto el worker, que en este admin es uno solo y además corre deployments.
     *
     * 40 megapíxeles son 160 MB de GD. Es holgadísimo para lo que existe: las fotos de artículos
     * de demo3 pesan entre 7 KB y 92 KB.
     */
    const MAXIMO_DE_MEGAPIXELES = 40;

    /**
     * Bytes que ocupa un píxel de una imagen truecolor de GD.
     */
    const BYTES_POR_PIXEL = 4;

    /**
     * Memoria que se deja libre al calcular cuántos píxeles entran, en bytes.
     *
     * Cubre lo que vive al lado de la imagen de origen mientras se convierte: el binario original
     * (hasta 12 MB), el lienzo de destino (1600×1600×4 ≈ 10 MB), el buffer del JPEG de salida y lo
     * que GD pida de más durante el `imagecopyresampled`.
     */
    const RESERVA_DE_MEMORIA = 67108864;

    /**
     * Decide si una foto puede salir por link o si hay que bajarla y convertirla.
     *
     * 🔴 **ACÁ ESTÁ LA TENTACIÓN, Y LAS DOS FORMAS DE SIMPLIFICARLA ESTÁN MAL.**
     *
     * *"Mandemos todo por link, que es más barato"* — es lo que se hacía hasta el 22/9/2026 y es el
     * bug: Meta acepta el request, contesta un `wamid`, valida el link después y descarta el webp
     * en silencio. No hay excepción, no hay status de error, no hay línea de log. La única señal es
     * el dueño diciendo que no le llegó nada.
     *
     * *"Convirtamos todas y listo, un solo camino"* — funciona, pero cada foto pasa a costar dos
     * viajes de red (bajarla del hosting del cliente y subirla a `/media`) más una pasada de GD, en
     * un worker que también corre deployments. Para una foto que **ya** es jpeg o png eso es gasto
     * puro: Meta la baja sola, gratis y sin que el admin toque un byte.
     *
     * Por eso la decisión es por extensión y no por lo que declare nadie: `.jpg`, `.jpeg` y `.png`
     * salen por link; **todo lo demás, incluida la URL sin extensión, se baja y se mira por los
     * bytes**. Sin extensión no se puede afirmar nada, y el camino largo es el único que termina en
     * un rechazo *sincrónico* — o sea, el único que puede contar bien.
     *
     * @param string $url URL pública de la foto, tal como la mandó el `empresa-api`.
     *
     * @return bool true si se puede mandar por link tal cual.
     */
    public function va_por_link(string $url): bool
    {
        $ruta      = (string) parse_url(trim($url), PHP_URL_PATH);
        $extension = strtolower((string) pathinfo($ruta, PATHINFO_EXTENSION));

        return in_array($extension, self::EXTENSIONES_QUE_META_BAJA, true);
    }

    /**
     * Baja la foto, confirma qué es de verdad y devuelve los bytes listos para subir a `/media`.
     *
     * @param string $url URL pública de la foto.
     *
     * @return array{binario: string|null, mime: string|null, nombre: string|null, convertida: bool, motivo: string|null}
     *         Con `binario` en null, `motivo` dice por qué en castellano y es lo que va al log.
     */
    public function preparar(string $url): array
    {
        $descarga = $this->descargar($url);
        if ($descarga['binario'] === null) {
            return $this->fallo((string) $descarga['motivo']);
        }

        $binario = (string) $descarga['binario'];

        /* 🔴 El tipo real sale de los BYTES y no de la extensión de la URL, que es justamente la
         * que no alcanzó para nada hasta acá. Misma regla que el servicio de las fotos entrantes:
         * un nombre de archivo es lo que alguien escribió, no lo que hay adentro. */
        $datos = $this->datos_de_los_bytes($binario);
        if ($datos === null) {
            return $this->fallo('Lo que devolvió el link no es una imagen que se pueda leer.');
        }

        $mime_real = (string) $datos['mime'];

        /* Ya es de un tipo que Meta acepta y entra en el tope: se sube tal cual. Pasarla igual por
         * GD sería reencodear un JPEG —perder calidad— para llegar al mismo lugar. Y de paso se
         * saltea la guarda de resolución de abajo, que es correcto: GD no participa. */
        if (in_array($mime_real, self::MIMES_QUE_META_ACEPTA, true)
            && strlen($binario) <= self::MAXIMO_DE_BYTES_PARA_META) {
            return [
                'binario'    => $binario,
                'mime'       => $mime_real,
                'nombre'     => $this->nombre_de_archivo($url, $mime_real),
                'convertida' => false,
                'motivo'     => null,
            ];
        }

        /* 🔴 LA GUARDA VA ACÁ, ANTES DE GD, Y NO ES OPCIONAL. `getimagesizefromstring()` ya leyó el
         * ancho y el alto del encabezado sin descomprimir un solo píxel: es gratis y es el único
         * momento en que se puede decir que no. Un renglón más abajo, `imagecreatefromstring()` ya
         * reservó `ancho × alto × 4` bytes, y si eso se pasa del `memory_limit` el proceso muere
         * con un error FATAL que ningún `catch (\Throwable)` agarra — o sea que la promesa de que
         * una foto no puede voltear el turno se rompe justo acá. Ver MAXIMO_DE_MEGAPIXELES. */
        $pixeles = (int) $datos['ancho'] * (int) $datos['alto'];
        $techo   = $this->pixeles_que_entran();

        if ($pixeles > $techo) {
            return $this->fallo(
                'La foto tiene ' . $this->en_megapixeles($pixeles) . ' megapíxeles y el máximo que se convierte es '
                    . $this->en_megapixeles($techo) . ': descomprimirla necesitaría '
                    . $this->en_megas($pixeles * self::BYTES_POR_PIXEL) . ' MB de memoria.'
            );
        }

        $jpeg = $this->a_jpeg($binario);
        if ($jpeg === null) {
            return $this->fallo('No se pudo convertir la foto a JPEG (llegó como ' . $mime_real . ').');
        }

        return [
            'binario'    => $jpeg,
            'mime'       => 'image/jpeg',
            'nombre'     => $this->nombre_de_archivo($url, 'image/jpeg'),
            'convertida' => true,
            'motivo'     => null,
        ];
    }

    /**
     * Baja los bytes del hosting del cliente.
     *
     * No reintenta: esto corre adentro del turno, después de que el texto ya salió, y una foto es
     * un extra. Un hosting que no contesta a la primera no justifica gastarle segundos al job.
     *
     * `protected` por lo mismo que {@see motivo_para_no_bajar()}: hay comportamiento acá —el corte
     * de los 3xx— que por el camino largo queda tapado por otro rechazo que llega antes, y una
     * prueba que pase por ahí estaría verde por el motivo equivocado.
     *
     * @param string $url URL pública de la foto.
     *
     * @return array{binario: string|null, motivo: string|null}
     */
    protected function descargar(string $url): array
    {
        $destino = trim($url);

        /* 🔴 Los saltos SE SIGUEN, pero de a uno y revalidando el destino en cada uno. Guzzle los
         * sigue solo (`allow_redirects` prendido) sin volver a preguntarle nada a nadie: ahí el
         * control de dirección se haría sobre la URL original y la descarga terminaría en otra, así
         * que un `302` hacia `169.254.169.254` lo saltearía entero. Pero cortarlos seco tampoco
         * sirve: el `empresa-api` devuelve la `hosting_url` tal cual está guardada, o sea que
         * **puede venir en `http://`**, y un hosting que la normaliza a `https://` con un 301 dejaría
         * la foto muerta por un salto perfectamente legítimo. El equilibrio es este bucle. */
        for ($salto = 0; $salto <= self::MAXIMO_DE_SALTOS; $salto++) {
            $rechazo = $this->motivo_para_no_ir_a($destino);
            if ($rechazo !== null) {
                return ['binario' => null, 'motivo' => $rechazo];
            }

            try {
                /* Sin credenciales y sin `retry()`: ver el docblock de la clase. El `Accept` es para
                 * que un hosting que negocia contenido no devuelva una página de error en HTML.
                 * `stream => true` es lo que permite cortar el cuerpo a mitad de camino, más abajo. */
                $respuesta = Http::timeout(self::SEGUNDOS_DE_DESCARGA)
                    ->withOptions(['allow_redirects' => false, 'stream' => true])
                    ->withHeaders(['Accept' => 'image/*'])
                    ->get($destino);
            } catch (\Throwable $excepcion) {
                return ['binario' => null, 'motivo' => 'No se pudo bajar la foto: ' . $excepcion->getMessage()];
            }

            $status = (int) $respuesta->status();

            if ($status < 300 || $status >= 400) {
                if (! $respuesta->successful()) {
                    return [
                        'binario' => null,
                        'motivo'  => 'El hosting del cliente respondió ' . $status . ' al pedir la foto.',
                    ];
                }

                return $this->leer_el_cuerpo($respuesta);
            }

            if ($salto === self::MAXIMO_DE_SALTOS) {
                return [
                    'binario' => null,
                    'motivo'  => 'El link de la foto redirige más de ' . self::MAXIMO_DE_SALTOS . ' veces.',
                ];
            }

            $siguiente = trim((string) $respuesta->header('Location'));
            if ($siguiente === '') {
                return [
                    'binario' => null,
                    'motivo'  => 'El link de la foto redirige (' . $status . ') pero no dice a dónde.',
                ];
            }

            /* Un `Location` puede ser relativo; se resuelve contra la URL que lo devolvió, y el
             * resultado vuelve a pasar por el control de dirección en la próxima vuelta. */
            $destino = $this->resolver_destino($destino, $siguiente);
            if ($destino === null) {
                return ['binario' => null, 'motivo' => 'El link de la foto redirige a una dirección ilegible.'];
            }
        }

        return ['binario' => null, 'motivo' => 'No se pudo bajar la foto.'];
    }

    /**
     * Lee el cuerpo de la respuesta cortando en cuanto se pasa del tope.
     *
     * 🔴 **Acá el tope se aplica MIENTRAS se baja, no después.** `$respuesta->body()` trae el
     * cuerpo entero a memoria y recién ahí se podría medir: contra un hosting que sirve 500 MB, el
     * rechazo llegaría con los 500 MB ya adentro del worker, que es exactamente lo que el tope
     * existe para evitar. Leyendo de a pedazos, lo peor que entra es un pedazo de más.
     *
     * @param \Illuminate\Http\Client\Response $respuesta Respuesta con el cuerpo sin consumir.
     *
     * @return array{binario: string|null, motivo: string|null}
     */
    private function leer_el_cuerpo($respuesta): array
    {
        /* El `Content-Length`, cuando está, ahorra bajar aunque sea el primer pedazo. No se confía
         * en él para lo otro: puede mentir o no venir (respuestas `chunked`). */
        $declarado = (int) $respuesta->header('Content-Length');
        if ($declarado > self::MAXIMO_DE_BYTES_DE_DESCARGA) {
            return [
                'binario' => null,
                'motivo'  => 'La foto declara ' . $this->en_megas($declarado) . ' MB y el máximo que se baja es '
                    . $this->en_megas(self::MAXIMO_DE_BYTES_DE_DESCARGA) . ' MB.',
            ];
        }

        try {
            $cuerpo = $respuesta->toPsrResponse()->getBody();

            /* Se rebobina antes de leer: `read()` avanza el puntero y no lo devuelve, así que un
             * cuerpo que alguien ya tocó —o una respuesta que se reusa, como pasa con el
             * `Http::fake()` de las pruebas, donde el mismo objeto vuelve en cada llamada que
             * matchea el stub— se leería vacío. En producción no cambia nada; acá es la diferencia
             * entre bajar la segunda foto y creer que el link devolvió un archivo vacío. */
            if ($cuerpo->isSeekable()) {
                $cuerpo->rewind();
            }

            $binario = '';

            while (! $cuerpo->eof()) {
                $pedazo = $cuerpo->read(self::BYTES_POR_PEDAZO);
                if ($pedazo === '') {
                    break;
                }

                $binario .= $pedazo;

                if (strlen($binario) > self::MAXIMO_DE_BYTES_DE_DESCARGA) {
                    $cuerpo->close();

                    return [
                        'binario' => null,
                        'motivo'  => 'La foto pasa los ' . $this->en_megas(self::MAXIMO_DE_BYTES_DE_DESCARGA)
                            . ' MB que se bajan como máximo: se cortó la descarga.',
                    ];
                }
            }
        } catch (\Throwable $excepcion) {
            return ['binario' => null, 'motivo' => 'No se pudo leer la foto: ' . $excepcion->getMessage()];
        }

        if ($binario === '') {
            return ['binario' => null, 'motivo' => 'El link devolvió un archivo vacío.'];
        }

        return ['binario' => $binario, 'motivo' => null];
    }

    /**
     * Motivo por el cual no hay que ir a buscar una foto a esa URL, o null si se puede.
     *
     * @param string $url URL a evaluar (la original o la de un salto).
     *
     * @return string|null
     */
    private function motivo_para_no_ir_a(string $url): ?string
    {
        $esquema = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($esquema !== 'http' && $esquema !== 'https') {
            return 'El link de la foto no es http(s).';
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return 'El link de la foto no tiene dominio.';
        }

        return $this->motivo_para_no_bajar($host);
    }

    /**
     * Resuelve un `Location` contra la URL que lo devolvió.
     *
     * @param string $base    URL del pedido que redirigió.
     * @param string $destino Valor del header `Location`, absoluto o relativo.
     *
     * @return string|null URL absoluta, o null si no se pudo armar.
     */
    private function resolver_destino(string $base, string $destino): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $destino) === 1) {
            return $destino;
        }

        $partes = parse_url($base);
        if (! is_array($partes) || empty($partes['scheme']) || empty($partes['host'])) {
            return null;
        }

        $raiz = $partes['scheme'] . '://' . $partes['host'];
        if (! empty($partes['port'])) {
            $raiz .= ':' . $partes['port'];
        }

        if (strpos($destino, '/') === 0) {
            return $raiz . $destino;
        }

        $ruta = isset($partes['path']) ? (string) $partes['path'] : '/';
        $ruta = substr($ruta, 0, (int) strrpos($ruta, '/') + 1);

        return $raiz . ($ruta === '' ? '/' : $ruta) . $destino;
    }

    /**
     * Motivo por el cual NO hay que ir a buscar una foto a ese dominio, o null si se puede.
     *
     * 🔴 **ACÁ ESTÁ LA OTRA TENTACIÓN: *"es el hosting de nuestro propio cliente, ¿para qué el
     * chequeo?"*. Y la respuesta es que lo que cambió es QUIÉN visita esa URL.**
     *
     * Hasta el 22/9/2026 el link viajaba adentro del mensaje y la que iba a buscarlo era **Meta**,
     * desde afuera. El admin nunca la abría. Desde que existe el camino por media_id, el que hace
     * el GET es **este proceso**, corriendo adentro del VPS — el mismo VPS donde viven el admin,
     * su MySQL, su Redis y los ~40 clientes migrados. Y la URL no la elige nadie de este lado: la
     * manda el `empresa-api` de un cliente, en la clave `adjuntos` de su respuesta.
     *
     * O sea que sin este chequeo, un `empresa-api` comprometido —o con un bug— convierte al admin
     * en su proxy hacia la red interna: `http://127.0.0.1:6379/…` es el Redis, `http://10.x.x.x/…`
     * es cualquier cliente vecino, y `http://169.254.169.254/…` es el endpoint de metadata del
     * cloud, que es el premio gordo. Es un SSRF, y lo abrió el arreglo de la foto: por eso el
     * candado va en el mismo lugar.
     *
     * **Se resuelve sobre la IP y no sobre el string del host.** Una lista negra de nombres no
     * sirve para nada: `interno.cliente.com` puede apuntar a `10.0.0.5` igual que `localhost`.
     *
     * ⚠️ **Lo que este chequeo NO cubre, y queda escrito para el que venga:** el DNS rebinding.
     * Entre esta resolución y la que hace Guzzle al conectar hay una ventana en la que el dominio
     * puede cambiar de IP. Taparlo pide fijar la IP en la conexión (`CURLOPT_RESOLVE`), que ata el
     * servicio a cURL y no se puede ejercitar con el `Http::fake()` de las pruebas. Se dejó afuera
     * a conciencia: exige que el atacante controle el DNS del dominio, que es bastante más que
     * devolver una URL rara en un JSON.
     *
     * `protected` para que las pruebas puedan recorrer la tabla de direcciones de una, sin montar
     * un turno entero por cada una: por HTTP hay rechazos que llegan antes que este control —Guzzle
     * ni siquiera parsea `http://[::ffff:127.0.0.1]/…`— y una prueba que pase por ahí estaría
     * midiendo el error de otro.
     *
     * @param string $host Dominio o IP literal del link, tal como salió de `parse_url()`.
     *
     * @return string|null Motivo legible del rechazo, o null si el destino es público.
     */
    protected function motivo_para_no_bajar(string $host): ?string
    {
        /* Una IPv6 en una URL viaja entre corchetes: `http://[::1]/foto.webp`. */
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            $ips = $this->ips_del_host($host);
        }

        /* 🔴 **Si la resolución falló, se falla CERRADO.** Es distinto de "el dominio no tiene
         * ninguna dirección": acá la consulta no contestó, así que no se sabe a dónde apunta. Y
         * Guzzle resuelve por su cuenta con `getaddrinfo` cuando conecta — o sea que seguir con una
         * lista incompleta es exactamente el agujero: se evalúan las `A`, la consulta `AAAA` se
         * cae, y la conexión sale por una `AAAA` que nadie miró. No hace falta un atacante con
         * control del DNS, alcanza con una resolución parcial.
         *
         * El precio está asumido: si el resolver del VPS falla de forma intermitente, esas fotos no
         * salen. Es una foto que no llega contra un pedido a la red interna, y no hay comparación. */
        if ($ips === null) {
            return 'No se pudo resolver del todo el dominio de la foto (' . $host . '): no se baja.';
        }

        if ($ips === []) {
            return 'El dominio de la foto no resuelve a ninguna dirección (' . $host . ').';
        }

        /* TODAS las direcciones, no la primera: un dominio con un A público y un AAAA interno
         * pasaría mirando solo una, y cuál usa la conexión no lo decide este código. */
        foreach ($ips as $ip) {
            if (! $this->es_ip_publica($ip)) {
                return 'El link de la foto apunta a una dirección no ruteable (' . $ip . '): no se baja.';
            }
        }

        return null;
    }

    /**
     * Direcciones IP de un dominio.
     *
     * `protected` para que las pruebas puedan fijar qué resuelve cada host sin tocar el DNS de
     * verdad — que además las haría lentas y dependientes de la red.
     *
     * 🔴 **Devuelve null cuando una de las dos consultas FALLA**, que no es lo mismo que devolver
     * una lista vacía. Una lista vacía es un dominio que no tiene direcciones; null es "no sé", y
     * el llamador lo trata como rechazo. La diferencia importa porque `gethostbynamel()` y
     * `dns_get_record()` devuelven `false` en error y una lista vacía cuando simplemente no hay
     * registros de ese tipo — y tragarse el `false` deja pasar una resolución a medias.
     *
     * @param string $host Dominio.
     *
     * @return array<int, string>|null IPv4 e IPv6; vacío si no tiene ninguna; null si falló la consulta.
     */
    protected function ips_del_host(string $host): ?array
    {
        $ips = [];

        $cuatro = @gethostbynamel($host);
        if ($cuatro === false) {
            /* `gethostbynamel()` no distingue "no hay A" de "falló": las dos son false. Se vuelve a
             * preguntar por `dns_get_record()`, que sí las separa, y recién ahí se decide. */
            $registros = @dns_get_record($host, DNS_A);
            if ($registros === false) {
                return null;
            }
        } else {
            $ips = $cuatro;
        }

        $seis = @dns_get_record($host, DNS_AAAA);
        if ($seis === false) {
            return null;
        }

        foreach ($seis as $fila) {
            if (is_array($fila) && ! empty($fila['ipv6'])) {
                $ips[] = (string) $fila['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Indica si una IP es de las que se puede ir a buscar: pública y ruteable.
     *
     * @param string $ip Dirección a evaluar.
     *
     * @return bool
     */
    private function es_ip_publica(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::RANGOS_NO_RUTEABLES as $rango) {
            if ($this->en_rango($ip, $rango)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Indica si una IP cae adentro de un CIDR. Sirve para IPv4 e IPv6 por igual.
     *
     * La comparación se hace sobre la forma BINARIA (`inet_pton`) y no sobre el texto: `010.1.2.3`,
     * `0x7f.0.0.1` y `::ffff:10.0.0.1` son la misma dirección escrita de tres formas, y comparar
     * strings las deja pasar a las tres.
     *
     * @param string $ip   Dirección.
     * @param string $cidr Rango en formato `red/bits`.
     *
     * @return bool
     */
    private function en_rango(string $ip, string $cidr): bool
    {
        $partes = explode('/', $cidr);
        if (count($partes) !== 2) {
            return false;
        }

        $red     = @inet_pton($partes[0]);
        $binaria = @inet_pton($ip);
        $bits    = (int) $partes[1];

        if ($red === false || $binaria === false || strlen($red) !== strlen($binaria)) {
            return false;
        }

        $bytes_enteros = intdiv($bits, 8);
        $bits_sueltos  = $bits % 8;

        if ($bytes_enteros > 0 && strncmp($binaria, $red, $bytes_enteros) !== 0) {
            return false;
        }

        if ($bits_sueltos === 0) {
            return true;
        }

        $mascara = chr((0xFF << (8 - $bits_sueltos)) & 0xFF);

        return ($binaria[$bytes_enteros] & $mascara) === ($red[$bytes_enteros] & $mascara);
    }

    /**
     * Convierte a JPEG lo que sea que haya llegado, hasta que entre en el tope de Meta.
     *
     * Primero achica al lado máximo, después baja calidad, y recién si con la calidad más baja
     * sigue sin entrar vuelve a achicar a la mitad. Ese orden es a propósito: bajar calidad no se
     * nota casi en una foto de catálogo, y achicar sí.
     *
     * ✅ El PHP 7.4 del VPS de producción tiene GD con WebP, JPEG y PNG (verificado el 22/9/2026
     * contra el servidor real), así que `imagecreatefromstring()` lee el webp derecho. Las guardas
     * de `function_exists` son para no reventar en un entorno sin GD: ahí la foto no sale, que es
     * exactamente lo que pasa hoy, no algo peor.
     *
     * @param string $binario Bytes del original.
     *
     * @return string|null Bytes del JPEG, o null si no se pudo.
     */
    private function a_jpeg(string $binario): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        $imagen = @imagecreatefromstring($binario);
        if ($imagen === false) {
            return null;
        }

        $ancho = imagesx($imagen);
        $alto  = imagesy($imagen);

        if ($ancho < 1 || $alto < 1) {
            imagedestroy($imagen);

            return null;
        }

        $lado = min(max($ancho, $alto), self::LADO_MAXIMO);

        /* 🔴 El bucle hace SIEMPRE una pasada, y recién después mira si puede achicar más. Con la
         * condición al principio (`while ($lado >= LADO_MINIMO)`), una foto de menos de 400 px de
         * lado —un thumbnail del catálogo, que los hay— no entraba nunca y volvía sin convertir, o
         * sea sin llegar. Lo agarró `test_el_409_reintenta_el_envio_sin_volver_a_bajar_ni_convertir`
         * con una imagen de 300 px. */
        while (true) {
            $lienzo = $this->aplanar_sobre_blanco($imagen, $ancho, $alto, $lado);

            if ($lienzo !== null) {
                foreach (self::CALIDADES_DE_JPEG as $calidad) {
                    $jpeg = $this->encodear_jpeg($lienzo, $calidad);

                    if ($jpeg !== null && strlen($jpeg) <= self::MAXIMO_DE_BYTES_PARA_META) {
                        imagedestroy($lienzo);
                        imagedestroy($imagen);

                        return $jpeg;
                    }
                }

                imagedestroy($lienzo);
            }

            if ($lado <= self::LADO_MINIMO) {
                break;
            }

            $lado = max(self::LADO_MINIMO, (int) floor($lado / 2));
        }

        imagedestroy($imagen);

        return null;
    }

    /**
     * Copia la imagen sobre un lienzo blanco del tamaño pedido.
     *
     * 🔴 **El blanco no es estética: JPEG no tiene canal alfa.** Un webp o un png con transparencia
     * —el fondo recortado de una foto de producto es el caso típico del catálogo— sale con el fondo
     * NEGRO si se lo encodea sin aplanar, porque el alfa se descarta y abajo queda el lienzo en
     * cero. Se pinta blanco primero y se copia encima con blending prendido.
     *
     * @param resource|\GdImage $imagen       Imagen de origen.
     * @param int               $ancho        Ancho del origen.
     * @param int               $alto         Alto del origen.
     * @param int               $lado_maximo  Lado mayor que tiene que tener el resultado.
     *
     * @return resource|\GdImage|null
     */
    private function aplanar_sobre_blanco($imagen, int $ancho, int $alto, int $lado_maximo)
    {
        $escala = min(1.0, $lado_maximo / max($ancho, $alto));

        $nuevo_ancho = max(1, (int) round($ancho * $escala));
        $nuevo_alto  = max(1, (int) round($alto * $escala));

        $lienzo = @imagecreatetruecolor($nuevo_ancho, $nuevo_alto);
        if ($lienzo === false) {
            return null;
        }

        imagealphablending($lienzo, true);

        $blanco = imagecolorallocate($lienzo, 255, 255, 255);
        imagefilledrectangle($lienzo, 0, 0, $nuevo_ancho - 1, $nuevo_alto - 1, $blanco);

        $copiada = @imagecopyresampled($lienzo, $imagen, 0, 0, 0, 0, $nuevo_ancho, $nuevo_alto, $ancho, $alto);
        if (! $copiada) {
            imagedestroy($lienzo);

            return null;
        }

        return $lienzo;
    }

    /**
     * Encodea un lienzo a JPEG en memoria.
     *
     * @param resource|\GdImage $lienzo  Imagen ya aplanada.
     * @param int               $calidad 0-100.
     *
     * @return string|null Bytes del JPEG, o null si GD no pudo.
     */
    private function encodear_jpeg($lienzo, int $calidad): ?string
    {
        ob_start();
        $salio = @imagejpeg($lienzo, null, $calidad);
        $bytes = ob_get_clean();

        if (! $salio || ! is_string($bytes) || $bytes === '') {
            return null;
        }

        return $bytes;
    }

    /**
     * Tipo, ancho y alto reales de una imagen, leídos de sus bytes.
     *
     * 🔴 **El ancho y el alto se devuelven a propósito y hay que usarlos.** `getimagesizefromstring()`
     * los saca del encabezado sin descomprimir nada, así que son el único dato barato que existe
     * para decidir si conviene llamar a GD. Tirarlos —que es lo que hacía este método cuando
     * devolvía sólo el mime— deja la conversión sin ninguna guarda de resolución.
     *
     * @param string $binario Bytes del archivo.
     *
     * @return array{mime: string, ancho: int, alto: int}|null Null si no es una imagen legible.
     */
    private function datos_de_los_bytes(string $binario): ?array
    {
        $info = @getimagesizefromstring($binario);
        if ($info === false || empty($info['mime'])) {
            return null;
        }

        $ancho = isset($info[0]) ? (int) $info[0] : 0;
        $alto  = isset($info[1]) ? (int) $info[1] : 0;

        if ($ancho < 1 || $alto < 1) {
            return null;
        }

        $mime = strtolower(trim((string) $info['mime']));

        /* `image/jpg` no es un tipo real pero aparece en cabeceras viejas; se normaliza en vez de
         * descartarse, igual que en el servicio de las fotos entrantes. */
        return [
            'mime'  => $mime === 'image/jpg' ? 'image/jpeg' : $mime,
            'ancho' => $ancho,
            'alto'  => $alto,
        ];
    }

    /**
     * Cuántos píxeles se pueden descomprimir sin arriesgar la memoria del worker.
     *
     * Son dos techos y gana el más bajo:
     *
     *   1. **El absoluto** (`MAXIMO_DE_MEGAPIXELES`), que es el que manda en producción: con
     *      `memory_limit = 4048M` el cálculo de abajo daría cientos de megapíxeles, y nadie quiere
     *      que el admin reserve 2 GB por una foto de catálogo aunque "entre".
     *   2. **El que sale del `memory_limit` real**, para que esto siga siendo correcto en un
     *      entorno con menos memoria (el `php` del shared, una máquina de desarrollo, el día que
     *      alguien baje el límite). Se usa la mitad de lo que queda libre, no todo: la imagen de
     *      origen no es lo único vivo mientras se convierte.
     *
     * `memory_limit` en -1 es "sin límite": ahí sólo queda el techo absoluto.
     *
     * @return int Píxeles.
     */
    private function pixeles_que_entran(): int
    {
        $absoluto = self::MAXIMO_DE_MEGAPIXELES * 1000000;

        $limite = $this->memory_limit_en_bytes();
        if ($limite === null) {
            return $absoluto;
        }

        $libre = $limite - memory_get_usage(true) - self::RESERVA_DE_MEMORIA;
        if ($libre < 1) {
            return 0;
        }

        $por_memoria = (int) floor(($libre / 2) / self::BYTES_POR_PIXEL);

        return min($absoluto, $por_memoria);
    }

    /**
     * `memory_limit` del proceso, en bytes, o null si no tiene tope.
     *
     * @return int|null
     */
    private function memory_limit_en_bytes(): ?int
    {
        $crudo = trim((string) ini_get('memory_limit'));

        if ($crudo === '' || $crudo === '-1') {
            return null;
        }

        $unidad = strtolower(substr($crudo, -1));
        $numero = (int) $crudo;

        if ($unidad === 'g') {
            return $numero * 1073741824;
        }

        if ($unidad === 'm') {
            return $numero * 1048576;
        }

        if ($unidad === 'k') {
            return $numero * 1024;
        }

        return $numero;
    }

    /**
     * Megapíxeles con un decimal, para los motivos.
     *
     * @param int $pixeles
     *
     * @return string
     */
    private function en_megapixeles(int $pixeles): string
    {
        return number_format($pixeles / 1000000, 1, ',', '');
    }

    /**
     * Nombre con el que el archivo viaja en el multipart de `/media`.
     *
     * 🔴 **La extensión tiene que coincidir con el `Content-Type`.** Meta valida el par al subir, y
     * un `foto.webp` declarado `image/jpeg` es un rechazo — que es lo mismo que ya pasa con el
     * audio (ver `resolve_whatsapp_audio_upload_filename()`). Por eso el nombre se arma desde el
     * mime de salida y no desde la URL de origen: de la URL solo se conserva la raíz, que es lo que
     * hace legible un error.
     *
     * @param string $url  URL de origen.
     * @param string $mime Mime con el que se sube.
     *
     * @return string
     */
    private function nombre_de_archivo(string $url, string $mime): string
    {
        $base = basename((string) parse_url(trim($url), PHP_URL_PATH));
        $base = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $base);
        $base = (string) pathinfo($base, PATHINFO_FILENAME);

        if ($base === '') {
            $base = 'foto';
        }

        return mb_strimwidth($base, 0, 60, '') . '.' . ($mime === 'image/png' ? 'png' : 'jpg');
    }

    /**
     * Resultado de un fallo, con su motivo.
     *
     * @param string $motivo Qué pasó, en castellano.
     *
     * @return array{binario: null, mime: null, nombre: null, convertida: bool, motivo: string}
     */
    private function fallo(string $motivo): array
    {
        return [
            'binario'    => null,
            'mime'       => null,
            'nombre'     => null,
            'convertida' => false,
            'motivo'     => $motivo,
        ];
    }

    /**
     * Megabytes con un decimal, para los motivos.
     *
     * @param int $bytes
     *
     * @return string
     */
    private function en_megas(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '');
    }
}
