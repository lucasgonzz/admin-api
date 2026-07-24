<?php

use Illuminate\Support\Facades\Route;

// Auth (Blade)
Route::middleware('guest')->group(function () {
    Route::get('/login', 'Auth\LoginController@showLoginForm')->name('login');
    Route::post('/login', 'Auth\LoginController@login');
});

Route::post('/logout', 'Auth\LoginController@logout')
    ->middleware('auth')
    ->name('logout');

Route::get('/', function () {
    return redirect()->route('versions.index');
});

// Landing pública de la demo por lead (prompt 213): sin login, token = uuid del lead.
// Fuera de los grupos guest/auth a propósito: es pública por diseño.
Route::get('/demo/{uuid}', 'DemoLandingController@show')->name('demo.landing');

Route::get('/recall', function () {
    $lead = \App\Models\Lead::find(254);
    $recall = app(\App\Services\RecallService::class);
    $utterances = $recall->get_transcript('59a05143-6817-4da8-836a-88669718fb97');
    if ($utterances) {
        $text = $recall->format_transcript($utterances);
        app(\App\Services\CallSummaryService::class)->process_transcript_for_lead($lead, $text);
        echo "OK, resumen generado\n";
    } else {
        echo "Sigue sin poder traer la transcripcion, revisar logs\n";
    }
});

// Panel admin (autenticado)
Route::middleware('auth')->group(function () {

    // Versions + subrecursos
    Route::resource('versions', 'VersionController');

    Route::post('versions/{version}/notifications', 'VersionNotificationController@store')->name('versions.notifications.store');
    Route::put('versions/{version}/notifications/{notification}', 'VersionNotificationController@update')->name('versions.notifications.update');
    Route::delete('versions/{version}/notifications/{notification}', 'VersionNotificationController@destroy')->name('versions.notifications.destroy');

    Route::post('versions/{version}/seeders', 'VersionSeederController@store')->name('versions.seeders.store');
    Route::put('versions/{version}/seeders/{seeder}', 'VersionSeederController@update')->name('versions.seeders.update');
    Route::delete('versions/{version}/seeders/{seeder}', 'VersionSeederController@destroy')->name('versions.seeders.destroy');

    Route::post('versions/{version}/commands', 'VersionCommandController@store')->name('versions.commands.store');
    Route::put('versions/{version}/commands/{command}', 'VersionCommandController@update')->name('versions.commands.update');
    Route::delete('versions/{version}/commands/{command}', 'VersionCommandController@destroy')->name('versions.commands.destroy');

    Route::post('versions/{version}/manual-tasks', 'VersionManualTaskController@store')->name('versions.manual-tasks.store');
    Route::put('versions/{version}/manual-tasks/{task}', 'VersionManualTaskController@update')->name('versions.manual-tasks.update');
    Route::delete('versions/{version}/manual-tasks/{task}', 'VersionManualTaskController@destroy')->name('versions.manual-tasks.destroy');

    // Publicar desde vista de versión
    Route::post('versions/{version}/publish', 'PublishVersionController@fromVersion')->name('versions.publish');

    // Clients
    Route::resource('clients', 'ClientController');

    // Leads (prospectos comerciales) + acciones específicas
    Route::resource('leads', 'LeadController');
    Route::post('leads/{lead}/send-presentation-mail', 'LeadController@send_presentation_mail')
        ->name('leads.send_presentation_mail');
    Route::post('leads/{lead}/send-followup-mail', 'LeadController@send_followup_mail')
        ->name('leads.send_followup_mail');
    Route::post('leads/{lead}/run-demo-setup', 'LeadController@run_demo_setup')
        ->name('leads.run_demo_setup');

    // Flujo de promoción Lead → Client + setup del sistema real
    Route::get('leads/{lead}/promote', 'LeadController@promote')
        ->name('leads.promote');
    Route::post('leads/{lead}/promote', 'LeadController@store_promote')
        ->name('leads.store_promote');
    Route::post('leads/{lead}/run-user-setup', 'LeadController@run_user_setup')
        ->name('leads.run_user_setup');
    Route::get('leads/{lead}/preview-demo-mail', 'LeadController@preview_demo_mail')
        ->name('leads.preview_demo_mail');

    // Actualizaciones
    Route::get('updates', 'UpdateController@index')->name('updates.index');
    Route::get('updates/create', 'UpdateController@create')->name('updates.create');
    Route::post('updates', 'UpdateController@store')->name('updates.store');
    Route::get('updates/{update}', 'UpdateController@show')->name('updates.show');
    Route::post('updates/{update}/advance-status', 'UpdateController@advance_status')->name('updates.advance_status');
    Route::post('updates/{update}/mark-step', 'UpdateController@mark_step')->name('updates.mark_step');
    Route::post('updates/{update}/sync', 'UpdateController@sync_to_client')->name('updates.sync');

    // Items hijos de actualización
    Route::post('updates/{update}/seeders/{seeder}/mark', 'UpdateSeederController@mark')->name('updates.seeders.mark');
    Route::post('updates/{update}/commands/{command}/mark', 'UpdateCommandController@mark')->name('updates.commands.mark');
});
