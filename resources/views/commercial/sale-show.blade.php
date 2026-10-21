{{-- Detalle de una negociación (SaleController::show): compradores, documentos y anulación (CU-15). --}}
@extends('layouts.panel')

@section('title', 'Negociación '.$sale->sale_code)

@section('breadcrumb')
    <a href="{{ route('sales.index') }}">Negociaciones</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">{{ $sale->sale_code }}</span>
@endsection

@section('content')
    @php
        $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
        $user = auth()->user();
        $tone = [1 => 'success', 2 => 'info', 3 => 'danger', 4 => 'danger'][$sale->status_id];
        $statusTone = ['PENDIENTE' => 'warning', 'APROBADA' => 'success', 'RECHAZADA' => 'neutral'];
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-handshake"></i></span>
            <div>
                <h1>{{ $sale->sale_code }} <span class="badge badge-{{ $tone }}" style="vertical-align:middle">{{ $sale->status->name }}</span>
                    @if ($pending) <span class="badge badge-warning" style="vertical-align:middle">Anulación pendiente</span> @endif</h1>
                <p>{{ $sale->lot->project->name }} · {{ $sale->lot->label }} · {{ $sale->sale_date->format('Y-m-d') }}</p>
            </div>
        </div>
        <div class="page-actions">
            <a href="{{ route('sales.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Negociaciones</a>
        </div>
    </div>

    <div class="stats">
        <div class="card stat"><div class="stat-icon"><i class="ph ph-currency-circle-dollar"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ $money($sale->agreed_price) }}</div><div class="stat-label">precio pactado</div></div>
        <div class="card stat"><div class="stat-icon info"><i class="ph ph-tag"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ $money($sale->lot->list_price) }}</div><div class="stat-label">precio de lista actual</div></div>
        <div class="card stat"><div class="stat-icon accent"><i class="ph ph-hand-coins"></i></div>
            <div class="stat-value" style="font-size:1.05rem">{{ $sale->modality->name }}</div><div class="stat-label">modalidad de pago</div></div>
    </div>

    @includeIf('financing.partials.sale-agreement', ['sale' => $sale])

    <div class="option-grid">
        <section class="card">
            <div class="card-head"><h3>Compradores</h3><span class="muted" style="font-size:.85rem">{{ $sale->clients->count() }}</span></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Cliente</th><th>Identificación</th><th class="num">Participación</th></tr></thead>
                    <tbody>
                        @foreach ($sale->clients as $c)
                            <tr>
                                <td class="strong">{{ $c->person->full_name }} @if ($c->pivot->is_primary) <span class="badge badge-info">Titular</span> @endif</td>
                                <td class="cell-muted">{{ $c->person->identificationType?->code }} {{ $c->person->identification_number }}</td>
                                <td class="num">{{ number_format((float) $c->pivot->ownership_pct, 2, ',', '.') }} %</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-body">
                <ul class="list">
                    <li><span class="grow muted">Asesor</span><span>{{ $sale->advisor->display_name }}</span></li>
                    <li><span class="grow muted">Reserva convertida</span>
                        <span>@if ($sale->reservation) <a href="{{ route('reservations.show', $sale->reservation_id) }}">#{{ $sale->reservation_id }}</a> @else — @endif</span></li>
                    @if ($sale->cancellation_reason)
                        <li><span class="grow muted">Motivo de cierre</span><span>{{ $sale->cancellation_reason }}</span></li>
                        <li><span class="grow muted">Aprobado por</span><span>{{ $sale->approver?->display_name }}</span></li>
                    @endif
                </ul>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h3>Documentos soporte</h3></div>
            @if ($sale->documents->isEmpty())
                <div class="card-body muted" style="font-size:.9rem">Sin documentos. Carga la promesa de compraventa y los documentos de identidad.</div>
            @else
                <ul class="list" style="padding:0 1.25rem">
                    @foreach ($sale->documents as $doc)
                        <li>
                            <i class="ph ph-file-text"></i>
                            <span class="grow">{{ $doc->title }} <span class="cell-muted" style="font-size:.8rem">· {{ $doc->type->name }} · {{ $doc->created_at->format('Y-m-d') }}</span></span>
                            @if ($user->hasPermission('documentos.ver'))
                                <a href="{{ route('documents.download', $doc->id) }}" class="icon-btn" title="Descargar"><i class="ph ph-download-simple"></i></a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($user->hasPermission('documentos.cargar'))
                <form method="POST" action="{{ route('sales.documents.store', $sale->id) }}" enctype="multipart/form-data" class="card-body" style="display:grid;gap:.6rem">
                    @csrf
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
                        <select name="document_type" class="select" required aria-label="Tipo de documento">
                            @foreach (['PROMESA' => 'Promesa de compraventa', 'DOC_IDENTIDAD' => 'Documento de identidad', 'ACUERDO_PAGO' => 'Acuerdo de pago firmado', 'OTRO' => 'Otro'] as $code => $name)
                                <option value="{{ $code }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        <input name="title" class="input" placeholder="Título (opcional)" maxlength="150">
                    </div>
                    <input type="file" name="file" class="input" style="padding:.55rem .7rem;height:auto" accept=".pdf,.jpg,.jpeg,.png,.webp" data-max-bytes="10485760" required>
                    <button class="btn btn-ghost btn-sm" style="justify-self:start"><i class="ph ph-upload-simple"></i> Cargar documento</button>
                </form>
            @endif
        </section>
    </div>

    {{-- CU-15: solicitud (Asesor) y aprobación (Director) de anulación o desistimiento --}}
    @if ($sale->isActive() && ($pending ? $user->hasPermission('negociaciones.aprobar_anulacion') : $user->hasPermission('negociaciones.solicitar_anulacion')))
        <section class="card form-card">
            @if ($pending)
                <div class="card-head"><h3>Solicitud de {{ mb_strtolower($pending->type_label) }} pendiente</h3><span class="badge badge-warning">Pendiente</span></div>
                <form method="POST" action="{{ route('sales.cancellation.resolve', [$sale->id, $pending->id]) }}" class="card-body" id="resolve-form">
                    @csrf
                    <p style="margin-bottom:.8rem"><strong>{{ $pending->requester->display_name }}</strong> solicitó el {{ $pending->requested_at->format('Y-m-d H:i') }}: «{{ $pending->reason }}»</p>
                    @if ($appliedPayments > 0)
                        <div class="alert alert-error"><i class="ph ph-warning-circle"></i>
                            <div>La obligación tiene abonos aplicados por {{ $money($appliedPayments) }}. Para aprobar, describe su tratamiento (devolución o penalidad).</div></div>
                    @endif
                    <div class="field">
                        <label for="notes">Observaciones {{ $appliedPayments > 0 ? '(tratamiento de los abonos) *' : '' }}</label>
                        <textarea id="notes" name="notes" rows="2" class="textarea" maxlength="500">{{ old('notes') }}</textarea>
                        <div class="help">Obligatorias para rechazar. Al aprobar, la negociación se cierra, la obligación se anula y el lote vuelve a DISPONIBLE (RN-08).</div>
                    </div>
                    <div style="display:flex;gap:.6rem">
                        <button name="decision" value="approve" class="btn btn-danger"><i class="ph ph-check"></i> Aprobar</button>
                        <button name="decision" value="reject" class="btn btn-ghost"><i class="ph ph-x"></i> Rechazar</button>
                    </div>
                </form>
            @else
                <div class="card-head"><h3>Solicitar anulación o desistimiento</h3></div>
                <form method="POST" action="{{ route('sales.cancellation.request', $sale->id) }}" class="card-body">
                    @csrf
                    <div class="form-grid">
                        <div class="field w-third">
                            <label for="request_type">Tipo <span class="req">*</span></label>
                            <select id="request_type" name="request_type" class="select" required>
                                @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>
                        <div class="field w-two-thirds">
                            <label for="reason">Motivo <span class="req">*</span></label>
                            <input id="reason" name="reason" class="input @error('reason') is-invalid @enderror" minlength="10" maxlength="255" value="{{ old('reason') }}" required>
                            @error('reason') <div class="error">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <p class="muted" style="font-size:.85rem;margin:.3rem 0 .8rem">La solicitud queda pendiente hasta que el Director comercial la apruebe o la rechace.</p>
                    <button class="btn btn-ghost"><i class="ph ph-paper-plane-tilt"></i> Enviar solicitud</button>
                </form>
            @endif
        </section>
    @endif

    @if ($sale->cancellationRequests->where('status', '!=', 'PENDIENTE')->isNotEmpty())
        <section class="card">
            <div class="card-head"><h3>Historial de solicitudes</h3></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Fecha</th><th>Tipo</th><th>Motivo</th><th>Solicitó</th><th>Resolución</th></tr></thead>
                    <tbody>
                        @foreach ($sale->cancellationRequests->where('status', '!=', 'PENDIENTE') as $req)
                            <tr>
                                <td class="nowrap">{{ $req->requested_at->format('Y-m-d') }}</td>
                                <td>{{ $req->type_label }}</td>
                                <td>{{ $req->reason }}</td>
                                <td class="cell-muted">{{ $req->requester->display_name }}</td>
                                <td><span class="badge badge-{{ $statusTone[$req->status] }}">{{ ucfirst(mb_strtolower($req->status)) }}</span>
                                    <span class="cell-muted" style="font-size:.8rem">{{ $req->resolver?->display_name }} · {{ $req->resolution_notes }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection

@push('scripts')
<script>
    document.getElementById('resolve-form')?.addEventListener('submit', async (e) => {
        const button = e.submitter;
        if (e.target.dataset.confirmed || button?.value !== 'approve') return;
        e.preventDefault();
        const ok = await LotHub.confirm({ title: 'Aprobar solicitud', message: 'La negociación se cerrará y el lote volverá a estar disponible. Esta acción no se puede deshacer.', confirmText: 'Aprobar', danger: true });
        if (ok) {
            e.target.dataset.confirmed = '1';
            const decision = Object.assign(document.createElement('input'), { type: 'hidden', name: 'decision', value: 'approve' });
            e.target.append(decision);
            e.target.submit();
        }
    });
</script>
@endpush
