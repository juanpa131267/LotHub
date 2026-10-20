{{-- Layout de autenticación: panel ilustrado a la izquierda, formulario a la derecha. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    @include('partials.head')
</head>
<body data-flash-success="{{ session('success') }}" data-flash-error="{{ session('error') }}">
    <div class="auth">
        <section class="auth-art">
            <a href="{{ route('public.home') }}" class="brand" style="display:flex;align-items:center;gap:.6rem">
                <img src="{{ asset('img/logo-badge.svg') }}" alt="" class="brand-mark">
                <span class="brand-name">Lot<span>Hub</span></span>
            </a>
            <div>
                <h2>Tu cartera inmobiliaria, clara y al día.</h2>
                <p>Proyectos, lotes, clientes y abonos en un solo lugar, con trazabilidad completa de cada movimiento.</p>
                <div class="auth-points">
                    <div><i class="ph ph-grid-four"></i> Disponibilidad de lotes en tiempo real</div>
                    <div><i class="ph ph-receipt"></i> Estados de cuenta para cada comprador</div>
                    <div><i class="ph ph-shield-check"></i> Acceso por roles y auditoría de operaciones</div>
                </div>
            </div>
            <small style="color:rgb(var(--on-dark-rgb) / .5)">{{ config('lothub.company.city') }}</small>
        </section>

        <section class="auth-panel">
            <div class="auth-box">
                <img src="{{ asset('img/logo.svg') }}" alt="LotHub" class="auth-logo">
                @yield('content')
                <p class="auth-foot"><a href="{{ route('public.home') }}"><i class="ph ph-arrow-left"></i> Volver al sitio</a></p>
            </div>
        </section>
    </div>
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
</body>
</html>
