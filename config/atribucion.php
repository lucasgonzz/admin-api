<?php

return [
    'dias_vigencia' => 7,
    'limite_por_minuto' => 30,
    'cors_origenes' => array_values(array_filter(array_merge([
        'https://comerciocity.com',
        'https://www.comerciocity.com',
    ], array_map('trim', explode(',', (string) env('ATRIBUCION_ORIGENES_LOCALES', '')))))),
];
