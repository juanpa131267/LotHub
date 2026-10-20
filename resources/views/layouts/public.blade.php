{{-- Layout del sitio público (visitantes sin sesión). --}}
<!DOCTYPE html>
<html lang="es">
<head>
    @include('partials.head')
    <meta name="description" content="Lotes con financiación directa en Girardot y el Alto Magdalena. Consulta disponibilidad y precios.">
</head>
<body data-flash-success="{{ session('success') }}" data-flash-error="{{ session('error') }}">

    <header class="site-header">
        <div class="container">
            <a href="{{ route('public.home') }}" class="brand">
                <img src="{{ asset('img/logo-badge.svg') }}" alt="" class="brand-mark">
                <span class="brand-name">Lot<span>Hub</span></span>
            </a>
            <nav class="site-nav">
                <a href="{{ route('public.home') }}#proyectos">Proyectos</a>
                <a href="{{ route('public.home') }}#como-funciona">Cómo funciona</a>
                <a href="{{ route('public.home') }}#contacto">Contacto</a>
            </nav>
            @auth
                <a href="{{ route('home.redirect') }}" class="btn btn-accent btn-sm"><i class="ph ph-squares-four"></i> Mi panel</a>
            @else
                <a href="{{ route('login') }}" class="btn btn-accent btn-sm"><i class="ph ph-sign-in"></i> Ingresar</a>
            @endauth
        </div>
    </header>

    @yield('content')

    <footer class="site-footer">
        <div class="container">
            <span>© {{ date('Y') }} {{ config('lothub.company.name') }} · {{ config('lothub.company.city') }}</span>
            <span>Tus datos se tratan conforme a la Ley 1581 de 2012.</span>
        </div>
    </footer>

    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
    @stack('scripts')
</body>
</html>
