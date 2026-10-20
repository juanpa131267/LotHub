{{--
    Archivo más grande de lo que acepta PHP (HTTP 413). PHP descarta la petición antes de que
    Laravel inicie la sesión, así que esta página no usa layouts, sesión ni token CSRF.
    Variables: $limitMb, $sentMb, $back.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Archivo demasiado grande · {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap">
    <script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body style="min-height:100vh;display:grid;place-items:center;padding:1.5rem">
    <main class="card card-body" style="max-width:560px;text-align:center;padding:2.5rem 2rem">
        <img src="{{ asset('img/logo.svg') }}" alt="LotHub" style="height:42px;margin:0 auto 1.5rem">
        <i class="ph ph-file-x" style="font-size:3rem;color:var(--danger)"></i>
        <h1 style="font-size:1.4rem;margin-top:.75rem">El archivo es demasiado grande</h1>
        <p class="muted" style="margin:.75rem 0 1.25rem">
            Enviaste <strong>{{ $sentMb }} MB</strong> y el servidor acepta como máximo <strong>{{ $limitMb }} MB</strong>.
            No se guardó ningún cambio.
        </p>
        <div class="alert alert-info" style="text-align:left"><i class="ph ph-lightbulb"></i>
            <div>
                <strong>Cómo resolverlo</strong>
                <ul style="margin:.4rem 0 0;padding-left:1.1rem">
                    <li>Exporta desde AutoCAD solo el loteo (sin topografía, redes ni imágenes) como DXF.</li>
                    <li>O pide al administrador aumentar <code>post_max_size</code> y <code>upload_max_filesize</code> en <code>php.ini</code> y reiniciar el servidor.</li>
                </ul>
            </div>
        </div>
        <a href="{{ $back }}" class="btn btn-primary"><i class="ph ph-arrow-left"></i> Volver al formulario</a>
    </main>
</body>
</html>
