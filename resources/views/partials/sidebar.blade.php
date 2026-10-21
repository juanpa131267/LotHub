@php
    /*
     | Menú lateral. Para agregar una opción basta con añadirla aquí:
     | [etiqueta, ícono Phosphor, nombre de ruta, permiso requerido, patrón de ruta activa]
     | Los grupos sin opciones visibles para el usuario no se muestran.
     */
    $menu = [
        'General' => [
            ['Panel', 'ph-squares-four', 'dashboard', 'panel.acceder', 'dashboard'],
            ['Mi estado de cuenta', 'ph-receipt', 'portal.index', 'portal.acceder', 'portal.*'],
        ],
        'Comercial' => [
            ['Proyectos', 'ph-buildings', 'projects.index', 'proyectos.ver', 'projects.*'],
            ['Etapas', 'ph-stack', 'stages.index', 'proyectos.ver', 'stages.*'],
            ['Lotes', 'ph-grid-four', 'lots.index', 'lotes.ver', 'lots.*'],
            ['Clientes', 'ph-address-book', 'clients.index', 'clientes.ver', 'clients.*'],
            ['Solicitudes', 'ph-chat-circle-text', 'information-requests.index', 'solicitudes.ver', 'information-requests.*'],
            ['Reservas', 'ph-bookmark-simple', 'reservations.index', 'reservas.ver', 'reservations.*'],
            ['Negociaciones', 'ph-handshake', 'sales.index', 'negociaciones.ver', 'sales.index|sales.create|sales.show'],
            ['Anulaciones', 'ph-seal-warning', 'sales.cancellations', 'negociaciones.aprobar_anulacion', 'sales.cancellations'],
        ],
        'Cartera' => [
            ['Acuerdos pendientes', 'ph-file-dashed', 'agreements.pending', 'acuerdos.ver', 'agreements.*'],
            ['Obligaciones', 'ph-receipt', 'obligations.index', 'acuerdos.ver', 'obligations.*'],
        ],
        'Catálogos' => [
            ['Departamentos', 'ph-map-trifold', 'states.index', 'catalogos.ver', 'states.*'],
            ['Municipios', 'ph-map-pin-area', 'cities.index', 'catalogos.ver', 'cities.*'],
            ['Tipos de identificación', 'ph-identification-card', 'identification-types.index', 'catalogos.ver', 'identification-types.*'],
            ['Modalidades de pago', 'ph-hand-coins', 'financing-modalities.index', 'catalogos.ver', 'financing-modalities.*'],
            ['Periodicidades', 'ph-calendar-dots', 'payment-frequencies.index', 'catalogos.ver', 'payment-frequencies.*'],
            ['Medios de pago', 'ph-credit-card', 'payment-methods.index', 'catalogos.ver', 'payment-methods.*'],
            ['Tipos de documento', 'ph-files', 'document-types.index', 'catalogos.ver', 'document-types.*'],
        ],
        'Seguridad' => [
            ['Usuarios', 'ph-users-three', 'users.index', 'usuarios.ver', 'users.*'],
            ['Roles', 'ph-shield-check', 'roles.index', 'roles.ver', 'roles.*'],
            ['Permisos', 'ph-key', 'permissions.index', 'permisos.ver', 'permissions.*'],
            ['Permisos por rol', 'ph-toggle-right', 'role-permissions.index', 'roles.asignar_permisos', 'role-permissions.*'],
            ['Auditoría', 'ph-fingerprint', 'audit.index', 'auditoria.ver', 'audit.*'],
        ],
    ];

    $user = auth()->user();
@endphp

<aside class="sidebar" aria-label="Menú principal">
    <a href="{{ route('home.redirect') }}" class="sidebar-brand">
        <img src="{{ asset('img/logo-badge.svg') }}" alt="" class="brand-mark">
        <span class="brand-name">Lot<span>Hub</span></span>
    </a>

    <nav class="sidebar-nav">
        @foreach ($menu as $group => $items)
            @php($visible = array_filter($items, fn ($i) => $user->hasPermission($i[3])))
            @continue(! $visible)

            <div class="nav-label">{{ $group }}</div>
            @foreach ($visible as [$label, $icon, $route, $permission, $pattern])
                <a href="{{ route($route) }}" class="nav-link {{ request()->routeIs(...explode('|', $pattern)) ? 'active' : '' }}">
                    <i class="ph {{ $icon }}"></i> <span>{{ $label }}</span>
                </a>
            @endforeach
        @endforeach
    </nav>

    <div class="sidebar-foot">Gestión de cartera inmobiliaria · v0.1</div>
</aside>
