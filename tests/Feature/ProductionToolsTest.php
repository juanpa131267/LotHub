<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Sprint 11: verificación de preparación para producción. */
class ProductionToolsTest extends TestCase
{
    public function test_check_fails_with_development_settings(): void
    {
        config(['app.debug' => true, 'lothub.force_https' => false]);

        $this->assertSame(1, Artisan::call('lothub:check'));
        $this->assertStringContainsString('por resolver', Artisan::output());
    }

    public function test_check_passes_with_production_settings(): void
    {
        config([
            'app.debug' => false, 'app.url' => 'https://cartera.test', 'lothub.force_https' => true, 'session.secure' => true,
        ]);
        Cache::put('lothub:scheduler:heartbeat', now()->toDateTimeString());

        // Con la configuración segura solo quedan advertencias (correo, Wompi, respaldo): no bloquean.
        $this->assertSame(0, Artisan::call('lothub:check'));
        $this->assertSame(1, Artisan::call('lothub:check', ['--strict' => true]));
    }
}
