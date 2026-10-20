{{-- Formulario genérico de creación / edición. Los campos los define CrudController::fields(). --}}
@extends('layouts.panel')

@section('title', ($record ? 'Editar ' : 'Nuevo ').$page['singular'])

@section('breadcrumb')
    <a href="{{ $page['routes']['index'] }}">{{ $page['title'] }}</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">{{ $record ? 'Editar' : 'Nuevo' }}</span>
@endsection

@section('content')
    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph {{ $page['icon'] }}"></i></span>
            <div>
                <h1>{{ $record ? 'Editar '.$page['singular'] : 'Nuevo '.$page['singular'] }}</h1>
                @if ($label)
                    <span class="editing-chip"><i class="ph ph-pencil-simple"></i> {{ $label }}</span>
                @else
                    <p>Los campos marcados con <span style="color:var(--danger)">*</span> son obligatorios.</p>
                @endif
            </div>
        </div>
        <a href="{{ $page['routes']['index'] }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Volver</a>
    </div>

    @if ($top)
        @include($top['view'], $top['data'])
    @endif

    @if ($errors->any())
        <div class="alert alert-error form-card"><i class="ph ph-warning-circle"></i>
            <div>Revisa los campos marcados: {{ $errors->count() }} {{ $errors->count() === 1 ? 'error' : 'errores' }}.</div>
        </div>
    @endif

    <form method="POST" action="{{ $action }}" class="card form-card" novalidate
          @if ($fields->contains('type', 'file')) enctype="multipart/form-data" @endif>
        @csrf
        @if ($record) @method('PUT') @endif

        <div class="card-body">
            <div class="form-grid">
                @foreach ($fields as $field)
                    @include('crud.partials.field', ['field' => $field])
                @endforeach
            </div>

            <div class="form-actions">
                <a href="{{ $page['routes']['index'] }}" class="btn btn-ghost">Cancelar</a>
                <button type="submit" class="btn btn-primary"><i class="ph ph-check"></i> {{ $record ? 'Guardar cambios' : 'Crear '.$page['singular'] }}</button>
            </div>
        </div>
    </form>
@endsection
