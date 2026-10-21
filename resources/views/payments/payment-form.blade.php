{{-- CU-17.1 Registrar abono manual (efectivo, consignación o transferencia) con su soporte. --}}
@extends('layouts.panel')

@section('title', 'Registrar abono · '.$obligation->obligation_code)

@section('breadcrumb')
    <a href="{{ route('obligations.index') }}">Obligaciones</a>
    <i class="ph ph-caret-right"></i>
    <a href="{{ route('obligations.show', $obligation->id) }}">{{ $obligation->obligation_code }}</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">Registrar abono</span>
@endsection

@section('content')
    @php
        $sale = $obligation->agreement->sale;
        $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
        $primary = $buyers->firstWhere('pivot.is_primary', true);
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-coins"></i></span>
            <div>
                <h1>Registrar abono</h1>
                <p>{{ $obligation->obligation_code }} · {{ $sale->lot->project->name }} · {{ $sale->lot->label }} · saldo {{ $money($summary->balance) }}</p>
            </div>
        </div>
        <a href="{{ route('obligations.show', $obligation->id) }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Volver</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-error form-card"><i class="ph ph-warning-circle"></i><div>Revisa los campos marcados: {{ $errors->count() }} error(es).</div></div>
    @endif

    <form method="POST" action="{{ route('payments.store-for', $obligation->id) }}" enctype="multipart/form-data" class="card form-card" novalidate>
        @csrf
        <div class="card-body">
            <p class="muted" style="font-size:.88rem;margin-bottom:1rem">El abono se aplica solo, desde la cuota vencida más antigua y en el orden interés de mora → interés corriente → capital (RN-14).
                Si el valor supera el saldo, el excedente queda como saldo a favor.</p>
            <div class="form-grid">
                @include('crud.partials.field', ['field' => ['name' => 'payer_client_id', 'label' => 'Pagador', 'type' => 'select', 'required' => true, 'width' => 'half',
                    'options' => $buyers->mapWithKeys(fn ($c) => [$c->id => $c->person->full_name.($c->pivot->is_primary ? ' (titular)' : '')])->all(), 'value' => $primary?->id,
                    'help' => 'Debe ser comprador de la negociación (CU-17).']])
                @include('crud.partials.field', ['field' => ['name' => 'payment_date', 'label' => 'Fecha del pago', 'type' => 'date', 'required' => true, 'width' => 'half',
                    'value' => now()->toDateString(), 'max' => now()->toDateString()]])
                @include('crud.partials.field', ['field' => ['name' => 'amount', 'label' => 'Valor (COP)', 'type' => 'number', 'required' => true, 'width' => 'third',
                    'step' => '1', 'min' => '1', 'value' => $summary->next_due_date ? null : null, 'placeholder' => 'Próxima cuota: '.($summary->next_due_date ?? '—')]])
                @include('crud.partials.field', ['field' => ['name' => 'payment_method_id', 'label' => 'Medio de pago', 'type' => 'select', 'required' => true, 'width' => 'third',
                    'options' => $methods->pluck('name', 'id')->all(), 'value' => $methods->firstWhere('code', 'CONSIGNACION')?->id]])
                @include('crud.partials.field', ['field' => ['name' => 'bank_reference', 'label' => 'Referencia bancaria', 'width' => 'third', 'maxlength' => 80,
                    'help' => 'Número de la consignación o transferencia.']])
                @include('crud.partials.field', ['field' => ['name' => 'support_file', 'label' => 'Soporte de pago', 'type' => 'file', 'required' => true,
                    'accept' => '.pdf,.jpg,.jpeg,.png,.webp', 'max_bytes' => 10485760, 'help' => 'Comprobante o recibo (CU-25). PDF o imagen, máximo 10 MB.']])
                @if (session('duplicate'))
                    @include('crud.partials.field', ['field' => ['name' => 'confirm_duplicate', 'label' => 'Posible duplicado', 'type' => 'checkbox',
                        'checkbox_label' => 'Revisé el abono #'.session('duplicate').' y este pago es distinto']])
                @endif
            </div>
            <div class="form-actions">
                <a href="{{ route('obligations.show', $obligation->id) }}" class="btn btn-ghost">Cancelar</a>
                <button type="submit" class="btn btn-primary" data-loading="Registrando…"><i class="ph ph-check"></i> Registrar y aplicar</button>
            </div>
        </div>
    </form>
@endsection
