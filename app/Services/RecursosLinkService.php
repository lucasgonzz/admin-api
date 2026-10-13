<?php

namespace App\Services;

use App\Models\Client;
use App\Models\RecursosLink;
use App\Models\RecursosUsuario;
use App\Models\RecursosVideo;
use Illuminate\Support\Str;

/**
 * El link personal del dueño al centro de recursos (misión centro-de-recursos-clientes, 13/10/2026).
 *
 * Es la entrada que no depende de la versión del sistema del cliente: la usa el mail "listo" de la
 * implementación (`ImplementacionMailService`) y el botón "Copiar link del dueño" del panel
 * (`POST admin/recursos/clientes/{id}/link-dueno`). Reutilizable hasta vencer: "crea o reusa el
 * vigente", así el dueño no recibe un link distinto cada vez que alguien lo copia.
 *
 * El usuario del centro del dueño es: el que tiene `empresa_user_id = clients.user_id` si la ficha
 * tiene ese dato; si no, cualquier dueño de ese cliente que ya esté en el centro (primero el que entró
 * desde su sistema); y si no hay ninguno, uno nuevo con `es_dueno` y el nombre de la ficha
 * (`clients.name`, el contacto). Cuando el dueño entre después desde su sistema con un pase,
 * `RecursosSesionService` le completa el id a esa misma fila en vez de crear otra.
 */
class RecursosLinkService
{
    /**
     * El link vigente del dueño (o uno nuevo) como `{url, expires_at}`.
     *
     * @param Client   $client
     * @param int|null $admin_id Quién lo pidió desde el panel (null si lo pidió el mail).
     *
     * @return array{url: string, expires_at: string}
     */
    public function link_del_dueno(Client $client, ?int $admin_id = null): array
    {
        $usuario = $this->usuario_dueno($client, true);

        $vigente = $this->link_vigente($usuario);

        if ($vigente !== null) {
            return $vigente;
        }

        $token = Str::random(64);

        $link = RecursosLink::create([
            'recursos_usuario_id' => $usuario->id,
            'token_hash'          => hash('sha256', $token),
            'token_cifrado'       => $token,
            'expires_at'          => now()->addDays((int) config('recursos.link_dias', 90)),
            'usos'                => 0,
            'creado_por_admin_id' => $admin_id,
        ]);

        return [
            'url'        => self::url_del_link($token),
            'expires_at' => $link->expires_at->toIso8601String(),
        ];
    }

    /**
     * El link vigente del dueño SI YA EXISTE, sin crear nada (ni el usuario ni el link). Es lo que usa
     * la vista previa del mail "listo", que por contrato no escribe nada.
     *
     * @param Client $client
     *
     * @return array{url: string, expires_at: string}|null
     */
    public function link_vigente_del_dueno(Client $client): ?array
    {
        $usuario = $this->usuario_dueno($client, false);

        if (! $usuario instanceof RecursosUsuario) {
            return null;
        }

        return $this->link_vigente($usuario);
    }

    /**
     * Si el catálogo tiene al menos un video activo. Sin catálogo cargado el centro está vacío y el
     * mail "listo" sigue mandando a la carpeta de Drive, como antes de la misión.
     *
     * @return bool
     */
    public static function hay_catalogo(): bool
    {
        return RecursosVideo::where('activo', true)->exists();
    }

    /**
     * La URL del link personal: la página con `?t=<token>`.
     *
     * @param string $token
     *
     * @return string
     */
    public static function url_del_link(string $token): string
    {
        return rtrim((string) config('recursos.url', 'https://recursos.comerciocity.com'), '/') . '/?t=' . rawurlencode($token);
    }

    /**
     * El usuario del centro del dueño del cliente (ver el docblock de la clase).
     *
     * @param Client $client
     * @param bool   $crear  false para solo buscar.
     *
     * @return RecursosUsuario|null
     */
    protected function usuario_dueno(Client $client, bool $crear): ?RecursosUsuario
    {
        $user_id = (int) $client->user_id;

        if ($user_id > 0) {
            $con_id = RecursosUsuario::where('client_id', $client->id)
                ->where('empresa_user_id', $user_id)
                ->first();

            if ($con_id instanceof RecursosUsuario) {
                return $con_id;
            }
        }

        /* Cualquier dueño que ya tenga el centro para este cliente: primero el que entró desde su sistema
           (tiene id), y entre ésos el último que entró. Así el link abre la MISMA cuenta que usa el botón
           del sistema aunque `clients.user_id` falte o no coincida. */
        $dueno = RecursosUsuario::where('client_id', $client->id)
            ->where('es_dueno', true)
            ->orderByRaw('CASE WHEN empresa_user_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('ultimo_ingreso_at')
            ->orderBy('id')
            ->first();

        if ($dueno instanceof RecursosUsuario) {
            if ($crear && $user_id > 0 && $dueno->empresa_user_id === null) {
                // La ficha ya sabe el id del dueño: se lo completa (solo cuando se puede escribir).
                $dueno->empresa_user_id = $user_id;
                $dueno->save();
            }

            return $dueno;
        }

        if (! $crear) {
            return null;
        }

        $nombre = trim((string) $client->name);

        if ($nombre === '') {
            $nombre = $client->resolve_display_name() !== '' ? $client->resolve_display_name() : 'Dueño';
        }

        return RecursosUsuario::create([
            'client_id'       => $client->id,
            'empresa_user_id' => $user_id > 0 ? $user_id : null,
            'nombre'          => mb_substr($nombre, 0, 255, 'UTF-8'),
            'email'           => ClientContactEmailResolver::mail_valido($client->email),
            'es_dueno'        => true,
        ]);
    }

    /**
     * El link vigente de un usuario, con su URL armada, o null.
     *
     * Un link cuyo token cifrado no se puede leer (otra APP_KEY, una fila vieja) no sirve para mostrar
     * y se ignora: se crea uno nuevo.
     *
     * @param RecursosUsuario $usuario
     *
     * @return array{url: string, expires_at: string}|null
     */
    protected function link_vigente(RecursosUsuario $usuario): ?array
    {
        $links = RecursosLink::where('recursos_usuario_id', $usuario->id)
            ->where('expires_at', '>', now())
            ->orderByDesc('expires_at')
            ->get();

        foreach ($links as $link) {
            try {
                $token = (string) $link->token_cifrado;
            } catch (\Throwable $e) {
                $token = '';
            }

            if ($token !== '' && hash_equals((string) $link->token_hash, hash('sha256', $token))) {
                return [
                    'url'        => self::url_del_link($token),
                    'expires_at' => $link->expires_at->toIso8601String(),
                ];
            }
        }

        return null;
    }
}
