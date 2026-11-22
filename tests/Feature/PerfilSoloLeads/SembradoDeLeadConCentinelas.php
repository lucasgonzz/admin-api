<?php

namespace Tests\Feature\PerfilSoloLeads;

use App\Models\Admin;
use App\Models\AdminTask;
use App\Models\AdminTaskNotification;
use App\Models\Client;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadMessage;
use App\Models\LeadMessageAttachment;
use App\Models\LeadPartner;
use App\Models\LeadPersonalizedDemoVideo;
use App\Models\LeadScheduledMessage;
use Illuminate\Support\Str;

/**
 * Siembra un lead con TODAS las relaciones que viajan en las respuestas de Leads y, en el Client objetivo,
 * en el Client promovido y en la Demo, valores centinela en las columnas que un vendedor externo (perfil
 * «Sólo Leads») no debe ver jamás: claves de API, datos de cobranza, contrato, precios y AFIP.
 *
 * Es un fixture de los tests de E5 (hallazgo bloqueante de la revisión): cada centinela es una cadena (o un
 * número) única que se busca en el cuerpo CRUDO de la respuesta; si aparece, el filtro de salida falló.
 */
trait SembradoDeLeadConCentinelas
{
    /**
     * Centinelas de texto sembrados en target_client, promoted_client y demo (nombre de columna => valor).
     * Se buscan como subcadena en el cuerpo crudo de la respuesta.
     *
     * @return array<string, string>
     */
    protected function centinelas_de_texto(): array
    {
        return [
            'target.api_key'                     => 'CENTINELA-TARGET-API-KEY-7f3a',
            'target.inbound_api_key'             => 'CENTINELA-TARGET-INBOUND-KEY-7f3a',
            'target.cobranzas_observaciones'     => 'CENTINELA-TARGET-COBRANZAS-7f3a',
            'target.contract_clausulas'          => 'CENTINELA-TARGET-CLAUSULAS-7f3a',
            'target.contract_client_cuit'        => 'CENTINELA-TARGET-CONTRACT-CUIT-7f3a',
            'target.afip_cuit'                   => 'CENTINELA-TARGET-AFIP-CUIT-7f3a',
            'target.afip_domicilio'              => 'CENTINELA-TARGET-AFIP-DOM-7f3a',
            'target.cobro_dudoso_motivo'         => 'CENTINELA-TARGET-DUDOSO-7f3a',
            'target.email'                       => 'centinela-target-email-7f3a@test.local',
            'target.slug'                        => 'CENTINELA-TARGET-SLUG-7f3a',
            'target.api_url'                     => 'https://CENTINELA-TARGET-API-URL-7f3a.test',
            'promoted.api_key'                   => 'CENTINELA-PROMO-API-KEY-9c1d',
            'promoted.inbound_api_key'           => 'CENTINELA-PROMO-INBOUND-KEY-9c1d',
            'promoted.cobranzas_observaciones'   => 'CENTINELA-PROMO-COBRANZAS-9c1d',
            'promoted.contract_clausulas'        => 'CENTINELA-PROMO-CLAUSULAS-9c1d',
            'promoted.afip_razon_social'         => 'CENTINELA-PROMO-AFIP-RAZON-9c1d',
            'promoted.api_url'                   => 'https://CENTINELA-PROMO-API-URL-9c1d.test',
            'demo.api_key'                       => 'CENTINELA-DEMO-API-KEY-5b2e',
            'demo.erp_api_url'                   => 'https://CENTINELA-DEMO-ERP-API-5b2e.test',
            'demo.ecommerce_api_url'             => 'https://CENTINELA-DEMO-ECOM-API-5b2e.test',
            'demo.erp_vps_path'                  => '/CENTINELA-DEMO-VPS-PATH-5b2e',
            'lead.demo_ingreso_token'            => 'CENTINELA-LEAD-INGRESO-TOKEN-2d4f',
            'lead.demo_eventos_token'            => 'CENTINELA-LEAD-EVENTOS-TOKEN-2d4f',
            'creador.email'                      => 'centinela-creador-email-6a8c@test.local',
            'creador.phone_number'               => '549CENTINELA-CREADOR-PHONE-6a8c',
        ];
    }

    /**
     * Centinelas numéricos (precios) sembrados en los Client. Se buscan como valor entero en el JSON crudo.
     *
     * @return array<string, int>
     */
    protected function centinelas_numericos(): array
    {
        return [
            'target.precio_plan'                => 8765431,
            'target.precio_ecommerce'           => 8765432,
            'target.total_mensualidad'          => 8765433,
            'target.contract_precio_licencia'   => 8765434,
            'promoted.precio_plan'              => 9765431,
            'promoted.total_mensualidad'        => 9765433,
        ];
    }

    /**
     * Crea el Client «objetivo» del lead (donde corre la demo) con centinelas.
     *
     * @return Client
     */
    protected function sembrar_client_objetivo(): Client
    {
        return Client::create([
            'name'                       => 'Objetivo Visible SA',
            'company_name'               => 'Empresa Objetivo Visible',
            'slug'                       => 'CENTINELA-TARGET-SLUG-7f3a',
            'email'                      => 'centinela-target-email-7f3a@test.local',
            'api_url'                    => 'https://CENTINELA-TARGET-API-URL-7f3a.test',
            'api_key'                    => 'CENTINELA-TARGET-API-KEY-7f3a',
            'inbound_api_key'            => 'CENTINELA-TARGET-INBOUND-KEY-7f3a',
            'cobranzas_observaciones'    => 'CENTINELA-TARGET-COBRANZAS-7f3a',
            'contract_clausulas_particulares' => 'CENTINELA-TARGET-CLAUSULAS-7f3a',
            'contract_client_cuit'       => 'CENTINELA-TARGET-CONTRACT-CUIT-7f3a',
            'contract_precio_licencia'   => '8765434',
            'afip_cuit'                  => 'CENTINELA-TARGET-AFIP-CUIT-7f3a',
            'afip_domicilio'             => 'CENTINELA-TARGET-AFIP-DOM-7f3a',
            'cobro_dudoso'               => true,
            'cobro_dudoso_motivo'        => 'CENTINELA-TARGET-DUDOSO-7f3a',
            'precio_plan'                => 8765431,
            'precio_ecommerce'           => 8765432,
            'total_mensualidad'          => 8765433,
        ]);
    }

    /**
     * Crea el Client «promovido» del lead (el sistema productivo real) con centinelas.
     *
     * @return Client
     */
    protected function sembrar_client_promovido(): Client
    {
        return Client::create([
            'name'                       => 'Promovido Visible SA',
            'company_name'               => 'Empresa Promovida Visible',
            'api_url'                    => 'https://CENTINELA-PROMO-API-URL-9c1d.test',
            'api_key'                    => 'CENTINELA-PROMO-API-KEY-9c1d',
            'inbound_api_key'            => 'CENTINELA-PROMO-INBOUND-KEY-9c1d',
            'cobranzas_observaciones'    => 'CENTINELA-PROMO-COBRANZAS-9c1d',
            'contract_clausulas_particulares' => 'CENTINELA-PROMO-CLAUSULAS-9c1d',
            'afip_razon_social'          => 'CENTINELA-PROMO-AFIP-RAZON-9c1d',
            'precio_plan'                => 9765431,
            'total_mensualidad'          => 9765433,
        ]);
    }

    /**
     * Crea la Demo del lead con centinelas.
     *
     * @return Demo
     */
    protected function sembrar_demo(): Demo
    {
        return Demo::create([
            'nombre'                 => 'Demo Visible',
            'api_key'                => 'CENTINELA-DEMO-API-KEY-5b2e',
            'erp_spa_url'            => 'https://demo-visible.test',
            'ecommerce_spa_url'      => 'https://demo-visible-tienda.test',
            'erp_api_url'            => 'https://CENTINELA-DEMO-ERP-API-5b2e.test',
            'ecommerce_api_url'      => 'https://CENTINELA-DEMO-ECOM-API-5b2e.test',
            'erp_vps_path'           => '/CENTINELA-DEMO-VPS-PATH-5b2e',
        ]);
    }

    /**
     * Crea un lead completo: client objetivo, client promovido, demo, admin creador, mensajes (con adjunto y
     * emisor), mensaje programado, video personalizado, socio y llamada con resumen.
     *
     * @param Admin|null $admin_creador Admin que figura como creador y emisor (se crea uno si es null).
     * @param string     $status        Estado del lead.
     *
     * @return Lead
     */
    protected function sembrar_lead_completo(?Admin $admin_creador = null, string $status = 'contactado'): Lead
    {
        if ($admin_creador === null) {
            $admin_creador = Admin::create([
                'name'     => 'Creador Visible',
                'email'    => 'centinela-creador-email-6a8c@test.local',
                'phone_number' => '549CENTINELA-CREADOR-PHONE-6a8c',
                'password' => bcrypt('secret'),
            ]);
        }

        $objetivo = $this->sembrar_client_objetivo();
        $promovido = $this->sembrar_client_promovido();
        $demo     = $this->sembrar_demo();

        $lead                     = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = 'Contacto Sembrado';
        $lead->company_name       = 'Empresa Sembrada';
        $lead->phone              = '549341' . random_int(1000000, 9999999);
        $lead->notes              = 'Nota sembrada';
        $lead->status             = $status;
        $lead->created_by_admin_id = $admin_creador->id;
        $lead->target_client_id   = $objetivo->id;
        $lead->promoted_client_id = $promovido->id;
        $lead->demo_id            = $demo->id;
        $lead->notify_admin_id    = $admin_creador->id;
        $lead->demo_ingreso_token = 'CENTINELA-LEAD-INGRESO-TOKEN-2d4f';
        $lead->demo_eventos_token = 'CENTINELA-LEAD-EVENTOS-TOKEN-2d4f';
        $lead->save();

        $mensaje = LeadMessage::create([
            'lead_id'          => $lead->id,
            'sender'           => 'setter',
            'status'           => 'enviado',
            'content'          => 'Hola, mensaje sembrado',
            'is_status_event'  => false,
            'sent_by_admin_id' => $admin_creador->id,
            'read_at'          => null,
        ]);
        LeadMessage::create([
            'lead_id'         => $lead->id,
            'sender'          => 'lead',
            'status'          => 'recibido',
            'content'         => 'Respuesta del lead sembrada',
            'is_status_event' => false,
        ]);
        LeadMessageAttachment::create([
            'lead_message_id'   => $mensaje->id,
            'disk'              => 'local',
            'path'              => 'lead-attachments/sembrado.png',
            'mime'              => 'image/png',
            'original_filename' => 'sembrado.png',
            'size'              => 10,
        ]);
        LeadScheduledMessage::create([
            'lead_id'              => $lead->id,
            'scheduled_send_at'    => now()->addDay(),
            'mode'                 => 'texto',
            'content'              => 'Programado sembrado',
            'status'               => 'pendiente',
            'created_by_admin_id'  => $admin_creador->id,
        ]);
        LeadPersonalizedDemoVideo::create([
            'lead_id'    => $lead->id,
            'title'      => 'Video sembrado',
            'video_url'  => 'https://video.test/sembrado',
            'sort_order' => 1,
        ]);
        LeadPartner::create([
            'lead_id'              => $lead->id,
            'name'                 => 'Socio sembrado',
            'phone'                => '5493410000000',
            'pending_confirmation' => false,
        ]);
        LeadCall::create([
            'lead_id'      => $lead->id,
            'estado'       => 'finalizada',
            'call_summary' => json_encode(['resumen' => 'Resumen sembrado']),
            'transcript'   => 'Transcripción sembrada',
        ]);

        return $lead->fresh();
    }
}
