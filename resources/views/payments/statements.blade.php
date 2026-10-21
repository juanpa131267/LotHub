{{-- Carga de extractos bancarios para el motor de asignación automática (Sprint 6). --}}
@extends('layouts.panel')

@section('title', 'Extractos bancarios')

@section('content')
    @php($money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.'))

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-bank"></i></span>
            <div>
                <h1>Extractos bancarios</h1>
                <p>Carga el extracto y el motor asigna cada consignación a su obligación. Lo que no tenga un candidato claro va a la cola de revisión.</p>
            </div>
        </div>
        <div class="page-actions">
            <a href="{{ route('statements.template') }}" class="btn btn-ghost"><i class="ph ph-microsoft-excel-logo"></i> Plantilla</a>
            <a href="{{ route('allocation.queue') }}" class="btn btn-ghost"><i class="ph ph-list-checks"></i> Cola de revisión</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-error"><i class="ph ph-warning-circle"></i><div>{{ $errors->first() }}</div></div>
    @endif

    <div class="option-grid">
        <section class="card">
            <div class="card-head"><h3>Cargar extracto</h3></div>
            <form method="POST" action="{{ route('statements.store') }}" enctype="multipart/form-data" class="card-body" novalidate>
                @csrf
                <div class="form-grid">
                    @include('crud.partials.field', ['field' => ['name' => 'bank_name', 'label' => 'Banco', 'required' => true, 'width' => 'half', 'placeholder' => 'Bancolombia']])
                    @include('crud.partials.field', ['field' => ['name' => 'account_label', 'label' => 'Cuenta', 'width' => 'half', 'placeholder' => 'Ahorros ****1234',
                        'help' => 'Solo los últimos dígitos (RN-18).']])
                    @include('crud.partials.field', ['field' => ['name' => 'payment_method_id', 'label' => 'Registrar los abonos como', 'type' => 'select', 'required' => true,
                        'options' => $methods->pluck('name', 'id')->all(), 'value' => $methods->firstWhere('code', 'CONSIGNACION')?->id]])
                    @include('crud.partials.field', ['field' => ['name' => 'statement_file', 'label' => 'Archivo del extracto', 'type' => 'file', 'required' => true,
                        'accept' => '.xlsx,.xls,.csv,.txt', 'max_bytes' => 10485760, 'help' => 'Excel o CSV del banco. Se reconocen las columnas Fecha, Descripción, Referencia, NIT/Cédula y Valor o Crédito.']])
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" data-loading="Procesando extracto…"><i class="ph ph-lightning"></i> Cargar y asignar</button>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="card-head"><h3>¿Cómo decide el motor?</h3></div>
            <div class="card-body" style="font-size:.88rem">
                <p class="muted" style="margin-bottom:.6rem">Cada movimiento recibe un puntaje de 0 a 100 contra cada obligación activa:</p>
                <ul class="list">
                    <li><span class="grow">Documento del depositante igual al de un comprador</span><strong>45</strong></li>
                    <li><span class="grow">Referencia con el código OBL-, VTA- o CLI-</span><strong>40</strong></li>
                    <li><span class="grow">Identificación del comprador en la descripción</span><strong>35</strong></li>
                    <li><span class="grow">Valor igual a la cuota, a lo vencido o al saldo</span><strong>25</strong></li>
                    <li><span class="grow">Nombre y apellido del comprador en la descripción</span><strong>15</strong></li>
                    <li><span class="grow">Fecha cercana al vencimiento</span><strong>5</strong></li>
                </ul>
                <p class="muted" style="margin-top:.6rem">Se asigna solo si el mejor candidato llega a <strong>{{ $threshold }}</strong> puntos y supera al segundo por <strong>{{ $margin }}</strong>.
                    Los movimientos ya registrados se marcan como duplicados.</p>
            </div>
        </section>
    </div>

    @if ($result && $result['errors'])
        <section class="card">
            <div class="card-head"><h3>Filas con error</h3><span class="badge badge-danger">{{ count($result['errors']) }}</span></div>
            <div class="table-wrap price-errors">
                <table class="table">
                    <thead><tr><th>Fila</th><th>Qué corregir</th></tr></thead>
                    <tbody>@foreach ($result['errors'] as $err)<tr><td class="num">{{ $err['row'] }}</td><td>{{ $err['message'] }}</td></tr>@endforeach</tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="card">
        <div class="card-head"><h3>Extractos cargados</h3></div>
        @if ($statements->isEmpty())
            <div class="empty"><i class="ph ph-bank"></i>Aún no se ha cargado ningún extracto.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>#</th><th>Banco</th><th>Periodo</th><th class="num">Movimientos</th><th class="num">Créditos</th><th>Asignados</th><th>En revisión</th><th>Cargado</th><th class="actions"></th></tr></thead>
                    <tbody>
                        @foreach ($statements as $s)
                            <tr>
                                <td>{{ $s->id }}</td>
                                <td class="strong">{{ $s->bank_name }} <span class="cell-muted">{{ $s->account_label }}</span></td>
                                <td class="nowrap">{{ $s->period_start?->format('Y-m-d') }} a {{ $s->period_end?->format('Y-m-d') }}</td>
                                <td class="num">{{ $s->lines_count }}</td>
                                <td class="num nowrap">{{ $money($s->credits_total) }}</td>
                                <td><span class="badge badge-success">{{ $s->assigned_count }}</span></td>
                                <td><span class="badge badge-{{ $s->review_count ? 'warning' : 'neutral' }}">{{ $s->review_count }}</span></td>
                                <td class="cell-muted nowrap">{{ $s->uploaded_at->format('Y-m-d H:i') }} · {{ $s->uploader->display_name }}</td>
                                <td class="actions"><a href="{{ route('statements.show', $s->id) }}" class="icon-btn" title="Ver movimientos"><i class="ph ph-eye"></i></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pager">{{ $statements->links('pagination::simple-default') }}</div>
        @endif
    </section>
@endsection
