<?php

/*
|--------------------------------------------------------------------------
| Precios de la IA, en DÓLARES POR MILLÓN DE TOKENS
|--------------------------------------------------------------------------
|
| Cargados el 17/9/2026 contra la referencia oficial de la API de cada proveedor. Ampliada el
| 22/9/2026 (misión proveedores-ia-deepseek) con Opus 5 y los dos modelos de DeepSeek.
|
| 🔴 Esta tabla vive SOLO en el admin, y esa es la decisión que la hace útil: cuando un proveedor
| cambia la lista, se corrige acá y listo — no hay que tocar a los cuarenta y cinco clientes ni
| esperar a que cada uno se actualice. El `empresa-api` de cada cliente manda tokens; la plata la
| pone el admin al leer.
|
| 🔴 Y por eso tampoco se guarda el costo en `client_ai_token_usages`: guardarlo congelaría el
| precio del día en que se recolectó y obligaría a un backfill con cada cambio de lista.
|
| 🔴 Desde el 2/11/2026 (misión plan-ia-por-gasto) esta tabla TAMBIÉN VIAJA a cada cliente, dentro del
| PUT del plan (`ClientAiPlanSyncService::cuerpo_del_plan`, clave `precios_ia`): el `empresa-api`
| necesita costear para cortar por el tope de gasto sin consultarle al admin en cada mensaje. La
| fuente sigue siendo una sola, esta. Cuando cambia un precio se corrige acá, y llega solo con el
| barrido de cada noche (`ai-planes:sincronizar`, 03:45), o en el momento con
| `php artisan ia:sincronizar-precios`. Sigue sin hacer falta actualizar a ningún cliente.
|
| Las BÚSQUEDAS de imágenes (Serper / Google) no van acá: se cobran por búsqueda y no por token, y
| tienen su propia tabla, en dólares cada 1.000, en `config/busquedas_precios.php` (el porqué de
| que sean dos archivos está en su encabezado). Esta tabla es solo de modelos.
|
| **Un modelo que no esté en esta lista NO rompe nada**: su costo sale `null` y la respuesta lo
| marca como "sin precio cargado". No hay precio por defecto ni estimación a ojo, a propósito: un
| total que miente es peor que un renglón sin plata, porque el renglón sin plata se ve y el total
| que miente se cree. (Eso vale para la pantalla del admin. Para CORTAR no sirve: del lado de empresa
| un modelo que no esté acá se cobra a la tarifa más cara de su mismo proveedor, porque un modelo desconocido
| nunca puede ser gratis. Hoy está en ese caso `gpt-4o-mini-transcribe`, el de la transcripción de
| audios: sin precio a propósito, hasta que alguien lo lea de la lista oficial del proveedor.)
|
| Las cuatro puntas son las que factura Anthropic por separado:
|
|   input        → tokens del prompt que NO vinieron de la caché.
|   output       → tokens que generó el modelo.
|   cache_write  → escribir un bloque en la caché de prompt. Regla de Anthropic: **1,25 × input**.
|   cache_read   → leer un bloque ya cacheado. Regla de Anthropic: **0,1 × input**.
|
| ⚠️ El precio de OpenAI (`text-embedding-3-small`) queda **pendiente de confirmación de Lucas**:
| no se verificó contra la factura real. Los embeddings no tienen salida ni caché, así que esas
| tres puntas van en 0 y no son un faltante.
|
| ⚠️ Lo mismo para `claude-opus-5` y los dos de DeepSeek (`deepseek-flash`, `deepseek-v4-pro`),
| cargados el 22/9/2026: verificados contra la referencia pública de cada proveedor, pero no
| contra una factura. Los tres quedan pendientes de confirmación de Lucas.
|
| 🔴 DeepSeek cobra LA MITAD fuera de su horario pico (pico = 01–04 y 06–10 UTC, de lunes a
| viernes; leído de api-docs.deepseek.com/quick_start/pricing el 22/9/2026). El espejo del admin
| es POR DÍA y no sabe a qué hora fue cada llamada, así que se carga el precio PLENO como cota
| superior: el gasto real de DeepSeek puede ser hasta la mitad de lo que muestra la pantalla, nunca
| más. Se prefiere un techo honesto a un promedio inventado. Y DeepSeek no cobra la escritura en
| caché: su `cache_write` en 0 es el precio real, no un dato faltante.
|
*/

return [

    /*
     * Sonnet 5 — el modelo con el que corren hoy el asistente y las sugerencias de WhatsApp.
     */
    'claude-sonnet-5' => [
        'input'       => 2.00,
        'output'      => 10.00,
        'cache_write' => 2.50,
        'cache_read'  => 0.20,
    ],

    /*
     * Sonnet 4.5 y las dos formas en que aparece escrito el 4.x anterior. Se cargan las tres
     * claves aunque compartan precio: el modelo viaja tal cual lo informó el cliente y acá se busca
     * por coincidencia exacta, así que una clave que falte es un renglón sin costo.
     */
    'claude-sonnet-4-5' => [
        'input'       => 3.00,
        'output'      => 15.00,
        'cache_write' => 3.75,
        'cache_read'  => 0.30,
    ],

    'claude-sonnet-4-20250514' => [
        'input'       => 3.00,
        'output'      => 15.00,
        'cache_write' => 3.75,
        'cache_read'  => 0.30,
    ],

    'claude-sonnet-4-6' => [
        'input'       => 3.00,
        'output'      => 15.00,
        'cache_write' => 3.75,
        'cache_read'  => 0.30,
    ],

    /*
     * Haiku 4.5: el barato, el que corre en los caminos de alto volumen (títulos de conversación,
     * resúmenes). Que aparezca arriba en llamadas y abajo en plata es la señal de que está bien
     * repartido el trabajo.
     */
    'claude-haiku-4-5-20251001' => [
        'input'       => 1.00,
        'output'      => 5.00,
        'cache_write' => 1.25,
        'cache_read'  => 0.10,
    ],

    'claude-haiku-4-5' => [
        'input'       => 1.00,
        'output'      => 5.00,
        'cache_write' => 1.25,
        'cache_read'  => 0.10,
    ],

    /*
     * Opus 5: el modelo de "Profundo" del asistente desde el 17/9/2026, y hasta el 22/9/2026 NO
     * estaba en esta lista. O sea que todo lo que gastó Profundo se mostraba sin precio (null): el
     * renglón se veía, pero la cifra principal lo dejaba afuera. Hallazgo de la misión
     * proveedores-ia-deepseek, corregido de paso. Caché con la regla de Anthropic: 1,25× y 0,1×.
     * ⚠️ Pendiente de confirmación de Lucas contra la factura.
     */
    'claude-opus-5' => [
        'input'       => 5.00,
        'output'      => 25.00,
        'cache_write' => 6.25,
        'cache_read'  => 0.50,
    ],

    /*
     * Los tres Claude de la lectura de planillas del asistente (misión agente-venta-por-planilla, 2/11/2026):
     * Opus 5.5 y Haiku 5.5 leen, Sonnet 5.5 desempata. Sin estas claves el costo de cada lectura salía sin
     * precio, o con el de la generación anterior. Escritura en caché: 1,25×; lectura: la de la lista. Haiku 5.5
     * sube a 0,50 / 2,50 con prompts de más de 100 mil tokens; una lectura de planilla no llega a 10 mil.
     * ⚠️ Referencia de la lista de Anthropic: pendiente de confirmación de Lucas contra la factura.
     */
    'claude-opus-5-5' => [
        'input'       => 4.00,
        'output'      => 20.00,
        'cache_write' => 5.00,
        'cache_read'  => 0.20,
    ],

    'claude-sonnet-5-5' => [
        'input'       => 2.00,
        'output'      => 10.00,
        'cache_write' => 2.50,
        'cache_read'  => 0.20,
    ],

    'claude-haiku-5-5' => [
        'input'       => 0.10,
        'output'      => 0.50,
        'cache_write' => 0.125,
        'cache_read'  => 0.01,
    ],

    /*
     * Embeddings de OpenAI (indexar el catálogo y el RAG de cada respuesta de WhatsApp). Sin
     * salida y sin caché: las tres puntas en 0 son el precio real, no un dato faltante.
     * ⚠️ Pendiente de confirmación de Lucas.
     */
    'text-embedding-3-small' => [
        'input'       => 0.02,
        'output'      => 0.00,
        'cache_write' => 0.00,
        'cache_read'  => 0.00,
    ],

    /*
     * DeepSeek-V4.1-Flash: el "Ágil" cuando el dueño elige DeepSeek (misión
     * proveedores-ia-deepseek, 22/9/2026). Precio PLENO de hora pico, ver el encabezado: el real
     * puede ser hasta la mitad. `cache_write` en 0 es el precio real (DeepSeek no cobra escribir la
     * caché) y `cache_read` es su "cache hit". Leído de api-docs.deepseek.com/quick_start/pricing.
     * ⚠️ Pendiente de confirmación de Lucas contra la factura.
     */
    'deepseek-flash' => [
        'input'       => 0.30,
        'output'      => 1.20,
        'cache_write' => 0.00,
        'cache_read'  => 0.006,
    ],

    /*
     * DeepSeek-V4-Pro: el "Profundo" cuando el dueño elige DeepSeek. Mismas aclaraciones que
     * `deepseek-flash`: precio pleno de hora pico, caché de escritura gratis, pendiente de factura.
     */
    'deepseek-v4-pro' => [
        'input'       => 1.32,
        'output'      => 3.96,
        'cache_write' => 0.00,
        'cache_read'  => 0.044,
    ],

];
