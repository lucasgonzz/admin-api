<?php

namespace App\Providers;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\WhatsappAdReferral;
use App\Observers\ConversionesMetaObserver;
use App\Observers\OrigenDesdeElPrimerMensajeObserver;
use App\Observers\OrigenDesdeElReferralObserver;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        Schema::defaultStringLength(191);

        /*
         * El memo de admin_settings es "por request", y en un worker de cola el request dura lo
         * que dure el proceso — horas. Sin este corte, un valor que la web cambió después de que
         * arrancó el worker nunca llegaría: el caso caro es `implementation_form_url`, que
         * ImplementationConversationService::build_form_link_body() mete en el WhatsApp que se le
         * manda al cliente. Cada job arranca con el memo vacío y vuelve a leer de la base.
         */
        Queue::before(function () {
            AdminSetting::flush_memo();
        });

        /*
         * Origen del lead (misión origen-del-lead-y-conversiones-meta, 13/10/2026): se calcula
         * sobre el primer mensaje entrante, desde los dos webhooks que pueden llegar primero.
         * Observers y no renglones en los controladores de webhook a propósito: ver el docblock
         * de cada uno.
         */
        LeadMessage::observe(OrigenDesdeElPrimerMensajeObserver::class);
        WhatsappAdReferral::observe(OrigenDesdeElReferralObserver::class);

        /*
         * Conversiones a Meta (misma misión): cuando un lead de anuncio alcanza una etapa, se
         * registra el evento y se encola su envío. La red de seguridad para lo que este observer
         * no ve es `leads:meta-capi-conciliar`.
         */
        Lead::observe(ConversionesMetaObserver::class);
    }
}
