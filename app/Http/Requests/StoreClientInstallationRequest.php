<?php

namespace App\Http\Requests;

use App\Models\ClientInstallation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del alta global de instalaciones (POST /api/admin/installations).
 *
 * 🔴 Por qué existe este archivo y no está todo adentro del controlador. La regla R3 del plan (§9)
 * le pone a ClientInstallationController un techo de 700 líneas, y la acción prescrita cuando se
 * cruza es textualmente "mover la validación de targets/aprovisionamiento a un FormRequest". Con el
 * campo nuevo, la guarda de VPS y el endpoint de credenciales el controlador daba 702, así que se
 * aplicó antes de commitear y no después.
 *
 * Las reglas son las MISMAS de siempre, movidas tal cual: un consumidor viejo tiene que recibir
 * exactamente los mismos 422 que recibía. Lo único nuevo es 'provision_hosting_type'.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
class StoreClientInstallationRequest extends FormRequest
{
    /**
     * La autorización ya la resolvió el middleware de la ruta (auth:sanctum, grupo del admin): acá
     * no hay una segunda regla que agregar, y devolver false silenciosamente convertiría un 401
     * claro en un 403 que no explica nada.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'client_id'               => 'required|integer|exists:clients,id',
            'client_api_id'           => 'nullable|integer',
            'version_id'              => 'nullable|integer',
            'targets'                 => 'nullable|array|max:2',
            'targets.*.client_api_id' => 'required_with:targets|integer',
            'targets.*.kind'          => 'required_with:targets|in:completa,esqueleto',

            /*
             * 🔴 nullable, y la ausencia es el estado viejo: sin este campo la fila queda con null y
             * el pipeline corre exactamente como antes del 31/8/2026, sin un solo paso nuevo. Es lo
             * que hace que un SPA que todavía no conoce el campo siga funcionando igual.
             */
            'provision_hosting_type' => 'nullable|in:'
                . implode(',', ClientInstallation::PROVISION_HOSTING_TYPES),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            /*
             * 🔴 Mensaje propio y en castellano. config/app.php tiene locale 'en', así que el
             * default de Laravel acá sale en inglés ("The targets may not have more than 2 items"),
             * en un endpoint donde todos los demás 422 están en castellano y explicados. Y es
             * alcanzable de verdad: Client::client_apis() es un hasMany sin límite y hay endpoints
             * vivos para agregar una tercera API a un cliente (routes/api.php, el CRUD de
             * client-apis), así que el modal —que tilda todas las APIs por default— le manda tres
             * destinos al POST sin que el operador haya hecho nada raro. El mensaje tiene que
             * decirle qué hacer, no informarle que hay una regla.
             */
            'targets.max' => 'Solo se pueden instalar dos APIs de una vez: una con la instalación '
                . 'real y otra con el esqueleto. Destildá las que sobren y creá el resto en un '
                . 'segundo pedido.',

            'provision_hosting_type.in' => 'El tipo de hosting a aprovisionar tiene que ser '
                . '"shared_hosting" o "vps". Dejalo vacío para no aprovisionar nada.',
        ];
    }
}
