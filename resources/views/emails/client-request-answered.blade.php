<!DOCTYPE html>
<html lang="es">
<body style="margin:0;padding:32px 16px;background:#f4f6fa;font-family:Arial,Helvetica,sans-serif;color:#11151c">
    <table role="presentation" width="100%" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;border:1px solid #e0e5ee">
        <tr><td style="padding:28px 32px;border-bottom:1px solid #eef1f7">
            <strong style="font-size:20px;color:#10224a">Lot<span style="color:#305fb0">Hub</span></strong>
            <div style="color:#667085;font-size:13px;margin-top:4px">{{ $request->type_label }} radicada el {{ $request->filed_at->format('Y-m-d') }}</div>
        </td></tr>
        <tr><td style="padding:28px 32px;font-size:15px;line-height:1.6">
            <p>Hola {{ $request->client->person->first_name }},</p>
            <p>Respondimos tu solicitud <strong>«{{ $request->subject }}»</strong>:</p>
            <div style="background:#f4f6fa;border-radius:8px;padding:14px 16px;white-space:pre-line">{{ $request->response }}</div>
            <p style="margin-top:24px">También puedes consultarla en el portal de clientes.</p>
            <p style="color:#667085;font-size:12px">Si no estás de acuerdo con la respuesta puedes presentar un nuevo reclamo o acudir a la
                Superintendencia de Industria y Comercio (Ley 1581 de 2012).</p>
        </td></tr>
    </table>
</body>
</html>
