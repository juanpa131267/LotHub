<?php

namespace App\Providers;

use App\Models\Catalogs;
use App\Models\Commercial;
use App\Models\Security;
use App\Observers\AuditObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Modelos cuyos cambios quedan en audit_logs. */
    private const AUDITED_MODELS = [
        Security\User::class, Security\Person::class, Security\Role::class, Security\Permission::class,
        Catalogs\State::class, Catalogs\City::class, Catalogs\IdentificationType::class,
        Catalogs\FinancingModality::class, Catalogs\PaymentFrequency::class,
        Catalogs\PaymentMethod::class, Catalogs\DocumentType::class,
        Commercial\Project::class, Commercial\ProjectStage::class, Commercial\Lot::class,
        Commercial\Client::class, Commercial\InformationRequest::class, Commercial\DataConsent::class,
    ];

    public function boot(): void
    {
        foreach (self::AUDITED_MODELS as $model) {
            $model::observe(AuditObserver::class);
        }

        // US-TEC-001: los enlaces que genera la aplicación (correos, redirecciones) usan https.
        if (config('lothub.force_https')) {
            URL::forceScheme('https');
        }

        // Permite @can('lotes.editar') en Blade y $user->can(...) en código.
        Gate::before(fn (Security\User $user, string $ability) => $user->hasPermission($ability) ?: null);

        $this->registerRateLimiters();
        $this->registerCrudRouteMacro();
    }

    /**
     * Límites por acción (uso: ->middleware('throttle:login')). Se usan limitadores
     * con nombre porque "throttle:N,M" comparte un solo contador por IP entre rutas.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(10)->by('login|'.$r->ip()));
        RateLimiter::for('password', fn (Request $r) => Limit::perMinute(5)->by('password|'.$r->ip()));
        RateLimiter::for('public-form', fn (Request $r) => Limit::perMinute(5)->by('public-form|'.$r->ip()));
    }

    /**
     * Route::crud('/comercial/lotes', LotController::class)
     *
     * Registra las rutas estándar de un CrudController usando sus constantes
     * ROUTE (nombre: lots.index, lots.data...) y PERMISSION (prefijo: lotes.ver,
     * lotes.crear, lotes.editar, lotes.eliminar). Si el modelo usa SoftDeletes
     * agrega la papelera y la restauración.
     */
    private function registerCrudRouteMacro(): void
    {
        Route::macro('crud', function (string $uri, string $controller) {
            $name = $controller::ROUTE;
            $perm = $controller::PERMISSION;

            Route::controller($controller)->prefix($uri)->name("{$name}.")->group(function () use ($perm, $controller) {
                Route::get('/', 'index')->name('index')->middleware("permission:{$perm}.ver");
                Route::get('/datos', 'data')->name('data')->middleware("permission:{$perm}.ver");
                Route::get('/crear', 'create')->name('create')->middleware("permission:{$perm}.crear");
                Route::post('/', 'store')->name('store')->middleware("permission:{$perm}.crear");
                Route::get('/{id}/editar', 'edit')->name('edit')->middleware("permission:{$perm}.editar")->whereNumber('id');
                Route::put('/{id}', 'update')->name('update')->middleware("permission:{$perm}.editar")->whereNumber('id');
                Route::delete('/{id}', 'destroy')->name('destroy')->middleware("permission:{$perm}.eliminar")->whereNumber('id');

                if (in_array(SoftDeletes::class, class_uses_recursive($controller::MODEL), true)) {
                    Route::get('/papelera', 'trash')->name('trash')->middleware("permission:{$perm}.eliminar");
                    Route::post('/{id}/restaurar', 'restore')->name('restore')->middleware("permission:{$perm}.eliminar")->whereNumber('id');
                }
            });
        });
    }
}
