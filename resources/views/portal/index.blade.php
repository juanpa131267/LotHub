{{-- Portal del comprador (CU-24): saldo, cuotas vencidas, próximo vencimiento y plan de cuotas. --}}
@extends('layouts.panel')

@section('title', 'Mi estado de cuenta')

@section('content')
    @php
        $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
        $statusTone = ['PAGADA' => 'success', 'VENCIDA' => 'danger', 'PAGO_PARCIAL' => 'warning', 'PENDIENTE' => 'neutral'];
        $statusLabel = ['PAGADA' => 'Pagada', 'VENCIDA' => 'Vencida', 'PAGO_PARCIAL' => 'Pago parcial', 'PENDIENTE' => 'Pendiente'];
    @endphp

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
        @foreach ($obligations as $o)
            @php($plan = $installments[$o->obligation_id] ?? collect())
            <section class="card">
                <div class="card-head">
                    <div>
                        <h3>{{ $o->project_name }} · Mz {{ $o->block }} Lote {{ $o->lot_number }}</h3>
                        <p class="muted" style="font-size:.82rem">Obligación {{ $o->obligation_code }} · negociación {{ $o->sale_code }}
                            @if ((float) $o->ownership_pct < 100) · tu participación {{ number_format((float) $o->ownership_pct, 2, ',', '.') }} % @endif</p>
                    </div>
                    <span class="badge badge-{{ $o->overdue_installments ? 'danger' : 'success' }}">{{ $o->overdue_installments ? $o->overdue_installments.' cuota(s) vencida(s)' : 'Al día' }}</span>
                </div>
                <div class="card-body">
                    <div class="plan-report" style="margin-bottom:1rem">
                        <div><strong>{{ $money($o->balance) }}</strong><span>saldo pendiente</span></div>
                        <div><strong>{{ $money($o->total_paid) }}</strong><span>pagado</span></div>
                        <div><strong>{{ $o->next_due_date ?? '—' }}</strong><span>próximo vencimiento</span></div>
                        <div><strong>{{ $o->max_days_overdue ?: 0 }}</strong><span>días de mora</span></div>
                    </div>
                    @stack('portal-actions-'.$o->obligation_id)
                    <details>
                        <summary style="cursor:pointer;font-weight:600;margin-bottom:.6rem">Plan de cuotas ({{ $plan->count() }})</summary>
                        <div class="table-wrap">
                            <table class="table">
                                <thead><tr><th>Cuota</th><th>Vence</th><th class="num">Valor</th><th class="num">Pagado</th><th class="num">Saldo</th><th>Estado</th></tr></thead>
                                <tbody>
                                    @foreach ($plan as $row)
                                        <tr>
                                            <td>{{ (int) $row->installment_number === 0 ? 'Inicial' : $row->installment_number }}</td>
                                            <td class="nowrap">{{ $row->due_date }}</td>
                                            <td class="num nowrap">{{ $money($row->amount_due) }}</td>
                                            <td class="num nowrap">{{ $money($row->amount_paid) }}</td>
                                            <td class="num nowrap strong">{{ $money(max(0, $row->balance)) }}</td>
                                            <td><span class="badge badge-{{ $statusTone[$row->computed_status] }}">{{ $statusLabel[$row->computed_status] }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>
            </section>
        @endforeach
    @endif
@endsection
