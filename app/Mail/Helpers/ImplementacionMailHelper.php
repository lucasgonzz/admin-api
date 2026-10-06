<?php

namespace App\Mail\Helpers;

use App\Models\Implementation;
use App\Models\ImplementationStage;

/**
 * Arma lo que ven las vistas de los mails de la implementación (`emails/implementacion/*`).
 *
 * Es la parte PURA del mail: recibe datos ya validados y devuelve el arreglo que consumen las
 * vistas, con el texto de cada hito, los números con el formato argentino, el singular y el plural,
 * y la línea de progreso con el estado real de las ocho etapas. No manda nada ni escribe en ninguna
 * tabla (lo único que lee es `implementation_stages`); eso es de `ImplementacionMailService`.
 *
 * El diseño es el del prototipo aprobado (`mail-prototipo/` de la misión implementar-cliente) y el
 * copy de cada hito sale de ahí. Las vistas lo imprimen todo con `{{ }}`: esta clase NO escapa
 * nada, y por eso NO devuelve HTML en ninguna parte. Lo único con HTML son las propias vistas.
 *
 * Mismo criterio que `LeadDemoAccesoMailHelper`: el mapeo datos → vista vive acá, y el Mailable
 * y el servicio no tienen que saber de dónde sale cada cosa.
 */
class ImplementacionMailHelper
{
    /**
     * Las ocho etapas de la implementación, con el nombre que ve el comerciante.
     *
     * Son las mismas ocho del panel (`implementation_stage_configs`) pero con nombres escritos para
     * el dueño de un negocio y no para el equipo: "Instalamos tu sistema" y no "Instalación del
     * sistema". Mapa del panel al mail: 1 Información de la empresa, 2 Instalación del sistema,
     * 3 Recolección de archivos, 4 Migración de datos, 5 Entrega del sistema, 6 Capacitación,
     * 7 Vinculación ARCA/AFIP, 8 Videollamada de capacitación.
     *
     * @var array<int, string>
     */
    const ETAPAS = [
        1 => 'Conocemos tu negocio',
        2 => 'Instalamos tu sistema',
        3 => 'Recibimos tu información',
        4 => 'Cargamos tu catálogo',
        5 => 'Te entregamos el sistema',
        6 => 'Tu equipo y los tutoriales',
        7 => 'Facturación electrónica',
        8 => 'Videollamada de cierre',
    ];

    /**
     * Subpasos de la etapa 4 ("Cargamos tu catálogo") según el hito que se manda. Cada uno es
     * [texto, estado] con estado hecho | en_curso | pendiente.
     *
     * Solo se muestran si la etapa 4 está en curso de verdad. En `listo` no hay subpasos porque la
     * etapa 4 ya está completa. `categorias` es igual a `imagenes`: las categorías siguen en curso
     * mientras el cliente no elige una de las tres opciones.
     *
     * @var array<string, array<int, array<int, string>>>
     */
    const SUBPASOS_DE_LA_ETAPA_4 = [
        'acceso' => [
            ['Tus artículos', 'hecho'],
            ['Las fotos', 'en_curso'],
            ['Las categorías', 'pendiente'],
        ],
        'imagenes' => [
            ['Tus artículos', 'hecho'],
            ['Las fotos', 'hecho'],
            ['Las categorías', 'en_curso'],
        ],
        'categorias' => [
            ['Tus artículos', 'hecho'],
            ['Las fotos', 'hecho'],
            ['Las categorías', 'en_curso'],
        ],
    ];

    /**
     * Los rubros del resumen del mail "listo", en el orden en que se muestran, con su singular y su
     * plural. Solo salen los que vienen con un número mayor que cero.
     *
     * @var array<string, array<int, string>>
     */
    const RUBROS_DEL_RESUMEN = [
        'articulos'   => ['artículo', 'artículos'],
        'con_foto'    => ['con foto', 'con foto'],
        'categorias'  => ['categoría', 'categorías'],
        'marcas'      => ['marca', 'marcas'],
        'clientes'    => ['cliente', 'clientes'],
        'proveedores' => ['proveedor', 'proveedores'],
    ];

    /**
     * Piso de la barra de progreso, en porcentaje. Una barra en 0 no se ve: un hilo mínimo dice
     * "empezamos".
     */
    const BARRA_MINIMA = 4;

    /**
     * Lo que sigue a la dirección del sistema para llegar a las fotos por revisar.
     */
    const RUTA_DE_LAS_FOTOS = '/alertas/imagenes';

    /**
     * Arma TODO lo que consumen las vistas del mail de un hito.
     *
     * `$contexto` trae lo que sale de la implementación y de la configuración (no de los datos del
     * hito): nombre, negocio, logo_url, firma_nombre, firma_rol, form_link, url_sistema,
     * recursos_url (el de config) y progreso (ver `progreso()`). `$datos` son los datos del hito YA
     * validados y normalizados por `ImplementacionMailService`.
     *
     * @param array<string, mixed> $contexto Lo que sale de la implementación y de la config.
     * @param string               $hito     Uno de `ImplementacionMailService::HITOS`.
     * @param array<string, mixed> $datos    Datos del hito, validados.
     *
     * @return array<string, mixed>
     */
    public static function vista(array $contexto, string $hito, array $datos): array
    {
        $copia = self::copia($hito, $datos);

        $url_sistema = (string) $contexto['url_sistema'];

        $comun = [
            'hito'              => $hito,
            'asunto'            => $copia['asunto'],
            'preheader'         => $copia['preheader'],
            'titular'           => $copia['titular'],
            'intro'             => $copia['intro'],
            // La nota personal, partida en párrafos. Vacío = no hay nota.
            'nota'              => isset($datos['nota']) && is_array($datos['nota']) ? $datos['nota'] : [],
            'nombre'            => (string) $contexto['nombre'],
            'negocio'           => (string) $contexto['negocio'],
            'logo_url'          => (string) $contexto['logo_url'],
            'firma_nombre'      => (string) $contexto['firma_nombre'],
            'firma_rol'         => (string) $contexto['firma_rol'],
            'progreso'          => $contexto['progreso'],
            'form_link'         => (string) $contexto['form_link'],
            'url_sistema'       => $url_sistema,
            // La dirección para mostrar: sin "https://" y sin la barra final.
            'direccion_sistema' => self::direccion_para_mostrar($url_sistema),
        ];

        return array_merge($comun, self::vista_del_hito($hito, $datos, $contexto));
    }

    /**
     * Lo que falta de la implementación para que el mail de este hito se pueda mandar.
     *
     * No incluye `email`: la casilla es del servicio, que es quien la resuelve.
     *
     * @param string               $hito     Hito del mail.
     * @param array<string, mixed> $contexto El mismo contexto que `vista()`.
     *
     * @return array<int, string>
     */
    public static function faltan(string $hito, array $contexto): array
    {
        $faltan = [];

        // El botón del mail de bienvenida es el link del formulario. Sin él no hay mail.
        if ($hito === 'bienvenida' && trim((string) $contexto['form_link']) === '') {
            $faltan[] = 'form_link';
        }

        // Los mails que mandan al cliente a su sistema necesitan la dirección del sistema.
        if (in_array($hito, ['acceso', 'imagenes', 'listo'], true) && trim((string) $contexto['url_sistema']) === '') {
            $faltan[] = 'url_sistema';
        }

        return $faltan;
    }

    /**
     * El texto fijo de cada hito: asunto, preheader, titular e introducción.
     *
     * Los asuntos son los de la tabla del plan (§2.8). Los titulares, el preheader y la
     * introducción salen del prototipo aprobado, que es el texto final.
     *
     * El único texto con datos adentro es el preheader de `acceso` e `imagenes`, que lleva los
     * números.
     *
     * @param string               $hito  Hito del mail.
     * @param array<string, mixed> $datos Datos del hito, validados.
     *
     * @return array<string, string>
     */
    public static function copia(string $hito, array $datos): array
    {
        switch ($hito) {
            case 'bienvenida':
                return [
                    'asunto'    => 'Arrancamos con la implementación de tu sistema',
                    'preheader' => 'El primer paso es un formulario de cinco minutos para conocer cómo trabaja tu negocio.',
                    'titular'   => 'Arrancamos.',
                    'intro'     => 'Desde hoy empezamos a preparar tu sistema. Para dejarlo configurado como trabaja tu negocio '
                        . '—tus precios, tu stock, cómo vendés y quiénes forman tu equipo— necesitamos que completes un formulario corto.',
                ];

            case 'instalado':
                return [
                    'asunto'    => 'Tu sistema ya está instalado',
                    'preheader' => 'Ahora necesitamos la información de tu negocio para cargarla.',
                    'titular'   => 'Tu sistema ya está instalado.',
                    'intro'     => 'Lo dejamos configurado con lo que nos contaste: tus listas de precios, tus depósitos y la forma en que vendés. '
                        . 'Ahora le cargamos tu información, para que el primer día ya trabajes con tus datos reales.',
                ];

            case 'acceso':
                $articulos = isset($datos['articulos']) ? (int) $datos['articulos'] : 0;

                return [
                    'asunto'    => 'Ya podés entrar a tu sistema',
                    'preheader' => 'Cargamos ' . self::numero($articulos) . ' ' . self::plural($articulos, 'artículo', 'artículos')
                        . '. Entrá y fijate que esté todo como corresponde.',
                    'titular'   => 'Tu catálogo ya está adentro.',
                    'intro'     => 'Terminamos de cargar tus artículos y ya podés entrar a tu sistema con tus datos reales. '
                        . 'Recorré el listado con calma: precios, costos y stock. Si algo no te cierra, avisanos y lo ajustamos.',
                ];

            case 'imagenes':
                $con_foto = isset($datos['con_foto']) ? (int) $datos['con_foto'] : 0;
                $total    = isset($datos['total']) ? (int) $datos['total'] : 0;

                return [
                    'asunto'    => 'Las fotos de tu catálogo',
                    'preheader' => self::numero($con_foto) . ' de tus ' . self::numero($total) . ' '
                        . self::plural($total, 'artículo', 'artículos') . ' ya ' . ($con_foto === 1 ? 'tiene' : 'tienen') . ' foto.',
                    'titular'   => 'Tus productos ya tienen foto.',
                    'intro'     => 'Con el Catálogo Inteligente buscamos la foto de cada artículo y la verificamos antes de asignarla.',
                ];

            case 'categorias':
                return [
                    'asunto'    => 'Tres formas de ordenar tu catálogo',
                    'preheader' => 'Armamos tres propuestas de categorías para tus productos. Elegí la que más te sirva.',
                    'titular'   => 'Elegí cómo ordenar tu catálogo.',
                    'intro'     => 'Analizamos todos tus productos y armamos tres formas distintas de organizarlos. Cada una parte de un '
                        . 'criterio diferente: quedate con la que se parezca más a cómo te buscan tus clientes y cómo trabaja tu equipo.',
                ];

            default:
                // 'listo'. El servicio ya rechazó cualquier otro hito antes de llegar acá.
                return [
                    'asunto'    => 'Tu sistema está listo',
                    'preheader' => 'Terminamos de cargar tu información. Ya podés operar con ComercioCity.',
                    'titular'   => 'Todo listo para operar.',
                    'intro'     => 'Terminamos de cargar tu información y tu sistema ya está listo para vender, comprar y facturar con tus datos reales.',
                ];
        }
    }

    /**
     * La línea de progreso: el estado REAL de las ocho etapas al momento de armar el mail.
     *
     * Estado de cada etapa (el de `implementation_stages.status`): completed → completada,
     * in_progress → en_curso, skipped → salteada; cualquier otra cosa, y las etapas que no tienen
     * fila, son pendientes.
     *
     * "Etapa N de 8" es la etapa en curso. Si ninguna está en curso, es la primera que todavía no
     * se cerró (la que toca), y si todas están cerradas, la 8.
     *
     * La barra es (completadas + salteadas) / 8, redondeada, con un piso para que una
     * implementación recién empezada no muestre una barra invisible. Una etapa salteada cuenta
     * como hecha: no aplicaba, y no puede dejar la barra trabada.
     *
     * Los subpasos de la etapa 4 solo salen si esa etapa está en curso y el hito los define.
     *
     * @param Implementation $implementacion Implementación de la que se lee el estado.
     * @param string         $hito           Hito del mail (define los subpasos).
     *
     * @return array<string, mixed> etapa_actual, porcentaje, barra y etapas (numero, nombre, estado, subpasos).
     */
    public static function progreso(Implementation $implementacion, string $hito): array
    {
        // Se lee de la base y no de la relación `stages` de la instancia: esa puede venir cargada
        // de antes de que se cerrara la etapa, y el mail tiene que decir cómo está AHORA.
        $filas = ImplementationStage::where('implementation_id', $implementacion->id)
            ->get()
            ->keyBy('stage_number');

        $etapas              = [];
        $cerradas            = 0;
        $primera_en_curso    = null;
        $primera_sin_cerrar  = null;

        foreach (self::ETAPAS as $numero => $nombre) {
            $fila   = $filas->get($numero);
            $estado = self::estado_de_la_etapa($fila === null ? '' : (string) $fila->status);

            if ($estado === 'completada' || $estado === 'salteada') {
                $cerradas++;
            }

            if ($estado === 'en_curso' && $primera_en_curso === null) {
                $primera_en_curso = $numero;
            }

            if (($estado === 'en_curso' || $estado === 'pendiente') && $primera_sin_cerrar === null) {
                $primera_sin_cerrar = $numero;
            }

            $subpasos = [];

            if ($numero === 4 && $estado === 'en_curso' && isset(self::SUBPASOS_DE_LA_ETAPA_4[$hito])) {
                foreach (self::SUBPASOS_DE_LA_ETAPA_4[$hito] as $subpaso) {
                    $subpasos[] = ['texto' => $subpaso[0], 'estado' => $subpaso[1]];
                }
            }

            $etapas[] = [
                'numero'   => $numero,
                'nombre'   => $nombre,
                'estado'   => $estado,
                'subpasos' => $subpasos,
            ];
        }

        if ($primera_en_curso !== null) {
            $etapa_actual = $primera_en_curso;
        } elseif ($primera_sin_cerrar !== null) {
            $etapa_actual = $primera_sin_cerrar;
        } else {
            $etapa_actual = count(self::ETAPAS);
        }

        $porcentaje = (int) round($cerradas * 100 / count(self::ETAPAS));

        return [
            'etapa_actual' => $etapa_actual,
            'porcentaje'   => $porcentaje,
            'barra'        => max($porcentaje, self::BARRA_MINIMA),
            'etapas'       => $etapas,
        ];
    }

    /**
     * Número entero con el separador de miles argentino: 2418 → "2.418".
     *
     * @param int $numero
     *
     * @return string
     */
    public static function numero(int $numero): string
    {
        return number_format($numero, 0, ',', '.');
    }

    /**
     * Singular o plural según la cantidad: 1 → singular; cualquier otra cosa, incluido el cero, plural.
     *
     * @param int    $cantidad
     * @param string $singular
     * @param string $plural
     *
     * @return string
     */
    public static function plural(int $cantidad, string $singular, string $plural): string
    {
        return $cantidad === 1 ? $singular : $plural;
    }

    /**
     * Enmascara una casilla para mostrarla sin exponerla: "lucas@gmail.com" → "l***@gmail.com".
     *
     * Deja la primera letra y el dominio entero. Una dirección sin arroba (que no debería llegar
     * hasta acá) sale toda tapada.
     *
     * @param string $email
     *
     * @return string
     */
    public static function enmascarar(string $email): string
    {
        $email  = trim($email);
        $arroba = strrpos($email, '@');

        if ($arroba === false || $arroba === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1, 'UTF-8') . '***' . substr($email, $arroba);
    }

    /**
     * Porcentaje de artículos con foto, redondeado, sin mentir en los bordes.
     *
     * Solo es 100 si TODOS tienen foto (2.417 de 2.418 redondea a 100, y decirle "el 100 %" al
     * cliente cuando falta uno es una promesa que el sistema no cumple), y solo es 0 si ninguno
     * tiene (1 de 5.000 redondea a 0 y se lee como "no hicimos nada").
     *
     * @param int $con_foto Artículos con foto.
     * @param int $total    Artículos del catálogo. Mayor que cero (lo valida el servicio).
     *
     * @return int
     */
    public static function porcentaje_con_foto(int $con_foto, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        $porcentaje = (int) round($con_foto * 100 / $total);

        if ($con_foto < $total && $porcentaje >= 100) {
            return 99;
        }

        if ($con_foto > 0 && $porcentaje <= 0) {
            return 1;
        }

        return max(0, min(100, $porcentaje));
    }

    /**
     * Traduce el status de una etapa del panel al estado que muestra el mail.
     *
     * @param string $status completed | in_progress | skipped | pending | '' (sin fila).
     *
     * @return string completada | en_curso | salteada | pendiente.
     */
    private static function estado_de_la_etapa(string $status): string
    {
        switch ($status) {
            case 'completed':
                return 'completada';

            case 'in_progress':
                return 'en_curso';

            case 'skipped':
                return 'salteada';

            default:
                return 'pendiente';
        }
    }

    /**
     * Deja la dirección del sistema lista para mostrarla: sin esquema y sin barra final.
     *
     * @param string $url "https://losandes.comerciocity.com" → "losandes.comerciocity.com".
     *
     * @return string
     */
    private static function direccion_para_mostrar(string $url): string
    {
        $limpia = preg_replace('#^https?://#i', '', trim($url));

        return rtrim((string) $limpia, '/');
    }

    /**
     * Lo que agrega cada hito a la vista, ya formateado.
     *
     * @param string               $hito     Hito del mail.
     * @param array<string, mixed> $datos    Datos del hito, validados.
     * @param array<string, mixed> $contexto Contexto (para el `recursos_url` por defecto y la URL del sistema).
     *
     * @return array<string, mixed>
     */
    private static function vista_del_hito(string $hito, array $datos, array $contexto): array
    {
        switch ($hito) {
            case 'acceso':
                $articulos = (int) $datos['articulos'];

                return [
                    'articulos_texto'   => self::numero($articulos),
                    'articulos_leyenda' => self::plural($articulos, 'artículo cargado', 'artículos cargados') . ' en tu sistema',
                ];

            case 'imagenes':
                return self::vista_de_las_imagenes($datos, (string) $contexto['url_sistema']);

            case 'categorias':
                return self::vista_de_las_categorias($datos);

            case 'listo':
                return self::vista_del_listo($datos, (string) $contexto['recursos_url']);

            default:
                // bienvenida e instalado no llevan datos propios.
                return [];
        }
    }

    /**
     * El bloque del mail de las fotos: cuántos artículos tienen foto, el porcentaje y las que
     * esperan el visto bueno del cliente.
     *
     * @param array<string, mixed> $datos       con_foto, total y, si vinieron, a_revisar y fuentes.
     * @param string               $url_sistema Dirección del sistema, para el botón.
     *
     * @return array<string, mixed>
     */
    private static function vista_de_las_imagenes(array $datos, string $url_sistema): array
    {
        $con_foto  = (int) $datos['con_foto'];
        $total     = (int) $datos['total'];
        $a_revisar = array_key_exists('a_revisar', $datos) && $datos['a_revisar'] !== null ? (int) $datos['a_revisar'] : null;

        // El aviso de las fotos por revisar solo existe si hay alguna. El texto cambia de forma en
        // singular, no solo el sustantivo: "Es la que la IA no pudo confirmar ... la aprobás".
        $aviso_titulo  = '';
        $aviso_detalle = '';

        if ($a_revisar !== null && $a_revisar > 0) {
            if ($a_revisar === 1) {
                $aviso_titulo  = '1 foto espera tu visto bueno.';
                $aviso_detalle = 'Es la que la IA no pudo confirmar con total seguridad: '
                    . 'con un toque la aprobás o elegís otra de las que encontró.';
            } else {
                $aviso_titulo  = self::numero($a_revisar) . ' fotos esperan tu visto bueno.';
                $aviso_detalle = 'Son las que la IA no pudo confirmar con total seguridad: '
                    . 'con un toque las aprobás o elegís otra de las que encontró.';
            }
        }

        return [
            'con_foto_texto' => self::numero($con_foto),
            'total_texto'    => self::numero($total),
            'fotos_leyenda'  => self::plural($total, 'artículo con foto', 'artículos con foto'),
            'porcentaje'     => self::porcentaje_con_foto($con_foto, $total),
            'aviso_titulo'   => $aviso_titulo,
            'aviso_detalle'  => $aviso_detalle,
            // Con nada por revisar, mandar al cliente a una pantalla vacía no tiene sentido. Si el
            // dato no vino, no se sabe, y el botón queda.
            'mostrar_boton'  => $a_revisar === null || $a_revisar > 0,
            'url_fotos'      => $url_sistema !== '' ? rtrim($url_sistema, '/') . self::RUTA_DE_LAS_FOTOS : '',
            'fuentes'        => isset($datos['fuentes']) ? (string) $datos['fuentes'] : '',
        ];
    }

    /**
     * El bloque del mail de las categorías: las tres opciones y el cierre.
     *
     * @param array<string, mixed> $datos opciones (exactamente tres) y, si vino, como_elegir.
     *
     * @return array<string, mixed>
     */
    private static function vista_de_las_categorias(array $datos): array
    {
        $opciones = [];

        foreach (array_values($datos['opciones']) as $posicion => $opcion) {
            $cantidad = (int) $opcion['categorias'];

            $opciones[] = [
                'indice'            => $posicion + 1,
                'categorias_texto'  => self::numero($cantidad),
                'categorias_leyenda' => self::plural($cantidad, 'categoría', 'categorías'),
                'nombre'            => (string) $opcion['nombre'],
                'base'              => (string) $opcion['base'],
                'ejemplos'          => isset($opcion['ejemplos']) && is_array($opcion['ejemplos']) ? array_values($opcion['ejemplos']) : [],
            ];
        }

        return [
            'opciones'    => $opciones,
            // Si el dato no vino, el cierre es el de siempre y lo pone la vista.
            'como_elegir' => isset($datos['como_elegir']) ? (string) $datos['como_elegir'] : '',
        ];
    }

    /**
     * El bloque del mail "listo": el resumen de lo que quedó cargado y el botón de los tutoriales.
     *
     * Del resumen salen solo los rubros que vinieron con un número mayor que cero: "0 proveedores"
     * en una lista de lo que quedó cargado es ruido, no información.
     *
     * @param array<string, mixed> $datos            resumen y, si vino, recursos_url.
     * @param string               $recursos_default El centro de recursos de la config.
     *
     * @return array<string, mixed>
     */
    private static function vista_del_listo(array $datos, string $recursos_default): array
    {
        $resumen = [];
        $cargado = isset($datos['resumen']) && is_array($datos['resumen']) ? $datos['resumen'] : [];

        foreach (self::RUBROS_DEL_RESUMEN as $clave => $rubro) {
            if (! isset($cargado[$clave]) || (int) $cargado[$clave] <= 0) {
                continue;
            }

            $cantidad  = (int) $cargado[$clave];
            $resumen[] = [
                'numero'  => self::numero($cantidad),
                'leyenda' => self::plural($cantidad, $rubro[0], $rubro[1]),
            ];
        }

        $recursos_url = isset($datos['recursos_url']) && trim((string) $datos['recursos_url']) !== ''
            ? trim((string) $datos['recursos_url'])
            : $recursos_default;

        return [
            'resumen'      => $resumen,
            'recursos_url' => $recursos_url,
            // La línea de ARCA de "lo que sigue" va salvo que se haya pasado `arca: false` (el cliente no factura electrónicamente).
            'con_arca'     => ! (isset($datos['arca']) && $datos['arca'] === false),
        ];
    }
}
