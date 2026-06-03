@extends('layouts.app')

@section('title', 'Actualización #'.$upgrade->id)

@section('content')
{{-- Encabezado --}}
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <div class="mb-1">
            <a href="{{ route('updates.index') }}" class="text-muted">&larr; Actualizaciones</a>
        </div>
        <h3 class="mb-0">
            Actualización #{{ $upgrade->id }}
            @php
                $badge = [
                    'pendiente'             => 'badge-secondary',
                    'listo_para_actualizar' => 'badge-info',
                    'actualizandose'        => 'badge-warning',
                    'terminada'             => 'badge-success',
                    'fallida'               => 'badge-danger',
                ][$upgrade->status] ?? 'badge-light';
            @endphp
            <span class="badge {{ $badge }} status-badge">{{ $status_labels[$upgrade->status] ?? $upgrade->status }}</span>
        </h3>
        <small class="text-muted">
            {{ $upgrade->client ? $upgrade->client->name : '-' }}
            &rarr; {{ $upgrade->to_version ? $upgrade->to_version->version : '-' }}
            @if($upgrade->from_version)
                (desde {{ $upgrade->from_version->version }})
            @endif
        </small>
    </div>
    <div class="d-flex flex-column align-items-end">
        @if ($next_status)
            <form method="POST" action="{{ route('updates.advance_status', $upgrade->id) }}" class="mb-1">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm">
                    Avanzar a "{{ $status_labels[$next_status] }}"
                </button>
            </form>
        @endif

        @if (in_array($upgrade->status, ['actualizandose', 'listo_para_actualizar', 'fallida']))
            <form method="POST" action="{{ route('updates.sync', $upgrade->id) }}"
                  onsubmit="return confirm('¿Sincronizar la versión al cliente ahora?')">
                @csrf
                <button type="submit" class="btn btn-success btn-sm">
                    Sincronizar al cliente
                </button>
            </form>
        @endif
    </div>
</div>

{{-- Info rápida --}}
<div class="card p-3 mb-3">
    <div class="row">
        <div class="col-md-3">
            <small class="text-muted d-block">Cliente</small>
            @if($upgrade->client)
                <a href="{{ route('clients.show', $upgrade->client->id) }}">{{ $upgrade->client->name }}</a>
            @else
                —
            @endif
        </div>
        <div class="col-md-2">
            <small class="text-muted d-block">Versión origen</small>
            {{ $upgrade->from_version ? $upgrade->from_version->version : '—' }}
        </div>
        <div class="col-md-2">
            <small class="text-muted d-block">Versión destino</small>
            {{ $upgrade->to_version ? $upgrade->to_version->version : '—' }}
        </div>
        <div class="col-md-2">
            <small class="text-muted d-block">Sincronizado</small>
            {{ $upgrade->synced_at ? $upgrade->synced_at->format('d/m/Y H:i') : '—' }}
        </div>
        <div class="col-md-3">
            <small class="text-muted d-block">Creada por</small>
            {{ $upgrade->created_by_admin ? $upgrade->created_by_admin->name : '—' }}
            <small class="text-muted d-block">{{ $upgrade->created_at->format('d/m/Y H:i') }}</small>
        </div>
    </div>
    @if($upgrade->notes)
        <hr class="my-2">
        <small class="text-muted d-block">Notas</small>
        <div>{{ $upgrade->notes }}</div>
    @endif
</div>

{{-- Tabs --}}
<ul class="nav nav-tabs" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" data-toggle="tab" href="#tab-steps">
            Pasos
            @php
                $steps_done = collect([
                    $upgrade->sistema_actualizado_at,
                    $upgrade->migraciones_corridas_at,
                    $upgrade->crons_supervisor_at,
                    $upgrade->seeders_ejecutados_at,
                    $upgrade->comandos_ejecutados_at,
                    $upgrade->sistema_configurado_at,
                ])->filter()->count();
            @endphp
            <span class="badge badge-light">{{ $steps_done }}/6</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-toggle="tab" href="#tab-seeders">
            Seeders
            <span class="badge badge-light">{{ $upgrade->update_seeders->count() }}</span>
            @if($upgrade->update_seeders->contains('status', 'fallido'))
                <span class="badge badge-danger">!</span>
            @endif
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-toggle="tab" href="#tab-commands">
            Comandos
            <span class="badge badge-light">{{ $upgrade->update_commands->count() }}</span>
            @if($upgrade->update_commands->contains('status', 'fallido'))
                <span class="badge badge-danger">!</span>
            @endif
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-toggle="tab" href="#tab-tasks">
            Tareas manuales
            <span class="badge badge-light">{{ $aggregatedManualTasks->count() }}</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-toggle="tab" href="#tab-notifications">
            Notificaciones
            <span class="badge badge-light">{{ $aggregatedNotifications->count() }}</span>
        </a>
    </li>
</ul>

<div class="tab-content p-3 bg-white border border-top-0 rounded-bottom">
    <div class="tab-pane fade show active" id="tab-steps">
        @include('updates.partials.steps', ['upgrade' => $upgrade])
    </div>
    <div class="tab-pane fade" id="tab-seeders">
        @include('updates.partials.seeders', ['upgrade' => $upgrade])
    </div>
    <div class="tab-pane fade" id="tab-commands">
        @include('updates.partials.commands', ['upgrade' => $upgrade])
    </div>
    <div class="tab-pane fade" id="tab-tasks">
        @include('updates.partials.manual_tasks', [
            'aggregatedManualTasks' => $aggregatedManualTasks,
        ])
    </div>
    <div class="tab-pane fade" id="tab-notifications">
        @include('updates.partials.notifications', [
            'aggregatedNotifications' => $aggregatedNotifications,
            'readsByNotificationId' => $readsByNotificationId,
        ])
    </div>
</div>

@push('scripts')
<script>
(function () {
    var hash = window.location.hash;
    if (hash) {
        var tab = document.querySelector('[href="' + hash + '"]');
        if (tab) { $(tab).tab('show'); }
    }
})();
</script>
<script>
/**
 * Copia el contenido de un input al portapapeles Y envía un POST para marcar
 * el seeder/comando como exitoso en esta actualización.
 * tabFragment: fragmento de tab al que volver tras recargar (ej: '#tab-seeders')
 */
window.adminApiCopyAndMark = function (inputId, btn, markUrl, csrfToken, tabFragment) {
    var el = document.getElementById(inputId);
    if (!el) return;
    var text = el.value !== undefined ? String(el.value) : '';
    if (!text) return;

    var markDone = function () {
        fetch(markUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: '_token=' + encodeURIComponent(csrfToken) + '&status=exitoso',
        }).then(function () {
            // ?_= fuerza GET nuevo: si la URL solo cambia el hash, el navegador no recarga el HTML.
            location.href = location.pathname + '?_=' + Date.now() + (tabFragment || '');
        });
    };

    var copyDone = function () {
        if (!btn) { markDone(); return; }
        if (!btn.getAttribute('data-original-label')) {
            btn.setAttribute('data-original-label', btn.textContent.trim());
        }
        var orig = btn.getAttribute('data-original-label');
        btn.textContent = 'Copiado';
        btn.classList.add('btn-success');
        btn.classList.remove('btn-outline-secondary');
        setTimeout(function () {
            btn.textContent = orig;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }, 1500);
        markDone();
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(copyDone).catch(function () {
            fallbackCopy(el, copyDone);
        });
    } else {
        fallbackCopy(el, copyDone);
    }
};

function fallbackCopy(el, done) {
    el.focus();
    el.select();
    el.setSelectionRange(0, 99999);
    try { document.execCommand('copy'); } catch (e) {}
    if (done) done();
}
</script>
@endpush
@endsection
