<?php

/*
 | «El local» (misión el-local-linux, 25/11/2026): ajustes de la oficina del admin.
 |
 | `umbrales_demora_min` es el ÚNICO lugar donde se define cuánto puede llevar una misión en una etapa antes de
 | mostrarse como «demorada» (la escena lo calcula en EscenaService; ni el reporter ni la SPA deciden esto).
 | Se mide en minutos y cada etapa se puede pisar con su variable de entorno.
 */
return [

    /*
     | Minutos máximos en la misma etapa antes de marcar la misión como demorada. Las claves son las seis etapas
     | del contrato A v2 (plan, construccion, revision, tests, merge, cierre).
     */
    'umbrales_demora_min' => [
        'plan'         => (int) env('EL_LOCAL_DEMORA_PLAN_MIN', 120),
        'construccion' => (int) env('EL_LOCAL_DEMORA_CONSTRUCCION_MIN', 240),
        'revision'     => (int) env('EL_LOCAL_DEMORA_REVISION_MIN', 120),
        'tests'        => (int) env('EL_LOCAL_DEMORA_TESTS_MIN', 120),
        'merge'        => (int) env('EL_LOCAL_DEMORA_MERGE_MIN', 60),
        'cierre'       => (int) env('EL_LOCAL_DEMORA_CIERRE_MIN', 120),
    ],

];
