{{-- Pestañas de configuración del proyecto. Variables: $project, $active ('data'|'plan'|'photos'|'prices'). --}}
@php
    $user = auth()->user();
    $tabs = [
        'data'   => ['Datos', 'ph-buildings', route('projects.edit', $project->id), $user->hasPermission('proyectos.editar')],
        'plan'   => ['Plano', 'ph-map-trifold', route('projects.plan', $project->id), true],
        'photos' => ['Fotos', 'ph-images', route('projects.photos', $project->id), true],
        'prices' => ['Precios', 'ph-currency-circle-dollar', route('projects.prices', $project->id), true],
    ];
@endphp
<nav class="project-tabs" aria-label="Configuración del proyecto">
    @foreach ($tabs as $key => [$label, $icon, $url, $visible])
        @continue(! $visible)
        <a href="{{ $url }}" class="{{ $active === $key ? 'active' : '' }}" @if ($active === $key) aria-current="page" @endif>
            <i class="ph {{ $icon }}"></i> {{ $label }}
        </a>
    @endforeach
</nav>
