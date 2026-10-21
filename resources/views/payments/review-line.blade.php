{{-- Revisión de un movimiento: candidatos con su puntaje recalculado, asignación manual o descarte. --}}
@extends('layouts.panel')

@section('title', 'Revisar movimiento')

@section('breadcrumb')
    <a href="{{ route('allocation.queue') }}">Cola de revisión</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">Movimiento #{{ $line->id }}</span>
@endsection

@section('content')
    @php($money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.'))

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-magnifying-glass"></i></span>
            <div>
                <h1>{{ $money($line->amount) }} · {{ $line->transaction_date->format('Y-m-d') }}</h1>
                <p>{{ $line->statement->bank_name }} · extracto #{{ $line->statement_id }}, fila {{ $line->line_number }}</p>
            </div>
        </div>
        <div class="page-actions">
            <a href="{{ route('allocation.queue') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Cola</a>
            @if ($next)<a href="{{ route('allocation.review', $next) }}" class="btn btn-ghost">Siguiente <i class="ph ph-arrow-right"></i></a>@endif
        </div>
    </div>

    <section class="card">
        <div class="card-body">
            <ul class="list">
                <li><span class="grow muted">Descripción</span><strong>{{ $line->description ?? '—' }}</strong></li>
                <li><span class="grow muted">Referencia</span><code>{{ $line->reference ?? '—' }}</code></li>
                <li><span class="grow muted">Documento del depositante</span><span>{{ $line->payer_document ?? 'No informado' }}</span></li>
                <li><span class="grow muted">Estado</span><span>{{ $line->status_label }}</span></li>
                @if ($line->review_notes)<li><span class="grow muted">Notas</span><span>{{ $line->review_notes }}</span></li>@endif
            </ul>
        </div>
    </section>

    @if ($line->status !== 'REVISION')
        <div class="alert alert-info"><i class="ph ph-info"></i><div>Este movimiento ya fue resuelto ({{ mb_strtolower($line->status_label) }}).
            @if ($line->payment_id) <a href="{{ route('payments.show', $line->payment_id) }}">Ver abono</a>@endif</div></div>
    @else
        <section class="card">
            <div class="card-head"><h3>Candidatos</h3><span class="muted" style="font-size:.82rem">Puntaje recalculado con los saldos actuales</span></div>
            @if ($candidates->isEmpty())
                <div class="card-body muted">Ninguna obligación activa coincide con este movimiento. Búscala manualmente o descártalo.</div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th class="num">Puntaje</th><th>Obligación</th><th>Por qué</th><th class="num">Cuota que sigue</th><th class="num">Saldo</th><th class="actions"></th></tr></thead>
                        <tbody>
                            @foreach ($candidates as $c)
                                <tr>
                                    <td class="num"><span class="badge badge-{{ $c['score'] >= 75 ? 'success' : ($c['score'] >= 40 ? 'warning' : 'neutral') }}">{{ $c['score'] }}</span></td>
                                    <td><a href="{{ route('obligations.show', $c['obligation_id']) }}" target="_blank">{{ $c['label'] }}</a></td>
                                    <td class="cell-muted" style="font-size:.82rem">{{ implode(' · ', $c['reasons']) }}</td>
                                    <td class="num nowrap">{{ $c['next_due'] ? $money($c['next_due']['balance']).' · '.$c['next_due']['due_date'] : '—' }}</td>
                                    <td class="num nowrap">{{ $money($c['balance']) }}</td>
                                    <td class="actions">
                                        <form method="POST" action="{{ route('allocation.assign', $line->id) }}">
                                            @csrf
                                            <input type="hidden" name="obligation_id" value="{{ $c['obligation_id'] }}">
                                            <input type="hidden" name="payer_client_id" value="{{ $c['payer_client_id'] }}">
                                            <button class="btn btn-primary btn-sm"><i class="ph ph-check"></i> Asignar</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <div class="option-grid">
            <section class="card">
                <div class="card-head"><h3>Asignar a otra obligación</h3></div>
                <form method="POST" action="{{ route('allocation.assign', $line->id) }}" class="card-body" novalidate>
                    @csrf
                    <div class="field">
                        <label for="obligation_code">Código de la obligación <span class="req">*</span></label>
                        <select id="obligation_code" name="obligation_id" class="select" required>
                            <option value="">— Seleccionar —</option>
                            @foreach (\App\Models\Financing\Obligation::with('agreement.sale.lot.project')->where('status_id', 1)->orderBy('obligation_code')->get() as $o)
                                <option value="{{ $o->id }}">{{ $o->obligation_code }} · {{ $o->agreement->sale->lot->project->name }} · {{ $o->agreement->sale->lot->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="review_notes_assign">Notas</label>
                        <input id="review_notes_assign" name="review_notes" class="input" maxlength="255" placeholder="p. ej. el cliente confirmó el pago por teléfono">
                    </div>
                    <button class="btn btn-primary"><i class="ph ph-check"></i> Asignar</button>
                </form>
            </section>

            <section class="card">
                <div class="card-head"><h3>Descartar</h3></div>
                <form method="POST" action="{{ route('allocation.discard', $line->id) }}" class="card-body" novalidate>
                    @csrf
                    <p class="muted" style="font-size:.88rem;margin-bottom:.8rem">Para movimientos que no son pagos de cartera (rendimientos, devoluciones, otros ingresos).</p>
                    <div class="field">
                        <label for="review_notes">Motivo <span class="req">*</span></label>
                        <input id="review_notes" name="review_notes" class="input @error('review_notes') is-invalid @enderror" minlength="5" maxlength="255" required>
                        @error('review_notes') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <button class="btn btn-ghost"><i class="ph ph-x"></i> Descartar movimiento</button>
                </form>
            </section>
        </div>
    @endif
@endsection
