<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Demo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * Hace cumplir que dos sistemas (un cliente y otro cliente, una demo y otra demo, o un cliente y
 * una demo) no tengan cargado el mismo `user_id` en el admin.
 *
 * QUÉ ES EL `user_id`. En `clients` es el id del dueño en la base del ERP de ese cliente (el
 * inicio del bloque de 100 que reparte `UserIdBlockAllocatorService`); en `demos` es el mismo
 * número que va en `USER_ID` del `.env` del ERP de la demo (ver la migración
 * `2026_08_31_100100_add_owner_fields_to_demos_table`). En los dos casos es "el id del dueño del
 * sistema", y por eso se comparan entre sí y no solo dentro de cada tabla.
 *
 * 🔴 POR QUÉ EXISTE (misión pusher-canales-por-sistema, 19/10/2026). Toda la flota, demos
 * incluidas, habla por UNA sola app de Pusher, y los canales llevan solo el id del dueño
 * (`global_notification.{owner}`, `added_model.{owner}`, `App.Models.User.{id}`...). Dos sistemas
 * con el mismo id de dueño se escuchan entre sí: el 19/10 el aviso del escaneo de una factura en
 * demo3 (USER_ID 2600) le apareció a Ferretotal, cuyo dueño también es el 2600. El arreglo de fondo
 * (el espacio de nombres por sistema en los canales) vive en empresa y tienda; esta guarda es la
 * parte del admin: que nadie pueda volver a CARGAR un id que ya tiene otro sistema.
 *
 * LOS DUPLICADOS HEREDADOS SE RESPETAN. En producción hay choques que ya existían cuando se escribió
 * esto (El Kiosco Verde y Feitoamao con 1, porque son bases viejas que numeran desde 1; el cliente
 * 45 «DEMO» y la demo 1 con 400, que son el mismo sistema). Esas filas se tienen que poder seguir
 * guardando —editarle el teléfono a Feitoamao, por ejemplo— mientras nadie toque su `user_id`. Por
 * eso la regla solo mira el valor cuando el registro es nuevo o cuando el `user_id` cambió: un
 * guardado que no toca el campo no puede fallar por un choque que no causó.
 *
 * POR QUÉ NO UN ÍNDICE ÚNICO EN LA BASE. Por los mismos duplicados heredados (la migración fallaría
 * contra producción) y porque un índice no puede cruzar dos tablas (`clients` contra `demos`). La
 * contracara es que dos guardados simultáneos con el mismo valor nuevo podrían pasar los dos; es una
 * carrera que solo puede ocurrir si dos operadores cargan el mismo id en el mismo segundo, y se
 * acepta a sabiendas.
 *
 * SOFTDELETES (decidido el 19/10/2026). Medido: ni `Client` ni `Demo` usan `SoftDeletes` y ninguna
 * migración crea `deleted_at` en `clients` ni en `demos`; borrar un cliente o una demo es un borrado
 * físico y su fila desaparece, así que hoy no hay "borrados" que contar. Si algún día se agrega el
 * trait, la consulta los incluye (ver `consulta_de()`): un cliente dado de baja con borrado lógico
 * puede tener su sistema todavía levantado y escuchando en Pusher, y restaurarlo traería el choque
 * de vuelta sin que nadie pase por esta guarda.
 *
 * La engancha el evento `saving` de `Client` y de `Demo` (ver el `booted()` de cada modelo), así que
 * cubre todos los caminos de escritura a la vez: el panel (`ClientController` y `DemoController` con
 * `ModelPropertiesHelper`), los endpoints `claude/*`, los servicios (`RunUserSetupService`) y los
 * seeders. Mismo criterio que el hook `saving` de `ClientEcommerce`: validar en un solo controlador
 * es la forma conocida de que el quinto camino, escrito dentro de seis meses, lo saltee.
 */
class UserIdUnicoService
{
    /**
     * Valida el `user_id` de un cliente o una demo que está por guardarse.
     *
     * Se llama desde el evento `saving`, o sea ANTES del INSERT/UPDATE: si tira, no se escribe
     * nada. Tira una `ValidationException` (y no una `RuntimeException` como `ClientEcommerce`)
     * porque este error lo causa un dato que cargó una persona, y Laravel la convierte en un 422
     * con `errors.user_id` que el panel (`admin-spa/src/utils/axios.js::resolve_error_message`)
     * ya muestra tal cual en el toast.
     *
     * @param Model $modelo Instancia de `Client` o de `Demo` a punto de guardarse.
     *
     * @return void
     *
     * @throws ValidationException Si otro cliente u otra demo ya tiene ese `user_id`.
     */
    public function validar(Model $modelo)
    {
        /* Un registro que ya existe y no cambió su `user_id` pasa sin consultar nada. Es lo que
         * deja seguir guardando los duplicados heredados, y además evita una consulta por cada
         * `update()` que el pipeline hace sobre un cliente (estado del upgrade, sincronizaciones). */
        if ($modelo->exists && ! $modelo->isDirty('user_id')) {
            return;
        }

        // Valor normalizado a entero, o null si no hay un id de dueño que comparar.
        $user_id = $this->normalizar($modelo->getAttribute('user_id'));
        if ($user_id === null) {
            return;
        }

        // Id del propio registro (null si se está creando), para no contarlo como choque consigo mismo.
        $id_propio = $modelo->exists ? (int) $modelo->getKey() : null;

        // Quién ya tiene ese id, sin contar al propio registro.
        $mensaje = $this->describir_quien_lo_tiene(
            $user_id,
            $modelo instanceof Client ? $id_propio : null,
            $modelo instanceof Demo ? $id_propio : null
        );

        if ($mensaje === null) {
            return;
        }

        throw ValidationException::withMessages([
            /*
             * El consejo no es "cargá el ID de la base": en el caso real que originó esto (demo3), el
             * dueño de la base de la demo ES el 2600 de Ferretotal, así que eso llevaba a cargar justo
             * el número que se rechaza. Si de verdad son dos sistemas con el mismo id, el arreglo es
             * cambiar el `USER_ID` de uno de ellos también del lado del ERP.
             */
            'user_id' => 'El ID de comercio ' . $user_id . ' ya lo tiene ' . $mensaje . '. Dos sistemas '
                . 'con el mismo ID de dueño se mezclaban los avisos en vivo: usá un ID libre, y si el '
                . 'dueño de este sistema ya tiene ese número en su base, hay que cambiarle el USER_ID '
                . 'también del lado del ERP.',
        ]);
    }

    /**
     * Dice si un `user_id` ya lo tiene algún cliente o alguna demo.
     *
     * La usa `UserIdBlockAllocatorService::suggest_next_block_start()` para no sugerirle a un lead
     * nuevo un bloque que ya tiene una demo: el asignador ancla en el máximo de `clients` y no mira
     * `demos`, y sin este chequeo la promoción del lead terminaría en el 422 de `validar()`.
     *
     * @param int $user_id Id de dueño a buscar.
     *
     * @return bool
     */
    public function esta_tomado($user_id)
    {
        return $this->describir_quien_lo_tiene((int) $user_id, null, null) !== null;
    }

    /**
     * Arma la parte del mensaje que dice quién tiene el id ("el cliente Ferretotal (#11)" o "la
     * demo demo3 (#3)"), o devuelve null si nadie lo tiene.
     *
     * Busca primero entre los clientes y después entre las demos: si lo tienen los dos (el caso
     * del cliente 45 «DEMO» y la demo 1), alcanza con nombrar a uno para que el operador sepa que
     * ese número no se puede usar.
     *
     * @param int      $user_id           Id de dueño a buscar, ya normalizado.
     * @param int|null $excluir_client_id Id del cliente que se está guardando (no cuenta como choque).
     * @param int|null $excluir_demo_id   Id de la demo que se está guardando (no cuenta como choque).
     *
     * @return string|null
     */
    protected function describir_quien_lo_tiene($user_id, $excluir_client_id, $excluir_demo_id)
    {
        // Cliente que ya tiene ese id (el de menor id, para que el mensaje sea estable).
        $consulta_clientes = $this->consulta_de(Client::class)->where('user_id', $user_id);
        if ($excluir_client_id !== null) {
            $consulta_clientes->where('id', '!=', $excluir_client_id);
        }
        $cliente = $consulta_clientes->orderBy('id')->first();

        if ($cliente !== null) {
            // Nombre legible del cliente; si no tiene ni razón social ni nombre, queda solo el número.
            $nombre = trim((string) $cliente->resolve_display_name());

            return 'el cliente ' . ($nombre !== '' ? $nombre . ' ' : '') . '(#' . $cliente->id . ')';
        }

        // Demo que ya tiene ese id.
        $consulta_demos = $this->consulta_de(Demo::class)->where('user_id', $user_id);
        if ($excluir_demo_id !== null) {
            $consulta_demos->where('id', '!=', $excluir_demo_id);
        }
        $demo = $consulta_demos->orderBy('id')->first();

        if ($demo !== null) {
            // `display_name()` cae al slug del ERP (demo3) cuando la demo no tiene nombre cargado.
            $nombre = trim((string) $demo->display_name());

            return 'la demo ' . ($nombre !== '' ? $nombre . ' ' : '') . '(#' . $demo->id . ')';
        }

        return null;
    }

    /**
     * Consulta base sobre un modelo, incluyendo los borrados lógicos si el modelo los usa.
     *
     * Hoy ni `Client` ni `Demo` usan `SoftDeletes` y esto es una consulta común; el `withTrashed()`
     * queda para que agregar el trait mañana no abra un hueco en silencio (ver el PHPDoc de la
     * clase: un sistema dado de baja lógica puede seguir escuchando en Pusher).
     *
     * @param string $clase Clase del modelo (`Client::class` o `Demo::class`).
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function consulta_de($clase)
    {
        // Consulta sin restricciones propias más allá de los scopes globales del modelo.
        $consulta = $clase::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($clase), true)) {
            $consulta->withTrashed();
        }

        return $consulta;
    }

    /**
     * Lleva el valor crudo del atributo a un entero comparable, o a null si no hay nada que validar.
     *
     * - null o cadena vacía (el panel puede mandar el campo vacío) → null: un sistema sin id cargado
     *   no choca con nadie. En producción hay 7 clientes activos así.
     * - Algo que no es un número → null: el tipo no es asunto de esta guarda, lo rechaza la base.
     * - Cero o negativo → null: no es un id de dueño posible (los ids arrancan en 1), así que ningún
     *   usuario se autentica ni emite por ese canal; bloquearlo solo confundiría al operador con un
     *   "el ID 0 ya lo tiene...". Mismo criterio que `RunUserSetupService`, que trata `<= 0` como
     *   "sin id".
     *
     * Se compara como entero (y no como texto) porque `clients.user_id` tiene cast a `integer` y
     * `demos.user_id` no: "2600" y 2600 son el mismo dueño.
     *
     * @param mixed $valor Valor crudo de `user_id`.
     *
     * @return int|null
     */
    protected function normalizar($valor)
    {
        if ($valor === null) {
            return null;
        }

        // Se recorta porque en PHP 7.4 `is_numeric()` no acepta espacios al final ("2600 ").
        $texto = trim((string) $valor);
        if ($texto === '' || ! is_numeric($texto)) {
            return null;
        }

        // Entero comparable contra las dos tablas.
        $entero = (int) $texto;

        return $entero > 0 ? $entero : null;
    }
}
