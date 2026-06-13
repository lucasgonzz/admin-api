<?php

namespace App\ModelProperties;

use App\Models\LeadPipelineStatus;

/**
 * Propiedades declarativas del recurso Lead para admin-spa.
 *
 * Segmentación del formulario (modal): incluir filas solo con `group_title` (sin `key`)
 * para definir secciones. El admin-spa muestra un tablist con esos títulos y filtra campos.
 * Ejemplo:
 *   [ 'group_title' => 'Datos de contacto' ],
 *   [ 'key' => 'email', ... ],
 */
class LeadProperties
{
    /**
     * Retorna el esquema de columnas/campos consumido por MetaController.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all()
    {
        return [
            // [
            //     'key' => 'id',
            //     'text' => 'N°',
            //     'type' => 'number',
            //     'value' => null,
            //     'show' => true,
            //     'only_show' => true,
            //     'exclude_on_update' => true,
            //     'use_to_filter_in_search' => true,
            //     'width' => 64,
            // ],

            [
                'group_title'   => 'Basico'
            ],
            [
                'key' => 'status',
                'text' => 'Estado',
                'type' => 'pipeline_status',
                'value' => 'nuevo',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 150,
                'options' => LeadPipelineStatus::options_for_meta(),
            ],
            [
                'key' => 'tiene_sugerencia_pendiente',
                'text' => 'IA pendiente',
                'type' => 'checkbox',
                'value' => false,
                'show' => true,
                'only_show' => true,
                'width' => 90,
            ],
            [
                'key' => 'requiere_seguimiento',
                'text' => 'Seguimiento',
                'type' => 'checkbox',
                'value' => false,
                'show' => true,
                'only_show' => true,
                'width' => 100,
            ],
            [
                'key' => 'tiene_seguimiento_sin_ver',
                'text' => 'Alerta seg.',
                'type' => 'alert_badge',
                'value' => false,
                'show' => true,
                'table_only' => true,
                'width' => 110,
                // Clave del row que aporta la cantidad de seguimientos enviados para el badge.
                'badge_count_key' => 'followup_count',
            ],
            [
                /* Badge per-usuario: mensajes del lead sin leer para el admin logueado (no persistido; viene de withCount). */
                'key' => 'unread_count',
                'text' => 'Sin leer',
                'type' => 'unread_badge',
                'value' => 0,
                'show' => true,
                'table_only' => true,
                'only_show' => true,
                'exclude_on_update' => true,
                'not_persisted_on_model' => true,
                'width' => 90,
            ],
            [
                'key' => 'contact_name',
                'text' => 'Nombre',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 170,
            ],
            [
                'key' => 'phone',
                'text' => 'Teléfono',
                'type' => 'text',
                'value' => '',
                'use_to_filter_in_search' => true,
                'width' => 140,
            ],
            [
                'key' => 'email',
                'text' => 'Email',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 220,
            ],
            [
                'key' => 'company_name',
                'text' => 'Empresa',
                'type' => 'text',
                'value' => '',
                'show' => true,
                'use_to_filter_in_search' => true,
                'width' => 180,
            ],
            [
                'key' => 'doc_number',
                'text' => 'Documento',
                'type' => 'text',
                'value' => '',
            ],
            [
                'key' => 'notes',
                'text' => 'Notas',
                'type' => 'textarea',
                'value' => '',
                'not_show_on_table' => true,
            ],

            // [
            //     'group_title'   => 'Reunion'
            // ],
            // [
            //     'key' => 'meeting_scheduled_at',
            //     'text' => 'Reunión',
            //     'type' => 'date',
            //     'value' => null,
            //     'width' => 170,
            // ],
            // [
            //     'key' => 'notes',
            //     'text' => 'Notas',
            //     'type' => 'textarea',
            //     'value' => '',
            //     'not_show_on_table' => true,
            // ],



            [
                'group_title'   => 'Demo'
            ],
            [
                /* Resumen del lead generado por Claude antes del fin de la demo; solo lectura. */
                'key'              => 'demo_summary',
                'text'             => 'Resumen del lead (IA)',
                'type'             => 'textarea',
                'value'            => null,
                'only_show'        => true,
                'not_show_on_table' => true,
                'exclude_on_update' => true,
            ],
            [
                'key' => 'demo_id',
                'text' => 'Demo asignada',
                'type' => 'select',
                'relation' => 'demo',
                'relation_label' => 'erp_spa_url',
                'value' => null,
                'width' => 220,
            ],
            [
                'key' => 'demo_date',
                'text' => 'Fecha demo',
                'type' => 'day',
                'value' => null,
                'width' => 170,
                'show'  => true,
            ],
            [
                'key' => 'demo_start_time',
                'text' => 'Hora inicio demo',
                'type' => 'text',
                'value' => '',
                'width' => 150,
                'show'  => true,
            ],
            [
                'key' => 'demo_end_time',
                'text' => 'Hora fin demo',
                'type' => 'text',
                'value' => '',
                'width' => 150,
                'show'  => true,
            ],
            [
                'key' => 'personalized_demo_videos',
                'text' => 'Videos tutoriales personalizados (mail demo)',
                'type' => 'custom',
                'custom_component' => 'lead_personalized_demo_videos',
                'not_persisted_on_model' => true,
                'not_show_on_table' => true,
                'full_width' => true,
                'value' => [],
            ],
            // [
            //     'key' => 'business_type',
            //     'text' => 'Tipo de negocio',
            //     'type' => 'select',
            //     'value' => 'ferreteria',
            //     'width' => 150,
            //     'options' => [
            //         ['value' => 'ferreteria', 'text' => 'Ferretería - otro'],
            //         ['value' => 'ropa', 'text' => 'Tienda de ropa'],
            //     ],
            // ],
            // [
            //     'key' => 'user_name',
            //     'text' => 'Usuario demo',
            //     'type' => 'text',
            //     'value' => '',
            //     'show' => true,
            //     'width' => 150,
            // ],
            // [
            //     'key' => 'total_a_pagar',
            //     'text' => 'Total a pagar',
            //     'type' => 'text',
            //     'value' => '',
            // ],
            [
                'key' => 'use_deposits',
                'text' => 'Usa depósitos',
                'type' => 'checkbox',
                'value' => false,
            ],
            [
                'key' => 'address_1',
                'text' => 'Sucursal 1',
                'type' => 'text',
                'value' => '',
            ],
            [
                'key' => 'address_2',
                'text' => 'Sucursal 2',
                'type' => 'text',
                'value' => '',
            ],
            [
                'key' => 'address_3',
                'text' => 'Sucursal 3',
                'type' => 'text',
                'value' => '',
            ],
            [
                'key' => 'use_price_lists',
                'text' => 'Usa listas de precios',
                'type' => 'checkbox',
                'value' => false,
            ],
            [
                'key' => 'price_type_1',
                'text' => 'Lista de precio 1',
                'type' => 'text',
                'value' => '',
            ],
            [
                'key' => 'price_type_2',
                'text' => 'Lista de precio 2',
                'type' => 'text',
                'value' => '',
            ],
            [
                'key' => 'price_type_3',
                'text' => 'Lista de precio 3',
                'type' => 'text',
                'value' => '',
            ],
            // [
            //     'key' => 'iva_included',
            //     'text' => 'IVA incluido',
            //     'type' => 'checkbox',
            //     'value' => false,
            // ],
            // [
            //     'key' => 'ventas_con_fecha_de_entrega',
            //     'text' => 'Ventas con fecha de entrega',
            //     'type' => 'checkbox',
            //     'value' => false,
            // ],
            // [
            //     'key' => 'cajas',
            //     'text' => 'Usa cajas',
            //     'type' => 'checkbox',
            //     'value' => false,
            // ],
            // [
            //     'key' => 'usar_codigos_de_barra',
            //     'text' => 'Usar códigos de barra',
            //     'type' => 'checkbox',
            //     'value' => false,
            // ],
            // [
            //     'key' => 'codigos_de_barra_por_defecto',
            //     'text' => 'Códigos de barra por defecto',
            //     'type' => 'checkbox',
            //     'value' => false,
            // ],
            // [
            //     'key' => 'consultora_de_precios',
            //     'text' => 'Consultora de precios',
            //     'type' => 'checkbox',
            //     'value' => false,
            // ],
            // [
            //     'key' => 'imagenes',
            //     'text' => 'Imágenes',
            //     'type' => 'checkbox',
            //     'value' => false,
            // ],
            // [
            //     'key' => 'produccion',
            //     'text' => 'Producción',
            //     'type' => 'checkbox',
            //     'value' => false,
            // ],
            // [
            //     'key' => 'ask_amount_in_vender',
            //     'text' => 'Pedir cantidad en Vender',
            //     'type' => 'checkbox',
            //     'value' => false,
            // ],
            // [
            //     'key' => 'redondear_centenas_en_vender',
            //     'text' => 'Redondear centenas',
            //     'type' => 'checkbox',
            //     'value' => false,
            // ],
            // [
            //     'key' => 'omitir_cuentas_corrientes',
            //     'text' => 'Omitir cuentas corrientes',
            //     'type' => 'checkbox',
            //     'value' => false,
            // ],


            // [
            //     'group_title'   => 'Sistema'
            // ],
            // [
            //     'key' => 'user_id',
            //     'text' => 'User ID base',
            //     'type' => 'number',
            //     'value' => null,
            //     'width' => 120,
            // ],
            // // [
            // //     'key' => 'target_client_id',
            // //     'text' => 'Cliente',
            // //     'type' => 'select',
            // //     'width' => 190,
            // //     'only_show' => true,
            // // ],
            // [
            //     'key' => 'promoted_client_id',
            //     'text' => 'Cliente promovido',
            //     'type' => 'search',
            //     'relation' => 'client',
            //     'relation_label' => 'name',
            //     'value' => null,
            //     'only_show' => true,
            //     'width' => 190,
            // ],
            // [
            //     'key' => 'api_url',
            //     'text' => 'API URL sistema productivo',
            //     'type' => 'text',
            //     'value' => '',
            //     'not_show_on_table' => true,
            //     'width' => 280,
            // ],

            
            // [
            //     'key' => 'presentation_mail_sent_at',
            //     'text' => 'Mail presentación',
            //     'type' => 'date',
            //     'value' => null,
            //     'only_show' => true,
            //     'width' => 170,
            // ],
            // [
            //     'key' => 'followup_mail_sent_at',
            //     'text' => 'Mail seguimiento',
            //     'type' => 'date',
            //     'value' => null,
            //     'only_show' => true,
            //     'width' => 170,
            // ],
            // [
            //     'key' => 'demo_mail_sent_at',
            //     'text' => 'Mail demo',
            //     'type' => 'date',
            //     'value' => null,
            //     'only_show' => true,
            //     'width' => 170,
            // ],
            // [
            //     'key' => 'demo_mail_last_error',
            //     'text' => 'Error mail demo',
            //     'type' => 'textarea',
            //     'value' => '',
            //     'only_show' => true,
            // ],
            // [
            //     'key' => 'demo_setup_status',
            //     'text' => 'Estado demo',
            //     'type' => 'text',
            //     'value' => '',
            //     'only_show' => true,
            //     'width' => 130,
            // ],
            // [
            //     'key' => 'demo_setup_last_error',
            //     'text' => 'Error demo',
            //     'type' => 'textarea',
            //     'value' => '',
            //     'only_show' => true,
            // ],
            // [
            //     'key' => 'demo_setup_last_run_at',
            //     'text' => 'Última demo',
            //     'type' => 'date',
            //     'value' => null,
            //     'only_show' => true,
            //     'width' => 170,
            // ],
            // [
            //     'key' => 'user_setup_status',
            //     'text' => 'Estado sistema real',
            //     'type' => 'text',
            //     'value' => '',
            //     'only_show' => true,
            //     'width' => 150,
            // ],
            // [
            //     'key' => 'user_setup_last_error',
            //     'text' => 'Error sistema real',
            //     'type' => 'textarea',
            //     'value' => '',
            //     'only_show' => true,
            // ],
            // [
            //     'key' => 'user_setup_last_run_at',
            //     'text' => 'Último sistema real',
            //     'type' => 'date',
            //     'value' => null,
            //     'only_show' => true,
            //     'width' => 180,
            // ],

            // [
            //     'group_title'   => 'Contrato',
            // ],
            // [
            //     'key' => 'contract_client_name',
            //     'text' => 'Contrato — nombre comercial',
            //     'type' => 'text',
            //     'value' => null,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_client_razon_social',
            //     'text' => 'Contrato — razón social cliente',
            //     'type' => 'text',
            //     'value' => null,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_client_cuit',
            //     'text' => 'Contrato — CUIT cliente',
            //     'type' => 'text',
            //     'value' => null,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_currency',
            //     'text' => 'Contrato — moneda pago único',
            //     'type' => 'text',
            //     'value' => null,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_precio_licencia',
            //     'text' => 'Contrato — precio licencia',
            //     'type' => 'text',
            //     'value' => null,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_fecha_emision',
            //     'text' => 'Contrato — fecha emisión',
            //     'type' => 'day',
            //     'value' => null,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_fecha_primer_pago_unico',
            //     'text' => 'Contrato — fecha primer pago único',
            //     'type' => 'day',
            //     'value' => null,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_financiacion',
            //     'text' => 'Contrato — financiación (cuotas)',
            //     'type' => 'text',
            //     'value' => null,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_mensualidad_moneda',
            //     'text' => 'Contrato — moneda mensualidad',
            //     'type' => 'text',
            //     'value' => null,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_mensualidad_base',
            //     'text' => 'Contrato — mensualidad base',
            //     'type' => 'text',
            //     'value' => null,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_usuarios_incluidos',
            //     'text' => 'Contrato — usuarios incluidos',
            //     'type' => 'number',
            //     'value' => null,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_usuarios_extra',
            //     'text' => 'Contrato — usuarios extra',
            //     'type' => 'number',
            //     'value' => 0,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_precio_usuario_extra',
            //     'text' => 'Contrato — precio usuario extra',
            //     'type' => 'text',
            //     'value' => null,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_perfiles_ecommerce',
            //     'text' => 'Contrato — perfiles ecommerce',
            //     'type' => 'number',
            //     'value' => 0,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_precio_perfil_ecommerce',
            //     'text' => 'Contrato — precio perfil ecommerce',
            //     'type' => 'text',
            //     'value' => null,
            //     'show' => false,
            // ],
            // [
            //     'key' => 'contract_fecha_primer_pago_mensual',
            //     'text' => 'Contrato — fecha primer pago mensual',
            //     'type' => 'day',
            //     'value' => null,
            //     'show' => false,
            // ],
        ];
    }
}
