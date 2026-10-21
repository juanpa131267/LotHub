{{-- Negociaciones vigentes sin acuerdo de pago (bandeja del Auxiliar de cartera, CU-12 paso 6). --}}
@extends('layouts.panel')

@section('title', 'Acuerdos pendientes')

@section('content')
    @php($money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.'))

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-file-dashed"></i></span>
            <div>
                <h1>Acuerdos pendientes</h1>
                <p>Negociaciones vigentes que esperan la definición de su acuerdo de pago y su plan de cuotas.</p>
            </div>
        </div>
    </div>

    <section class="card">
        @if ($sales->isEmpty())
            <div class="empty"><i class="ph ph-check-circle"></i>Todas las negociaciones vigentes tienen acuerdo de pago.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Negociación</th><th>Fecha</th><th>Lote</th><th>Titular</th><th class="num">Precio pactado</th><th>Modalidad</th><th>Asesor</th><th class="actions"></th></tr></thead>
                    <tbody>
                        @foreach ($sales as $sale)
                            <tr>
                                <td><a href="{{ route('sales.show', $sale->id) }}"><code>{{ $sale->sale_code }}</code></a></td>
                                <td class="nowrap">{{ $sale->sale_date->format('Y-m-d') }}</td>
                                <td>{{ $sale->lot->project->name }} · {{ $sale->lot->label }}</td>
                                <td class="strong">{{ $sale->primaryClient()?->person->full_name }}</td>
                                <td class="num nowrap">{{ $money($sale->agreed_price) }}</td>
                                <td class="cell-muted">{{ $sale->modality->name }}</td>
                                <td class="cell-muted">{{ $sale->advisor->display_name }}</td>
                                <td class="actions">
                                    @can('acuerdos.crear')
                                        <a href="{{ route('agreements.create', $sale->id) }}" class="btn btn-primary btn-sm"><i class="ph ph-file-text"></i> Definir acuerdo</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
