<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Marca ComercioCity (correos transaccionales de admin-api)
    |--------------------------------------------------------------------------
    |
    | Valores usados por los partials de emails/commerciocity. Se espera mantener
    | paridad con la config homónima de empresa-api para que la estética sea la
    | misma en ambos orígenes.
    */

    'brand_name' => env('COMMERCIOCITY_BRAND_NAME', 'ComercioCity'),

    // URL absoluta del logo que se muestra en el header del mail.
    'logo_url' => env('COMMERCIOCITY_LOGO_URL', ''),

    // Color principal del header en formato CSS (#hex o nombre).
    'header_background' => env('COMMERCIOCITY_HEADER_BG', '#0068D4'),

    // Enlaces del pie: por defecto apuntan al sitio público y al Instagram.
    'website_url' => env('COMMERCIOCITY_WEBSITE_URL', 'https://comerciocity.com'),
    'instagram_url' => env('COMMERCIOCITY_INSTAGRAM_URL', 'https://instagram.com/comerciocity_com'),

    'website_label' => env('COMMERCIOCITY_WEBSITE_LABEL', 'Sitio web'),
    'instagram_label' => env('COMMERCIOCITY_INSTAGRAM_LABEL', 'Instagram'),

    // Texto legal opcional bajo los enlaces (p. ej. baja de suscripción).
    // Vacío = no se muestra bloque.
    'footer_legal_html' => env('COMMERCIOCITY_FOOTER_LEGAL_HTML', ''),

    /*
    |--------------------------------------------------------------------------
    | Presentador (para el mail "tarjeta de presentación")
    |--------------------------------------------------------------------------
    |
    | Identidad de la persona que "firma" la tarjeta. Se usa como avatar + nombre
    | + rol en el bloque superior del correo.
    */
    'presenter' => [
        'name' => env('COMMERCIOCITY_PRESENTER_NAME', 'Equipo ComercioCity'),
        'role' => env('COMMERCIOCITY_PRESENTER_ROLE', 'Equipo de ventas'),
        'avatar_url' => env('COMMERCIOCITY_PRESENTER_AVATAR_URL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Video de presentación
    |--------------------------------------------------------------------------
    |
    | URL del video que nutre al prospecto antes de la reunión, miniatura que se
    | muestra con overlay de play, y texto debajo. Si video_url está vacío no se
    | renderiza el bloque de video.
    */
    'presentation_video' => [
        'url' => env('COMMERCIOCITY_PRESENTATION_VIDEO_URL', ''),
        'thumbnail_url' => env('COMMERCIOCITY_PRESENTATION_VIDEO_THUMBNAIL_URL', ''),
        'caption' => env('COMMERCIOCITY_PRESENTATION_VIDEO_CAPTION', 'Mirá este video antes de la reunión'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Call to action por defecto de la tarjeta de presentación
    |--------------------------------------------------------------------------
    |
    | Texto y URL del botón principal del mail. Típicamente apunta al WhatsApp
    | del equipo de ventas o a un link de agendamiento.
    */
    'presentation_cta' => [
        'text' => env('COMMERCIOCITY_PRESENTATION_CTA_TEXT', 'Escribinos por WhatsApp'),
        'url' => env('COMMERCIOCITY_PRESENTATION_CTA_URL', 'https://api.whatsapp.com/send?phone=3444622139'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recursos del mail de seguimiento post-reunión
    |--------------------------------------------------------------------------
    |
    | Enlaces y contenidos usados cuando el equipo comercial envía el correo
    | posterior a la reunión del lead.
    */
    'followup_proposal_url' => env('COMMERCIOCITY_FOLLOWUP_PROPOSAL_URL', ''),

    // Video testimonio para reforzar la decisión luego de la reunión.
    'followup_testimonial_video' => [
        'url' => env('COMMERCIOCITY_FOLLOWUP_TESTIMONIAL_VIDEO_URL', ''),
        'thumbnail_url' => env('COMMERCIOCITY_FOLLOWUP_TESTIMONIAL_VIDEO_THUMBNAIL_URL', ''),
        'caption' => env('COMMERCIOCITY_FOLLOWUP_TESTIMONIAL_VIDEO_CAPTION', 'Un cliente que estuvo en tu lugar y hoy recomienda dar el paso.'),
    ],

    // Botón extra bajo el video testimonio (por ejemplo, Instagram del negocio del cliente).
    'followup_testimonial_instagram_cta' => [
        'text' => env('COMMERCIOCITY_FOLLOWUP_TESTIMONIAL_INSTAGRAM_CTA_TEXT', 'Ver Instagram del negocio'),
        'url' => env('COMMERCIOCITY_FOLLOWUP_TESTIMONIAL_INSTAGRAM_CTA_URL', ''),
    ],

    // CTA principal del mail de seguimiento para empujar cierre comercial.
    'followup_cta' => [
        'text' => env('COMMERCIOCITY_FOLLOWUP_CTA_TEXT', 'Quiero empezar'),
        'url' => env('COMMERCIOCITY_FOLLOWUP_CTA_URL', 'https://api.whatsapp.com/send?phone=3444622139'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail 2 - Propuesta (cierre comercial)
    |--------------------------------------------------------------------------
    |
    | Configuración de contenido para el correo de cierre enviado luego de la
    | demo cuando el lead quiere implementar la solución base.
    */
    'proposal_mail' => [
        // CTA principal de conversión a WhatsApp.
        'whatsapp_url' => env('COMMERCIOCITY_PROPOSAL_WHATSAPP_URL', 'https://api.whatsapp.com/send?phone=3444622139'),
        // Vigencia de urgencia en horas para reforzar el cierre.
        'urgency_hours' => (int) env('COMMERCIOCITY_PROPOSAL_URGENCY_HOURS', 48),
        // Identidad del firmante del correo.
        'presenter_name' => env('COMMERCIOCITY_PROPOSAL_PRESENTER_NAME', 'Equipo ComercioCity'),
        'presenter_role' => env('COMMERCIOCITY_PROPOSAL_PRESENTER_ROLE', 'Fundador'),
        // Video explicativo detallado del servicio.
        'detail_video_url' => env('COMMERCIOCITY_PROPOSAL_DETAIL_VIDEO_URL', 'https://drive.google.com/file/d/15F1lB-goQK5J3YfrJdDVDHrQYyFhnCau/view?usp=sharing'),
        // Componentes de pricing usados en el bloque destacado del mail.
        'pricing' => [
            'base_price' => (int) env('COMMERCIOCITY_PROPOSAL_BASE_PRICE', 700),
            'discount_price' => (int) env('COMMERCIOCITY_PROPOSAL_DISCOUNT_PRICE', 500),
            'saving_amount' => (int) env('COMMERCIOCITY_PROPOSAL_SAVING_AMOUNT', 200),
            'installment_amount' => (int) env('COMMERCIOCITY_PROPOSAL_INSTALLMENT_AMOUNT', 350),
        ],
        // Lista de valor del servicio.
        'items' => [
            'Implementación inicial del sistema en tu negocio.',
            'Migración de la información para empezar ordenado.',
            'Capacitación práctica para que puedas usarlo desde el día uno.',
            'Soporte para resolver dudas y acompañarte de por vida.',
            'Análisis de datos para tomar mejores decisiones comerciales.',
        ],
        // Testimonios del mail de propuesta (opcional). Si no definís esta clave, se usan los defaults del helper.
        // Cada elemento: business_name, video_url, instagram_url (máximo 2 tarjetas).
        'testimonials' => [
            [
                'business_name' => 'Innovate Materiales',
                'video_url' => 'https://drive.google.com/file/d/1NbphhaI33hoPd-fYiLlvcClglqsobkVv/view?usp=sharing',
                'instagram_url' => 'https://www.instagram.com/innovate.materiales9dj',
            ],
            [
                'business_name' => 'Pack Descartables',
                'video_url' => 'https://drive.google.com/file/d/1NbphhaI33hoPd-fYiLlvcClglqsobkVv/view?usp=sharing',
                'instagram_url' => 'https://www.instagram.com/packdescartables_',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail de demo (Mail 1 - DEMO)
    |--------------------------------------------------------------------------
    |
    | Variables usadas por LeadDemoMail para notificar al prospecto que su demo
    | está lista. Incluye acceso al sistema, tienda demo y links de tutoriales.
    */
    'demo_mail' => [
        // Contraseña genérica del usuario demo (igual para todos los leads).
        'demo_password' => env('COMMERCIOCITY_DEMO_PASSWORD', '1234'),

        // URL de WhatsApp del equipo para consultas post-mail.
        'whatsapp_url' => env('COMMERCIOCITY_DEMO_WHATSAPP_URL', 'https://api.whatsapp.com/send?phone=3444622139'),

        // Nombre y cargo del firmante del mail.
        'presenter_name' => env('COMMERCIOCITY_DEMO_PRESENTER_NAME', 'Equipo ComercioCity'),
        'presenter_role' => env('COMMERCIOCITY_DEMO_PRESENTER_ROLE', 'Fundador'),

        // Video 1: contexto/intro del servicio (botón destacado).
        'video_intro' => env('COMMERCIOCITY_DEMO_VIDEO_INTRO', 'https://drive.google.com/file/d/15F1lB-goQK5J3YfrJdDVDHrQYyFhnCau/view?usp=sharing'),

        // Videos tutoriales (sección de 4 filas).
        'video_stock'      => env('COMMERCIOCITY_DEMO_VIDEO_STOCK', 'https://drive.google.com/file/d/15F1lB-goQK5J3YfrJdDVDHrQYyFhnCau/view?usp=sharing'),
        'video_ventas'     => env('COMMERCIOCITY_DEMO_VIDEO_VENTAS', 'https://drive.google.com/file/d/15F1lB-goQK5J3YfrJdDVDHrQYyFhnCau/view?usp=sharing'),
        'video_ecommerce'  => env('COMMERCIOCITY_DEMO_VIDEO_ECOMMERCE', 'https://drive.google.com/file/d/15F1lB-goQK5J3YfrJdDVDHrQYyFhnCau/view?usp=sharing'),
        'video_cierre'     => env('COMMERCIOCITY_DEMO_VIDEO_CIERRE', 'https://drive.google.com/file/d/15F1lB-goQK5J3YfrJdDVDHrQYyFhnCau/view?usp=sharing'),
    ],
];
