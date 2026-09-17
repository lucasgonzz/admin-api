<?php

namespace App\Providers;

use App\Models\AdminSetting;
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
    }
}
