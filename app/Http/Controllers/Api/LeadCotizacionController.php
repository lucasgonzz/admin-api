<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\CotizadorLeadService;
use App\Services\CotizadorSettings;
use App\Services\MercadoPagoLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Genera el link de pago de Mercado Pago para la cotización del sistema de un lead.
 *
 * 🔴 POR QUÉ EL NAVEGADOR PUEDE FIJAR LOS PRECIOS, Y POR QUÉ ESO NO ES EL AGUJERO DEL 16/9
 * ----------------------------------------------------------------------------------------
 * En la tienda, el precio de la preferencia lo dictaba el navegador y cualquiera podía pagar lo
 * que quisiera (informe `20260916-mp-precio-servidor-y-credenciales-env.md`). Se cerró haciendo
 * que el monto salga del servidor.
 *
 * Acá el caso es distinto **a propósito**: allá el navegador era el de un comprador ANÓNIMO
 * fijando lo que iba a pagar; acá es el de un admin AUTENTICADO fijando lo que le va a cobrar a
 * otro. Que quien vende decida el precio de cada lead *es* la funcionalidad — Lucas pidió inputs
 * y no etiquetas, porque hace falta poder hacer un precio especial sin cambiar el default de
 * todos.
 *
 * Lo que el servidor sí hace, sin excepción:
 *
 * 1. **Recalcula el total** desde los valores recibidos. Un `total` que venga en el body se
 *    ignora: no hay una sola línea que lo lea.
 * 2. **Valida rangos**: precios y dólar > 0, con techo, para que un dedo pegado no genere un link
 *    de cobro por una cifra absurda que el lead recibe por WhatsApp antes de que nadie lo mire.
 * 3. **Guarda la foto** de con qué números se cotizó, así el link siempre se puede auditar contra
 *    lo que se le ofreció al lead.
 *
 * 🔴 Y el lead no se toca hasta que Mercado Pago devolvió la preferencia. Si falta la credencial o
 * si Mercado Pago rechaza, sale un 422 con el motivo y las siete columnas quedan como estaban: una
 * cotización a medias guardada es peor que ninguna, porque nadie sabe si ese link existe.
 */
class LeadCotizacionController extends Controller
{
    /**
     * Cotiza los sistemas elegidos y devuelve el link de pago ya generado.
     *
     * @param Request                $request
     * @param int|string             $id      Id del lead.
     * @param MercadoPagoLinkService $mp      Cliente de Mercado Pago (inyectado para poder fakearlo).
     *
     * @return JsonResponse
     */
    public function generar_link(Request $request, $id, MercadoPagoLinkService $mp): JsonResponse
    {
        $lead = Lead::findOrFail($id);

        $validated = $request->validate([
            /* Cotización del dólar, cargada a mano. No hay fuente automática y es a propósito: el
               precio que se acuerda con el lead no siempre es el dólar del día. */
            'dolar'                  => 'required|numeric|min:'.CotizadorSettings::MIN_DOLAR.'|max:'.CotizadorSettings::MAX_DOLAR,
            /* Al menos un sistema: cotizar cero sistemas daría un link de pago por $0. */
            'sistemas'               => 'required|array|min:1',
            /* La key tiene que estar en el catálogo: de ahí sale la etiqueta que el lead lee en el
               checkout, así que una key inventada no tiene nombre que mostrar. `distinct` porque
               cotizar dos veces el mismo sistema duplicaría el importe sin que se vea. */
            'sistemas.*.key'         => ['required', 'string', 'distinct', Rule::in(array_keys(CotizadorSettings::SISTEMAS))],
            /* El precio de cada sistema, en dólares. `numeric` y no `integer`: puede tener
               centavos. El techo es la guarda contra el dedo pegado — ver CotizadorSettings. */
            'sistemas.*.precio_usd'  => 'required|numeric|min:'.CotizadorSettings::MIN_PRECIO.'|max:'.CotizadorSettings::MAX_PRECIO,
        ]);

        /* 🔴 El descuento y las cuotas NO salen del request: son configuración, no algo que el
           navegador pueda fijar por cotización. Si mañana hacen falta por lead, se agregan como
           campos validados; hoy dejarlos entrar sin querer sería otra puerta abierta. */
        $descuento = CotizadorSettings::get_descuento_transferencia();
        $cuotas    = CotizadorSettings::get_cuotas();

        /* El total se rehace acá, desde cero, con las seis fórmulas de CotizadorLeadService. */
        $cotizacion = CotizadorLeadService::cotizar(
            $validated['sistemas'],
            (float) $validated['dolar'],
            $descuento,
            $cuotas
        );

        /* El link vence: una cotización hecha con el dólar de hoy no puede seguir cobrando dentro
           de dos meses. Los días los manda la configuración del panel. */
        $generada_at = now();
        $vence_at    = $generada_at->copy()->addDays(CotizadorSettings::get_link_vence_dias());

        try {
            $preferencia = $mp->crear_preferencia(
                (int) $lead->id,
                $cotizacion['items'],
                $cuotas,
                $vence_at
            );
        } catch (\RuntimeException $e) {
            /* Falta la credencial, Mercado Pago rechazó, o no se pudo llegar al servicio. En los
               tres casos el motivo es legible y el lead queda sin tocar. 422 y no 500: no es un
               error del programa, es algo que quien está cotizando puede leer y resolver. */
            return response()->json(['message' => $e->getMessage()], 422);
        }

        /* Recién ahora se persiste la foto, con el link ya en la mano. */
        $lead->contract_cotizacion_items         = $cotizacion['items'];
        $lead->contract_cotizacion_dolar         = $cotizacion['dolar'];
        $lead->contract_cotizacion_total_usd     = $cotizacion['total_usd'];
        $lead->contract_cotizacion_total_ars     = $cotizacion['total_ars'];
        $lead->contract_cotizacion_link_pago     = $preferencia['link_pago'];
        $lead->contract_cotizacion_preference_id = $preferencia['preference_id'];
        $lead->contract_cotizacion_generada_at   = $generada_at;

        /* 🔴 El precio del contrato se persiste ACÁ y no queda como borrador del formulario.
           Lucas pidió que al cotizar el total llene el campo "Precio total (licencia +
           implementación)"; si eso viviera solo en el borrador del modal, cerrar el lead sin
           apretar "Guardar datos del contrato" dejaría la cotización y el link ya guardados por
           USD 2.700 y el precio del contrato en el valor viejo, sin que nada avise. Dos cosas que
           tienen que decir lo mismo no pueden guardarse una sí y la otra no. */
        $lead->contract_precio_licencia = (string) $cotizacion['total_usd'];
        $lead->contract_currency        = 'USD';

        $lead->save();

        /* Los datos del link se suman a la cotización recién acá: CotizadorLeadService es el
           cálculo puro y no sabe nada de Mercado Pago. */
        $cotizacion['link_pago']     = $preferencia['link_pago'];
        $cotizacion['preference_id'] = $preferencia['preference_id'];
        $cotizacion['generada_at']   = $generada_at->copy();
        $cotizacion['vence_at']      = $vence_at->toIso8601String();

        /* `model` sale con la misma forma que el PUT /lead/{id}, para que el front lo pueda meter
           en su estado sin distinguir de dónde vino. */
        return response()->json([
            'model'      => $this->fullModel('lead', $lead->id),
            'cotizacion' => $cotizacion,
        ], 200);
    }
}
