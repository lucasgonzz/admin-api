<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PruebaEncuesta;
use App\Services\Prueba\PruebaEncuestaService;
use Illuminate\Http\Request;

/**
 * La página pública de la encuesta de fin de prueba gratuita (misión cruzada prueba-e2e-campana-imperio,
 * 11/11/2026): `GET prueba/encuesta/{token}` la muestra y `POST prueba/encuesta/{token}` guarda la respuesta.
 *
 * Sin login: el token largo del enlace es la única credencial (por eso el POST no pide CSRF: no hay sesión
 * que proteger, y un formulario que quedó abierto un rato no puede fallar por un token de sesión vencido).
 * Una sola respuesta por token: volver a abrir el enlace, o reenviar el formulario, muestra el «gracias».
 * Un token que no existe da 404 sin decir nada más.
 */
class PruebaEncuestaController extends Controller
{
    /**
     * Muestra la encuesta, o el «gracias» si ya la contestó.
     *
     * @param string $token
     *
     * @return \Illuminate\Http\Response
     */
    public function show($token)
    {
        $encuesta = $this->encuesta($token);
        if ($encuesta === null) {
            return $this->enlace_invalido();
        }

        return $this->pagina($encuesta, [], []);
    }

    /**
     * Guarda la respuesta.
     *
     * @param Request $request
     * @param string  $token
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, $token)
    {
        $encuesta = $this->encuesta($token);
        if ($encuesta === null) {
            return $this->enlace_invalido();
        }

        if ($encuesta->respondida_at !== null) {
            return $this->pagina($encuesta, [], []);
        }

        $errores = [];
        $datos   = [];

        $estrellas = $request->input('estrellas');
        if (! is_scalar($estrellas) || ! ctype_digit((string) $estrellas) || (int) $estrellas < 1 || (int) $estrellas > 5) {
            $errores['estrellas'] = 'Elegí de 1 a 5 estrellas para poder enviar la encuesta.';
        } else {
            $datos['estrellas'] = (int) $estrellas;
        }

        foreach (array_keys(PruebaEncuesta::PREGUNTAS) as $pregunta) {
            $valor = $request->input($pregunta);
            /* Sin contestar queda en null: no es lo mismo que «no». */
            $datos[$pregunta] = $valor === 'si' ? true : ($valor === 'no' ? false : null);
        }

        $comentario = $request->input('comentario');
        $comentario = is_string($comentario) ? trim($comentario) : '';
        if (mb_strlen($comentario) > PruebaEncuestaService::MAX_COMENTARIO) {
            $errores['comentario'] = 'El comentario es muy largo: acortalo a ' . PruebaEncuestaService::MAX_COMENTARIO . ' caracteres.';
        }
        $datos['comentario'] = $comentario;

        if ($errores !== []) {
            return $this->pagina($encuesta, $errores, $datos, 422);
        }

        app(PruebaEncuestaService::class)->responder($encuesta, $datos);

        /* Redirige al GET: recargar la página no reenvía el formulario. */
        return redirect()->to(PruebaEncuestaService::url($encuesta));
    }

    /**
     * La encuesta del token, o null si el enlace no es válido.
     *
     * @param mixed $token
     *
     * @return PruebaEncuesta|null
     */
    protected function encuesta($token): ?PruebaEncuesta
    {
        $token = is_string($token) ? $token : '';

        /* El largo se mira antes de ir a la base: un token es de 48 caracteres alfanuméricos. */
        if (preg_match('/^[A-Za-z0-9]{40,64}$/', $token) !== 1) {
            return null;
        }

        return PruebaEncuesta::where('token', $token)->first();
    }

    /**
     * Un enlace que no existe: la misma página, con un mensaje amable, y 404 para quien mira el código.
     *
     * @return \Illuminate\Http\Response
     */
    protected function enlace_invalido()
    {
        return response()
            ->view('prueba.encuesta', [
                'invalido' => true, 'respondida' => false, 'negocio' => '', 'preguntas' => [],
                'errores' => [], 'datos' => [], 'max' => PruebaEncuestaService::MAX_COMENTARIO,
            ], 404)
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Renderiza la página.
     *
     * @param PruebaEncuesta        $encuesta
     * @param array<string, string> $errores
     * @param array<string, mixed>  $datos    Lo que había elegido, para no hacérselo cargar de nuevo.
     * @param int                   $status
     *
     * @return \Illuminate\Http\Response
     */
    protected function pagina(PruebaEncuesta $encuesta, array $errores, array $datos, int $status = 200)
    {
        $client = Client::find((int) $encuesta->client_id);

        return response()
            ->view('prueba.encuesta', [
                'respondida' => $encuesta->respondida_at !== null,
                'negocio'    => $client !== null ? $client->resolve_display_name() : '',
                'preguntas'  => PruebaEncuesta::PREGUNTAS,
                'errores'    => $errores,
                'datos'      => $datos,
                'max'        => PruebaEncuestaService::MAX_COMENTARIO,
            ], $status)
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
