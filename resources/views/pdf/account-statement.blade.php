{{-- Estado de cuenta en PDF (AccountStatementService, CU-24). Estilos en línea: Dompdf no carga CSS externo. --}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Estado de cuenta {{ $obligation->obligation_code }}</title>
<style>
    @page { margin: 28px 34px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #11151c; }
    h1 { font-size: 17px; color: #10224a; margin: 0; }
    h2 { font-size: 11px; color: #224690; margin: 16px 0 6px; text-transform: uppercase; letter-spacing: .04em; }
    .muted { color: #667085; }
    table { width: 100%; border-collapse: collapse; }
    .grid td { padding: 3px 6px 3px 0; vertical-align: top; }
    .data th { background: #224690; color: #fff; text-align: left; padding: 5px 6px; font-size: 8.5px; }
    .data td { border-bottom: 1px solid #e0e5ee; padding: 4px 6px; }
    .num, .data th.num { text-align: right; white-space: nowrap; }
    .box { border: 1px solid #e0e5ee; border-radius: 6px; padding: 8px 10px; }
    .kpi { font-size: 14px; font-weight: bold; color: #10224a; }
    .tag { padding: 1px 6px; border-radius: 8px; font-size: 8px; }
    .t-PAGADA { background: #e5f5eb; color: #157a3d; } .t-VENCIDA { background: #fde9e7; color: #b42318; }
    .t-PAGO_PARCIAL { background: #fdf1d6; color: #9a5b06; } .t-PENDIENTE { background: #eef1f7; color: #475467; }
    .foot { margin-top: 18px; font-size: 8px; color: #667085; border-top: 1px solid #e0e5ee; padding-top: 6px; }
</style>
</head>
<body>
@php
    $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
    $holder = $sale->primaryClient();
    $agreement = $obligation->agreement;
    $labels = ['PAGADA' => 'Pagada', 'VENCIDA' => 'Vencida', 'PAGO_PARCIAL' => 'Pago parcial', 'PENDIENTE' => 'Pendiente'];
@endphp

<table class="grid">
    <tr>
        <td style="width:60%">
            <div style="font-size:16px;font-weight:bold;color:#10224a">Lot<span style="color:#305fb0">Hub</span></div>
            <div class="muted">{{ $company['name'] }} · {{ $company['city'] }} · {{ $company['phone'] }} · {{ $company['email'] }}</div>
        </td>
        <td style="text-align:right">
            <h1>Estado de cuenta</h1>
            <div class="muted">Corte: {{ $cutOff->format('Y-m-d H:i') }}</div>
            <div><strong>{{ $obligation->obligation_code }}</strong> · {{ $obligation->status->name }}</div>
        </td>
    </tr>
</table>

<h2>Comprador y lote</h2>
<table class="grid">
    <tr><td style="width:18%" class="muted">Titular</td><td style="width:32%"><strong>{{ $holder?->person->full_name }}</strong></td>
        <td style="width:18%" class="muted">Identificación</td><td>{{ $holder?->person->identificationType?->code }} {{ $holder?->person->identification_number }}</td></tr>
    @if ($sale->clients->count() > 1)
        <tr><td class="muted">Otros compradores</td><td colspan="3">{{ $sale->clients->where('pivot.is_primary', false)->map(fn ($c) => $c->person->full_name.' ('.number_format((float) $c->pivot->ownership_pct, 0).' %)')->implode(', ') }}</td></tr>
    @endif
    <tr><td class="muted">Proyecto</td><td>{{ $sale->lot->project->name }} ({{ $sale->lot->project->city?->name }})</td>
        <td class="muted">Lote</td><td>{{ $sale->lot->label }} · {{ number_format((float) $sale->lot->area_m2, 2, ',', '.') }} m²</td></tr>
    <tr><td class="muted">Negociación</td><td>{{ $sale->sale_code }} del {{ $sale->sale_date->format('Y-m-d') }}</td>
        <td class="muted">Precio pactado</td><td>{{ $money($sale->agreed_price) }}</td></tr>
    <tr><td class="muted">Acuerdo</td><td colspan="3">Versión {{ $agreement->version }} · {{ $agreement->installments_count }} cuotas {{ mb_strtolower($agreement->frequency->name) }}es ·
        tasa corriente {{ rtrim(rtrim(number_format((float) $agreement->monthly_interest_rate, 4, ',', '.'), '0'), ',') }} % y de mora {{ rtrim(rtrim(number_format((float) $agreement->late_interest_rate, 4, ',', '.'), '0'), ',') }} % mensual</td></tr>
</table>

<h2>Resumen</h2>
<table>
    <tr>
        <td class="box" style="width:25%"><div class="muted">Saldo pendiente</div><div class="kpi">{{ $money($summary->balance) }}</div></td>
        <td style="width:2%"></td>
        <td class="box" style="width:24%"><div class="muted">Pagado</div><div class="kpi">{{ $money($summary->total_paid) }}</div></td>
        <td style="width:2%"></td>
        <td class="box" style="width:24%"><div class="muted">Cuotas vencidas</div><div class="kpi">{{ $summary->overdue_installments }}</div><div class="muted">máx. {{ $summary->max_days_overdue }} días</div></td>
        <td style="width:2%"></td>
        <td class="box" style="width:21%"><div class="muted">Próximo vencimiento</div><div class="kpi">{{ $summary->next_due_date ?? '—' }}</div></td>
    </tr>
</table>

<h2>Plan de cuotas</h2>
<table class="data">
    <thead><tr><th>Cuota</th><th>Vence</th><th class="num">Valor</th><th class="num">Pagado</th><th class="num">Saldo</th><th class="num">Mora (días)</th><th>Estado</th></tr></thead>
    <tbody>
        @foreach ($rows as $r)
            <tr>
                <td>{{ (int) $r->installment_number === 0 ? 'Inicial' : $r->installment_number }}</td>
                <td>{{ $r->due_date }}</td>
                <td class="num">{{ $money($r->amount_due) }}</td>
                <td class="num">{{ $money($r->amount_paid) }}</td>
                <td class="num"><strong>{{ $money(max(0, $r->balance)) }}</strong></td>
                <td class="num">{{ $r->days_overdue ?: '—' }}</td>
                <td><span class="tag t-{{ $r->computed_status }}">{{ $labels[$r->computed_status] }}</span></td>
            </tr>
        @endforeach
    </tbody>
</table>

@if ($payments->isNotEmpty())
    <h2>Abonos aplicados</h2>
    <table class="data">
        <thead><tr><th>Fecha</th><th>Medio</th><th>Referencia</th><th class="num">Valor</th></tr></thead>
        <tbody>
            @foreach ($payments as $p)
                <tr><td>{{ $p->payment_date->format('Y-m-d') }}</td><td>{{ $p->method->name }}</td><td>{{ $p->bank_reference ?? '—' }}</td><td class="num">{{ $money($p->amount) }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="foot">
    Documento generado por {{ config('app.name') }}. Los valores incluyen los intereses de mora liquidados a la fecha de corte.
    Para consultas o reclamos sobre este estado de cuenta escribe a {{ $company['email'] }} o radícalos en el portal de clientes.
    Tus datos personales se tratan conforme a la Ley 1581 de 2012.
</div>
</body>
</html>
