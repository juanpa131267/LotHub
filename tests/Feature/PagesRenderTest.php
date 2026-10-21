<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Recorre las pantallas del panel con un administrador para detectar errores de renderizado. */
class PagesRenderTest extends TestCase
{
    public function test_admin_pages_render(): void
    {
        $admin = $this->user(['ADMINISTRADOR']);
        $client = $this->client();
        $lot = $this->lot();

        $routes = [
            route('dashboard'),
            route('profile.show'),
            route('password.change'),
            route('lots.index'),
            route('lots.import'),
            route('lots.history', $lot->id),
            route('clients.index'),
            route('clients.portal', $client->id),
            route('reservations.index'),
            route('reservations.create', ['lot_id' => $lot->id]),
            route('sales.index'),
            route('sales.create', ['lot_id' => $lot->id]),
            route('sales.cancellations'),
            route('agreements.pending'),
            route('obligations.index'),
            route('payments.index'),
        ];

        foreach ($routes as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        $this->actingAs($admin)->getJson(route('clients.data'))->assertOk()->assertJsonPath('data.0.portal', 'Sin acceso');
    }
}
