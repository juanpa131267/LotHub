<!DOCTYPE html>
<html lang="es">
<body style="margin:0;padding:32px 16px;background:#f4f6fa;font-family:Arial,Helvetica,sans-serif;color:#11151c">
    <table role="presentation" width="100%" style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;border:1px solid #e0e5ee">
        <tr><td style="padding:28px 32px;border-bottom:1px solid #eef1f7">
            <strong style="font-size:20px;color:#10224a">Lot<span style="color:#305fb0">Hub</span></strong>
            <div style="color:#667085;font-size:13px;margin-top:4px">{{ $notification->type_label }}</div>
        </td></tr>
        <tr><td style="padding:28px 32px;font-size:15px;line-height:1.6">
            <p>{{ $notification->message }}</p>
            <p style="margin:28px 0">
                <a href="{{ route('portal.index') }}" style="background:#224690;color:#ffffff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Ir al portal de clientes</a>
            </p>
            <p style="color:#667085;font-size:12px">Recibes este mensaje porque autorizaste el correo electrónico como canal de contacto. Puedes cambiar tus
                preferencias en el portal o escribiendo a {{ config('lothub.company.email') }} (Ley 2300 de 2023).</p>
        </td></tr>
    </table>
</body>
</html>
