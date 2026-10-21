{{--
    Definir (CU-13) o reestructurar (CU-16) el acuerdo de pago, con vista previa del plan de cuotas (CU-14).
    $mode: create | restructure · $base: precio pactado o saldo a refinanciar.
--}}
@extends('layouts.panel')

@php
    $restructure = $mode === 'restructure';
    $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
    $cash = ! $sale->modality->requires_agreement;
    $title = $restructure ? 'Reestructurar acuerdo' : 'Definir acuerdo de pago';
@endphp

@section('title', $title.' · '.$sale->sale_code)

@section('breadcrumb')
    @if ($restructure)
        <a href="{{ route('obligations.index') }}">Obligaciones</a>
        <i class="ph ph-caret-right"></i>
        <a href="{{ route('obligations.show', $obligation->id) }}">{{ $obligation->obligation_code }}</a>
    @else
        <a href="{{ route('agreements.pending') }}">Acuerdos pendientes</a>
    @endif
    <i class="ph ph-caret-right"></i>
    <span class="current">{{ $restructure ? 'Reestructurar' : 'Definir acuerdo' }}</span>
@endsection

@section('content')
    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph {{ $restructure ? 'ph-arrows-clockwise' : 'ph-file-text' }}"></i></span>
            <div>
                <h1>{{ $title }}</h1>
                <p>{{ $sale->sale_code }} · {{ $sale->lot->project->name }} · {{ $sale->lot->label }} · {{ $sale->primaryClient()?->person->full_name }}</p>
            </div>
        </div>
        <a href="{{ $restructure ? route('obligations.show', $obligation->id) : route('sales.show', $sale->id) }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Volver</a>
    </div>

    <div class="stats">
        <div class="card stat"><div class="stat-icon"><i class="ph ph-currency-circle-dollar"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ $money($base) }}</div>
            <div class="stat-label">{{ $restructure ? 'saldo a refinanciar (se convierte en el nuevo capital, RN-11)' : 'precio pactado (cuota inicial + capital financiado, RN-09)' }}</div></div>
        <div class="card stat"><div class="stat-icon accent"><i class="ph ph-hand-coins"></i></div>
            <div class="stat-value" style="font-size:1.05rem">{{ $sale->modality->name }}</div>
            <div class="stat-label">{{ $cash ? 'una sola cuota sin intereses (RN-10)' : 'requiere acuerdo firmado (RN-10)' }}</div></div>
        <div class="card stat"><div class="stat-icon info"><i class="ph ph-percent"></i></div>
            <div class="stat-value" style="font-size:1.25rem">{{ number_format($usury, 2, ',', '.') }} %</div><div class="stat-label">límite de usura mensual (RN-19)</div></div>
    </div>

    <form method="POST" action="{{ $action }}" class="card form-card" id="agreement-form" novalidate>
        @csrf
        <div class="card-body">
            <div class="form-grid">
                <div class="form-section">Condiciones</div>
                <div class="field w-third">
                    <label for="down_payment">{{ $restructure ? 'Abono inicial' : 'Cuota inicial' }} (COP)</label>
                    <input id="down_payment" name="down_payment" type="number" min="0" step="1" class="input" data-calc
                           value="{{ old('down_payment', $cash || $restructure ? 0 : round($base * 0.3, -3)) }}" @disabled($cash)>
                    <div class="help">Cuota 0 del plan, con vencimiento hoy.</div>
                </div>
                <div class="field w-third">
                    <label for="financed_amount">Capital financiado (COP) <span class="req">*</span></label>
                    <input id="financed_amount" name="financed_amount" type="number" min="1" step="1" class="input" data-calc required
                           value="{{ old('financed_amount', $cash || $restructure ? $base : $base - round($base * 0.3, -3)) }}">
                    <div class="help" id="sum-help"></div>
                </div>
                <div class="field w-third">
                    <label for="installments_count">Número de cuotas <span class="req">*</span></label>
                    <input id="installments_count" name="installments_count" type="number" min="1" max="360" step="1" class="input" data-calc required
                           value="{{ old('installments_count', $cash ? 1 : ($current->installments_count ?? 36)) }}" @readonly($cash)>
                </div>
                <div class="field w-third">
                    <label for="frequency_id">Periodicidad <span class="req">*</span></label>
                    <select id="frequency_id" name="frequency_id" class="select" data-calc required>
                        @foreach ($frequencies as $f)
                            <option value="{{ $f->id }}" @selected(old('frequency_id', $current->frequency_id ?? 1) == $f->id)>{{ $f->name }} (cada {{ $f->months_interval }} mes{{ $f->months_interval > 1 ? 'es' : '' }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="field w-third">
                    <label for="monthly_interest_rate">Tasa corriente (% mensual) <span class="req">*</span></label>
                    <input id="monthly_interest_rate" name="monthly_interest_rate" type="number" min="0" max="{{ $usury }}" step="0.0001" class="input" data-calc required
                           value="{{ old('monthly_interest_rate', $cash ? 0 : ($current->monthly_interest_rate ?? 1)) }}" @readonly($cash)>
                </div>
                <div class="field w-third">
                    <label for="late_interest_rate">Tasa de mora (% mensual) <span class="req">*</span></label>
                    <input id="late_interest_rate" name="late_interest_rate" type="number" min="0" max="{{ $usury }}" step="0.0001" class="input" required
                           value="{{ old('late_interest_rate', $current->late_interest_rate ?? $usury) }}">
                </div>
                <div class="field w-third">
                    <label for="first_due_date">Primer vencimiento <span class="req">*</span></label>
                    <input id="first_due_date" name="first_due_date" type="date" class="input" data-calc required min="{{ now()->toDateString() }}"
                           value="{{ old('first_due_date', now()->addMonthNoOverflow()->toDateString()) }}">
                </div>
                @if ($restructure)
                    <div class="field w-two-thirds">
                        <label for="restructure_reason">Motivo de la reestructuración <span class="req">*</span></label>
                        <input id="restructure_reason" name="restructure_reason" class="input @error('restructure_reason') is-invalid @enderror" minlength="10" maxlength="255" required value="{{ old('restructure_reason') }}">
                        @error('restructure_reason') <div class="error">{{ $message }}</div> @enderror
                    </div>
                @endif
            </div>

            <div class="form-section" style="margin-top:1.2rem">Plan de cuotas propuesto</div>
            <div id="plan-preview" class="muted" style="font-size:.9rem;padding:.6rem 0">Calculando…</div>

            <div class="form-actions">
                <a href="{{ $restructure ? route('obligations.show', $obligation->id) : route('sales.show', $sale->id) }}" class="btn btn-ghost">Cancelar</a>
                <button type="submit" class="btn btn-primary" data-loading="Generando obligación…"><i class="ph ph-check"></i>
                    {{ $restructure ? 'Reestructurar y generar nueva obligación' : 'Definir acuerdo y generar plan' }}</button>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
    (() => {
        const form = document.getElementById('agreement-form');
        const preview = document.getElementById('plan-preview');
        const base = {{ (float) $base }};
        const cash = @json($cash);
        const money = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
        const down = form.down_payment;
        const financed = form.financed_amount;
        let timer;

        const sumHelp = () => {
            const total = (Number(down.value) || 0) + (Number(financed.value) || 0);
            const ok = Math.abs(total - base) < 1;
            document.getElementById('sum-help').innerHTML = ok
                ? 'Suma correcta.'
                : `<span style="color:var(--danger)">Suma ${money.format(total)}; debe ser ${money.format(base)}.</span>`;
        };
        down.addEventListener('input', () => { financed.value = Math.max(0, Math.round(base - (Number(down.value) || 0))); sumHelp(); });
        financed.addEventListener('input', sumHelp);

        async function simulate() {
            const body = Object.fromEntries(new FormData(form));
            if (cash) body.down_payment = 0;
            try {
                const res = await LotHub.request(@json($preview), { method: 'POST', body });
                const rows = res.plan;
                const show = rows.length > 14 ? [...rows.slice(0, 6), null, ...rows.slice(-6)] : rows;
                preview.classList.remove('muted');
                preview.innerHTML = `
                    <div class="plan-report" style="margin-bottom:.8rem">
                        <div><strong>${rows.length}</strong><span>cuotas${rows[0].installment_number === 0 ? ' (incluye la cuota 0)' : ''}</span></div>
                        <div><strong>${money.format(rows.find((r) => r.installment_number > 0).principal_amount + rows.find((r) => r.installment_number > 0).interest_amount)}</strong><span>valor de la cuota</span></div>
                        <div><strong>${money.format(res.totals.interest)}</strong><span>intereses totales</span></div>
                        <div><strong>${money.format(res.totals.total)}</strong><span>total a pagar</span></div>
                    </div>
                    <div class="table-wrap"><table class="table"><thead><tr><th>Cuota</th><th>Vence</th><th class="num">Capital</th><th class="num">Interés</th><th class="num">Valor</th></tr></thead><tbody>
                    ${show.map((r) => r === null ? '<tr><td colspan="5" class="cell-muted" style="text-align:center">…</td></tr>' : `<tr>
                        <td>${r.installment_number === 0 ? 'Inicial' : r.installment_number}</td><td class="nowrap">${r.due_date}</td>
                        <td class="num">${money.format(r.principal_amount)}</td><td class="num">${money.format(r.interest_amount)}</td>
                        <td class="num strong">${money.format(r.principal_amount + r.interest_amount)}</td></tr>`).join('')}
                    </tbody></table></div>`;
            } catch (err) {
                preview.classList.add('muted');
                preview.innerHTML = `<span style="color:var(--danger)"><i class="ph ph-warning-circle"></i> ${LotHub.esc(err.message)}</span>`;
            }
        }

        form.addEventListener('input', (e) => { if (e.target.matches('[data-calc]')) { clearTimeout(timer); timer = setTimeout(simulate, 350); } });
        form.addEventListener('change', (e) => { if (e.target.matches('select[data-calc]')) simulate(); });
        sumHelp();
        simulate();
    })();
</script>
@endpush
