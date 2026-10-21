<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Commercial\Client;
use App\Models\Commercial\ContactChannel;
use App\Services\Communications\ContactChannelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** CU-10 include: canales de contacto autorizados por el titular para comunicaciones de cobro (Ley 2300). */
class ContactChannelController extends Controller
{
    public function __construct(private ContactChannelService $channels) {}

    public function edit(int $id): View
    {
        $client = Client::with('person', 'consents')->findOrFail($id);

        return view('commercial.client-channels', [
            'client'   => $client,
            'current'  => $this->channels->channels($client),
            'labels'   => ContactChannel::CHANNELS,
            'defaults' => collect(ContactChannel::CHANNELS)->mapWithKeys(fn ($l, $c) => [$c => $this->channels->defaultValue($client, $c)]),
            'consent'  => $client->hasActiveConsent(),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $client = Client::with('person')->findOrFail($id);
        $data = $request->validate([
            'channels'           => ['array'],
            'channels.*.enabled' => ['nullable', 'boolean'],
            'channels.*.value'   => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $this->channels->sync($client, $data['channels'] ?? []);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Canales de contacto actualizados.');
    }
}
