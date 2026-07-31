/**
 * Script node para generar la imagen de vista previa (og:image, 1200x630) que consume la tienda al
 * compartir el link por WhatsApp/Instagram/Facebook (grupo 270), a partir del logo del comercio o,
 * si no hay logo, de un placeholder generado en el momento con la inicial del nombre — mismo
 * criterio que "generate_pwa_icons.js" (grupo 208), pero para un único archivo con proporción
 * 1.91:1 en vez del set completo de íconos cuadrados.
 *
 * Este archivo lo copia y ejecuta EcommerceInstallationService::step_generate_og_image() dentro
 * del clone de tienda-spa en el VPS de builds (antes de "npm run build"), apuntando la salida a
 * "public/img/og-image.png" del repo, para que Vue CLI la copie a "dist/img/og-image.png" durante
 * el build. Esa es la URL fija que ya escribe VUE_APP_SITE_IMAGE (grupo 270/04):
 * "{spa_url}/img/og-image.png".
 *
 * Por qué se compone en vez de reusar el logo/favicon tal cual: WhatsApp y Facebook recortan
 * "og:image" a proporción 1.91:1. Un logo cuadrado (con frecuencia de fondo transparente), servido
 * tal cual, sale recortado y con el fondo en negro en la vista previa del link.
 *
 * Uso (modo logo):
 *   node generate_og_image.js <logo_local_path> <color_hex> <output_path>
 *
 * Uso (modo placeholder — sin logo, o si la descarga del logo falló):
 *   node generate_og_image.js --placeholder <color_hex> <nombre_comercio> <output_path>
 *
 * Notas de diseño:
 * - Lienzo fijo de 1200x630 (proporción 1.91:1, la que recortan WhatsApp/Facebook), relleno con
 *   el color primario del comercio.
 * - Modo logo: el logo se redimensiona con fit: 'inside' (nunca 'cover', que lo recortaría) para
 *   que entre completo, sin deformarse ni recortarse, dentro de un área central de ~60% del ancho
 *   y ~60% del alto del lienzo, y se compone centrado. Si el logo tiene transparencia, se aplana
 *   (flatten) sobre el color primario del comercio antes de centrarlo — nunca sobre negro.
 * - Modo placeholder: mismo criterio visual que el placeholder de generate_pwa_icons.js — la
 *   inicial del nombre del comercio en blanco, centrada, compuesta como SVG en memoria y
 *   rasterizada con sharp (sin agregar dependencias de fuentes nuevas), escalada al lienzo
 *   landscape de 1200x630 en vez del cuadrado que usan los íconos.
 * - Depende solo de "sharp" (ya se instala en el VPS antes de correr este script, sin persistirlo
 *   en el package.json de tienda-spa — ver EcommerceInstallationService::step_generate_og_image()).
 */

/* Módulo nativo de node para manejo de rutas de archivos (dirname del output_path). */
const path = require('path')
/* Módulo nativo de node para crear el directorio de salida y verificar el logo local. */
const fs = require('fs')
/* Librería de procesamiento de imágenes usada para redimensionar el logo y componer el lienzo. */
const sharp = require('sharp')

/* Ancho fijo del lienzo (proporción 1.91:1, la que recortan WhatsApp/Facebook al armar la vista previa). */
const CANVAS_WIDTH = 1200
/* Alto fijo del lienzo. */
const CANVAS_HEIGHT = 630

/* Proporción del lienzo que ocupa el área central donde entra el logo (el resto queda de margen). */
const LOGO_AREA_RATIO = 0.6
/* Ancho máximo del área central del logo (~720px = 60% de 1200). */
const LOGO_AREA_WIDTH = Math.round(CANVAS_WIDTH * LOGO_AREA_RATIO)
/* Alto máximo del área central del logo (~378px = 60% de 630). */
const LOGO_AREA_HEIGHT = Math.round(CANVAS_HEIGHT * LOGO_AREA_RATIO)

/* Color de respaldo cuando el color primario recibido no es un hex válido (mismo valor que usa
 * generate_pwa_icons.js, para que el fallback visual sea consistente en toda la tienda). */
const PLACEHOLDER_FALLBACK_COLOR = '#c5111d'

/* Modo del script: si el primer argumento es literalmente '--placeholder', no hay logo (o falló
 * su descarga) y hay que componer la imagen base en el momento en vez de leerla de un archivo. */
const isPlaceholderMode = process.argv[2] === '--placeholder'

/* Ruta local del logo ya descargado (solo modo logo; null en modo placeholder). */
let logoPath = null
/* Color primario del comercio en hex, usado en los dos modos como fondo del lienzo. */
let colorHex = null
/* Nombre del comercio, usado para la inicial del placeholder (solo modo placeholder). */
let displayName = null
/* Ruta absoluta de salida del PNG compuesto (1200x630), en los dos modos. */
let outputPath = null

if (isPlaceholderMode) {
	/* Modo placeholder: node generate_og_image.js --placeholder <color_hex> <nombre_comercio> <output_path> */
	colorHex = process.argv[3]
	displayName = process.argv[4]
	outputPath = process.argv[5]

	if (!outputPath) {
		console.error('Uso: node generate_og_image.js --placeholder <color_hex> <nombre_comercio> <output_path>')
		process.exit(1)
	}
} else {
	/* Modo logo: node generate_og_image.js <logo_local_path> <color_hex> <output_path> */
	logoPath = process.argv[2]
	colorHex = process.argv[3]
	outputPath = process.argv[4]

	if (!logoPath || !outputPath) {
		console.error('Uso: node generate_og_image.js <logo_local_path> <color_hex> <output_path>')
		process.exit(1)
	}

	if (!fs.existsSync(logoPath)) {
		console.error('GENERATE_OG_IMAGE_ERROR: no existe el logo local: ' + logoPath)
		process.exit(1)
	}
}

/**
 * Valida el color hex recibido contra "#rrggbb"; si no matchea, devuelve el color de respaldo
 * (mismo criterio que resolvePlaceholderColor() de generate_pwa_icons.js).
 *
 * @param  string  color
 * @return string
 */
function resolveColor(color) {
	return /^#[0-9a-fA-F]{6}$/.test(String(color || '')) ? color : PLACEHOLDER_FALLBACK_COLOR
}

/**
 * Convierte un color hex "#rrggbb" ya validado a un objeto {r, g, b} para las opciones de sharp
 * que piden componentes numéricos ("create" del lienzo y "flatten" del logo).
 *
 * @param  string  hex  Color ya validado por resolveColor() (siempre "#rrggbb").
 * @return {r: number, g: number, b: number}
 */
function hexToRgb(hex) {
	return {
		r: parseInt(hex.slice(1, 3), 16),
		g: parseInt(hex.slice(3, 5), 16),
		b: parseInt(hex.slice(5, 7), 16),
	}
}

/**
 * Devuelve la inicial a usar en el placeholder: el primer carácter alfanumérico del nombre del
 * comercio, en mayúscula. Si el nombre viene vacío o no tiene ningún carácter alfanumérico,
 * devuelve "?" (mismo criterio que resolveInitial() de generate_pwa_icons.js).
 *
 * @param  string  name  Nombre del comercio (puede venir vacío o undefined).
 * @return string
 */
function resolveInitial(name) {
	const match = String(name || '').match(/[a-zA-Z0-9]/)
	return match ? match[0].toUpperCase() : '?'
}

/**
 * Arma en memoria la imagen del placeholder: lienzo de 1200x630 relleno con el color primario del
 * comercio y la inicial del nombre en blanco, centrada, en un tipo sans-serif bold. Se compone
 * como SVG en string y se rasteriza con sharp (que soporta SVG de entrada, sin agregar ninguna
 * dependencia nueva) — mismo mecanismo que buildPlaceholderImageBuffer() de
 * generate_pwa_icons.js, escalado al lienzo landscape de la og:image en vez del cuadrado.
 *
 * @return Promise<Buffer>  PNG en memoria de 1200x630, listo para guardarse directo como salida.
 */
async function buildPlaceholderBuffer() {
	const color = resolveColor(colorHex)
	const initial = resolveInitial(displayName)
	/* Tamaño de fuente relativo al alto del lienzo (el lado más chico de los dos). */
	const fontSize = Math.round(CANVAS_HEIGHT * 0.5)

	const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + CANVAS_WIDTH + '" height="' + CANVAS_HEIGHT + '">'
		+ '<rect width="' + CANVAS_WIDTH + '" height="' + CANVAS_HEIGHT + '" fill="' + color + '" />'
		+ '<text x="50%" y="52%" text-anchor="middle" dominant-baseline="middle" '
		+ 'font-family="Arial, Helvetica, sans-serif" font-weight="bold" font-size="' + fontSize + '" '
		+ 'fill="#ffffff">' + initial + '</text>'
		+ '</svg>'

	return sharp(Buffer.from(svg)).png().toBuffer()
}

/**
 * Compone el logo del cliente sobre el lienzo de la og:image: lo redimensiona sin deformarlo ni
 * recortarlo (fit: 'inside') para que entre completo dentro del área central (~60% ancho/alto del
 * lienzo), aplana cualquier transparencia sobre el color primario del comercio (nunca sobre
 * negro) y lo centra sobre un lienzo de 1200x630 relleno con ese mismo color.
 *
 * @return Promise<Buffer>  PNG en memoria de 1200x630.
 */
async function buildLogoBuffer() {
	const color = resolveColor(colorHex)
	const rgb = hexToRgb(color)

	/* Redimensiona el logo para que entre completo dentro del área central sin deformarlo ni
	 * recortarlo ("inside" nunca recorta, a diferencia de "cover"), y aplana cualquier
	 * transparencia del logo sobre el color primario del comercio. */
	const resizedLogo = await sharp(logoPath)
		.resize(LOGO_AREA_WIDTH, LOGO_AREA_HEIGHT, { fit: 'inside' })
		.flatten({ background: rgb })
		.png()
		.toBuffer()

	/* Dimensiones reales tras el resize proporcional ("inside" no rellena hasta el tamaño pedido
	 * si el logo no tiene esa proporción exacta), necesarias para centrarlo a mano en el lienzo. */
	const resizedMeta = await sharp(resizedLogo).metadata()
	const left = Math.round((CANVAS_WIDTH - resizedMeta.width) / 2)
	const top = Math.round((CANVAS_HEIGHT - resizedMeta.height) / 2)

	return sharp({
		create: {
			width: CANVAS_WIDTH,
			height: CANVAS_HEIGHT,
			channels: 4,
			/* Fondo del lienzo: el color primario del comercio, tal como pide el prompt (nunca negro). */
			background: { r: rgb.r, g: rgb.g, b: rgb.b, alpha: 1 },
		},
	})
		.composite([{ input: resizedLogo, left: left, top: top }])
		.png()
		.toBuffer()
}

/**
 * Genera la og:image (modo logo o placeholder, según los argumentos recibidos) y la guarda en
 * "outputPath", creando el directorio de destino si todavía no existe.
 *
 * @return Promise<void>
 */
async function run() {
	/* Fuente de la imagen final: el logo compuesto sobre el lienzo (modo logo), o el placeholder
	 * con la inicial ya compuesto en memoria (modo --placeholder). */
	const imageBuffer = isPlaceholderMode ? await buildPlaceholderBuffer() : await buildLogoBuffer()

	/* Crea la carpeta de salida si todavía no existe (instalación desde cero: "public/img" puede
	 * no existir todavía en el clone del VPS). */
	fs.mkdirSync(path.dirname(outputPath), { recursive: true })
	await sharp(imageBuffer).png().toFile(outputPath)

	console.log('GENERATE_OG_IMAGE_DONE')
}

run().catch(function (error) {
	console.error('GENERATE_OG_IMAGE_ERROR: ' + (error && error.message ? error.message : String(error)))
	process.exit(1)
})
