<?php

/*
|--------------------------------------------------------------------------
| Aviso "estamos actualizando el sistema" (misión aviso-actualizacion-sistema, 25/11/2026)
|--------------------------------------------------------------------------
| Cuando el admin actualiza un cliente EN EL LUGAR, el sistema del cliente puede mostrarle a sus
| usuarios un aviso, cerrarles la sesión y no dejarlos ingresar hasta que termine el despliegue
| (estado `actualizando` de empresa-api). Con `force`, antes de eso se les pide el OK a cada
| computadora conectada (estado `pidiendo_ok`). Todo se maneja por HTTP contra el admin-sync de
| empresa-api (`api/admin-sync/actualizacion-del-sistema`), desde dos lugares:
|
|   - el pipeline (`DeploymentService`): prende, renueva y libera el aviso;
|   - las rutas `claude/upgrades/{id}/pedido-de-ok` y `…/aviso-de-actualizacion`, que usa la skill.
|
| 🔴 ESTE ARCHIVO SOLO TIENE UN INTERRUPTOR Y VIENE APAGADO. Apagado, el admin se comporta exactamente
| como antes de esta misión: ni una request nueva a ningún cliente, ni un 409 nuevo en `deploy/start`,
| y las cuatro rutas `claude/` contestan 409 `aviso_apagado`. Se prende (env + `php artisan
| config:clear`) recién después de: release de empresa con el aviso, tienda desplegada, skill
| mergeada y una prueba en un cliente canario. Para volver atrás alcanza con volver la env a `false`.
|
| Vive en un archivo propio y no en `config/services.php` a propósito: otra misión en curso
| (martin-descripcion-y-actualizaciones) toca ese archivo, y compartirlo sería un conflicto de merge
| gratis.
*/

return [

    /**
     * Interruptor general del aviso. Default FALSO: hay que prenderlo a mano en el `.env` del admin
     * con `AVISO_DE_ACTUALIZACION_HABILITADO=true`.
     */
    'habilitado' => (bool) env('AVISO_DE_ACTUALIZACION_HABILITADO', false),

    /**
     * Segundos máximos que el admin espera la respuesta de empresa-api en cada llamada del aviso.
     * Corto a propósito: el aviso nunca puede demorar el pipeline ni dejar colgado un endpoint de
     * la skill.
     */
    'timeout' => 10,

    /**
     * Minutos por defecto que dura el estado `actualizando` si nadie lo renueva. Los ganchos del
     * pipeline lo renuevan en cada tramo; si el admin se cae a mitad del despliegue, el aviso se
     * suelta solo a los 120 minutos y los usuarios vuelven a entrar.
     */
    'vence_en_minutos' => 120,

    /**
     * Minutos por defecto que tienen las computadoras para responder el pedido de OK. Pasado ese
     * plazo, empresa-api le cierra la sesión a las que no contestaron y sigue.
     */
    'minutos_para_responder' => 10,

];
