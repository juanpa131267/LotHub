{{-- Listado genérico. La configuración ($page) la arma CrudController::page(); la tabla la pinta public/js/crud.js. --}}
@extends('layouts.panel')

@section('title', $page['title'])

@section('content')
    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph {{ $page['icon'] }}"></i></span>
            <div>
                <h1>{{ $page['title'] }}</h1>
                <p>{{ $page['subtitle'] ?? '' }}</p>
            </div>
        </div>
        <div class="page-actions">
            @if ($page['trash'] && $page['can']['delete'])
                <a href="{{ $page['routes']['trash'] }}" class="btn btn-ghost"><i class="ph ph-trash"></i> Papelera</a>
            @endif
            @if ($page['can']['create'])
                <a href="{{ $page['routes']['create'] }}" class="btn btn-primary"><i class="ph ph-plus"></i> Nuevo {{ $page['singular'] }}</a>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="toolbar">
            <label class="input-icon search">
                <i class="ph ph-magnifying-glass"></i>
                <input type="search" id="crud-search" class="input" placeholder="Buscar…" autocomplete="off" aria-label="Buscar">
            </label>

            @foreach ($page['filters'] as $filter)
                <select class="select" data-filter="{{ $filter['name'] }}" aria-label="{{ $filter['label'] }}">
                    <option value="">{{ $filter['label'] }}: todos</option>
                    @foreach ($filter['options'] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            @endforeach

            <span class="info" id="crud-info"></span>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        @foreach ($page['columns'] as $col)
                            <th class="{{ in_array($col['type'] ?? '', ['number', 'money']) ? 'num' : '' }}">{{ $col['label'] }}</th>
                        @endforeach
                        <th class="actions"><span hidden>Acciones</span></th>
                    </tr>
                </thead>
                <tbody id="crud-body"></tbody>
            </table>
        </div>

        <div class="pager">
            <label class="nowrap">Filas
                <select id="crud-per-page" class="select" style="width:auto;height:34px;margin-left:.4rem">
                    @foreach ([15, 25, 50, 100] as $n)
                        <option value="{{ $n }}">{{ $n }}</option>
                    @endforeach
                </select>
            </label>
            <div class="pager-buttons" id="crud-pager"></div>
        </div>
    </div>

    @php
        $clientConfig = \Illuminate\Support\Arr::only($page, ['columns', 'routes', 'can', 'trash', 'singular', 'row_actions']);
    @endphp
    <script type="application/json" id="crud-config">{!! json_encode($clientConfig, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
@endsection

@push('scripts')
    <script src="{{ asset('js/crud.js') }}?v={{ filemtime(public_path('js/crud.js')) }}"></script>
@endpush
