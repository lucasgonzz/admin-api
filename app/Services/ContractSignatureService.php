<?php

namespace App\Services;

use App\Models\AdminSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Punto único de verdad sobre la firma del PRESTADOR que se estampa en el PDF del contrato.
 *
 * La firma se carga una sola vez desde la vista Cuenta y después sale en cada contrato que
 * genera {@see LeadContractPdfService}. Acá vive todo lo que sabe de ella: dónde está guardada,
 * si existe, qué mide y cómo se convierte en algo que dompdf pueda dibujar.
 *
 * Dos claves en `admin_settings` y ningún binario en la base:
 *
 *   - `contract_signature_path`       ruta relativa al disco `local` (ej: `firmas/firma-prestador.png`)
 *   - `contract_signature_updated_at` ISO8601 de la última subida
 *
 * El MIME NO se guarda: se deriva de la extensión del archivo. Si se guardara aparte podría
 * desincronizarse de la extensión real (basta con que un `store` falle a mitad) y el data URI
 * saldría declarando `image/jpeg` sobre bytes PNG. Derivado, ese estado no existe.
 */
class ContractSignatureService
{
    /**
     * Clave de `admin_settings` con la ruta relativa al disco `local`.
     */
    const CLAVE_RUTA = 'contract_signature_path';

    /**
     * Clave de `admin_settings` con el ISO8601 de la última subida.
     */
    const CLAVE_ACTUALIZADA_EN = 'contract_signature_updated_at';

    /**
     * Disco donde vive el archivo. `storage/app/`, sin URL pública.
     */
    const DISCO = 'local';

    /**
     * Carpeta dentro del disco. `storage/app/.gitignore` es `*` / `!public/` / `!.gitignore`,
     * así que el archivo nunca se versiona.
     */
    const CARPETA = 'firmas';

    /**
     * Nombre fijo del archivo, sin extensión. Al reemplazar gana el último.
     */
    const NOMBRE_BASE = 'firma-prestador';

    /**
     * Mapa cerrado extensión → MIME. Es la única fuente del Content-Type y del data URI.
     *
     * @var array<string, string>
     */
    const MIMES = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    /**
     * Tope de píxeles totales (ancho × alto) que se acepta al subir una firma.
     *
     * 🔴 No duplica la regla `dimensions` del controlador: la acota por otro lado y por otro
     * motivo. `imagecreatefromstring` descomprime la imagen a un bitmap de **4 bytes por
     * píxel**, y ese bitmap no lo acota ni el peso del archivo ni `max:2048`: un PNG de firma
     * es casi todo transparente y comprime a nada, así que uno de 4000×4000 pesa 83 KB, pasa
     * la validación de peso, entra justo adentro de `max_width=4000,max_height=4000` y recién
     * después revienta.
     *
     * La cuenta: 4000 × 4000 = 16.000.000 px × 4 bytes = **64 MB** de bitmap, contra un
     * `memory_limit` de **128M** en el runtime real (`php.ini` de wamp; los 512M de
     * `phpunit.xml` son solo de la suite). La request muere con un fatal de memoria, que ni
     * el `@` suprime ni un `try/catch` de PHP 7.4 captura: 500 sin cuerpo JSON. El umbral
     * medido está cerca de los 3.800 px de lado.
     *
     * 6.000.000 px = **24 MB** de bitmap, que entra holgado. Una firma escaneada de verdad no
     * llega ni cerca: la de Lucas son 320 × 386 = 123.520 px, 48 veces menos.
     *
     * 🔴 Si alguien sube este número hay que rehacer la cuenta contra el `memory_limit` del
     * servidor donde corre, no estimarla de memoria.
     */
    const PIXELES_MAX = 6000000;

    /**
     * El hueco arriba de la línea. Son los 64px de `.firma-linea` traducidos a puntos.
     *
     * 🔴 Si se cambia acá hay que cambiarlo TAMBIÉN en la vista, y en las DOS columnas: las dos
     * celdas están en el mismo `<tr>` con `vertical-align:top`, así que si el PRESTADOR usa un
     * hueco distinto al del CLIENTE, las dos líneas de firma quedan a distinta altura en la
     * misma fila y el contrato se ve roto.
     */
    const HUECO_PT = 48.0;

    /**
     * Aire entre el pie de la firma y la línea. Una firma real apoya sobre la línea, no flota.
     */
    const SEPARACION_PT = 3.0;

    /**
     * Tope de alto. HUECO - SEPARACION - ALTO_MAX = 5pt de margen superior mínimo.
     */
    const ALTO_MAX_PT = 40.0;

    /**
     * Tope de ancho. La línea mide ~162pt (80% de la celda); 150 deja ver que es una firma
     * SOBRE una línea y no una firma que tapa la línea.
     */
    const ANCHO_MAX_PT = 150.0;

    /**
     * Ruta relativa al disco `local` de la firma cargada, o null si no hay ninguna.
     *
     * @return string|null
     */
    public static function ruta_relativa(): ?string
    {
        $ruta = AdminSetting::get(self::CLAVE_RUTA);

        if (!is_string($ruta) || trim($ruta) === '') {
            return null;
        }

        return $ruta;
    }

    /**
     * Hay firma usable: existe la setting Y el archivo está efectivamente en disco.
     *
     * Las dos cosas, no una: el caso "deployé y me olvidé de subir la firma" deja la setting
     * apuntando a un archivo que no está, y el contrato tiene que salir igual, sin firma.
     *
     * @return bool
     */
    public static function existe(): bool
    {
        $ruta = self::ruta_relativa();

        if ($ruta === null) {
            return false;
        }

        // 🔴 Storage::disk('local') y no storage_path(): es lo único que hace que
        // Storage::fake('local') aísle los tests. Si un solo método de esta clase leyera o
        // escribiera con storage_path()/file_get_contents(), la suite pisaría la firma real
        // del entorno de Lucas.
        return Storage::disk(self::DISCO)->exists($ruta);
    }

    /**
     * MIME de la firma cargada, derivado de la extensión del archivo.
     *
     * @return string|null
     */
    public static function mime(): ?string
    {
        $ruta = self::ruta_relativa();

        if ($ruta === null) {
            return null;
        }

        $extension = strtolower((string) pathinfo($ruta, PATHINFO_EXTENSION));

        if (!array_key_exists($extension, self::MIMES)) {
            return null;
        }

        return self::MIMES[$extension];
    }

    /**
     * Guarda (o reemplaza) la firma del PRESTADOR.
     *
     * La extensión sale del MIME que detecta el contenido del archivo subido, no del nombre que
     * mandó el navegador: así la extensión con la que se persiste y el MIME que después se
     * deriva de ella no pueden discrepar.
     *
     * @param UploadedFile $archivo Archivo ya validado por el controlador.
     *
     * @throws \RuntimeException Si el contenido no es un PNG ni un JPEG, o si el disco no
     *                            acepta la escritura. Las dos las traduce a 422 el controlador:
     *                            un 500 sin cuerpo no le dice nada a la SPA.
     *
     * @return void
     */
    public static function guardar(UploadedFile $archivo): void
    {
        $mime_detectado = (string) $archivo->getMimeType();
        $extension = array_search($mime_detectado, self::MIMES, true);

        if ($extension === false) {
            throw new \RuntimeException('Tipo de imagen no soportado para la firma: ' . $mime_detectado);
        }

        $disco = Storage::disk(self::DISCO);
        $ruta_anterior = self::ruta_relativa();
        $ruta_nueva = self::CARPETA . '/' . self::NOMBRE_BASE . '.' . $extension;

        // 🔴 Se escribe PRIMERO la firma nueva y se borra la vieja DESPUÉS. Al revés —borrar y
        // después escribir— un `putFileAs` que falla deja a Lucas sin la firma anterior y sin
        // ninguna nueva: `putFileAs` NO tira excepción, devuelve `false`, `AdminSetting::set`
        // lo castea a cadena vacía y la API contesta 200 "listo" sobre un estado vacío.
        //
        // Y va a un nombre temporal en vez de directo al definitivo por el caso borde de
        // reemplazar un .png por otro .png: ahí el destino ES el archivo viejo, así que
        // escribir "primero" lo pisaría igual y no quedaría nada a lo que volver si la
        // escritura sale a medias. Con el temporal, los bytes nuevos están confirmados en
        // disco antes de que el nombre definitivo se toque.
        $nombre_temporal = self::NOMBRE_BASE . '.subiendo-' . uniqid() . '.tmp';

        if ($disco->putFileAs(self::CARPETA, $archivo, $nombre_temporal) === false) {
            throw new \RuntimeException('No se pudo escribir el archivo de la firma en el disco.');
        }

        $ruta_temporal = self::CARPETA . '/' . $nombre_temporal;

        // Recién ahora, con los bytes nuevos ya confirmados en disco, se libera el nombre
        // definitivo. `move` de Flysystem falla si el destino existe, así que hay que borrarlo.
        if ($disco->exists($ruta_nueva)) {
            $disco->delete($ruta_nueva);
        }

        if ($disco->move($ruta_temporal, $ruta_nueva) === false) {
            $disco->delete($ruta_temporal);

            throw new \RuntimeException('No se pudo dejar el archivo de la firma en su lugar definitivo.');
        }

        // 🔴 Se guarda la RUTA, no el base64 de la imagen. `admin_settings.value` es TEXT (64 KB)
        // y el base64 del PNG de una firma real mide ~70 KB: entraría truncado y sin avisar, el
        // data URI saldría cortado y dompdf dibujaría su "broken image" —una X gris— adentro del
        // contrato que firma el cliente. Los bytes viven en disco; acá va la ruta.
        AdminSetting::set(self::CLAVE_RUTA, $ruta_nueva);
        AdminSetting::set(self::CLAVE_ACTUALIZADA_EN, now()->toIso8601String());

        // La firma anterior con OTRA extensión (un .jpg reemplazado por un .png) se borra recién
        // acá, leyendo la ruta que estaba efectivamente persistida y no adivinando el nombre: si
        // se adivinara, quedarían las dos en disco y una de ellas huérfana para siempre.
        if ($ruta_anterior !== null && $ruta_anterior !== $ruta_nueva && $disco->exists($ruta_anterior)) {
            $disco->delete($ruta_anterior);
        }
    }

    /**
     * Borra la firma: el archivo del disco y las dos claves de configuración.
     *
     * Idempotente: si no había nada cargado, no falla ni cambia nada.
     *
     * @return void
     */
    public static function borrar(): void
    {
        self::borrar_archivo();

        AdminSetting::where('key', self::CLAVE_RUTA)->delete();
        AdminSetting::where('key', self::CLAVE_ACTUALIZADA_EN)->delete();
    }

    /**
     * Payload que consume la vista Cuenta de la SPA.
     *
     * @return array<string, mixed>
     */
    public static function estado(): array
    {
        $estado = [
            'cargada'        => false,
            'actualizada_en' => null,
            'ancho'          => null,
            'alto'           => null,
            'bytes'          => null,
        ];

        if (!self::existe()) {
            return $estado;
        }

        $mime = self::mime();
        $medidas_px = self::medidas_en_pixeles();

        // 🔴 `cargada` dice si la firma se va a poder ESTAMPAR, no si hay un archivo en disco.
        // No es lo mismo: un `.png` de bytes basura, o un `.gif` (extensión fuera del mapa de
        // MIMEs), están en disco y pasan `existe()`, pero el contrato sale sin firma igual.
        // Si acá dijéramos `true`, la SPA pintaría el badge verde "Cargada" y Lucas mandaría
        // el contrato creyendo que va firmado. Los dos motivos de descarte son exactamente los
        // mismos que usa LeadContractPdfService::build_signature_data para no estampar nada,
        // así que la UI y el PDF no pueden discrepar.
        if ($mime === null || $medidas_px === null) {
            return $estado;
        }

        $actualizada_en = AdminSetting::get(self::CLAVE_ACTUALIZADA_EN);

        $estado['cargada'] = true;
        $estado['actualizada_en'] = is_string($actualizada_en) && $actualizada_en !== ''
            ? $actualizada_en
            : null;
        $estado['bytes'] = (int) Storage::disk(self::DISCO)->size(self::ruta_relativa());
        $estado['ancho'] = $medidas_px['ancho_px'];
        $estado['alto'] = $medidas_px['alto_px'];

        return $estado;
    }

    /**
     * Bytes crudos de la firma, para el endpoint de vista previa.
     *
     * @return string|null
     */
    public static function contenido(): ?string
    {
        if (!self::existe()) {
            return null;
        }

        return Storage::disk(self::DISCO)->get(self::ruta_relativa());
    }

    /**
     * Data URI listo para el `src` del `<img>` de la vista del contrato.
     *
     * Se usa data URI y no una ruta de archivo porque así el render no depende de que nadie
     * publique nunca un `config/dompdf.php` con un chroot más angosto que `base_path()`, y
     * porque evita el camino de `realpath()` de dompdf, que en Windows con rutas de worktree
     * es el que da sorpresas.
     *
     * @return string|null
     */
    public static function data_uri(): ?string
    {
        $contenido = self::contenido();
        $mime = self::mime();

        if ($contenido === null || $mime === null) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($contenido);
    }

    /**
     * Medidas en puntos con las que la firma entra en la celda del PRESTADOR.
     *
     * El invariante que importa: el alto ocupado arriba de la línea suma siempre HUECO_PT,
     * venga la imagen con la proporción que venga. Eso es lo que mantiene las dos líneas de
     * firma a la misma altura y lo que hace que los saltos de página del contrato no cambien
     * respecto de un contrato sin firma.
     *
     * @return array{ancho_pt: float, alto_pt: float, margen_superior_pt: float}|null
     */
    public static function medidas_en_puntos(): ?array
    {
        $medidas_px = self::medidas_en_pixeles();

        if ($medidas_px === null) {
            return null;
        }

        // 🔴 El 1.0 del min() es el tope que IMPIDE AGRANDAR una imagen chica, y es exactamente
        // lo que alguien saca al "simplificar" la cuenta. Sin él, una firma de 80x40 px se
        // estira a 150pt de ancho y sale toda pixelada en un contrato que el cliente firma.
        $escala = min(
            self::ALTO_MAX_PT / $medidas_px['alto_px'],
            self::ANCHO_MAX_PT / $medidas_px['ancho_px'],
            1.0
        );

        $alto_pt = round($medidas_px['alto_px'] * $escala, 2);
        $ancho_pt = round($medidas_px['ancho_px'] * $escala, 2);

        // El margen superior es el resto: lo que sobra del hueco después de la firma y su
        // separación de la línea. Se calcula sobre el alto YA redondeado para que la suma
        // dé HUECO_PT exacto y no arrastre el error del redondeo.
        $margen_superior_pt = round(self::HUECO_PT - self::SEPARACION_PT - $alto_pt, 2);

        return [
            'ancho_pt'           => $ancho_pt,
            'alto_pt'            => $alto_pt,
            'margen_superior_pt' => $margen_superior_pt,
        ];
    }

    /**
     * Ancho y alto en píxeles de la firma cargada, leídos del contenido del archivo.
     *
     * @return array{ancho_px: int, alto_px: int}|null
     */
    protected static function medidas_en_pixeles(): ?array
    {
        $contenido = self::contenido();

        if ($contenido === null) {
            return null;
        }

        $info = @getimagesizefromstring($contenido);

        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return null;
        }

        return [
            'ancho_px' => (int) $info[0],
            'alto_px'  => (int) $info[1],
        ];
    }

    /**
     * Borra del disco el archivo apuntado por la setting, si lo hay.
     *
     * @return void
     */
    protected static function borrar_archivo(): void
    {
        $ruta = self::ruta_relativa();

        if ($ruta === null) {
            return;
        }

        if (Storage::disk(self::DISCO)->exists($ruta)) {
            Storage::disk(self::DISCO)->delete($ruta);
        }
    }
}
