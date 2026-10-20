<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * php artisan db:seed            → datos base obligatorios (idempotente)
 * php artisan db:seed --class=DemoDataSeeder   → datos de ejemplo opcionales
 *
 * El esquema (tablas, vistas, triggers) NO se crea con migraciones: se carga
 * desde database/schema/cartera_inmobiliaria.sql. Ver README.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            GeographySeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
