<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Mail\ClientVersionUpgradeMail;
use App\Models\Client;
use App\Models\ClientUpgradeNotice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * 🔴 **Dos workers sobre el mismo aviso mandan UN solo mail.**
 *
 * El scheduler corre `queue:work database --stop-when-empty` cada minuto y a propósito NO usa
 * `withoutOverlapping()` — está escrito y explicado en `Console/Kernel.php`, y sacarlo costaría
 * dejar sin worker a los jobs que sí tienen un turno que cumplir. O sea que puede haber dos
 * workers vivos a la vez, y los dos pueden tomar un job del mismo aviso.
 *
 * La ventana entre "leo que falta mandar el mail" y "escribo que lo mandé" no es un instante: son
 * la resolución de la casilla (HTTP al `empresa-api` del cliente, con timeout de 15 s), la consulta
 * de novedades y el SMTP. Si los dos leen antes de que ninguno escriba, al dueño le llegan dos
 * mails contándole la misma actualización.
 *
 * El índice único `cun_upgrade_client_unique` NO cubre esto: protege la fila, no el envío. Lo que
 * lo cubre es el reclamo de `AvisoDeActualizacionService::reclamar()`.
 */
class ConcurrenciaDelAvisoTest extends BaseDelAviso
{
    /**
     * 🔴 El test del hallazgo: dos procesos que leyeron la MISMA fila antes de que ninguno
     * escribiera mandan un solo mail.
     *
     * Así se simula la concurrencia sin dos procesos de verdad: las dos instancias del modelo se
     * leen antes de que salga nada, que es exactamente el estado en memoria que tendrían dos
     * workers corriendo a la vez. Cuando el segundo llega, su objeto sigue diciendo
     * `mail_enviado_at = null` —por eso pasa el `mail_pendiente()`— y lo único que lo frena es que
     * el UPDATE condicional del reclamo le devuelve cero filas.
     *
     * Sin el reclamo, este test manda dos mails.
     *
     * @return void
     */
    public function test_dos_procesos_sobre_la_misma_fila_mandan_un_solo_mail()
    {
        Mail::fake();
        Http::fake();

        $aviso = $this->dejar_un_aviso_pendiente();

        /* Las dos lecturas, las dos antes de que salga nada. */
        $worker_a = ClientUpgradeNotice::find($aviso->id);
        $worker_b = ClientUpgradeNotice::find($aviso->id);

        $this->assertTrue($worker_a->mail_pendiente());
        $this->assertTrue($worker_b->mail_pendiente(), 'precondición: los dos creen que falta mandarlo');

        $this->servicio()->reintentar($worker_a);
        $this->servicio()->reintentar($worker_b);

        Mail::assertSent(ClientVersionUpgradeMail::class, 1);

        $final = $aviso->fresh();

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $final->estado);
        $this->assertNotNull($final->mail_enviado_at);
    }

    /**
     * Un aviso que otro proceso acaba de reclamar no se toca: el que llega segundo se va sin
     * mandar nada y sin pisarle el estado.
     *
     * @return void
     */
    public function test_un_aviso_reclamado_por_otro_no_se_trabaja()
    {
        Mail::fake();
        Http::fake();

        $aviso = $this->dejar_un_aviso_pendiente();

        /* El otro worker lo reclamó hace un instante y está trabajándolo ahora mismo. */
        ClientUpgradeNotice::where('id', $aviso->id)->update([
            'estado'              => ClientUpgradeNotice::ESTADO_ENVIANDO,
            'dispatch_started_at' => now(),
        ]);

        $resultado = $this->servicio()->reintentar(ClientUpgradeNotice::find($aviso->id));

        Mail::assertNothingSent();
        $this->assertSame(0, $this->whatsapp->cuantos_envios());

        $this->assertSame(
            ClientUpgradeNotice::ESTADO_ENVIANDO,
            $resultado->estado,
            'el estado sigue siendo el del que lo tiene: no se le pisa nada'
        );
        $this->assertNull($aviso->fresh()->mail_enviado_at);
    }

    /**
     * 🔴 Un reclamo colgado NO traba el aviso para siempre.
     *
     * Es el caso que ningún `catch` puede atrapar: el proceso muere entre el reclamo y el
     * resultado, así que la fila queda en `enviando` con nadie trabajándola. Pasados
     * `MINUTOS_PARA_DAR_POR_COLGADO`, el siguiente que pase se la puede quedar.
     *
     * @return void
     */
    public function test_un_reclamo_colgado_se_puede_retomar()
    {
        Mail::fake();
        Http::fake();

        $aviso = $this->dejar_un_aviso_pendiente();

        /* Un worker lo reclamó y se murió. Nadie lo soltó. */
        ClientUpgradeNotice::where('id', $aviso->id)->update([
            'estado'              => ClientUpgradeNotice::ESTADO_ENVIANDO,
            'dispatch_started_at' => now()->subMinutes(ClientUpgradeNotice::MINUTOS_PARA_DAR_POR_COLGADO + 1),
        ]);

        $resultado = $this->servicio()->reintentar(ClientUpgradeNotice::find($aviso->id));

        Mail::assertSent(ClientVersionUpgradeMail::class, 1);

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $resultado->estado);
        $this->assertNotNull($resultado->mail_enviado_at);
    }

    /**
     * El reclamo no deja la fila trabada cuando el aviso falla: un `sin_mail` queda en `sin_mail`
     * y se puede volver a reintentar cuantas veces haga falta.
     *
     * Es la otra mitad del hallazgo: reclamar antes de mandar no puede convertir cada fallo en una
     * fila trabada, porque justamente los `sin_mail` son el estado más frecuente del canal.
     *
     * @return void
     */
    public function test_un_aviso_que_falla_suelta_el_reclamo()
    {
        Mail::fake();
        Http::fake(['*contacto-dueno*' => Http::response('', 404)]);

        $client = $this->crear_cliente(['email' => null]);
        $aviso  = $this->dejar_un_aviso_pendiente($client);

        $primero = $this->servicio()->reintentar(ClientUpgradeNotice::find($aviso->id));

        $this->assertSame(
            ClientUpgradeNotice::ESTADO_SIN_MAIL,
            $primero->estado,
            'el fallo suelta el reclamo: no queda en `enviando`'
        );

        /* Y por lo tanto se lo puede volver a trabajar: con la casilla cargada, ahora sí sale. */
        Client::where('id', $client->id)->update(['email' => 'conseguido@ejemplo.test']);

        $segundo = $this->servicio()->reintentar(ClientUpgradeNotice::find($aviso->id));

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $segundo->estado);
        Mail::assertSent(ClientVersionUpgradeMail::class, 1);
    }

    /**
     * Deja un aviso recién registrado, en `pendiente`, con una novedad para mandar.
     *
     * @param Client|null $client Cliente a usar; si no viene, se crea uno con casilla cargada.
     *
     * @return ClientUpgradeNotice
     */
    private function dejar_un_aviso_pendiente(?Client $client = null): ClientUpgradeNotice
    {
        if (! $client instanceof Client) {
            $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        }

        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');

        $upgrade = $this->crear_upgrade($client, [$version]);
        /* El hook registra la fila; el job queda encolado y no corre (Queue::fake en el setUp). */
        $upgrade->update(['status' => 'terminada']);

        $aviso = ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->first();

        $this->assertInstanceOf(ClientUpgradeNotice::class, $aviso);
        $this->assertSame(ClientUpgradeNotice::ESTADO_PENDIENTE, $aviso->estado);

        return $aviso;
    }
}
