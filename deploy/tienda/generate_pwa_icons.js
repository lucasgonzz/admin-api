/**
 * Script node reutilizable para generar el set de íconos PWA de tienda-spa, a partir del logo
 * cuadrado de un cliente o, si no hay logo, de un ícono placeholder generado en el momento
 * (grupo 208 — antes solo se contemplaba el caso con logo).
 *
 * Este archivo lo copia y ejecuta EcommerceInstallationService::step_generate_pwa_icons()
 * dentro del clone de tienda-spa en el VPS de builds (antes de "npm run build"), apuntando
 * la salida a "public/img/icons" del repo — el mismo set que hoy produce vue-asset-generate
 * y que consume vue.config.js (ver bloque pwa.manifestOptions.icons).
 *
 * Uso (modo logo, firma histórica — se mantiene intacta):
 *   node generate_pwa_icons.js <logo_local_path> <output_dir> [nombre_comercio]
 *
 * Uso (modo placeholder, grupo 208 — sin logo, o si la descarga del logo falló):
 *   node generate_pwa_icons.js --placeholder <color_hex> <nombre_comercio> <output_dir>
 *
 * Notas de diseño:
 * - Modo logo: el logo del cliente YA es cuadrado (se valida/recorta antes en el flujo de
 *   onboarding), así que acá no se recorta: se coloca tal cual sobre un lienzo cuadrado con fondo
 *   BLANCO. Para los íconos "maskable" se deja un padding adicional para que Android no corte el
 *   logo al aplicar la máscara (safe zone).
 * - Modo placeholder: se compone un lienzo cuadrado relleno con el color primario del comercio y
 *   la inicial del nombre en blanco, centrada, como SVG en memoria (sharp acepta SVG de entrada
 *   sin dependencias nuevas). Esa imagen se usa como fuente y reutiliza EXACTAMENTE el mismo
 *   pipeline de composeIcon()/FLAT_SIZES/MASKABLE_SIZES/favicon.ico que el modo con logo — el
 *   objetivo es que ningún cliente sin logo publique jamás el favicon de otro cliente (ver
 *   EcommerceInstallationService::reset_versioned_icon_assets() para la otra mitad del fix).
 * - Depende de "sharp" (se instala en el VPS antes de correr este script, sin persistirlo en el
 *   package.json de tienda-spa — ver EcommerceInstallationService::step_generate_pwa_icons()).
 * - "safari-pinned-tab.svg" SÍ se genera acá (grupo 208 — antes se dejaba el genérico versionado,
 *   que era en realidad el de un cliente real viejo): es un vectorial monocromo simple con la
 *   inicial del comercio, en los dos modos.
 */

/* Módulo nativo de node para manejo de rutas de archivos. */
const path = require('path')
/* Módulo nativo de node para crear directorios, verificar archivos y escribir el SVG de Safari. */
const fs = require('fs')
/* Librería de procesamiento de imágenes usada para componer el lienzo y redimensionar. */
const sharp = require('sharp')

/* Color de respaldo del ícono placeholder cuando el color primario recibido no es un hex válido. */
const PLACEHOLDER_FALLBACK_COLOR = '#c5111d'

/* Modo del script: si el primer argumento es literalmente '--placeholder', no hay logo (o falló
 * la descarga) y hay que generar la imagen base en el momento en vez de leerla de un archivo. */
const isPlaceholderMode = process.argv[2] === '--placeholder'

/* Ruta local del logo ya descargado (solo modo logo; null en modo placeholder). */
let logoPath = null
/* Carpeta de salida (public/img/icons), resuelta según el modo. */
let outputDir = null
/* Color primario del comercio en hex (solo modo placeholder). */
let placeholderColor = null
/* Nombre del comercio, usado para la inicial del placeholder y de safari-pinned-tab.svg. */
let displayName = null

if (isPlaceholderMode) {
	/* Modo placeholder: node generate_pwa_icons.js --placeholder <color_hex> <nombre_comercio> <output_dir> */
	placeholderColor = process.argv[3]
	displayName = process.argv[4]
	outputDir = process.argv[5]

	if (!outputDir) {
		console.error('Uso: node generate_pwa_icons.js --placeholder <color_hex> <nombre_comercio> <output_dir>')
		process.exit(1)
	}
} else {
	/* Modo logo (firma histórica, con el nombre del comercio como tercer argumento OPCIONAL nuevo
	 * — usado solo para la inicial de safari-pinned-tab.svg; si no se pasa, esa inicial cae a "?"). */
	logoPath = process.argv[2]
	outputDir = process.argv[3]
	displayName = process.argv[4] || ''

	if (!logoPath || !outputDir) {
		console.error('Uso: node generate_pwa_icons.js <logo_local_path> <output_dir> [nombre_comercio]')
		process.exit(1)
	}

	if (!fs.existsSync(logoPath)) {
		console.error('GENERATE_ICONS_ERROR: no existe el logo local: ' + logoPath)
		process.exit(1)
	}
}

/* Crea la carpeta de salida si todavía no existe (instalación desde cero). */
fs.mkdirSync(outputDir, { recursive: true })

/**
 * Tamaños "planos" (el logo ocupa todo el lienzo, sin padding extra) requeridos por vue.config.js:
 * favicons, apple-touch-icon, msapplication y mstile. Se generan sobre lienzo cuadrado blanco.
 */
const FLAT_SIZES = [
	{ name: 'android-chrome-192x192.png', size: 192 },
	{ name: 'android-chrome-512x512.png', size: 512 },
	{ name: 'apple-touch-icon-60x60.png', size: 60 },
	{ name: 'apple-touch-icon-76x76.png', size: 76 },
	{ name: 'apple-touch-icon-120x120.png', size: 120 },
	{ name: 'apple-touch-icon-152x152.png', size: 152 },
	{ name: 'apple-touch-icon-180x180.png', size: 180 },
	{ name: 'apple-touch-icon.png', size: 180 },
	{ name: 'favicon-16x16.png', size: 16 },
	{ name: 'favicon-32x32.png', size: 32 },
	{ name: 'msapplication-icon-144x144.png', size: 144 },
	{ name: 'mstile-150x150.png', size: 150 },
]

/**
 * Tamaños "maskable" (Android puede recortar en círculo): se deja un padding del ~20% para que
 * el logo quede dentro de la "safe zone" y no se corte con la máscara circular.
 */
const MASKABLE_SIZES = [
	{ name: 'android-chrome-maskable-192x192.png', size: 192 },
	{ name: 'android-chrome-maskable-512x512.png', size: 512 },
]

/* Proporción del lienzo que ocupa el logo en los íconos maskable (resto = padding blanco). */
const MASKABLE_LOGO_RATIO = 0.8

/**
 * Devuelve la inicial a usar en el ícono placeholder y en safari-pinned-tab.svg: el primer
 * carácter alfanumérico del nombre del comercio, en mayúscula. Si el nombre viene vacío o no
 * tiene ningún carácter alfanumérico, devuelve "?".
 *
 * @param  string  name  Nombre del comercio (puede venir vacío o undefined).
 * @return string
 */
function resolveInitial(name) {
	const match = String(name || '').match(/[a-zA-Z0-9]/)
	return match ? match[0].toUpperCase() : '?'
}

/**
 * Valida el color hex recibido contra "#rrggbb"; si no matchea, devuelve el color de respaldo.
 *
 * @param  string  color
 * @return string
 */
function resolvePlaceholderColor(color) {
	return /^#[0-9a-fA-F]{6}$/.test(String(color || '')) ? color : PLACEHOLDER_FALLBACK_COLOR
}

/**
 * Arma en memoria la imagen base del ícono placeholder: lienzo cuadrado de 512x512 relleno con
 * el color primario del comercio, con la inicial del nombre en blanco, centrada, en un tipo
 * sans-serif bold que ocupa aproximadamente el 55% del lado. Se compone como SVG en string y se
 * rasteriza con sharp (que soporta SVG de entrada, sin agregar ninguna dependencia nueva).
 *
 * @return Promise<Buffer>  PNG en memoria, listo para usarse como fuente de composeIcon().
 */
async function buildPlaceholderImageBuffer() {
	/* Lado del lienzo base (se reescala hacia abajo para cada tamaño dentro de composeIcon()). */
	const size = 512
	const color = resolvePlaceholderColor(placeholderColor)
	const initial = resolveInitial(displayName)
	const fontSize = Math.round(size * 0.55)

	const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '">'
		+ '<rect width="' + size + '" height="' + size + '" fill="' + color + '" />'
		+ '<text x="50%" y="52%" text-anchor="middle" dominant-baseline="middle" '
		+ 'font-family="Arial, Helvetica, sans-serif" font-weight="bold" font-size="' + fontSize + '" '
		+ 'fill="#ffffff">' + initial + '</text>'
		+ '</svg>'

	return sharp(Buffer.from(svg)).png().toBuffer()
}

/**
 * Compone la imagen base (logo del cliente o placeholder en memoria) sobre un lienzo cuadrado con
 * fondo blanco y lo guarda como PNG.
 *
 * @param  string|Buffer  source      Fuente de la imagen: path local del logo, o buffer PNG del
 *                                    placeholder ya compuesto (sharp() acepta ambos por igual).
 * @param  number          targetSize  Lado del lienzo final en píxeles.
 * @param  number          logoSize    Lado al que se redimensiona la imagen dentro del lienzo (<= targetSize).
 * @param  string          outputPath  Ruta absoluta del PNG de salida.
 * @return Promise<void>
 */
async function composeIcon(source, targetSize, logoSize, outputPath) {
	/* Redimensiona la imagen fuente al tamaño interno (sin recortar, "contain" preserva el cuadrado). */
	const resizedLogo = await sharp(source)
		.resize(logoSize, logoSize, { fit: 'contain', background: { r: 255, g: 255, b: 255, alpha: 1 } })
		.toBuffer()

	/* Offset para centrar la imagen dentro del lienzo final. */
	const offset = Math.round((targetSize - logoSize) / 2)

	await sharp({
		create: {
			width: targetSize,
			height: targetSize,
			channels: 4,
			/* Fondo blanco del lienzo, tal como pide el prompt (no transparente). */
			background: { r: 255, g: 255, b: 255, alpha: 1 },
		},
	})
		.composite([{ input: resizedLogo, left: offset, top: offset }])
		.png()
		.toFile(outputPath)
}

/**
 * Escribe "safari-pinned-tab.svg": vectorial monocromo simple con la inicial del comercio, usado
 * por Safari para el ícono de pestaña anclada. Se genera en los DOS modos (con logo o
 * placeholder) porque no se puede derivar razonablemente de un raster a color — dejar el del
 * cliente anterior es justamente el bug que este script soluciona (grupo 208).
 *
 * @param  string  outputPath  Ruta absoluta del SVG de salida.
 * @return void
 */
function writeSafariPinnedTab(outputPath) {
	const initial = resolveInitial(displayName)
	const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">'
		+ '<text x="8" y="8.5" text-anchor="middle" dominant-baseline="middle" '
		+ 'font-family="Arial, Helvetica, sans-serif" font-weight="bold" font-size="12" '
		+ 'fill="#000000">' + initial + '</text>'
		+ '</svg>'
	fs.writeFileSync(outputPath, svg)
}

/**
 * Genera todo el set de íconos (planos + maskable), favicon.ico y safari-pinned-tab.svg, a partir
 * del logo del cliente (modo logo) o de un ícono compuesto en el momento (modo placeholder).
 *
 * @return Promise<void>
 */
async function run() {
	/* Fuente de la imagen base para composeIcon(): el logo local (modo clásico) o el PNG del
	 * placeholder ya compuesto en memoria (modo --placeholder). sharp() acepta indistintamente un
	 * path de archivo o un Buffer, así que el resto del pipeline no necesita saber cuál es. */
	const iconSource = isPlaceholderMode ? await buildPlaceholderImageBuffer() : logoPath

	/* Íconos planos: la imagen ocupa el 100% del lienzo. */
	for (const icon of FLAT_SIZES) {
		const outPath = path.join(outputDir, icon.name)
		await composeIcon(iconSource, icon.size, icon.size, outPath)
		console.log('ICON_OK ' + icon.name)
	}

	/* Íconos maskable: la imagen ocupa MASKABLE_LOGO_RATIO del lienzo, con padding blanco alrededor. */
	for (const icon of MASKABLE_SIZES) {
		const logoSize = Math.round(icon.size * MASKABLE_LOGO_RATIO)
		const outPath = path.join(outputDir, icon.name)
		await composeIcon(iconSource, icon.size, logoSize, outPath)
		console.log('ICON_OK ' + icon.name)
	}

	/* favicon.ico: sharp no exporta ICO nativamente; se guarda como PNG de 32x32 con extensión .ico,
	 * que la mayoría de navegadores igual acepta cuando se sirve con el content-type correcto. Esta
	 * línea siempre sobreescribe el favicon.ico que hubiera versionado (ver reset_versioned_icon_assets()
	 * del lado PHP, que además lo restaura a lo versionado antes de correr este script). */
	await sharp(iconSource)
		.resize(32, 32, { fit: 'contain', background: { r: 255, g: 255, b: 255, alpha: 1 } })
		.png()
		.toFile(path.join(outputDir, '..', '..', 'favicon.ico'))
	console.log('ICON_OK favicon.ico')

	/* safari-pinned-tab.svg: ver writeSafariPinnedTab() — se genera siempre, en los dos modos. */
	writeSafariPinnedTab(path.join(outputDir, 'safari-pinned-tab.svg'))
	console.log('ICON_OK safari-pinned-tab.svg')

	console.log('GENERATE_ICONS_DONE')
}

run().catch(function (error) {
	console.error('GENERATE_ICONS_ERROR: ' + (error && error.message ? error.message : String(error)))
	process.exit(1)
})
