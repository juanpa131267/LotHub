{{-- Matriz rol ↔ permisos. Cada interruptor guarda al instante (RolePermissionController::toggle). --}}
@extends('layouts.panel')

@section('title', 'Permisos por rol')

@section('content')
    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-toggle-right"></i></span>
            <div>
                <h1>Permisos por rol</h1>
                <p>Selecciona un rol y activa los permisos que necesita. Los cambios se guardan automáticamente y quedan en auditoría.</p>
            </div>
        </div>
    </div>

    @if (! $role)
        <div class="card empty"><i class="ph ph-shield-warning"></i>No hay roles registrados.</div>
    @else
        <div class="perm-layout">
            <nav class="card role-list" aria-label="Roles">
                @foreach ($roles as $r)
                    <a href="{{ route('role-permissions.index', ['rol' => $r->id]) }}" class="role-item {{ $r->is($role) ? 'active' : '' }}">
                        <span>{{ str_replace('_', ' ', $r->name) }}</span>
                        <span class="count" @if ($r->is($role)) id="perm-count" @endif>{{ $r->permissions_count }}</span>
                    </a>
                @endforeach
            </nav>

            <div>
                @if ($role->isProtected())
                    <div class="alert alert-info"><i class="ph ph-info"></i>
                        <div><strong>{{ $role->name }}</strong> tiene <code>{{ config('lothub.super_permission') }}</code>, que concede acceso a todo sin importar los demás permisos.</div>
                    </div>
                @endif

                @foreach ($groups as $group)
                    <section class="card perm-group">
                        <div class="card-head"><h3>{{ $group['label'] }}</h3></div>
                        @foreach ($group['permissions'] as $perm)
                            <label class="perm-row">
                                <div class="grow">
                                    <code>{{ $perm->code }}</code>
                                    <div class="desc">{{ $perm->description }} @unless ($perm->is_active)<span class="badge badge-neutral">Inactivo</span>@endunless</div>
                                </div>
                                <span class="switch">
                                    <input type="checkbox" data-url="{{ route('role-permissions.toggle', [$role, $perm]) }}"
                                           @checked(in_array($perm->id, $assigned, true)) aria-label="{{ $perm->code }}">
                                    <span></span>
                                </span>
                            </label>
                        @endforeach
                    </section>
                @endforeach
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script>
    document.addEventListener('change', async (e) => {
        const input = e.target.closest('[data-url]');
        if (!input) return;
        input.disabled = true;
        try {
            const res = await LotHub.request(input.dataset.url, { method: 'POST', body: { enabled: input.checked } });
            document.getElementById('perm-count').textContent = res.count;
            LotHub.toast(input.checked ? 'Permiso asignado.' : 'Permiso retirado.');
        } catch (err) {
            input.checked = !input.checked;
            LotHub.toast(err.message, 'error');
        } finally {
            input.disabled = false;
        }
    });
</script>
@endpush
