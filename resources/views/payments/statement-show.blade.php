{{-- Movimientos de un extracto con el resultado del motor de asignación. --}}
@extends('layouts.panel')

@section('title', 'Extracto #'.$statement->id)

@section('breadcrumb')
    <a href="{{ route('statements.index') }}">Extractos bancarios</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">#{{ $statement->id }}</span>
@endsection

@section('content')
    @php
        $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
        $tone = ['PENDIENTE' => 'neutral', 'ASIGNADA' => 'success', 'REVISION' => 'warning', 'DESCARTADA' => 'neutral', 'DUPLICADA' => 'danger'];
        $labels = \App\Models\Payments\BankStatementLine::STATUSES;
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-bank"></i></span>
            <div>
                <h1>Extracto #{{ $statement->id }} · {{ $statement->bank_name }}</h1>
                <p>{{ $statement->original_name }} · {{ $statement->period_start?->format('Y-m-d') }} a {{ $statement->period_end?->format('Y-m-d') }} ·
                    abonos como {{ mb_strtolower($statement->method->name) }}</p>
            </div>
        </div>
        <a href="{{ route('statements.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Extractos</a>
    </div>

    <div class="view-tabs" style="margin-bottom:1rem;display:flex;gap:.4rem;flex-wrap:wrap">
        <a href="{{ route('statements.show', $statement->id) }}" class="btn btn-sm {{ $status ? 'btn-ghost' : 'btn-primary' }}">Todos ({{ $counts->sum() }})</a>
        @foreach ($labels as $code => $label)
            @if ($counts[$code] ?? 0)
                <a href="{{ route('statements.show', [$statement->id, 'estado' => $code]) }}" class="btn btn-sm {{ $status === $code ? 'btn-primary' : 'btn-ghost' }}">{{ $label }} ({{ $counts[$code] }})</a>
            @endif
        @endforeach
    </div>

    <section class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Fila</th><th>Fecha</th><th>Descripción</th><th>Referencia</th><th class="num">Valor</th><th class="num">Puntaje</th><th>Resultado</th><th class="actions"></th></tr></thead>
                <tbody>
                    @forelse ($lines as $line)
                        <tr>
                            <td class="num">{{ $line->line_number }}</td>
                            <td class="nowrap">{{ $line->transaction_date->format('Y-m-d') }}</td>
                            <td>{{ $line->description ?? '—' }} @if ($line->payer_document)<span class="cell-muted" style="font-size:.8rem">· doc. {{ $line->payer_document }}</span>@endif</td>
                            <td><code>{{ $line->reference ?? '—' }}</code></td>
                            <td class="num nowrap strong">{{ $money($line->amount) }}</td>
                            <td class="num">{{ $line->score ?? '—' }}</td>
                            <td>
                                <span class="badge badge-{{ $tone[$line->status] }}">{{ $line->status_label }}</span>
                                @if ($line->obligation)
                                    <a href="{{ route('obligations.show', $line->matched_obligation_id) }}" style="font-size:.82rem">{{ $line->obligation->obligation_code }}</a>
                                    <span class="cell-muted" style="font-size:.8rem">{{ $line->assignment_mode === 'AUTOMATICA' ? 'automático' : 'revisado' }}</span>
                                @endif
                                @if ($line->review_notes)<div class="cell-muted" style="font-size:.8rem">{{ $line->review_notes }}</div>@endif
                            </td>
                            <td class="actions">
                                @if ($line->status === 'REVISION')
                                    <a href="{{ route('allocation.review', $line->id) }}" class="btn btn-ghost btn-sm">Revisar</a>
                                @elseif ($line->payment_id)
                                    <a href="{{ route('payments.show', $line->payment_id) }}" class="icon-btn" title="Ver abono"><i class="ph ph-coins"></i></a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty-row">Sin movimientos con ese estado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
