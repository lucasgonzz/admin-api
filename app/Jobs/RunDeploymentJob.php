<?php

namespace App\Jobs;

use App\Models\ClientVersionUpgrade;
use App\Services\DeploymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job en cola que ejecuta el deployment de un upgrade (SSH + etapas).
 */
class RunDeploymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int Tiempo máximo de ejecución (segundos).
     */
    public $timeout = 1800;

    /**
     * @var int Sin reintentos automáticos.
     */
    public $tries = 1;

    /**
     * UUID del ClientVersionUpgrade a procesar.
     *
     * @var string
     */
    private $upgrade_uuid;

    /**
     * Etapa desde la cual reanudar (null = desde el inicio).
     *
     * @var string|null
     */
    private $resume_from_step;

    /**
     * @param  string|ClientVersionUpgrade  $upgrade_uuid
     * @param  string|null                  $resume_from_step
     */
    public function __construct($upgrade_uuid, $resume_from_step = null)
    {
        if ($upgrade_uuid instanceof ClientVersionUpgrade) {
            $upgrade_uuid = $upgrade_uuid->uuid;
        }

        $this->upgrade_uuid = (string) $upgrade_uuid;
        $this->resume_from_step = $resume_from_step;
    }

    /**
     * Conecta SSH y ejecuta el pipeline de deployment.
     *
     * @return void
     */
    public function handle()
    {
        $upgrade = ClientVersionUpgrade::where('uuid', $this->upgrade_uuid)
            ->with(['client', 'target_client_api', 'from_version', 'to_version'])
            ->firstOrFail();

        $service = new DeploymentService($upgrade);

        try {
            $service->connect();
            $service->run($this->resume_from_step);
        } catch (\Throwable $e) {
            $upgrade->refresh();
            if (
                $upgrade->deployment_status !== 'failed'
                && $upgrade->deployment_status !== 'paused'
                && $upgrade->deployment_status !== 'paused_post_tasks'
            ) {
                $upgrade->update(['deployment_status' => 'failed']);
            }
            throw $e;
        }
    }
}
