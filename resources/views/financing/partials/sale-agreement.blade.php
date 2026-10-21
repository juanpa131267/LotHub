{{-- Resumen del acuerdo de pago dentro del detalle de la negociación (sale-show). --}}
@php
    $agreement = $sale->currentAgreement;
    $obligation = $agreement?->obligation;
    $user = auth()->user();
@endphp

@if ($agreement && $obligation)
    @php($summary = $obligation->summary())
    <section class="card">
        <div class="card-head">
            <h3>Acuerdo de pago v{{ $agreement->version }} · {{ $obligation->obligation_code }}</h3>
            <span class="badge badge-{{ $obligation->isActive() ? ($summary->overdue_installments ? 'danger' : 'success') : 'neutral' }}">
                {{ $obligation->isActive() ? ($summary->overdue_installments ? 'En mora' : 'Al día') : $obligation->status->name }}</span>
        </div>
        <div class="card-body" style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:center">
            <span><span class="muted">Saldo</span> <strong>$ {{ number_format($summary->balance, 0, ',', '.') }}</strong></span>
            <span><span class="muted">Pagado</span> $ {{ number_format($summary->total_paid, 0, ',', '.') }}</span>
            <span><span class="muted">Cuotas</span> {{ $agreement->installments_count }} · {{ $agreement->frequency->name }}</span>
            <span><span class="muted">Firma</span> {{ $agreement->signed_at?->format('Y-m-d') ?? 'pendiente' }}</span>
            @if ($user->hasPermission('acuerdos.ver'))
                <a href="{{ route('obligations.show', $obligation->id) }}" class="btn btn-ghost btn-sm" style="margin-left:auto"><i class="ph ph-list-numbers"></i> Ver plan de cuotas</a>
            @endif
        </div>
    </section>
@elseif ($sale->isActive())
    <div class="alert alert-info"><i class="ph ph-file-dashed"></i>
        <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;width:100%">
            <span>La negociación aún no tiene acuerdo de pago: cartera debe definir la cuota inicial, las cuotas y las tasas (CU-13).</span>
            @if ($user->hasPermission('acuerdos.crear'))
                <a href="{{ route('agreements.create', $sale->id) }}" class="btn btn-primary btn-sm" style="margin-left:auto"><i class="ph ph-file-text"></i> Definir acuerdo</a>
            @endif
        </div>
    </div>
@endif
