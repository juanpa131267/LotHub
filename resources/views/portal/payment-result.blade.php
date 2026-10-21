{{-- Resultado del pago en línea al volver de Wompi (CU-20 paso 5). --}}
@extends('layouts.panel')

@section('title', 'Resultado del pago')

@section('content')
    @php
        $status = (int) $transaction->status_id;
        $pending = $status === 1;
        [$icon, $tone, $title, $message] = match (true) {
            $status === 2 && $transaction->payment !== null => ['ph-check-circle', 'success', 'Pago aplicado', 'Recibimos tu pago y ya se aplicó a tus cuotas.'],
            $status === 2 => ['ph-hourglass-medium', 'warning', 'Pago aprobado', 'Wompi aprobó el pago; estamos aplicándolo a tus cuotas.'],
            $status === 3 => ['ph-x-circle', 'danger', 'Pago rechazado', 'El pago fue rechazado por la entidad. Puedes intentarlo de nuevo con otro medio.'],
            $status === 4 => ['ph-warning-circle', 'danger', 'Pago fallido', 'Ocurrió un error en el pago. Puedes intentarlo de nuevo.'],
            $status === 5 => ['ph-clock-countdown', 'neutral', 'Pago expirado', 'El tiempo para completar el pago terminó. Puedes iniciar uno nuevo.'],
            default => ['ph-hourglass-medium', 'warning', 'Pago en verificación',
                $provider === 'APPROVED' ? 'Wompi informa que el pago fue aprobado. Lo aplicaremos en cuanto recibamos su confirmación firmada.'
                    : 'Estamos esperando la confirmación de Wompi. Esta página se actualiza sola.'],
        };
    @endphp

    @if ($pending)
        @push('head')<meta http-equiv="refresh" content="6">@endpush
    @endif

    <section class="card form-card" style="text-align:center;padding:2.5rem 1.5rem">
        <i class="ph-fill {{ $icon }}" style="font-size:3.5rem;color:var(--{{ $tone === 'neutral' ? 'muted' : $tone }})"></i>
        <h1 style="margin-top:.6rem">{{ $title }}</h1>
        <p class="muted" style="max-width:52ch;margin:.5rem auto 1.5rem">{{ $message }}</p>
        <ul class="list" style="max-width:420px;margin:0 auto 1.5rem;text-align:left">
            <li><span class="grow muted">Referencia</span><code>{{ $transaction->internal_reference }}</code></li>
            <li><span class="grow muted">Valor</span><strong>$ {{ number_format((float) $transaction->amount, 0, ',', '.') }}</strong></li>
            <li><span class="grow muted">Obligación</span><span>{{ $transaction->obligation->obligation_code }}</span></li>
            <li><span class="grow muted">Estado</span><span class="badge badge-{{ $tone }}">{{ $transaction->status->name }}</span></li>
        </ul>
        <a href="{{ route('portal.index') }}" class="btn btn-primary"><i class="ph ph-receipt"></i> Volver a mi estado de cuenta</a>
    </section>
@endsection
