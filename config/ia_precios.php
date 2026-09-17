<?php

/*
|--------------------------------------------------------------------------
| Precios de la IA, en DÓLARES POR MILLÓN DE TOKENS
|--------------------------------------------------------------------------
|
| Cargados el 17/9/2026 contra la referencia oficial de la API de cada proveedor.
|
| 🔴 Esta tabla vive SOLO en el admin, y esa es la decisión que la hace útil: cuando un proveedor
| cambia la lista, se corrige acá y listo — no hay que tocar a los cuarenta y cinco clientes ni
| esperar a que cada uno se actualice. El `empresa-api` de cada cliente manda tokens; la plata la
| pone el admin al leer.
|
| 🔴 Y por eso tampoco se guarda el costo en `client_ai_token_usages`: guardarlo congelaría el
| precio del día en que se recolectó y obligaría a un backfill con cada cambio de lista.
|
| **Un modelo que no esté en esta lista NO rompe nada**: su costo sale `null` y la respuesta lo
| marca como "sin precio cargado". No hay precio por defecto ni estimación a ojo, a propósito: un
| total que miente es peor que un renglón sin plata, porque el renglón sin plata se ve y el total
| que miente se cree.
|
| Las cuatro puntas son las que factura Anthropic por separado:
|
|   input        → tokens del prompt que NO vinieron de la caché.
|   output       → tokens que generó el modelo.
|   cache_write  → escribir un bloque en la caché de prompt. Regla de Anthropic: **1,25 × input**.
|   cache_read   → leer un bloque ya cacheado. Regla de Anthropic: **0,1 × input**.
|
| ⚠️ El precio de OpenAI (`text-embedding-3-small`) queda **pendiente de confirmación de Lucas**:
| es el único de esta lista que no se verificó contra la factura real. Los embeddings no tienen
| salida ni caché, así que esas tres puntas van en 0 y no son un faltante.
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

];
