{{-- Papelera de los módulos con borrado lógico (deleted_at). Solo permite restaurar (RN-26). --}}
@extends('layouts.panel')

@section('title', 'Papelera · '.$page['title'])

@section('breadcrumb')
    <a href="{{ $page['routes']['index'] }}">{{ $page['title'] }}</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">Papelera</span>
@endsection

@section('content')
    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-trash"></i></span>
            <div>
                <h1>Papelera · {{ $page['title'] }}</h1>
                <p>Registros dados de baja. Por trazabilidad no se borran físicamente; puedes restaurarlos.</p>
            </div>
        </div>
        <a href="{{ $page['routes']['index'] }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Volver al listado</a>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        @foreach (array_slice($page['columns'], 0, 3) as $col)
                            <th>{{ $col['label'] }}</th>
                        @endforeach
                        <th>Eliminado</th>
                        <th class="actions"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            @foreach (array_slice($page['columns'], 0, 3) as $col)
                                <td class="{{ $loop->first ? 'strong' : '' }}">{{ data_get($row, $col['key']) ?? '—' }}</td>
                            @endforeach
                            <td class="cell-muted nowrap">{{ $row['deleted_at'] }}</td>
                            <td class="actions">
                                <button class="btn btn-ghost btn-sm" data-restore="{{ str_replace('__ID__', $row['id'], $page['routes']['restore']) }}">
                                    <i class="ph ph-arrow-counter-clockwise"></i> Restaurar
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-row"><i class="ph ph-check-circle"></i> La papelera está vacía.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-restore]');
        if (!btn) return;
        btn.disabled = true;
        try {
            const res = await LotHub.request(btn.dataset.restore, { method: 'POST' });
            LotHub.toast(res.message);
            btn.closest('tr').remove();
        } catch (err) {
            btn.disabled = false;
            LotHub.toast(err.message, 'error');
        }
    });
</script>
@endpush
