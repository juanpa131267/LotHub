{{-- Detalle de una obligación: plan de cuotas con saldo calculado, acuerdo vigente y versiones (CU-13, CU-14, CU-16). --}}
@extends('layouts.panel')

@section('title', 'Obligación '.$obligation->obligation_code)

@section('breadcrumb')
    <a href="{{ route('obligations.index') }}">Obligaciones</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">{{ $obligation->obligation_code }}</span>
@endsection

@section('content')
    @php
        $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
        $agreement = $obligation->agreement;
        $user = auth()->user();
        $statusTone = ['PAGADA' => 'success', 'VENCIDA' => 'danger', 'PAGO_PARCIAL' => 'warning', 'PENDIENTE' => 'neutral'];
        $statusLabel = ['PAGADA' => 'Pagada', 'VENCIDA' => 'Vencida', 'PAGO_PARCIAL' => 'Pago parcial', 'PENDIENTE' => 'Pendiente'];
        $state = $obligation->isActive() ? ($summary->overdue_installments ? ['En mora', 'danger'] : ['Al día', 'success']) : [$obligation->status->name, 'neutral'];
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-receipt"></i></span>
            <div>
                <h1>{{ $obligation->obligation_code }} <span class="badge badge-{{ $state[1] }}" style="vertical-align:middle">{{ $state[0] }}</span></h1>
                <p><a href="{{ route('sales.show', $sale->id) }}">{{ $sale->sale_code }}</a> · {{ $sale->lot->project->name }} · {{ $sale->lot->label }} ·
                    {{ $sale->primaryClient()?->person->full_name }}</p>
            </div>
        </div>
        <div class="page-actions">
            <a href="{{ route('obligations.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Obligaciones</a>
            @if ($obligation->isActive() && Route::has('payments.create-for') && $user->hasPermission('abonos.crear'))
                <a href="{{ route('payments.create-for', $obligation->id) }}" class="btn btn-primary"><i class="ph ph-plus"></i> Registrar abono</a>
            @endif
            @if ($user->hasPermission('estados_cuenta.ver'))
                <form method="POST" action="{{ route('account-statements.generate', $obligation->id) }}">
                    @csrf
                    <button class="btn btn-ghost"><i class="ph ph-file-pdf"></i> Estado de cuenta</button>
                </form>
            @endif
            @if ($obligation->isActive() && $summary->balance > 0 && $user->hasPermission('acuerdos.reestructurar'))
                <a href="{{ route('obligations.restructure', $obligation->id) }}" class="btn btn-ghost"><i class="ph ph-arrows-clockwise"></i> Reestructurar</a>
            @endif
        </div>
    </div>

    <div class="stats">
        <div class="card stat"><div class="stat-icon"><i class="ph ph-wallet"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ $money($summary->balance) }}</div><div class="stat-label">saldo pendiente</div></div>
        <div class="card stat"><div class="stat-icon accent"><i class="ph ph-check-circle"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ $money($summary->total_paid) }}</div><div class="stat-label">pagado de {{ $money($summary->total_due) }}</div></div>
        <div class="card stat"><div class="stat-icon {{ $summary->overdue_installments ? 'warn' : 'info' }}"><i class="ph ph-warning"></i></div>
            <div class="stat-value">{{ $summary->overdue_installments }}</div><div class="stat-label">cuotas vencidas · máx. {{ $summary->max_days_overdue }} días de mora</div></div>
        <div class="card stat"><div class="stat-icon info"><i class="ph ph-calendar"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ $obligation->isActive() ? ($summary->next_due_date ?? '—') : '—' }}</div><div class="stat-label">próximo vencimiento</div></div>
    </div>

    @includeIf('payments.partials.obligation-payments', ['obligation' => $obligation])

    <section class="card">
        <div class="card-head"><h3>Plan de cuotas</h3><span class="muted" style="font-size:.85rem">Saldo y estado calculados en línea (v_installment_balance)</span></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Cuota</th><th>Vence</th><th class="num">Valor</th><th class="num">Pagado</th><th class="num">Saldo</th><th class="num">Días de mora</th><th>Estado</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ (int) $row->installment_number === 0 ? 'Inicial' : $row->installment_number }}</td>
                            <td class="nowrap">{{ $row->due_date }}</td>
                            <td class="num nowrap">{{ $money($row->amount_due) }}</td>
                            <td class="num nowrap">{{ $money($row->amount_paid) }}</td>
                            <td class="num nowrap strong">{{ $money(max(0, $row->balance)) }}</td>
                            <td class="num">{{ $row->days_overdue ?: '—' }}</td>
                            <td><span class="badge badge-{{ $statusTone[$row->computed_status] }}">{{ $statusLabel[$row->computed_status] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="option-grid">
        <section class="card">
            <div class="card-head"><h3>Acuerdo v{{ $agreement->version }}</h3>
                <span class="badge badge-{{ $agreement->signed_at ? 'success' : 'warning' }}">{{ $agreement->signed_at ? 'Firmado '.$agreement->signed_at->format('Y-m-d') : 'Firma pendiente' }}</span></div>
            <div class="card-body">
                <ul class="list">
                    <li><span class="grow muted">{{ $agreement->version > 1 ? 'Abono inicial' : 'Cuota inicial' }}</span><span>{{ $money($agreement->down_payment) }}</span></li>
                    <li><span class="grow muted">Capital financiado</span><strong>{{ $money($agreement->financed_amount) }}</strong></li>
                    <li><span class="grow muted">Cuotas</span><span>{{ $agreement->installments_count }} · {{ $agreement->frequency->name }} desde {{ $agreement->first_due_date->format('Y-m-d') }}</span></li>
                    <li><span class="grow muted">Tasa corriente / mora</span><span>{{ rtrim(rtrim(number_format((float) $agreement->monthly_interest_rate, 4, ',', '.'), '0'), ',') }} % · {{ rtrim(rtrim(number_format((float) $agreement->late_interest_rate, 4, ',', '.'), '0'), ',') }} % mensual</span></li>
                    <li><span class="grow muted">Definido por</span><span>{{ $agreement->creator->display_name }} · {{ $agreement->created_at->format('Y-m-d') }}</span></li>
                    @if ($agreement->restructure_reason)
                        <li><span class="grow muted">Motivo de reestructuración</span><span>{{ $agreement->restructure_reason }}</span></li>
                    @endif
                </ul>
            </div>
            @if (! $agreement->signed_at && $obligation->isActive() && $user->hasPermission('acuerdos.crear'))
                <form method="POST" action="{{ route('agreements.sign', $agreement->id) }}" enctype="multipart/form-data" class="card-body" style="border-top:1px solid var(--line);display:grid;gap:.6rem">
                    @csrf
                    <strong style="font-size:.9rem">Registrar firma (CU-13 paso 5)</strong>
                    <div style="display:grid;grid-template-columns:auto 1fr;gap:.6rem">
                        <input type="date" name="signed_at" class="input" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required aria-label="Fecha de firma">
                        <input type="file" name="signed_file" class="input" style="padding:.55rem .7rem;height:auto" accept=".pdf,.jpg,.jpeg,.png,.webp" data-max-bytes="10485760" aria-label="Acuerdo firmado">
                    </div>
                    <button class="btn btn-ghost btn-sm" style="justify-self:start"><i class="ph ph-signature"></i> Registrar firma</button>
                </form>
            @endif
        </section>

        <section class="card">
            <div class="card-head"><h3>Versiones del acuerdo</h3></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Versión</th><th>Obligación</th><th class="num">Capital</th><th>Cuotas</th><th>Estado</th></tr></thead>
                    <tbody>
                        @foreach ($versions as $v)
                            <tr>
                                <td>v{{ $v->version }} <span class="cell-muted" style="font-size:.8rem">{{ $v->created_at->format('Y-m-d') }}</span></td>
                                <td>@if ($v->obligation)<a href="{{ route('obligations.show', $v->obligation->id) }}"><code>{{ $v->obligation->obligation_code }}</code></a>@endif</td>
                                <td class="num nowrap">{{ $money($v->down_payment + $v->financed_amount) }}</td>
                                <td>{{ $v->installments_count }} · {{ $v->frequency->name }}</td>
                                <td><span class="badge badge-{{ $v->obligation?->status_id === 1 ? 'success' : 'neutral' }}">{{ $v->obligation?->status->name }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
