<?php

namespace Tests\Feature;

use App\Services\DemoUpdateService;
use Tests\TestCase;

/**
 * Misión restart-worker-en-demo-update — el update de una demo reinicia su worker de cola.
 *
 * En el VPS el worker vive bajo supervisor y es un proceso de LARGA VIDA: carga las clases en
 * memoria al arrancar y no las recarga nunca. Sin esta etapa, después de cada update sigue
 * procesando jobs con el código de la versión anterior.
 *
 * INCIDENTE (26/8/2026): la demo 1 se actualizó a v4.0.4 y el worker seguía siendo el de una hora
 * antes, con el código de 4.0.3 en memoria. El mismo modo de falla en DeploymentService hacía que
 * un job se cayera con "Undefined class constant 'CONDICION_MT'" — una constante que existe— y que
 * al negocio no le llegara la notificación de que se proceso la cotización del dólar.
 *
 * Estos tests son estructurales a propósito: la etapa ejecuta por una sesión SSH contra el
 * servidor de la demo, que un test no puede levantar. Lo que se protege es la DECISIÓN — cuándo
 * actúa, con qué comando y qué pasa si falla — que es donde estuvieron los errores.
 */
class ReinicioDelWorkerEnElUpdateDeDemoTest extends TestCase
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
     * Solo el cuerpo de la etapa nueva, para que los candados no den falsos positivos con el
     * resto del servicio.
     *
     * @return string
     */
    private function cuerpo_de_la_etapa(): string
    {
        $fuente = $this->fuente();

        $desde = strpos($fuente, 'private function step_restart_queue_workers');
        $this->assertNotFalse($desde, 'No existe el metodo step_restart_queue_workers().');

        $bloque = substr($fuente, (int) $desde);
        $hasta  = strpos($bloque, 'private function step_verify_demo');

        return $hasta === false ? $bloque : substr($bloque, 0, (int) $hasta);
    }

    /**
     * Después de migrar (el worker nuevo arranca contra un esquema al día) y antes de verificar
     * (para que la verificación final corra con el worker ya renovado).
     *
     * @return void
     */
    public function test_la_etapa_corre_entre_las_migraciones_y_la_verificacion()
    {
        $fuente = $this->fuente();

        $migraciones = strpos($fuente, '$this->step_run_migrations();');
        $reinicio    = strpos($fuente, '$this->step_restart_queue_workers();');
        $verifica    = strpos($fuente, '$this->step_verify_demo();');

        $this->assertNotFalse($reinicio, 'La etapa no se invoca en la secuencia del pipeline.');

        $this->assertGreaterThan(
            $migraciones,
            $reinicio,
            'El reinicio va despues de las migraciones, o el worker arranca contra un esquema viejo.'
        );

        $this->assertLessThan(
            $verifica,
            $reinicio,
            'El reinicio va antes de la verificacion, para que esta corra con el worker renovado.'
        );
    }

    /**
     * En hosting compartido no hay worker de larga vida: la etapa tiene que cortar antes de
     * ejecutar nada.
     *
     * Si algún día alguien saca la condición, los updates de las demos en shared se pondrían a
     * correr un comando para nada, en servidores donde ese artisan puede ni estar disponible.
     *
     * @return void
     */
    public function test_en_hosting_compartido_no_ejecuta_nada()
    {
        $cuerpo = $this->cuerpo_de_la_etapa();

        $this->assertStringContainsString(
            "demo_hosting_type() !== 'vps'",
            $cuerpo,
            'La etapa tiene que estar condicionada al VPS.'
        );

        $guarda = strpos($cuerpo, "demo_hosting_type() !== 'vps'");
        $salida = strpos($cuerpo, 'return;');
        $ssh    = strpos($cuerpo, 'exec_hosting_ssh');

        $this->assertNotFalse($salida, 'Falta el return temprano para shared_hosting.');
        $this->assertGreaterThan($guarda, $salida, 'El return tiene que estar dentro de la guarda.');
        $this->assertLessThan(
            $ssh,
            $salida,
            'El return de shared_hosting tiene que venir ANTES de cualquier ejecucion por SSH.'
        );
    }

    /**
     * 🔴 Candado sobre CÓMO se reinicia.
     *
     * `queue:restart` es graceful: deja una marca que el worker lee entre job y job, así que
     * termina el que está procesando y recién ahí sale. `supervisorctl restart` lo corta en seco
     * a mitad de un job, y además pide root, que la sesión del update no tiene.
     *
     * @return void
     */
    public function test_reinicia_con_queue_restart_y_nunca_con_supervisorctl()
    {
        $cuerpo = $this->cuerpo_de_la_etapa();

        $this->assertStringContainsString('artisan queue:restart', $cuerpo);

        $ejecutables = array_filter(
            preg_split('/\r?\n/', $cuerpo),
            function ($linea) {
                return preg_match('/^\s*(\*|\/\/|\/\*)/', $linea) !== 1;
            }
        );

        $this->assertStringNotContainsString(
            'supervisorctl',
            implode("\n", $ejecutables),
            'supervisorctl corta el job en curso y pide root: no va en el pipeline del update.'
        );
    }

    /**
     * 🔴 `schedule:work` NO se reinicia y no tiene que aparecer.
     *
     * Ese comando lanza `schedule:run` como subproceso nuevo cada minuto, así que las tareas
     * programadas ya toman código fresco solas. Agregarlo seria trabajo inútil y un reinicio de
     * más sobre un proceso que no lo necesita.
     *
     * @return void
     */
    public function test_no_toca_el_scheduler()
    {
        $this->assertStringNotContainsString(
            'schedule:work',
            $this->cuerpo_de_la_etapa(),
            'El scheduler relanza subprocesos cada minuto: no hay que reiniciarlo.'
        );
    }

    /**
     * Si el reinicio falla, el update NO se aborta: el código ya está subido y migrado, y cortar
     * ahí lo dejaría a medias.
     *
     * @return void
     */
    public function test_si_falla_el_reinicio_el_update_no_se_aborta()
    {
        $cuerpo = $this->cuerpo_de_la_etapa();

        $this->assertStringNotContainsString(
            'throw ',
            $cuerpo,
            'La etapa no puede lanzar: abortaria un update que ya subio codigo y corrio migraciones.'
        );

        $this->assertMatchesRegularExpression(
            '/exec_hosting_ssh\(\s*\'restart_queue_workers\',[^;]*?,\s*false\s*\)/s',
            $cuerpo,
            'El comando tiene que ir con must_succeed = false.'
        );

        $this->assertStringContainsString(
            'queue:restart" en ',
            $cuerpo,
            'El aviso tiene que decir que correr a mano y donde.'
        );
    }

    /**
     * El servicio sigue siendo instanciable y la etapa existe como método real (no solo como
     * texto que casualmente aparece en un comentario).
     *
     * @return void
     */
    public function test_la_etapa_existe_como_metodo_del_servicio()
    {
        $this->assertTrue(
            method_exists(DemoUpdateService::class, 'step_restart_queue_workers'),
            'step_restart_queue_workers() tiene que existir como metodo de la clase.'
        );
    }
}
