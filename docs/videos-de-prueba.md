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
