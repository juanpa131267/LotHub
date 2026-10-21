{{--
    Layout del panel interno (personal y portal del cliente).
    Secciones: title, breadcrumb (opcional), content.  Stack: scripts.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    @include('partials.head')
</head>
<body data-flash-success="{{ session('success') }}" data-flash-error="{{ session('error') }}">

    @include('partials.sidebar')
    <div class="sidebar-backdrop" data-toggle-sidebar></div>

    <div class="main">
        <header class="topbar">
            <button class="icon-btn menu-toggle" data-toggle-sidebar aria-label="Abrir menú"><i class="ph ph-list"></i></button>

            <nav class="breadcrumb" aria-label="Ruta">
                @hasSection('breadcrumb')
                    @yield('breadcrumb')
                @else
                    <span class="current">@yield('title')</span>
                @endif
            </nav>

            <div class="topbar-actions">
                <a href="{{ route('public.home') }}" class="icon-btn" title="Ver sitio público" target="_blank"><i class="ph ph-globe-hemisphere-west"></i></a>

                @php($me = auth()->user())
                <div class="user-menu">
                    <button class="user-trigger" data-dropdown aria-haspopup="true">
                        <span class="avatar">{{ $me->initials }}</span>
                        <span class="user-meta">
                            <strong>{{ $me->display_name }}</strong>
                            <small>{{ $me->roles->pluck('name')->map(fn ($r) => str_replace('_', ' ', $r))->first() ?? 'Usuario' }}</small>
                        </span>
                        <i class="ph ph-caret-down muted"></i>
                    </button>
                    <div class="dropdown" role="menu">
                        <a href="{{ route('home.redirect') }}"><i class="ph ph-house"></i> Inicio</a>
                        <a href="{{ route('profile.show') }}"><i class="ph ph-user-circle"></i> Mi perfil</a>
                        <a href="{{ route('password.change') }}"><i class="ph ph-lock-key"></i> Cambiar contraseña</a>
                        <hr>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="danger"><i class="ph ph-sign-out"></i> Cerrar sesión</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="content">
            @yield('content')
        </main>
    </div>

    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
    @stack('scripts')
</body>
</html>
