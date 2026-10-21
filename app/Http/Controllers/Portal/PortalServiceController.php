<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Commercial\ClientNotification;
use App\Models\Commercial\ClientRequest;
use App\Models\Commercial\ContactChannel;
use App\Services\Communications\ClientRequestService;
use App\Services\Communications\ContactChannelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Atención al titular desde el portal: avisos recibidos, preferencias de contacto (el titular
 * decide sus canales, Ley 2300) y CU-28 Radicar consultas y reclamos (Ley 1581).
 */
class PortalServiceController extends Controller
{
    public function index(Request $request, ContactChannelService $channels): View
    {
        $client = $request->user()->client() ?? abort(403);

        return view('portal.service', [
            'client'   => $client,
            'notices'  => ClientNotification::where('client_id', $client->id)->where('status', 'ENVIADA')->latest('sent_at')->limit(30)->get(),
            'requests' => ClientRequest::where('client_id', $client->id)->latest('filed_at')->get(),
            'current'  => $channels->channels($client),
            'labels'   => ContactChannel::CHANNELS,
            'defaults' => collect(ContactChannel::CHANNELS)->mapWithKeys(fn ($l, $c) => [$c => $channels->defaultValue($client, $c)]),
            'types'    => ClientRequest::TYPES,
        ]);
    }

    public function updateChannels(Request $request, ContactChannelService $channels): RedirectResponse
    {
        $client = $request->user()->client() ?? abort(403);
        $data = $request->validate(['channels' => ['array'], 'channels.*.enabled' => ['nullable', 'boolean'], 'channels.*.value' => ['nullable', 'string', 'max:191']]);

        try {
            $channels->sync($client, $data['channels'] ?? []);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Tus preferencias de contacto se guardaron.');
    }

    public function storeRequest(Request $request, ClientRequestService $requests): RedirectResponse
    {
        $client = $request->user()->client() ?? abort(403);
        $data = $request->validate([
            'request_type' => ['required', Rule::in(array_keys(ClientRequest::TYPES))],
            'subject'      => ['required', 'string', 'min:5', 'max:200'],
            'description'  => ['required', 'string', 'min:20', 'max:5000'],
        ], [], ['request_type' => 'tipo', 'subject' => 'asunto', 'description' => 'descripción']);

        $filed = $requests->file($client, $data['request_type'], $data['subject'], $data['description']);

        return back()->with('success', "Radicamos tu {$filed->type_label} con el número #{$filed->id}. Te responderemos a más tardar el {$filed->due_date->format('Y-m-d')}.");
    }
}
