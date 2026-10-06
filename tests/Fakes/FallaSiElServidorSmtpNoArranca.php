<?php

namespace Tests\Fakes;

/**
 * Qué hace un test cuando `ServidorSmtpFake::levantar()` devolvió `null`: saltearse SOLO si el entorno no puede
 * lanzar procesos, y FALLAR si podía y el servidor no arrancó.
 *
 * 🔴 **Por qué no alcanza con `markTestSkipped()` siempre** (hallazgo del chequeo independiente de la misión
 * mails-a-leads-rechazados-por-smtp, 6/10/2026). `ServidorSmtpFake::levantar()` devuelve `null` ante CUALQUIER falla
 * de arranque —no solo cuando falta `proc_open`—: un error de sintaxis en el script del servidor, un puerto que no
 * se pudo abrir, un hijo que muere o que no anuncia su puerto. Un test que se saltea ahí sale con código 0 y
 * PHPUnit lo cuenta como "skipped", no como rojo. Y los tests que dependen de ese servidor (más de la mitad de los
 * de esta misión) son la ÚNICA prueba de que SwiftMailer no tira excepción ante un 550: una suite que "pasa"
 * porque se salteó todo eso es un falso verde, y nadie lo ve salvo que mire la línea `Skipped: N`.
 *
 * La precondición de "no se puede lanzar un proceso" es la MISMA que `ServidorSmtpFake::levantar()` mira antes de
 * intentar (`proc_open` existe y `PHP_BINARY` es un string no vacío): ahí el salto es honesto, porque no hay forma
 * de probar nada. Con esa precondición cumplida, que no arranque es un defecto del entorno o del script del
 * servidor y tiene que ponerse ROJO.
 *
 * Se usa con `use FallaSiElServidorSmtpNoArranca;` en la clase de test, y se llama así:
 *
 *   $servidor = ServidorSmtpFake::levantar($modo);
 *
 *   if ($servidor === null) {
 *       $this->el_servidor_smtp_no_arranco();
 *   }
 */
trait FallaSiElServidorSmtpNoArranca
{
    /**
     * Saltea el test si el entorno no puede lanzar procesos; si podía, lo hace fallar. No vuelve nunca.
     *
     * @return void
     */
    protected function el_servidor_smtp_no_arranco(): void
    {
        $puede_lanzar_procesos = function_exists('proc_open') && is_string(PHP_BINARY) && PHP_BINARY !== '';

        if (! $puede_lanzar_procesos) {
            $this->markTestSkipped('Este entorno no puede lanzar procesos (sin proc_open o sin PHP_BINARY): no se puede levantar el servidor SMTP de prueba.');
        }

        $this->fail(
            'El servidor SMTP de prueba no arrancó aunque este entorno puede lanzar procesos. No se saltea: estos tests son '
            . 'la única prueba de que SwiftMailer no tira excepción ante un 550. Mirá si `tests/Fakes/servidor_smtp_fake.php` '
            . 'corre solo con `php tests/Fakes/servidor_smtp_fake.php rechaza 10` y si anuncia `PUERTO <n>`.'
        );
    }
}
