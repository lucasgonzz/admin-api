<?php

/*
|--------------------------------------------------------------------------
| Precios de las BÚSQUEDAS DE IMÁGENES, en DÓLARES CADA 1.000 BÚSQUEDAS
|--------------------------------------------------------------------------
|
| Cargados el 27/9/2026 (misión imagenes-catalogo-completo, §12.2 del plan). Los lee
| `ClientImageSearchLogService` para ponerle plata al registro de consultas de imágenes que
| informa el `empresa-api` de cada cliente (solapa "Imágenes" de la ficha del cliente).
|
| Es la hermana de `config/ia_precios.php` y sigue su misma filosofía, punto por punto:
|
|   - La tabla vive SOLO en el admin. El `empresa-api` de cada cliente manda cuántas búsquedas hizo
|     y con qué proveedor; la plata la pone el admin al leer. Si un proveedor cambia la lista, se
|     corrige acá y listo: no hay que tocar a los cuarenta y cinco clientes.
|   - El costo no se guarda en ningún lado: se calcula al leer.
|   - 🔴 Un proveedor que no esté en esta lista NO rompe nada: su costo sale `null` y la respuesta
|     lo nombra como "sin precio cargado". No hay precio por defecto ni estimación a ojo: un total
|     que miente es peor que un renglón sin plata, porque el renglón sin plata se ve y el total que
|     miente se cree.
|
| 🔴 POR QUÉ ES UN ARCHIVO PROPIO Y NO UN BLOQUE MÁS DE `ia_precios.php`. Aquella tabla es
| "modelo → cuatro puntas por MILLÓN de tokens", y la recorren entera tres lugares
| (`ClientAiTokenUsage::tarifa_de()` —que además busca por PREFIJO—, `ClientAiTokenUsage::plegar()`
| y `ClientAiTokenUsagePersonModel`). Un bloque con otra forma y otra unidad metido en esa lista se
| costearía como si fuera un modelo más —con cero en las cuatro puntas, o sea un total que miente—
| el día que algún cliente informe un modelo que se llame igual o que empiece igual. Dos unidades
| distintas, dos tablas distintas.
|
| Una búsqueda se paga SOLO si el proveedor respondió bien, aunque haya venido vacía: es la columna
| `cobrada` del registro del cliente (misma regla que `consumir_cuota()` del lado de empresa). Un
| error del proveedor no se paga y no se costea.
|
| La clave es el `proveedor` tal como lo escribe el registro del cliente (`image_service_calls`).
|
*/

return [

    /*
     * Serper (Google Imágenes por API): el proveedor de las asignaciones de imágenes desde la
     * misión imagenes-catalogo-completo.
     *
     * ⚠️ Pendiente de confirmar contra la factura: según el paquete que compre Lucas va de 0,30 a
     * 1,00 dólares cada 1.000 búsquedas (leído de serper.dev el 27/9/2026, más 2.500 de regalo al
     * abrir la cuenta). Se carga el MÁS CARO a propósito: mientras no esté confirmado, el número
     * de la pantalla es un techo y nunca un piso.
     */
    'serper' => 1.00,

    /*
     * Google Custom Search (JSON API), el respaldo cuando el cliente no tiene clave de Serper.
     *
     * Es el precio del tier pago y funciona como TECHO a propósito: las primeras 100 búsquedas por
     * día del proyecto de Google son gratis, y como la clave es prácticamente una sola para toda la
     * flota, desde un cliente no hay forma de saber cuáles de las suyas cayeron adentro de esas 100.
     * Se prefiere un techo honesto a un promedio inventado (mismo criterio que DeepSeek en
     * `ia_precios.php`).
     */
    'google' => 5.00,

];
