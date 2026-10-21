{{-- Bandeja de solicitudes de anulación o desistimiento pendientes (CU-15, Director comercial). --}}
@extends('layouts.panel')

@section('title', 'Anulaciones pendientes')

@section('content')
    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-seal-warning"></i></span>
            <div>
                <h1>Anulaciones pendientes</h1>
                <p>Solicitudes de anulación o desistimiento que esperan tu aprobación (RN-08).</p>
            </div>
        </div>
    </div>

    <section class="card">
        @if ($requests->isEmpty())
            <div class="empty"><i class="ph ph-check-circle"></i>No hay solicitudes pendientes.</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Solicitada</th><th>Negociación</th><th>Lote</th><th>Titular</th><th>Tipo</th><th>Motivo</th><th>Solicitó</th><th class="actions"></th></tr></thead>
                    <tbody>
                        @foreach ($requests as $req)
                            <tr>
                                <td class="nowrap">{{ $req->requested_at->format('Y-m-d H:i') }}</td>
                                <td><code>{{ $req->sale->sale_code }}</code></td>
                                <td>{{ $req->sale->lot->project->name }} · {{ $req->sale->lot->label }}</td>
                                <td>{{ $req->sale->primaryClient()?->person->full_name }}</td>
                                <td>{{ $req->type_label }}</td>
                                <td>{{ $req->reason }}</td>
                                <td class="cell-muted">{{ $req->requester->display_name }}</td>
                                <td class="actions"><a href="{{ route('sales.show', $req->sale_id) }}" class="btn btn-ghost btn-sm">Revisar</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
