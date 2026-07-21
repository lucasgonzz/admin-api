/**
 * Script node reutilizable para generar el set de íconos PWA de tienda-spa a partir
 * del logo cuadrado de un cliente (prompt 584 — EcommerceInstallationService).
 *
 * Este archivo lo copia y ejecuta EcommerceInstallationService::step_generate_pwa_icons()
 * dentro del clone de tienda-spa en el VPS de builds (antes de "npm run build"), apuntando
 * la salida a "public/img/icons" del repo — el mismo set que hoy produce vue-asset-generate
 * y que consume vue.config.js (ver bloque pwa.manifestOptions.icons).
 *
 * Uso: node generate_pwa_icons.js <logo_local_path> <output_dir>
 *
 * Notas de diseño:
 * - El logo del cliente YA es cuadrado (se valida/recorta antes en el flujo de onboarding), así
 *   que acá no se recorta: se coloca tal cual sobre un lienzo cuadrado con fondo BLANCO. Para los
 *   íconos "maskable" se deja un padding adicional para que Android no corte el logo al aplicar
 *   la máscara (safe zone).
 * - Depende de "sharp" (se instala en el VPS antes de correr este script, sin persistirlo en el
 *   package.json de tienda-spa — ver EcommerceInstallationService::step_generate_pwa_icons()).
 * - "safari-pinned-tab.svg" NO se genera acá: es un vectorial monocromo y se deja el genérico ya
 *   versionado en el repo (no se puede derivar razonablemente de un raster a color).
 */

/* Módulo nativo de node para manejo de rutas de archivos. */
const path = require('path')
/* Módulo nativo de node para crear directorios y verificar archivos. */
const fs = require('fs')
/* Librería de procesamiento de imágenes usada para componer el lienzo y redimensionar. */
const sharp = require('sharp')

/* Argumentos recibidos: ruta local del logo ya descargado y carpeta de salida (public/img/icons). */
const logoPath = process.argv[2]
const outputDir = process.argv[3]

if (!logoPath || !outputDir) {
	console.error('Uso: node generate_pwa_icons.js <logo_local_path> <output_dir>')
	process.exit(1)
}

if (!fs.existsSync(logoPath)) {
	console.error('GENERATE_ICONS_ERROR: no existe el logo local: ' + logoPath)
	process.exit(1)
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
 * Compone el logo sobre un lienzo cuadrado con fondo blanco y lo guarda como PNG.
 *
 * @param  string  targetSize  Lado del lienzo final en píxeles.
 * @param  string  logoSize    Lado al que se redimensiona el logo dentro del lienzo (<= targetSize).
 * @param  string  outputPath  Ruta absoluta del PNG de salida.
 * @return Promise<void>
 */
async function composeIcon(targetSize, logoSize, outputPath) {
	/* Redimensiona el logo al tamaño interno (sin recortar, "contain" preserva el cuadrado). */
	const resizedLogo = await sharp(logoPath)
		.resize(logoSize, logoSize, { fit: 'contain', background: { r: 255, g: 255, b: 255, alpha: 1 } })
		.toBuffer()

	/* Offset para centrar el logo dentro del lienzo final. */
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
 * Genera todo el set de íconos (planos + maskable) y, al final, favicon.ico.
 *
 * @return Promise<void>
 */
async function run() {
	/* Íconos planos: el logo ocupa el 100% del lienzo. */
	for (const icon of FLAT_SIZES) {
		const outPath = path.join(outputDir, icon.name)
		await composeIcon(icon.size, icon.size, outPath)
		console.log('ICON_OK ' + icon.name)
	}

	/* Íconos maskable: el logo ocupa MASKABLE_LOGO_RATIO del lienzo, con padding blanco alrededor. */
	for (const icon of MASKABLE_SIZES) {
		const logoSize = Math.round(icon.size * MASKABLE_LOGO_RATIO)
		const outPath = path.join(outputDir, icon.name)
		await composeIcon(icon.size, logoSize, outPath)
		console.log('ICON_OK ' + icon.name)
	}

	/* favicon.ico: sharp no exporta ICO nativamente; se guarda como PNG de 32x32 con extensión .ico,
	 * que la mayoría de navegadores igual acepta cuando se sirve con el content-type correcto. Si el
	 * hosting ya tiene un favicon.ico genérico versionado, esta línea lo sobreescribe con el del cliente. */
	await sharp(logoPath)
		.resize(32, 32, { fit: 'contain', background: { r: 255, g: 255, b: 255, alpha: 1 } })
		.png()
		.toFile(path.join(outputDir, '..', '..', 'favicon.ico'))
	console.log('ICON_OK favicon.ico')

	console.log('GENERATE_ICONS_DONE')
}

run().catch(function (error) {
	console.error('GENERATE_ICONS_ERROR: ' + (error && error.message ? error.message : String(error)))
	process.exit(1)
})
