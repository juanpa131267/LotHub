<?php

namespace Tests\Feature;

use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoPortfolioSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Los datos de demostración se generan con los servicios reales y cubren los casos de uso principales. */
class DemoDataSeederTest extends TestCase
{
    public function test_demo_data_covers_the_main_use_cases(): void
    {
        Storage::fake('local');
        DemoPortfolioSeeder::$scale = 0.3;

        Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);

        $count = fn (string $table, array $where = []) => DB::table($table)->where($where)->count();
        $this->assertSame(1122, $count('cities'));
        $this->assertGreaterThan(50, $count('clients'));
        $this->assertGreaterThan(30, $count('sales'));
        $this->assertGreaterThan(500, $count('installments'));
        $this->assertGreaterThan(100, $count('payments'));
        $this->assertGreaterThan(0, $count('sales', ['status_id' => 2]));            // pagadas
        $this->assertGreaterThan(0, $count('obligations', ['status_id' => 1]));      // activas
        $this->assertGreaterThan(0, $count('reservations', ['status_id' => 1]));     // reservas activas
        $this->assertGreaterThan(0, $count('reservations', ['status_id' => 3]));     // vencidas
        $this->assertGreaterThan(0, $count('late_charges'));
        $this->assertGreaterThan(0, $count('gateway_transactions', ['status_id' => 2]));
        $this->assertGreaterThan(0, $count('bank_statement_lines', ['status' => 'REVISION']));
        $this->assertGreaterThan(0, $count('legacy_import_rows'));
        $this->assertGreaterThan(0, $count('parallel_runs'));
        $this->assertGreaterThan(0, DB::table('v_installment_balance')->where('computed_status', 'VENCIDA')->count());

        // Un segundo intento no duplica los datos.
        $sales = $count('sales');
        Artisan::call('db:seed', ['--class' => DemoPortfolioSeeder::class, '--force' => true]);
        $this->assertSame($sales, $count('sales'));
    }

    protected function tearDown(): void
    {
        DemoPortfolioSeeder::$scale = 1.0;
        parent::tearDown();
    }
}
