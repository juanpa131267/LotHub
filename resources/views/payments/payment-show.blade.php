{{-- Detalle de un abono: aplicación por cuota y concepto (CU-18), soportes y reversión (CU-19). --}}
@extends('layouts.panel')

@section('title', 'Abono #'.$payment->id)

@section('breadcrumb')
    <a href="{{ route('payments.index') }}">Abonos</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">#{{ $payment->id }}</span>
@endsection

@section('content')
    @php
        $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
        $obligation = $payment->obligation;
        $sale = $obligation->agreement->sale;
        $allocated = $payment->allocations->sum('amount');
        $user = auth()->user();
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-coins"></i></span>
            <div>
                <h1>Abono #{{ $payment->id }} <span class="badge badge-{{ $payment->isApplied() ? 'success' : 'danger' }}" style="vertical-align:middle">{{ $payment->status->name }}</span></h1>
                <p><a href="{{ route('obligations.show', $obligation->id) }}">{{ $obligation->obligation_code }}</a> · {{ $sale->lot->project->name }} · {{ $sale->lot->label }}</p>
            </div>
        </div>
        <a href="{{ route('obligations.show', $obligation->id) }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Obligación</a>
    </div>

    <div class="stats">
        <div class="card stat"><div class="stat-icon"><i class="ph ph-coins"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ $money($payment->amount) }}</div><div class="stat-label">valor del abono</div></div>
        <div class="card stat"><div class="stat-icon accent"><i class="ph ph-check-circle"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ $money($allocated) }}</div><div class="stat-label">aplicado a cuotas</div></div>
        <div class="card stat"><div class="stat-icon info"><i class="ph ph-piggy-bank"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ $money($payment->amount - $allocated) }}</div><div class="stat-label">saldo a favor (sin aplicar)</div></div>
    </div>

    <div class="option-grid">
        <section class="card">
            <div class="card-head"><h3>Datos del abono</h3></div>
            <div class="card-body">
                <ul class="list">
                    <li><span class="grow muted">Fecha del pago</span><strong>{{ $payment->payment_date->format('Y-m-d') }}</strong></li>
                    <li><span class="grow muted">Pagador</span><span>{{ $payment->payer->person->full_name }} ({{ $payment->payer->client_code }})</span></li>
                    <li><span class="grow muted">Medio</span><span>{{ $payment->method->name }}</span></li>
                    <li><span class="grow muted">Referencia</span><code>{{ $payment->bank_reference ?? '—' }}</code></li>
                    <li><span class="grow muted">Origen</span><span>{{ $payment->originLabel() }}
                        @if ($payment->statementLine) · <a href="{{ route('statements.show', $payment->statementLine->statement_id) }}">extracto #{{ $payment->statementLine->statement_id }}</a>, fila {{ $payment->statementLine->line_number }} @endif</span></li>
                    <li><span class="grow muted">Registrado por</span><span>{{ $payment->registeredBy?->display_name ?? 'Sistema (pasarela)' }} · {{ $payment->created_at->format('Y-m-d H:i') }}</span></li>
                    @if (! $payment->isApplied())
                        <li><span class="grow muted">Reversado</span><span>{{ $payment->reversedBy?->display_name }} · {{ $payment->reversed_at?->format('Y-m-d H:i') }}</span></li>
                        <li><span class="grow muted">Motivo</span><span>{{ $payment->reversal_reason }}</span></li>
                    @endif
                </ul>
                @foreach ($payment->documents as $doc)
                    <a href="{{ route('documents.download', $doc->id) }}" class="btn btn-ghost btn-sm" style="margin-top:.8rem"><i class="ph ph-file-arrow-down"></i> {{ $doc->type->name }}</a>
                @endforeach
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h3>Aplicación a cuotas</h3><span class="muted" style="font-size:.82rem">mora → interés → capital (RN-14)</span></div>
            @if ($payment->allocations->isEmpty())
                <div class="card-body muted">El abono no se aplicó a ninguna cuota (todo quedó como saldo a favor).</div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Cuota</th><th>Vence</th><th>Concepto</th><th class="num">Valor</th></tr></thead>
                        <tbody>
                            @foreach ($payment->allocations->sortBy(fn ($a) => [$a->installment->installment_number, array_search($a->concept, array_keys($concepts))]) as $a)
                                <tr>
                                    <td>{{ (int) $a->installment->installment_number === 0 ? 'Inicial' : $a->installment->installment_number }}</td>
                                    <td class="nowrap">{{ $a->installment->due_date->format('Y-m-d') }}</td>
                                    <td>{{ $concepts[$a->concept] }}</td>
                                    <td class="num nowrap">{{ $money($a->amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    @if ($payment->isApplied() && $user->hasPermission('abonos.reversar'))
        <section class="card form-card">
            <div class="card-head"><h3>Reversar abono (CU-19)</h3></div>
            <form method="POST" action="{{ route('payments.reverse', $payment->id) }}" class="card-body" id="reverse-form">
                @csrf
                <p class="muted" style="font-size:.88rem;margin-bottom:.8rem">El abono no se borra (RN-15): queda REVERSADO con tu usuario, la fecha y el motivo, y deja de sumar en el saldo.
                    @if ($payment->statementLine) El movimiento del extracto vuelve a la cola de revisión. @endif</p>
                <div class="field">
                    <label for="reversal_reason">Motivo <span class="req">*</span></label>
                    <input id="reversal_reason" name="reversal_reason" class="input @error('reversal_reason') is-invalid @enderror" minlength="10" maxlength="255" required>
                    @error('reversal_reason') <div class="error">{{ $message }}</div> @enderror
                </div>
                @if ($payment->gateway_transaction_id)
                    <input type="hidden" name="gateway_voided" value="0">
                    <label class="check" style="margin-bottom:.8rem"><input type="checkbox" name="gateway_voided" value="1"> La transacción ya fue anulada en la pasarela (CU-19 1b)</label>
                @endif
                <button class="btn btn-danger"><i class="ph ph-arrow-counter-clockwise"></i> Reversar abono</button>
            </form>
        </section>
    @endif
@endsection

@push('scripts')
<script>
    document.getElementById('reverse-form')?.addEventListener('submit', async (e) => {
        if (e.target.dataset.confirmed) return;
        e.preventDefault();
        const ok = await LotHub.confirm({ title: 'Reversar abono', message: 'El saldo de la obligación aumentará en el valor aplicado. Esta acción no se puede deshacer.', confirmText: 'Reversar', danger: true });
        if (ok) { e.target.dataset.confirmed = '1'; e.target.submit(); }
    });
</script>
@endpush
