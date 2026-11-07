# Videos de la prueba gratuita

## Separación de espera y primer ingreso

Admin guarda estos valores en `admin_settings` mediante `App\Models\AdminSetting::set()`:

| Clave | Destino | Pieza aprobada del 7/11/2026 |
|---|---|---|
| `prueba_video_espera_url` | `GET /api/landing/prueba/{uuid}/estado`, campo `video_url` | 02 Historia, v4 |
| `prueba_video_bienvenida_url` | C1 `POST /api/admin-sync/demo-setup`, campo `prueba.video_url` | 03 Bienvenida corta, v3 |
| `prueba_video_url` | Respaldo compatible de cada destino que no tiene valor propio | Valor anterior, no reemplazar |

Un ajuste nuevo ausente, vacío o con espacios vuelve al valor anterior. Configurar uno no cambia el otro. No se renombra ningún campo de C1 ni C6 y no se requiere migración de esquema. La espera conserva su video cuando pasa de `creando` a `lista`, para no interrumpir la historia ni demorar el ingreso.

## Activación posterior, no ejecutada por este cambio

1. Confirmar URLs públicas de las piezas exactas, tipo MP4, contenido y duración. La existencia de un manifiesto con URLs propuestas no prueba publicación.
2. Publicar primero este código de Admin mediante el proceso autorizado.
3. Respaldar las filas anteriores de estas tres claves. Escribir únicamente los dos ajustes nuevos mediante el modelo desde un proceso administrativo autorizado, por ejemplo Tinker. `AdminSetting::set()` hace upsert de una sola clave y limpia su memo. No copiar secretos ni modificar ajustes vecinos.
4. Verificar el valor resuelto de cada método y C6 en una prueba de ensayo. Las pruebas nuevas reciben la bienvenida en su armado normal. No crear altas reales para esta comprobación.
5. Para revertir, restaurar las dos filas nuevas o retirarlas de manera controlada. El ajuste anterior quedó intacto.

No hay editor de estos videos en la pantalla `/pruebas` ni un endpoint genérico de `admin_settings`: `PruebaInstanciaController` administra instancias y días. El editor `demo-media` pertenece a la demo por turno, no a estos ajustes. No hace falta inventar un endpoint para esta activación puntual.

## Pruebas ya asignadas: no repetir el armado

C6 lee la URL de espera en cada consulta: el cambio de ese ajuste alcanza a los seguimientos existentes.

C1 copia la bienvenida a `prueba_configs.video_url` en Empresa durante el alta. Cambiar el ajuste de Admin no actualiza esa copia. Las pruebas ya asignadas requieren una actualización puntual si se quiere que usen la nueva bienvenida.

**No volver a disparar `demo-setup`, reintentar el alta ni llamar `PruebaHelper::activar_desde_payload()` para actualizar un video.** El setup puede reconstruir la base; el helper también reinicia `modal_visto_at`, `importacion_origen` y `semilla_borrada_at`, además de modificar vencimiento. Ninguno es una migración segura de URL.

Procedimiento de actualización puntual, a ejecutar sólo con autorización de la operación y respaldo verificado:

1. Enumerar las instancias de prueba asignadas de Admin y sus clientes, sin incluir clientes contratados ni demos por turno. Resolver el dueño real en cada instancia y verificar que exista exactamente una fila `prueba_configs` para él.
2. Leer y guardar la fila completa previa y el vencimiento del dueño en un respaldo privado. No registrar claves ni tokens. Clasificar la URL actual: vacía, respaldo anterior reconocido o personalizada. Una personalizada no se reemplaza automáticamente.
3. En transacción, actualizar únicamente `prueba_configs.video_url` con un filtro por `id`, `owner_id` y **valor anterior exacto**. Exigir una fila afectada; si el valor cambió desde la lectura, abortar esa instancia. No usar `updateOrCreate`, no insertar una configuración inexistente, no tocar los demás campos ni extender el período.
4. Releer la fila y comparar todas las columnas salvo `video_url` contra el respaldo. Confirmar que siguen iguales `modal_visto_at`, origen de importación, semilla, días y vencimiento. Un modal ya visto no debe reaparecer.
5. Reversión equivalente: restaurar sólo `video_url` si todavía coincide con la nueva URL. Nunca pisar un cambio posterior.

La API actual de Empresa ofrece estado y vencimiento, pero no un endpoint para editar sólo este video. El procedimiento anterior no fue ejecutado ni se agregaron permisos de escritura remota.

## Catálogo y seguimiento

04 (recorrido) y 05–09 (prácticas) son recursos diferentes de 02/03. No compartir el ajuste de bienvenida con el recorrido completo. El material de octubre no se sobrescribe por semejanza temática: 0.1, 1.8, 2.1/2.4, 7.2 y 2.10 no implican equivalencia automática.

La demo por turno tiene `GET/PUT /api/claude/demo-media` (y editor autenticado de Admin), con `{slot_id, url}`. Valida cada ID contra `contexto/demo_catalogo.json` sincronizado. El objeto multimedia y el registro de URL son cosas distintas: actualizar una URL no autoriza borrar el objeto anterior.

10–12 quedan fuera de este split. No se modificaron seguimientos, plantillas, cuotas de contacto ni envíos.

## Catálogo propio 04–09

`PruebaVideoCatalogoService` habilita sólo las seis piezas aprobadas. Los ajustes son optativos:

| Pieza | Clave en `admin_settings` | Propósito |
|---|---|---|
| 04 v8 | `prueba_video_guia_04_url` | Bienvenida y recorrido completo |
| 05 v4 | `prueba_video_guia_05_url` | Importar catálogo |
| 06 v4 | `prueba_video_guia_06_url` | Revisar y cambiar un precio |
| 07 v4 | `prueba_video_guia_07_url` | Compra por foto desde Compras |
| 08 v4 | `prueba_video_guia_08_url` | Primera venta |
| 09 v4 | `prueba_video_guia_09_url` | Probar agente con catálogo |

No tienen fallback al catálogo de demo ni a la bienvenida. Se aceptan URLs HTTPS válidas, sin usuario ni contraseña y con ruta terminada en `.mp4`, `.webm` o `.m3u8` (pueden tener query). Sólo se cargan después de verificar el archivo publicado. No se hace una petición al recurso al construir el catálogo.

El snapshot ya existente de `GET /api/prueba-chat` añade estos objetos a `catalogo` y resuelve `mensajes[].videos` con el mismo catálogo vigente. Conserva `version: 1` y los campos existentes. Las claves son `prueba:04` a `prueba:09`, el origen es `prueba`; no se aceptan claves de `demo:` ni `recurso:`. Vaciar un ajuste retira el video del selector y de los adjuntos sin borrar mensajes. Los campos de reproducción (`porcentaje`, `completado`, `omitido`) permanecen en cero/false: mirar no completa una acción del negocio.

La IA recibe sólo las seis fichas configuradas y puede adjuntar hasta dos por su clave exacta. Conserva únicamente la herramienta de lectura del manual: no incorpora herramientas de acción, guías interactivas, selección automática, progreso ni cierre de demo. Se mantiene la autenticación doble y la separación de hilo de la prueba. El proxy de Empresa retorna el JSON completo; no requiere rutas nuevas. La interfaz de Empresa debe tener el consumidor de reproducción local para mostrarlo: Admin nuevo con SPA anterior no rompe el chat, pero no muestra los videos.

## Mapa de la demo por turno: preparar, no sustituir a ciegas

El catálogo histórico del 19/10/2026 confirma estas referencias. Son referencias temáticas, no sustituciones aprobadas por el código:

- 04 se relaciona con `0.1`, pero éste presenta al asistente de la demo por turno. No reemplazar hasta validar el cierre y los botones que muestra el video nuevo.
- 05 se relaciona con `1.8` (Importar tu Excel con IA), cuyo evento esperado es `importacion.completada`. Mantener esa semántica si se decide sustituir su URL después de revisar el contenido.
- 06 no equivale unívocamente a `1.2`/`1.2-mt`: esos explican costo fiscal RI/MT. Debe tener recurso propio, no reemplazar ambos.
- 07 corresponde al recorrido de `4.6` (Escanear la factura del proveedor), no al WhatsApp de `7.2`. No cambiar 7.2.
- 08 se relaciona con `2.1` (venta completa, evento `venta.creada`), no reemplaza el anexo `2.4` de cuenta corriente.
- 09 muestra simulación con catálogo; `2.10` también configura el objetivo. Conservar su material complementario hasta verificar equivalencia.

Operación existente para una sustitución que quede validada: leer `GET /api/claude/demo-media`, guardar la URL previa por `slot_id` y enviar un único `PUT /api/claude/demo-media` con `{ "slot_id": "ID verificado", "url": "URL publicada y verificada" }`. Releer el GET y comparar. Nunca enviar un ID deducido de un número de archivo ni borrar el objeto multimedia anterior. Este cambio no ejecutó estas operaciones.

## 10–12 y límites del seguimiento existente

No hay un ajuste de URL de seguimiento consumido actualmente por `PruebaWhatsappService`. `PruebaPlantillasService` declara los textos `cc_prueba_paso_<hito>` en código y los registra en `followup_templates` con `estado=manual_prueba`. El envío sólo pasa el nombre y, en las bienvenidas del asistente, su número: no hay una variable para URL de video.

El job diario `prueba:empujar` se programa a las 10:00, recorre el primer hito faltante en el orden de `PruebaDelClienteService::HITOS`, evita pruebas vencidas, como máximo envía un mensaje al día y deja a Martín las conversaciones con actividad en las últimas 24 horas. Las plantillas deben estar activas y APPROVED. El aviso del día 5 es al closer, no un video al negocio. El correo existente es únicamente bienvenida.

Por eso, 10 (IA a tarea), 11 (diez artículos) y 12 (visión del fundador) quedan en el manifiesto de publicación, no en una secuencia activa. Integrarlos requiere diseñar contenido y selección por hito, aprobar las plantillas correspondientes con su categoría correcta, coordinar correo/WhatsApp y probar límites y deduplicación. No basta cambiar una URL ni conviene ponerlas bajo `prueba_activa`: eso las expondría al seguimiento genérico de leads. No se crearon plantillas, cron ni envíos.

## Despliegue limitado y pruebas

Publicar primero Admin. Configurar sólo URLs verificadas. Incorporar luego el consumidor de Empresa SPA únicamente en la demo/plantilla de prueba autorizada; no actualizar instalaciones productivas de clientes como efecto de esta tarea. Las pruebas nuevas reciben la versión del pool/plantilla verificada. Para pruebas existentes, cualquier actualización de aplicación y backfill de bienvenida se decide por instancia, con respaldo y sin setup.

Para el backfill de bienvenida usar Query Builder con un `update` que incluya sólo `video_url`, no `save()` Eloquent que pueda cambiar `updated_at`: la comparación de las demás columnas tiene que dar idéntica.

Verificación local: PHP 7.4, pruebas focales de split, alta, armado, medición, catálogo y ambos chats. La base local S16 necesitó aplicar las migraciones ya existentes `2026_11_05_210000_add_guia_to_demo_chat_mensajes` y `2026_11_06_193000_separate_trial_from_demo_chat`; no se crearon migraciones nuevas, no se usó `migrate:fresh` y no se tocó producción. La reproducción y el responsive corresponden a la verificación independiente de Empresa SPA.
