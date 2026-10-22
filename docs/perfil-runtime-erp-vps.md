# Perfil optativo del ERP en VPS

El admin reconoce `vps-php84-v1` únicamente cuando cada frente tiene el marcador externo
`/home/api-<vps_path>/.comerciocity-runtime.json` con exactamente
`{"schema":1,"profile":"vps-php84-v1"}`. No lo crea ni lo activa durante una instalación o actualización.
La ausencia conserva el pipeline anterior. Un marcador ilegible, mal formado o desconocido corta
el proceso. El hosting compartido no consulta el marcador.

El lector usa Python 3 del VPS con `O_NOFOLLOW`, `O_NONBLOCK` y `fstat` del descriptor abierto:
sólo admite archivos regulares de hasta 1 KB. No sigue symlinks ni espera datos de un FIFO.
Sólo devuelve el JSON válido normalizado o estados fijos de ausencia/error; nunca devuelve el
contenido de un archivo inválido ni lo vuelca a los logs del pipeline.

El perfil fija `/usr/bin/php8.4`, `/usr/local/bin/composer` y `COMPOSER=composer.vps.json`;
el marcador no admite rutas ni binarios. Exige PHP `>=8.4.27 <8.5` y Composer 2. No modifica FPM,
alternativas globales, crons ni configuración de supervisores.

Antes de tocar SPA/API y al reanudar se comprueba el marcador, la igualdad entre los dos frentes
de una rotación y el release elegido. El ZIP debe traer `composer.json`, `composer.lock`,
`composer.vps.json` y `composer.vps.lock`. Las huellas SHA256 canónicas identifican los JSON normales
completos; el `content-hash` se calcula con el algoritmo de Composer (`JsonFile::encode(..., 0)`,
que es distinto de la codificación de las huellas SHA256).

El manifiesto sólo puede cambiar PHP, OpenSpout, la plataforma y las cuatro excepciones Linux de
classmap acordadas con empresa. El lock conserva los paquetes normales salvo OpenSpout 4.32.0,
Nette Schema 1.4.0 y Nette Utils 4.1.6. Las dependencias de desarrollo permanecen idénticas. Un tag
anterior sin estos archivos se rechaza antes de subir el SPA; nunca se cae al Composer normal.

El preflight ejecuta `check-platform-reqs --lock --no-dev` mediante el PHP explícito, sin plugins,
scripts ni instalación. Usa un directorio temporal fuera del sistema vivo, con el subconjunto
del lock que contiene los requisitos de plataforma, y lo elimina al terminar. En el camino
excepcional de build autorizado también se verifica el zipball del tag y el checkout del build.
El ZIP que efectivamente se sube vuelve a validarse.

Composer en build y en instalación inicial corre con `--no-scripts`: todavía no hay `.env`.
La instalación conserva `package:discover` después de escribir el entorno. En una actualización
se mantienen los scripts normales y `@php` recibe `PHP_BINARY` 8.4. Los comandos publicados
conservan sus asignaciones, comillas y encadenados; las funciones de shell `php` y `composer`
seleccionan los binarios del perfil. En el deployment esos ajustes también van dentro del
`bash -c` que corre como usuario del sitio, para que `sudo` no pierda el entorno.

Los comandos publicados de una versión tienen una gramática acotada en este perfil: `php artisan`
con sus argumentos entrecomillados, un `USER_ID` entero opcional y encadenados `&&`. El preflight
revisa todas las filas pendientes de `UpdateCommand`, incluidas las versiones intermedias, antes
de subir archivos. Omite las filas exitosas, salteadas o manuales como el pipeline existente.
También verifica el comando efectivo de cada `UpdateSeeder` (incluidos los comandos personalizados),
con las mismas reglas de omisión del pipeline: salteados y, en el lugar, ya exitosos.
Las expansiones de shell, redirecciones, shells anidados, intérpretes absolutos y cualquier
Composer publicado se rechazan; Composer queda reservado a los pasos controlados del pipeline.
Esto evita que un comando sobrescriba `COMPOSER` o resuelva dependencias por fuera del lock.

La activación de un marcador en un cliente corresponde a una operación posterior autorizada.
Esta preparación permite publicar el admin con cero clientes opt-in.

Pruebas: `RuntimeProfileServiceTest`, `PerfilRuntimeEnLosPipelinesTest` y
`PerfilRuntimeEnElDeploymentTest`, más las regresiones de instalación, demos, respaldo,
mantenimiento y reanudación. El fixture `tests/Fixtures/runtime-vps-real.zip` contiene los cuatro
manifiestos reales de empresa preparados el 22/10/2026 y resueltos con Composer oficial; no tiene
entornos ni credenciales. Se conserva para contrastar el algoritmo contra un lock independiente.

El contrato de rotación ahora necesita una única lectura del marcador del frente activo. La prueba
anterior que prohibía cualquier mención de ese frente pasó, con autorización de la misión, a
permitir exclusivamente ese comando exacto de lectura; todos los demás comandos sobre el activo
siguen prohibidos y una diferencia de perfiles debe frenar antes de subir archivos.
