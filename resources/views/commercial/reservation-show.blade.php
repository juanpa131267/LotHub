{{-- Detalle de una reserva (ReservationController::show). --}}
@extends('layouts.panel')

@section('title', 'Reserva #'.$reservation->id)

@section('breadcrumb')
    <a href="{{ route('reservations.index') }}">Reservas</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">#{{ $reservation->id }}</span>
@endsection

@section('content')
    @php
        $r = $reservation;
        $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
        $tone = [1 => 'warning', 2 => 'success', 3 => 'neutral', 4 => 'danger'][$r->status_id];
        $user = auth()->user();
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-bookmark-simple"></i></span>
            <div>
                <h1>Reserva #{{ $r->id }} <span class="badge badge-{{ $tone }}" style="vertical-align:middle">{{ $r->status->name }}</span></h1>
                <p>{{ $r->lot->project->name }} · {{ $r->lot->label }} · {{ $r->client->person->full_name }}</p>
            </div>
        </div>
        <div class="page-actions">
            <a href="{{ route('reservations.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Reservas</a>
            @if ($r->isActive() && $user->hasPermission('negociaciones.crear'))
                <a href="{{ route('sales.create', ['reservation_id' => $r->id]) }}" class="btn btn-primary"><i class="ph ph-handshake"></i> Convertir en negociación</a>
            @endif
        </div>
    </div>

    <div class="option-grid">
        <section class="card">
            <div class="card-head"><h3>Reserva</h3></div>
            <div class="card-body">
                <ul class="list">
                    <li><span class="grow muted">Lote</span><strong>{{ $r->lot->project->name }} · {{ $r->lot->label }}</strong></li>
                    <li><span class="grow muted">Área / precio de lista</span><span>{{ number_format((float) $r->lot->area_m2, 2, ',', '.') }} m² · {{ $money($r->lot->list_price) }}</span></li>
                    <li><span class="grow muted">Cliente</span><span>{{ $r->client->person->full_name }} ({{ $r->client->client_code }})</span></li>
                    <li><span class="grow muted">Asesor</span><span>{{ $r->advisor->display_name }}</span></li>
                    <li><span class="grow muted">Valor de separación</span><strong>{{ $money($r->deposit_amount) }}</strong></li>
                    <li><span class="grow muted">Reservada</span><span>{{ $r->reserved_at->format('Y-m-d H:i') }}</span></li>
                    <li><span class="grow muted">Vence</span>
                        <span>{{ $r->expires_at->format('Y-m-d H:i') }}
                            @if ($r->isActive())
                                <span class="badge badge-{{ $r->daysLeft() <= 1 ? 'danger' : 'warning' }}">{{ $r->daysLeft() >= 1 ? 'en '.$r->daysLeft().' día(s)' : 'hoy' }}</span>
                            @endif
                        </span></li>
                    @if ($r->sale)
                        <li><span class="grow muted">Negociación</span><a href="{{ route('sales.show', $r->sale->id) }}">{{ $r->sale->sale_code }}</a></li>
                    @endif
                    @if ($r->notes)
                        <li><span class="grow muted">Observaciones</span><span>{{ $r->notes }}</span></li>
                    @endif
                </ul>
            </div>
        </section>

        @if ($r->isActive() && $user->hasPermission('reservas.anular'))
            <section class="card">
                <div class="card-head"><h3>Anular reserva</h3></div>
                <form method="POST" action="{{ route('reservations.cancel', $r->id) }}" class="card-body" id="cancel-form">
                    @csrf
                    <p class="muted" style="font-size:.88rem;margin-bottom:.8rem">La reserva pasa a ANULADA y el lote vuelve a DISPONIBLE. La devolución de la separación se gestiona por fuera del sistema.</p>
                    <div class="field">
                        <label for="reason">Motivo <span class="req">*</span></label>
                        <input id="reason" name="reason" class="input @error('reason') is-invalid @enderror" minlength="5" maxlength="150" required value="{{ old('reason') }}">
                        @error('reason') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <button class="btn btn-danger"><i class="ph ph-prohibit"></i> Anular reserva</button>
                </form>
            </section>
        @endif
    </div>
@endsection

@push('scripts')
<script>
    document.getElementById('cancel-form')?.addEventListener('submit', async (e) => {
        if (e.target.dataset.confirmed) return;
        e.preventDefault();
        const ok = await LotHub.confirm({ title: 'Anular reserva', message: 'El lote quedará disponible para otros clientes.', confirmText: 'Anular', danger: true });
        if (ok) { e.target.dataset.confirmed = '1'; e.target.submit(); }
    });
</script>
@endpush
