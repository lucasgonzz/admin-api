<?php

namespace App\Services\Agenda;

use App\Models\Admin;

/**
 * Resuelve de qué admin es la agenda que Claude quiere leer o escribir (misión agenda-admin,
 * 31/10/2026).
 *
 * El panel no lo necesita: ahí la agenda es siempre la del admin logueado. Claude no tiene sesión,
 * así que dice a quién apunta con `admin_id` o `admin_name`, y si no dice nada se usa el admin por
 * defecto de las tareas de Claude (decisión 1 del plan).
 */
class AgendaAdminResolver
{
    /** @var AgendaValidator */
    private $validator;

    /**
     * @param AgendaValidator $validator Para cortar con el mismo formato de 422 que el resto de la Agenda.
     */
    public function __construct(AgendaValidator $validator)
    {
        $this->validator = $validator;
    }

    /**
     * El admin dueño de la agenda que se pide.
     *
     * Orden: `admin_id` (tiene que existir), `admin_name` (LIKE sin distinguir mayúsculas, tiene
     * que matchear exactamente uno), y si no vino ninguno, {@see admin_por_defecto()}. Si vienen los
     * dos y apuntan a admins distintos es 422: no se adivina cuál de los dos quiso decir.
     *
     * @param array $input Los parámetros del request.
     *
     * @return Admin
     *
     * @throws \Illuminate\Validation\ValidationException 422 si no se puede resolver a uno solo.
     */
    public function resolver(array $input): Admin
    {
        $por_id     = null;
        $por_nombre = null;

        if (array_key_exists('admin_id', $input) && $input['admin_id'] !== null && $input['admin_id'] !== '') {
            $crudo = $input['admin_id'];

            /* `is_scalar` primero: `admin_id[]=1` llega como array, y castearlo a string da el
               "Array to string conversion" que en Laravel es un 500. */
            if (! is_scalar($crudo) || ! is_numeric($crudo) || (string) (int) $crudo !== (string) $crudo || (int) $crudo <= 0) {
                $this->validator->fallar(['admin_id' => ['«admin_id» tiene que ser el id numérico de un admin. GET claude/admins los lista.']]);
            }

            $por_id = Admin::find((int) $crudo);

            if ($por_id === null) {
                $this->validator->fallar(
                    ['admin_id' => ['No existe ningún admin con el id ' . (int) $crudo . '.']],
                    ['admins' => $this->lista_de_admins()]
                );
            }
        }

        if (array_key_exists('admin_name', $input) && $input['admin_name'] !== null) {
            /* 🔴 `admin_name[]=x` (o `"admin_name": ["x"]` en el body) llega como array: castearlo a
               string era un 500 "Array to string conversion" (revisión independiente, 31/10/2026).
               Un nombre que no es texto es un 422 legible, no un error del servidor. */
            if (! is_string($input['admin_name']) && ! is_numeric($input['admin_name'])) {
                $this->validator->fallar(['admin_name' => ['«admin_name» tiene que ser un texto con el nombre (o parte del nombre) de un admin, no una lista. GET claude/admins los lista.']]);
            }

            if (trim((string) $input['admin_name']) !== '') {
                $por_nombre = $this->por_nombre(trim((string) $input['admin_name']));
            }
        }

        if ($por_id !== null && $por_nombre !== null && (int) $por_id->id !== (int) $por_nombre->id) {
            $this->validator->fallar([
                'admin_name' => ['«admin_id» y «admin_name» apuntan a admins distintos (' . $por_id->name . ' y ' . $por_nombre->name . '): mandá uno solo.'],
            ]);
        }

        if ($por_id !== null) {
            return $por_id;
        }

        if ($por_nombre !== null) {
            return $por_nombre;
        }

        $por_defecto = $this->admin_por_defecto();

        if ($por_defecto === null) {
            $this->validator->fallar(['admin_id' => ['No hay ningún admin cargado: la agenda necesita un dueño.']]);
        }

        return $por_defecto;
    }

    /**
     * El admin por defecto de las tareas de Claude.
     *
     * 🔴 Réplica DELIBERADA de `ClaudeTaskIngestController::resolve_creator_admin_id()`, que es
     * `protected` y vive en un controlador que esta misión no puede tocar (regla de alcance del
     * plan). Mismo orden de prioridad: el admin de `CLAUDE_TASK_INGEST_CREATOR_ADMIN_ID`
     * (`services.claude_task_ingest.default_creator_admin_id`), el primer admin marcado con
     * `is_default_task_assignee`, el primer admin de la tabla. Es la tercera copia (la segunda está
     * en `ClaudeImplementationOpsController::resolver_creador()`): el que unifique las tres, que las
     * lleve a un servicio y deje este método llamándolo.
     *
     * @return Admin|null Null si no hay ningún admin en la base.
     */
    public function admin_por_defecto(): ?Admin
    {
        $configurado = config('services.claude_task_ingest.default_creator_admin_id');

        if (! empty($configurado)) {
            $admin = Admin::find((int) $configurado);

            if ($admin !== null) {
                return $admin;
            }
        }

        $por_defecto = Admin::where('is_default_task_assignee', true)->orderBy('id')->first();
        if ($por_defecto !== null) {
            return $por_defecto;
        }

        return Admin::orderBy('id')->first();
    }

    /**
     * Busca un admin por nombre, igual que `ClaudeTaskIngestController`: LIKE %nombre% sin
     * distinguir mayúsculas (con LOWER() para no depender del collation). Nunca adivina: cero o más
     * de una coincidencia es 422, con la lista de nombres para que quien llama elija.
     *
     * Única diferencia con aquél: los comodines `%` y `_` del nombre se escapan, para que
     * «a_b» busque «a_b» y no «a cualquier letra b».
     *
     * @param string $nombre Nombre o parte del nombre.
     *
     * @return Admin
     *
     * @throws \Illuminate\Validation\ValidationException 422 si no hay uno solo.
     */
    private function por_nombre(string $nombre): Admin
    {
        $patron = '%' . addcslashes(mb_strtolower($nombre), '%_\\') . '%';

        $coincidencias = Admin::whereRaw('LOWER(name) LIKE ?', [$patron])
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($coincidencias->count() === 0) {
            $this->validator->fallar(
                ['admin_name' => ['Ningún admin se llama «' . $nombre . '». Elegí uno de la lista «admins».']],
                ['admins' => $this->lista_de_admins()]
            );
        }

        if ($coincidencias->count() > 1) {
            $this->validator->fallar(
                ['admin_name' => ['«' . $nombre . '» coincide con más de un admin (' . $coincidencias->pluck('name')->implode(', ') . '): sé más específico o mandá «admin_id».']],
                ['matches' => $coincidencias->pluck('name')->values()->all()]
            );
        }

        return $coincidencias->first();
    }

    /**
     * Nombres de todos los admins, para acompañar un 422 de "no encontrado".
     *
     * @return array<int, string>
     */
    private function lista_de_admins(): array
    {
        return Admin::orderBy('name')->pluck('name')->values()->all();
    }
}
