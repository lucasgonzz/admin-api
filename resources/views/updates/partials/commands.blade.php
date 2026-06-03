@if ($upgrade->update_commands->isEmpty())
    <p class="text-muted">Esta versión no tiene comandos asociados.</p>
@else
<table class="table">
    <thead>
        <tr>
            <th style="width: 120px;">Versión</th>
            <th>Comando</th>
            <th>Descripción</th>
            <th class="text-center">Estado</th>
            <th>Ejecutado</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($upgrade->update_commands as $item)
            @php
                $command  = $item->version_command;
                $input_id = 'upd-command-' . $item->id;
                $mark_url = route('updates.commands.mark', [$upgrade->id, $item->id]);
                $csrf     = csrf_token();
            @endphp
            <tr>
                <td>
                    <small class="text-muted">{{ $command && $command->version ? $command->version->version : '—' }}</small>
                </td>
                <td class="copy-cell">
                    <div class="input-group input-group-sm">
                        <input type="text"
                               id="{{ $input_id }}"
                               value="{{ $command ? $command->command : '' }}"
                               class="form-control form-control-sm"
                               readonly>
                        <div class="input-group-append">
                            <button type="button"
                                    class="btn btn-outline-secondary btn-sm"
                                    title="Copiar y marcar como ejecutado"
                                    onclick="adminApiCopyAndMark('{{ $input_id }}', this, '{{ $mark_url }}', '{{ $csrf }}', '#tab-commands')">
                                Copiar
                            </button>
                        </div>
                    </div>
                </td>
                <td><small class="text-muted">{{ $command ? $command->description : '—' }}</small></td>
                <td class="text-center">
                    @if ($item->status === 'exitoso')
                        <span class="badge badge-success">exitoso</span>
                    @elseif ($item->status === 'fallido')
                        <span class="badge badge-danger">fallido</span>
                    @else
                        <span class="badge badge-secondary">pendiente</span>
                    @endif
                </td>
                <td>
                    <small>{{ $item->executed_at ? $item->executed_at->format('d/m/Y H:i') : '—' }}</small>
                    @if ($item->failure_notes)
                        <div><small class="text-danger">{{ $item->failure_notes }}</small></div>
                    @endif
                </td>
                <td class="text-right text-nowrap">
                    @if ($item->status !== 'fallido')
                        <button type="button"
                                class="btn btn-sm btn-outline-danger"
                                data-toggle="collapse"
                                data-target="#fail-command-{{ $item->id }}">
                            Marcar fallido
                        </button>
                    @else
                        <form method="POST" action="{{ $mark_url }}" class="d-inline">
                            @csrf
                            <input type="hidden" name="status" value="exitoso">
                            <button type="submit" class="btn btn-sm btn-outline-success">Marcar exitoso</button>
                        </form>
                        <form method="POST" action="{{ $mark_url }}" class="d-inline">
                            @csrf
                            <input type="hidden" name="status" value="pendiente">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Pendiente</button>
                        </form>
                    @endif
                </td>
            </tr>
            @if ($item->status !== 'fallido')
                <tr class="collapse" id="fail-command-{{ $item->id }}">
                    <td colspan="6" class="bg-light pt-2 pb-2">
                        <form method="POST" action="{{ $mark_url }}" class="form-inline">
                            @csrf
                            <input type="hidden" name="status" value="fallido">
                            <input type="text"
                                   name="failure_notes"
                                   class="form-control form-control-sm mr-2"
                                   style="width: 350px;"
                                   placeholder="Motivo del fallo (opcional)">
                            <button type="submit" class="btn btn-sm btn-danger">Confirmar fallo</button>
                        </form>
                    </td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>
@endif
