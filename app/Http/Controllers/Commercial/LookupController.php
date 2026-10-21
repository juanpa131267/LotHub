<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Catalogs\LotStatus;
use App\Models\Catalogs\ReservationStatus;
use App\Models\Commercial\Client;
use App\Models\Commercial\Lot;
use App\Models\Commercial\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buscadores para los formularios de reservas y negociaciones (public/js/pickers.js).
 * Devuelven como máximo 30 resultados para no cargar miles de lotes en un desplegable.
 */
class LookupController extends Controller
{
    /** ?project_id=&q=&for=reserva|negociacion */
    public function lots(Request $request): JsonResponse
    {
        $statuses = $request->query('for') === 'negociacion'
            ? [LotStatus::DISPONIBLE, LotStatus::RESERVADO]
            : [LotStatus::DISPONIBLE];

        $query = Lot::with(['project', 'stage', 'status'])->whereIn('status_id', $statuses);

        if ($project = $request->query('project_id')) {
            $query->where('project_id', $project);
        }
        if ($term = trim((string) $request->query('q'))) {
            // "A-12", "A 12", "12" o el nombre de la manzana.
            $parts = preg_split('/[\s\-·]+/u', mb_strtoupper($term), 2);
            $query->where(fn ($w) => count($parts) === 2
                ? $w->where('block', $parts[0])->where('lot_number', 'like', $parts[1].'%')
                : $w->where('lot_number', 'like', $parts[0].'%')->orWhere('block', $parts[0]));
        }

        $lots = $query->orderBy('block')->orderByRaw('CAST(lot_number AS UNSIGNED), lot_number')->limit(30)->get();
        $reservations = Reservation::with('client.person')
            ->where('status_id', ReservationStatus::ACTIVA)->whereIn('lot_id', $lots->pluck('id'))->get()->keyBy('lot_id');

        return response()->json(['data' => $lots->map(fn (Lot $l) => [
            'id'          => $l->id,
            'label'       => "{$l->project->name} · {$l->label}".($l->stage ? " · {$l->stage->name}" : ''),
            'area_m2'     => (float) $l->area_m2,
            'list_price'  => (float) $l->list_price,
            'status'      => $l->status->name,
            'reservation' => ($r = $reservations[$l->id] ?? null) ? [
                'id' => $r->id, 'client_id' => $r->client_id, 'client' => $r->client->person->full_name,
                'deposit' => (float) $r->deposit_amount, 'expires_at' => $r->expires_at->format('Y-m-d H:i'),
            ] : null,
        ])]);
    }

    /** ?q= nombre, código o identificación */
    public function clients(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));

        $clients = Client::with('person.identificationType')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('client_code', 'like', "%{$term}%")
                ->orWhereHas('person', fn ($p) => $p->where('identification_number', 'like', "{$term}%")
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$term}%"]))))
            ->latest('id')->limit(30)->get();

        return response()->json(['data' => $clients->map(fn (Client $c) => [
            'id'    => $c->id,
            'label' => "{$c->person->full_name} · {$c->person->identificationType?->code} {$c->person->identification_number} · {$c->client_code}",
        ])]);
    }
}
