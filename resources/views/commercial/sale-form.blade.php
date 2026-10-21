{{-- Registro de negociación (SaleController::create, CU-12). --}}
@extends('layouts.panel')

@section('title', 'Nueva negociación')

@section('breadcrumb')
    <a href="{{ route('sales.index') }}">Negociaciones</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">Nueva</span>
@endsection

@section('content')
    @php
        // Filas de compradores: las enviadas (si hubo un error) o el cliente de la reserva.
        $oldBuyers = collect(old('buyers', []));
        $labels = \App\Models\Commercial\Client::with('person')->whereIn('id', $oldBuyers->pluck('client_id')->filter())->get()
            ->mapWithKeys(fn ($c) => [$c->id => "{$c->person->full_name} · {$c->client_code}"]);
        $rows = $oldBuyers->isNotEmpty()
            ? $oldBuyers->map(fn ($b, $i) => ['index' => $i, 'client_id' => $b['client_id'] ?? null, 'label' => $labels[$b['client_id'] ?? 0] ?? null, 'pct' => $b['ownership_pct'] ?? ''])
            : collect([['index' => 0, 'client_id' => $reservation?->client_id, 'label' => $reservation ? $reservation->client->person->full_name.' · '.$reservation->client->client_code : null, 'pct' => 100]]);
        $primary = old('primary', $rows->first()['index']);
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-handshake"></i></span>
            <div>
                <h1>Nueva negociación</h1>
                <p>Formaliza la venta de un lote. El lote pasa a VENDIDO y, si venía de una reserva, esta queda CONVERTIDA (RN-05).</p>
            </div>
        </div>
        <a href="{{ route('sales.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Volver</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-error form-card"><i class="ph ph-warning-circle"></i>
            <div>{{ $errors->count() === 1 ? $errors->first() : 'Revisa los campos marcados: '.$errors->count().' errores.' }}</div></div>
    @endif

    <form method="POST" action="{{ route('sales.store') }}" class="card form-card" enctype="multipart/form-data" novalidate id="sale-form">
        @csrf
        <div class="card-body">
            <div class="form-grid">
                <div class="form-section">Lote</div>
                @if ($reservation)
                    <div class="field w-full">
                        <div class="alert alert-info" style="margin:0"><i class="ph ph-bookmark-simple"></i>
                            <div>Se convierte la reserva #{{ $reservation->id }} de <strong>{{ $reservation->client->person->full_name }}</strong>
                                (separación $ {{ number_format((float) $reservation->deposit_amount, 0, ',', '.') }}). El cliente de la reserva debe figurar como comprador.</div></div>
                    </div>
                @endif
                <div class="field w-third">
                    <label for="project_id">Proyecto</label>
                    <select id="project_id" name="project_id" class="select">
                        <option value="">Todos</option>
                        @foreach ($projects as $p)
                            <option value="{{ $p->id }}" @selected(old('project_id', $lot?->project_id) == $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field w-two-thirds picker" data-picker="{{ route('lookup.lots', ['for' => 'negociacion']) }}" data-extra="#project_id" id="lot-picker">
                    <label for="lot_id">Lote <span class="req">*</span></label>
                    <input type="search" class="input" placeholder="Buscar por manzana y número: A-12" aria-label="Buscar lote" style="margin-bottom:.4rem">
                    <select id="lot_id" name="lot_id" class="select @error('lot_id') is-invalid @enderror" required
                            @if (old('lot_id', $lot?->id)) data-selected='@json(['id' => old('lot_id', $lot?->id), 'label' => $lot ? $lot->project->name.' · '.$lot->label : 'Lote #'.old('lot_id')])' @endif></select>
                    <div class="help" id="lot-info">Lotes DISPONIBLES o RESERVADOS (un lote reservado solo se negocia con su reserva).</div>
                    @error('lot_id') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="form-section">Compradores</div>
                <div class="field w-full">
                    <div class="table-wrap">
                        <table class="table" id="buyers">
                            <thead><tr><th style="width:55%">Cliente</th><th class="num">Participación %</th><th>Titular principal</th><th class="actions"><span hidden>Quitar</span></th></tr></thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    @include('commercial.partials.buyer-row', ['row' => $row, 'primary' => $primary])
                                @endforeach
                            </tbody>
                            <tfoot><tr><td><button type="button" class="btn btn-ghost btn-sm" id="add-buyer"><i class="ph ph-plus"></i> Agregar comprador</button></td>
                                <td class="num"><strong id="pct-total">100</strong> %</td><td colspan="2" class="cell-muted">Deben sumar 100 % (RN-07)</td></tr></tfoot>
                        </table>
                    </div>
                    @error('buyers') <div class="error">{{ $message }}</div> @enderror
                    @error('primary') <div class="error">{{ $message }}</div> @enderror
                    <div class="help">¿Un comprador no está registrado? <a href="{{ route('clients.create') }}" target="_blank">Registrar cliente</a> y búscalo de nuevo.</div>
                </div>

                <div class="form-section">Condiciones</div>
                <div class="field w-third">
                    <label for="agreed_price">Precio pactado (COP) <span class="req">*</span></label>
                    <input id="agreed_price" name="agreed_price" type="number" min="1" step="1" class="input @error('agreed_price') is-invalid @enderror"
                           value="{{ old('agreed_price', $lot ? (int) $lot->list_price : '') }}" required>
                    <div class="help" id="price-help">Puede diferir del precio de lista.</div>
                    @error('agreed_price') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field w-third">
                    <label for="financing_modality_id">Modalidad de pago <span class="req">*</span></label>
                    <select id="financing_modality_id" name="financing_modality_id" class="select @error('financing_modality_id') is-invalid @enderror" required>
                        @foreach ($modalities as $m)
                            <option value="{{ $m->id }}" @selected(old('financing_modality_id', 2) == $m->id)>{{ $m->name }}</option>
                        @endforeach
                    </select>
                    @error('financing_modality_id') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field w-third">
                    <label for="sale_date">Fecha <span class="req">*</span></label>
                    <input id="sale_date" name="sale_date" type="date" class="input @error('sale_date') is-invalid @enderror" value="{{ old('sale_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                    @error('sale_date') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="form-section">Documentos soporte (CU-25)</div>
                @include('crud.partials.field', ['field' => ['name' => 'promise_file', 'label' => 'Promesa de compraventa', 'type' => 'file', 'width' => 'half',
                    'accept' => '.pdf,.jpg,.jpeg,.png,.webp', 'max_bytes' => 10485760, 'help' => 'PDF o imagen, máximo 10 MB. Puedes cargarla después.']])
                @include('crud.partials.field', ['field' => ['name' => 'identity_file', 'label' => 'Documento de identidad', 'type' => 'file', 'width' => 'half',
                    'accept' => '.pdf,.jpg,.jpeg,.png,.webp', 'max_bytes' => 10485760]])
            </div>

            <div class="form-actions">
                <a href="{{ route('sales.index') }}" class="btn btn-ghost">Cancelar</a>
                <button type="submit" class="btn btn-primary" data-loading="Registrando…"><i class="ph ph-check"></i> Registrar negociación</button>
            </div>
        </div>
    </form>

    <template id="buyer-template">
        @include('commercial.partials.buyer-row', ['row' => ['index' => '__I__', 'client_id' => null, 'label' => null, 'pct' => ''], 'primary' => null])
    </template>
@endsection

@push('scripts')
    <script src="{{ asset('js/pickers.js') }}?v={{ filemtime(public_path('js/pickers.js')) }}"></script>
    <script>
        (() => {
            const body = document.querySelector('#buyers tbody');
            const total = document.getElementById('pct-total');
            const money = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
            let next = {{ (int) $rows->max('index') + 1 }};

            const sum = () => {
                const value = [...body.querySelectorAll('[data-pct]')].reduce((s, i) => s + (Number(i.value) || 0), 0);
                total.textContent = value.toLocaleString('es-CO', { maximumFractionDigits: 2 });
                total.style.color = Math.abs(value - 100) < 0.001 ? '' : 'var(--danger)';
            };

            document.getElementById('add-buyer').addEventListener('click', () => {
                const html = document.getElementById('buyer-template').innerHTML.replaceAll('__I__', next++);
                body.insertAdjacentHTML('beforeend', html);
                LotHubPickers.init(body.lastElementChild.querySelector('[data-picker]'));
            });
            body.addEventListener('input', (e) => e.target.matches('[data-pct]') && sum());
            body.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-remove]');
                if (!btn) return;
                if (body.children.length === 1) return LotHub.toast('La negociación necesita al menos un comprador.', 'error');
                btn.closest('tr').remove();
                if (!body.querySelector('input[name=primary]:checked')) body.querySelector('input[name=primary]').checked = true;
                sum();
            });
            sum();

            document.getElementById('lot-picker').addEventListener('picker:select', (e) => {
                const lot = e.detail;
                const info = document.getElementById('lot-info');
                if (!lot || lot.list_price === undefined) return;
                const price = document.getElementById('agreed_price');
                if (!price.value) price.value = Math.round(lot.list_price);
                document.getElementById('price-help').textContent = `Precio de lista: ${money.format(lot.list_price)} (${lot.area_m2.toLocaleString('es-CO')} m²).`;
                info.textContent = lot.reservation
                    ? `RESERVADO para ${lot.reservation.client} hasta ${lot.reservation.expires_at}: se convertirá esa reserva y su cliente debe ser comprador.`
                    : `${lot.status} · ${lot.area_m2.toLocaleString('es-CO')} m².`;
            });
        })();
    </script>
@endpush
