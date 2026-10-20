@extends('layouts.panel')

@section('title', 'Panel')

@section('content')
    @php
        $user = auth()->user();
        $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-hand-waving"></i></span>
            <div>
                <h1>Hola, {{ $user->person?->first_name ?? $user->username }}</h1>
                <p>Resumen de la operación comercial y de cartera al {{ now()->translatedFormat('j \d\e F \d\e Y') }}.</p>
            </div>
        </div>
        <div class="page-actions">
            @can('lotes.ver')
                <a href="{{ route('lots.index') }}" class="btn btn-ghost"><i class="ph ph-grid-four"></i> Ver lotes</a>
            @endcan
            @can('clientes.crear')
                <a href="{{ route('clients.create') }}" class="btn btn-primary"><i class="ph ph-user-plus"></i> Nuevo cliente</a>
            @endcan
        </div>
    </div>

    <div class="stats">
        <div class="card stat">
            <div class="stat-icon"><i class="ph ph-buildings"></i></div>
            <div class="stat-value">{{ $stats['projects_on_sale'] }}</div>
            <div class="stat-label">Proyectos en venta</div>
            @can('proyectos.ver')<a href="{{ route('projects.index') }}" class="stat-link"><i class="ph ph-arrow-up-right"></i></a>@endcan
        </div>
        <div class="card stat">
            <div class="stat-icon"><i class="ph ph-grid-four"></i></div>
            <div class="stat-value">{{ $stats['lots_available'] }}</div>
            <div class="stat-label">Lotes disponibles de {{ $stats['lots_total'] }}</div>
            @can('lotes.ver')<a href="{{ route('lots.index') }}" class="stat-link"><i class="ph ph-arrow-up-right"></i></a>@endcan
        </div>
        <div class="card stat">
            <div class="stat-icon accent"><i class="ph ph-handshake"></i></div>
            <div class="stat-value">{{ $stats['lots_reserved'] }} / {{ $stats['lots_sold'] }}</div>
            <div class="stat-label">Lotes reservados / vendidos</div>
        </div>
        <div class="card stat">
            <div class="stat-icon info"><i class="ph ph-address-book"></i></div>
            <div class="stat-value">{{ $stats['clients'] }}</div>
            <div class="stat-label">Clientes registrados</div>
            @can('clientes.ver')<a href="{{ route('clients.index') }}" class="stat-link"><i class="ph ph-arrow-up-right"></i></a>@endcan
        </div>
        <div class="card stat">
            <div class="stat-icon warn"><i class="ph ph-chat-circle-text"></i></div>
            <div class="stat-value">{{ $stats['requests_new'] }}</div>
            <div class="stat-label">Solicitudes sin atender</div>
            @can('solicitudes.ver')<a href="{{ route('information-requests.index') }}" class="stat-link"><i class="ph ph-arrow-up-right"></i></a>@endcan
        </div>
    </div>

    <div class="dash-grid">
        <div style="display:grid;gap:1.25rem">
            <section class="card">
                <div class="card-head">
                    <h3>Avance de ventas por proyecto</h3>
                    @can('proyectos.ver')<a href="{{ route('projects.index') }}" class="btn btn-ghost btn-sm">Ver todos</a>@endcan
                </div>
                @forelse ($projects as $project)
                    @php($sold = $project->total_lots ? round(100 * ($project->total_lots - $project->available_lots) / $project->total_lots) : 0)
                    <ul class="list"><li>
                        <div class="grow">
                            <div class="title">{{ $project->name }}</div>
                            <div class="sub">{{ $project->available_lots }} disponibles de {{ $project->total_lots }} lotes</div>
                            <div class="progress" style="margin-top:.5rem"><span style="width:{{ $sold }}%"></span></div>
                        </div>
                        <strong class="nowrap">{{ $sold }}%</strong>
                    </li></ul>
                @empty
                    <div class="empty"><i class="ph ph-buildings"></i>Aún no hay proyectos en venta o en planeación.</div>
                @endforelse
            </section>

            @if ($aging)
                <section class="card">
                    <div class="card-head"><h3>Cartera por edades de mora</h3></div>
                    <div class="card-body">
                        @php($maxBalance = max(1, collect($aging)->max('balance')))
                        @if (collect($aging)->sum('obligations') === 0)
                            <div class="empty" style="padding:1rem"><i class="ph ph-chart-bar"></i>La cartera se mostrará cuando existan obligaciones activas.</div>
                        @else
                            @foreach ($aging as $bucket)
                                <div class="aging-row">
                                    <span class="muted">{{ $bucket['label'] }}</span>
                                    <div class="progress"><span style="width:{{ round(100 * $bucket['balance'] / $maxBalance) }}%"></span></div>
                                    <strong class="nowrap">{{ $money($bucket['balance']) }}</strong>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </section>
            @endif
        </div>

        <div style="display:grid;gap:1.25rem">
            @can('solicitudes.ver')
                <section class="card">
                    <div class="card-head"><h3>Solicitudes nuevas</h3></div>
                    @forelse ($requests as $req)
                        <ul class="list"><li>
                            <span class="avatar" style="background:var(--accent-100);color:var(--accent-700)">{{ mb_strtoupper(mb_substr($req->full_name, 0, 1)) }}</span>
                            <div class="grow">
                                <div class="title">{{ $req->full_name }}</div>
                                <div class="sub">{{ $req->project?->name ?? 'Consulta general' }} · {{ $req->created_at->diffForHumans() }}</div>
                            </div>
                            @can('solicitudes.editar')
                                <a href="{{ route('information-requests.edit', $req->id) }}" class="icon-btn" title="Atender"><i class="ph ph-arrow-right"></i></a>
                            @endcan
                        </li></ul>
                    @empty
                        <div class="empty"><i class="ph ph-check-circle"></i>No hay solicitudes pendientes.</div>
                    @endforelse
                </section>
            @endcan

            @can('auditoria.ver')
                <section class="card">
                    <div class="card-head">
                        <h3>Actividad reciente</h3>
                        <a href="{{ route('audit.index') }}" class="btn btn-ghost btn-sm">Auditoría</a>
                    </div>
                    @forelse ($activity as $log)
                        <ul class="list"><li>
                            <i class="ph ph-clock-counter-clockwise muted" style="font-size:1.2rem"></i>
                            <div class="grow">
                                <div class="title">{{ $log->action }} · <code>{{ $log->table_name }}</code></div>
                                <div class="sub">{{ $log->user?->username ?? 'Sistema' }} · {{ $log->created_at->diffForHumans() }}</div>
                            </div>
                        </li></ul>
                    @empty
                        <div class="empty"><i class="ph ph-fingerprint"></i>Sin actividad registrada.</div>
                    @endforelse
                </section>
            @endcan
        </div>
    </div>
@endsection
