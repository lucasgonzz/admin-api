<?php

namespace App\Exceptions;

/**
 * Lo que tira `RunUserSetupService::run()` (la puerta de LEADS del user setup) cuando el candado
 * (`UserSetupCandadoService`) dice que ese sistema ya opera o ya se configuró y NO se le vuelve a aplicar
 * el setup (misión `puertas-del-user-setup`, 6/10/2026).
 *
 * 🔴 POR QUÉ ES UNA EXCEPCIÓN Y NO UN `mark_failed()`. `RunUserSetupService` cuenta lo que pasó en
 * `leads.user_setup_status`, y esa columna es la señal que leen los demás candados (`lead_sin_user_setup` de
 * `claude/implementations/*` y del botón del panel): un lead que dice `exitoso` no puede pasar a `fallido`
 * porque alguien volvió a apretar el botón y el candado lo frenó. Frenar no es fallar: el setup NI SE INTENTÓ,
 * así que no se escribe nada —ni el estado del lead, ni el cliente— y quien llama se entera por esta excepción.
 *
 * Lleva los chequeos que fallaron (`['chequeo' => ..., 'ok' => false, 'detalle' => ...]`, los mismos de
 * `claude/*`) para que el controlador los devuelva en la respuesta. El mensaje (`getMessage()`) está en
 * castellano y se puede mostrar tal cual.
 */
class UserSetupBloqueadoException extends \RuntimeException
{
    /**
     * Los chequeos del candado que fallaron.
     *
     * @var array<int, array<string, mixed>>
     */
    private $chequeos;

    /**
     * @param string                           $mensaje  Texto legible, en castellano.
     * @param array<int, array<string, mixed>> $chequeos Los chequeos que fallaron (cada uno con `chequeo`, `ok` y `detalle`).
     * @param \Throwable|null                  $previa   Excepción que la originó, si hubo.
     */
    public function __construct(string $mensaje, array $chequeos = [], ?\Throwable $previa = null)
    {
        parent::__construct($mensaje, 0, $previa);

        $this->chequeos = $chequeos;
    }

    /**
     * Los chequeos del candado que fallaron, con su detalle.
     *
     * @return array<int, array<string, mixed>>
     */
    public function chequeos(): array
    {
        return $this->chequeos;
    }
}
