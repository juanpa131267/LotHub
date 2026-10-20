{{-- Portal del comprador (CU-24). Pago en línea y reclamos llegan en los próximos sprints. --}}
@extends('layouts.panel')

@section('title', 'Mi estado de cuenta')

@section('content')
    @php($money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.'))

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-receipt"></i></span>
            <div>
                <h1>Mi estado de cuenta</h1>
                <p>Consulta el saldo, las cuotas vencidas y el próximo vencimiento de tus lotes.</p>
            </div>
        </div>
    </div>

    @if (! $client)
        <div class="card empty"><i class="ph ph-user-circle-dashed"></i>Tu usuario aún no está vinculado a una ficha de cliente. Comunícate con tu asesor.</div>
    @elseif ($obligations->isEmpty())
        <div class="card empty"><i class="ph ph-receipt"></i>No tienes obligaciones activas en este momento.</div>
    @else
        <div class="stats">
            @foreach ($obligations as $o)
                <div class="card stat">
                    <div class="stat-icon"><i class="ph ph-map-pin"></i></div>
                    <div class="stat-label">{{ $o->project_name }} · Mz {{ $o->block }} Lote {{ $o->lot_number }}</div>
                    <div class="stat-value" style="margin-top:.5rem">{{ $money($o->balance) }}</div>
                    <div class="stat-label">Saldo pendiente · obligación {{ $o->obligation_code }}</div>
                    <ul class="list" style="margin-top:1rem">
                        <li style="padding:.5rem 0"><span class="grow muted">Pagado</span><strong>{{ $money($o->total_paid) }}</strong></li>
                        <li style="padding:.5rem 0"><span class="grow muted">Cuotas vencidas</span>
                            <span class="badge badge-{{ $o->overdue_installments ? 'danger' : 'success' }}">{{ $o->overdue_installments }}</span></li>
                        <li style="padding:.5rem 0"><span class="grow muted">Próximo vencimiento</span><strong>{{ $o->next_due_date ?? '—' }}</strong></li>
                    </ul>
                </div>
            @endforeach
        </div>
    @endif
@endsection
