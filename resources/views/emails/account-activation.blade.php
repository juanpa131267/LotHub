<!DOCTYPE html>
<html lang="es">
<body style="margin:0;padding:32px 16px;background:#f4f6fa;font-family:Arial,Helvetica,sans-serif;color:#11151c">
    <table role="presentation" width="100%" style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;border:1px solid #e0e5ee">
        <tr><td style="padding:28px 32px;border-bottom:1px solid #eef1f7">
            <strong style="font-size:20px;color:#10224a">Lot<span style="color:#305fb0">Hub</span></strong>
        </td></tr>
        <tr><td style="padding:28px 32px;font-size:15px;line-height:1.6">
            <p>Hola {{ $user->display_name }},</p>
            <p>{{ config('lothub.company.name') }} habilitó tu acceso al portal de clientes. Allí podrás consultar tu estado de cuenta, el plan de cuotas y pagar en línea.</p>
            <p>Tu usuario es <strong>{{ $user->username }}</strong>.</p>
            <p style="margin:28px 0">
                <a href="{{ $url }}" style="background:#224690;color:#ffffff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Crear mi contraseña</a>
            </p>
            <p style="color:#667085;font-size:13px">El enlace vence en {{ $hours }} horas y solo puede usarse una vez. Si no reconoces esta solicitud, ignora este mensaje.</p>
        </td></tr>
    </table>
</body>
</html>
