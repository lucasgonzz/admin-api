<?php

namespace Tests;

use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Cada test arranca con el memo de admin_settings vacío.
     *
     * 🔴 No es decoración: el memo es una propiedad ESTÁTICA, así que sobrevive de un test al
     * siguiente dentro del mismo proceso de PHPUnit — y con DatabaseTransactions eso deja una
     * trampa fina. Un test que escribe un setting vacía el memo (lo hacen los eventos del modelo)
     * y después lo vuelve a leer, dejándolo memorizado; al terminar, el rollback borra la fila
     * pero NO el memo. El test siguiente leería un valor que en la base ya no existe.
     *
     * Limpiarlo acá vale para toda la suite, incluidos los tests que todavía no están escritos.
     * En producción no hay equivalente de esto: ahí cada request arranca con un proceso limpio, y
     * los jobs los corta AppServiceProvider::boot() con Queue::before().
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::flush_memo();
    }
}
