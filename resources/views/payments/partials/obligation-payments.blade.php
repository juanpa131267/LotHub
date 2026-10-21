{{-- Abonos de una obligación dentro de su detalle (obligation-show). --}}
@php
    $obligationPayments = \App\Models\Payments\Payment::with(['method', 'status', 'payer.person', 'statementLine'])
        ->where('obligation_id', $obligation->id)->latest('payment_date')->latest('id')->get();
    $credit = $obligationPayments->where('status_id', 1)->sum(fn ($p) => $p->unallocated());
    $user = auth()->user();
@endphp

@if ($user->hasPermission('abonos.ver') && ($obligationPayments->isNotEmpty() || $credit > 0))
    <section class="card">
        <div class="card-head">
            <h3>Abonos</h3>
            @if ($credit > 0)
                <span class="badge badge-info">Saldo a favor $ {{ number_format($credit, 0, ',', '.') }}</span>
            @endif
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Fecha</th><th class="num">Valor</th><th>Medio</th><th>Referencia</th><th>Origen</th><th>Pagador</th><th>Estado</th><th class="actions"></th></tr></thead>
                <tbody>
                    @foreach ($obligationPayments as $p)
                        <tr>
                            <td class="nowrap">{{ $p->payment_date->format('Y-m-d') }}</td>
                            <td class="num nowrap strong">$ {{ number_format((float) $p->amount, 0, ',', '.') }}</td>
                            <td class="cell-muted">{{ $p->method->name }}</td>
                            <td><code>{{ $p->bank_reference ?? '—' }}</code></td>
                            <td class="cell-muted">{{ $p->originLabel() }}</td>
                            <td>{{ $p->payer->person->full_name }}</td>
                            <td><span class="badge badge-{{ $p->isApplied() ? 'success' : 'danger' }}">{{ $p->status->name }}</span></td>
                            <td class="actions"><a href="{{ route('payments.show', $p->id) }}" class="icon-btn" title="Ver abono"><i class="ph ph-eye"></i></a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
