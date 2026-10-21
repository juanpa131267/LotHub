<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Commercial\Client;
use App\Services\Portal\PortalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

/** US-CLI-003 Acceso del cliente al portal: invitar, reenviar el enlace o revocar. */
class ClientPortalController extends Controller
{
    public function __construct(private PortalAccessService $access) {}

    public function show(int $id): View
    {
        $client = Client::with('person.identificationType', 'consents')->findOrFail($id);

        return view('commercial.client-portal', [
            'client'  => $client,
            'user'    => $this->access->userOf($client),
            'status'  => $this->access->status($client),
            'consent' => $client->hasActiveConsent(),
        ]);
    }

    public function invite(int $id): RedirectResponse
    {
        $client = Client::with('person')->findOrFail($id);

        try {
            $user = $this->access->invite($client);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Enlace de activación enviado a {$client->person->email} (usuario {$user->username}).");
    }

    public function revoke(int $id): RedirectResponse
    {
        try {
            $this->access->revoke(Client::findOrFail($id));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Acceso al portal desactivado.');
    }
}
