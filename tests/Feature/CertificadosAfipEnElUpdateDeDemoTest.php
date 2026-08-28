<?php

namespace Tests\Feature;

use App\Models\Demo;
use App\Services\DemoPathResolver;
use App\Services\DemoUpdateService;
use Tests\TestCase;

/**
 * Misión certificados-afip-en-update-de-demos — el update de una demo repone los certificados de
 * AFIP que le falten.
 *
 * Desde el commit ec6e164a de empresa-api (26/7/2026) los certificados no viajan en el código:
 * viven en storage/app/afip/, gitignoreados. El ZIP del update excluye `storage/*`, así que
 * ninguna actualización de demo los repuso nunca. El síntoma no se parece a la causa: buscar un
 * cliente por CUIT o DNI en el módulo vender devuelve HTTP 500 en 0,2 s —antes de salir a la red
 * hacia ARCA— porque el constructor de AfipWSAAHelper tira apenas no encuentra el archivo. Medido
 * en demo, demo2 y demo3 el 28/8/2026. El 20/8/2026 la reposición se había automatizado solo para
 * clientes (InstallationService y DeploymentService); el pipeline de demos quedó afuera.
 *
 * Casi todos estos tests son estructurales a propósito: la etapa ejecuta contra un servidor por
 * SSH/SFTP que un test no puede levantar. Lo que se protege es la DECISIÓN —cuándo actúa, con qué,
 * en qué orden y qué pasa si falla—, que es donde estuvieron los errores. El único de
 * comportamiento es el último, y está para sostener con datos la decisión que el anterior fija con
 * un strpos.
 */
class CertificadosAfipEnElUpdateDeDemoTest extends TestCase
{
    /**
     * El código fuente del servicio.
     *
     * @return string
     */
    private function fuente(): string
    {
        return (string) file_get_contents(app_path('Services/DemoUpdateService.php'));
    }

    /**
     * El fuente sin comentarios ni docblocks: solo lo que PHP realmente ejecuta.
     *
     * 🔴 Hace falta y no es una comodidad. Los comentarios de esta misión NOMBRAN a propósito las
     * cosas que los candados prohíben —`sync_afip_certificates`, `connect_hosting_ssh`— porque
     * explican por qué NO van o por qué van en ese orden. Sin este filtro, los candados romperían
     * contra la explicación de por qué existen. Se usa token_get_all() y no un regex sobre las
     * líneas que arrancan con `*` (el patrón de ReinicioDelWorkerEnElUpdateDeDemoTest) porque es
     * exacto: no depende de cómo esté formateado el comentario.
     *
     * @return string
     */
    private function fuente_ejecutable(): string
    {
        $ejecutable = '';

        foreach (token_get_all($this->fuente()) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    // Se reemplaza por un salto de línea para no pegar dos tokens que estaban
                    // separados solo por el comentario.
                    $ejecutable .= "\n";
                    continue;
                }

                $ejecutable .= $token[1];
                continue;
            }

            $ejecutable .= $token;
        }

        return $ejecutable;
    }

    /**
     * Solo el código ejecutable de un método, para que los candados no den falsos positivos con el
     * resto del servicio.
     *
     * @param  string  $metodo       Nombre del método a recortar
     * @param  string  $metodo_hasta Nombre del método que lo sigue en el archivo
     * @return string
     */
    private function cuerpo_de(string $metodo, string $metodo_hasta): string
    {
        $fuente = $this->fuente_ejecutable();

        $desde = strpos($fuente, 'private function ' . $metodo);
        $this->assertNotFalse($desde, "No existe el metodo {$metodo}().");

        $bloque = substr($fuente, (int) $desde);
        $hasta  = strpos($bloque, 'private function ' . $metodo_hasta);

        return $hasta === false ? $bloque : substr($bloque, 0, (int) $hasta);
    }

    /**
     * B1 — La etapa de migraciones repone los certificados. Sin esto la misión no existe.
     *
     * @return void
     */
    public function test_la_etapa_de_migraciones_repone_los_certificados_afip()
    {
        $this->assertTrue(
            method_exists(DemoUpdateService::class, 'provision_afip_certificates'),
            'provision_afip_certificates() tiene que existir como metodo de la clase.'
        );

        $this->assertStringContainsString(
            "\$this->provision_afip_certificates('run_migrations')",
            $this->cuerpo_de('step_run_migrations', 'step_restart_queue_workers'),
            'La etapa de migraciones tiene que llamar a la reposicion de certificados.'
        );
    }

    /**
     * B2 — La reposición corre ANTES de cualquier comando por SSH de la etapa.
     *
     * 🔴 Lo que protege es un reconnect implícito. La reposición abre su propia sesión SFTP; el
     * connect_hosting_ssh() que ya estaba en la etapa hace de reconexión posterior, y por eso no
     * hizo falta agregar un reconnect_hosting_ssh() propio como sí tiene DeploymentService. Si
     * alguien mueve la llamada abajo del connect sin agregar ese reconnect, los `artisan` de esta
     * etapa pueden devolver salida vacía sin dar ningún error.
     *
     * @return void
     */
    public function test_la_reposicion_corre_antes_de_cualquier_comando_por_ssh()
    {
        $cuerpo = $this->cuerpo_de('step_run_migrations', 'step_restart_queue_workers');

        $reposicion = strpos($cuerpo, '$this->provision_afip_certificates(');
        $connect    = strpos($cuerpo, '$this->connect_hosting_ssh();');
        $exec       = strpos($cuerpo, '$this->exec_hosting_ssh(');

        $this->assertNotFalse($reposicion, 'La reposicion no se invoca en la etapa de migraciones.');
        $this->assertNotFalse($connect, 'La etapa tiene que seguir reconectando la sesion SSH del hosting.');
        $this->assertNotFalse($exec, 'La etapa tiene que seguir ejecutando comandos por SSH.');

        $this->assertLessThan(
            $connect,
            $reposicion,
            'La reposicion va ANTES del connect_hosting_ssh(), que le hace de reconexion posterior.'
        );

        $this->assertLessThan(
            $exec,
            $reposicion,
            'La reposicion va ANTES del primer comando por SSH de la etapa.'
        );
    }

    /**
     * B3 — La reposición no puede abortar el update.
     *
     * A esta altura el código ya está subido y quedarse a mitad deja la demo rota delante de un
     * lead. Además, si el admin no tuviera los certificados cargados, un corte acá haría fallar
     * TODAS las actualizaciones de demo por un archivo que ni siquiera es del camino crítico de la
     * demo. La no-propagación en sí vive en AfipCertificateProvisionService::reponer_en_api() y la
     * cubre AfipCertificateProvisionServiceTest; acá se fija que este lado delegue ahí y no arme
     * su propio camino de error.
     *
     * @return void
     */
    public function test_la_reposicion_no_puede_abortar_el_update()
    {
        $cuerpo = $this->cuerpo_de('provision_afip_certificates', 'append_log');

        $this->assertStringContainsString(
            'reponer_en_api',
            $cuerpo,
            'Tiene que delegar en el metodo compartido, que es donde vive la politica de error.'
        );

        $this->assertStringNotContainsString(
            'throw ',
            $cuerpo,
            'No puede lanzar: abortaria un update que ya subio el codigo.'
        );

        $this->assertStringNotContainsString(
            'exec_hosting_ssh',
            $cuerpo,
            'No corre comandos por SSH, asi que tampoco puede pasar un must_succeed = true.'
        );
    }

    /**
     * B4 — 🔴 El pipeline de demos NO sincroniza desde una carpeta anterior.
     *
     * Un CLIENTE alterna carpeta física en cada actualización (v1/v2, ver active_client_api_id) y
     * storage/ no se comparte entre las dos, así que DeploymentService tiene que arrastrar lo que
     * el cliente ya tenía antes de completar los huecos: para eso están sync_afip_certificates() y
     * build_afip_sync_command().
     *
     * Una DEMO no alterna nada. DemoPathResolver::api_path() se deriva del slug y del hosting —no
     * de la versión que se esté desplegando— y step_upload_api() descomprime con `unzip -o` ahí
     * adentro sin borrar nada, con el ZIP excluyendo `storage/*`. O sea que storage/ sobrevive al
     * update y la "carpeta anterior" ES la misma carpeta. Un sync sería un `cp` de un directorio
     * sobre sí mismo: código muerto que además le mentiría al que lo lea después, sugiriendo que
     * la demo alterna carpetas.
     *
     * Este test existe para frenar a quien copie el sync desde DeploymentService por analogía.
     * Mira el código ejecutable, no los comentarios: el docblock de provision_afip_certificates()
     * nombra el sync justamente para explicar por qué no está.
     *
     * @return void
     */
    public function test_el_pipeline_de_demos_no_sincroniza_desde_una_carpeta_anterior()
    {
        $ejecutable = $this->fuente_ejecutable();

        foreach (['sync_afip_certificates', 'build_afip_sync_command', 'AFIP_SYNC_OK'] as $prohibido) {
            $this->assertStringNotContainsString(
                $prohibido,
                $ejecutable,
                "Una demo usa siempre el mismo directorio: no hay carpeta anterior de la que "
                . "arrastrar nada, asi que {$prohibido} no tiene sentido en este pipeline."
            );
        }
    }

    /**
     * B5 — La ruta de la API de una demo no cambia entre actualizaciones.
     *
     * El único test de comportamiento del archivo, y el que sostiene al anterior con datos en vez
     * de con un strpos: nada en la firma ni en el resultado de api_path() depende de la versión
     * que se esté desplegando. Sus únicas entradas son columnas de la fila `demos`.
     *
     * @return void
     */
    public function test_la_ruta_de_la_api_de_una_demo_no_cambia_entre_actualizaciones()
    {
        $resolver = new DemoPathResolver();

        // Sin tocar la base: el resolver solo lee atributos del modelo.
        $shared = new Demo(['erp_spa_url' => 'demo3.comerciocity.com']);

        $this->assertSame(
            'domains/comerciocity.com/public_html/demo3/api',
            $resolver->api_path($shared)
        );

        $this->assertSame(
            $resolver->api_path($shared),
            $resolver->api_path($shared),
            'Dos llamadas seguidas tienen que dar la misma ruta: no hay estado de version de por medio.'
        );

        $vps = new Demo([
            'erp_spa_url'      => 'demo3.comerciocity.com',
            'erp_hosting_type' => 'vps',
        ]);

        $this->assertSame('/home/api-demo3/empresa-api', $resolver->api_path($vps));
        $this->assertSame($resolver->api_path($vps), $resolver->api_path($vps));
    }
}
