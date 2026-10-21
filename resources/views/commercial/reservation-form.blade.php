{{-- Registro de reserva (ReservationController::create, CU-11). --}}
@extends('layouts.panel')

@section('title', 'Nueva reserva')

@section('breadcrumb')
    <a href="{{ route('reservations.index') }}">Reservas</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">Nueva</span>
@endsection

@section('content')
    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-bookmark-simple"></i></span>
            <div>
                <h1>Nueva reserva</h1>
                <p>Separa un lote DISPONIBLE para un cliente. Al vencer sin convertirse en negociación, el lote se libera solo (RN-03).</p>
            </div>
        </div>
        <a href="{{ route('reservations.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Volver</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-error form-card"><i class="ph ph-warning-circle"></i><div>{{ $errors->first() }}</div></div>
    @endif

    <form method="POST" action="{{ route('reservations.store') }}" class="card form-card" novalidate>
        @csrf
        <div class="card-body">
            <div class="form-grid">
                <div class="form-section">Lote</div>
                <div class="field w-third">
                    <label for="project_id">Proyecto</label>
                    <select id="project_id" name="project_id" class="select">
                        <option value="">Todos</option>
                        @foreach ($projects as $p)
                            <option value="{{ $p->id }}" @selected(old('project_id', $lot?->project_id) == $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field w-two-thirds picker" data-picker="{{ route('lookup.lots', ['for' => 'reserva']) }}" data-extra="#project_id" id="lot-picker">
                    <label for="lot_id">Lote disponible <span class="req">*</span></label>
                    <input type="search" class="input" placeholder="Buscar por manzana y número: A-12" aria-label="Buscar lote" style="margin-bottom:.4rem">
                    <select id="lot_id" name="lot_id" class="select @error('lot_id') is-invalid @enderror" required
                            @if (old('lot_id', $lot?->id)) data-selected='@json(['id' => old('lot_id', $lot?->id), 'label' => $lot ? $lot->project->name.' · '.$lot->label : 'Lote #'.old('lot_id')])' @endif></select>
                    <div class="help" id="lot-info">Solo aparecen lotes DISPONIBLES (RN-04).</div>
                    @error('lot_id') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="form-section">Cliente</div>
                <div class="field w-full picker" data-picker="{{ route('lookup.clients') }}">
                    <label for="client_id">Cliente <span class="req">*</span></label>
                    <input type="search" class="input" placeholder="Nombre, identificación o código" aria-label="Buscar cliente" style="margin-bottom:.4rem">
                    <select id="client_id" name="client_id" class="select @error('client_id') is-invalid @enderror" required
                            @if (old('client_id')) data-selected='@json(['id' => old('client_id'), 'label' => 'Cliente seleccionado'])' @endif></select>
                    <div class="help">¿No está registrado? <a href="{{ route('clients.create') }}" target="_blank">Registrar cliente</a> y vuelve a buscarlo.</div>
                    @error('client_id') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="form-section">Condiciones</div>
                <div class="field w-third">
                    <label for="deposit_amount">Valor de separación (COP) <span class="req">*</span></label>
                    <input id="deposit_amount" name="deposit_amount" type="number" min="0" step="1" class="input @error('deposit_amount') is-invalid @enderror"
                           value="{{ old('deposit_amount', 0) }}" required>
                    @error('deposit_amount') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field w-third">
                    <label for="expires_at">Vence <span class="req">*</span></label>
                    <input id="expires_at" name="expires_at" type="datetime-local" class="input @error('expires_at') is-invalid @enderror"
                           value="{{ old('expires_at', now()->addDays($defaultDays)->setTime(18, 0)->format('Y-m-d\TH:i')) }}"
                           min="{{ now()->format('Y-m-d\TH:i') }}" max="{{ now()->addDays($maxDays)->format('Y-m-d\T23:59') }}" required>
                    <div class="help">Máximo {{ $maxDays }} días.</div>
                    @error('expires_at') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field w-third">
                    <label for="notes">Observaciones</label>
                    <input id="notes" name="notes" class="input" maxlength="200" value="{{ old('notes') }}">
                </div>
            </div>

            <div class="form-actions">
                <a href="{{ route('reservations.index') }}" class="btn btn-ghost">Cancelar</a>
                <button type="submit" class="btn btn-primary"><i class="ph ph-check"></i> Reservar lote</button>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
    <script src="{{ asset('js/pickers.js') }}?v={{ filemtime(public_path('js/pickers.js')) }}"></script>
    <script>
        document.getElementById('lot-picker').addEventListener('picker:select', (e) => {
            const lot = e.detail;
            const money = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
            document.getElementById('lot-info').textContent = lot && lot.list_price !== undefined
                ? `${lot.area_m2.toLocaleString('es-CO')} m² · precio de lista ${money.format(lot.list_price)}`
                : 'Solo aparecen lotes DISPONIBLES (RN-04).';
        });
    </script>
@endpush
