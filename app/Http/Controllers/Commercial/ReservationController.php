<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\ReservationStatus;
use App\Models\Commercial\Client;
use App\Models\Commercial\Lot;
use App\Models\Commercial\Project;
use App\Models\Commercial\Reservation;
use App\Services\Commercial\ReservationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;

/**
 * CU-11 Registrar reserva de lote (RF-08). El listado usa el CRUD genérico; el registro tiene
 * un formulario propio con buscador de lotes disponibles y de clientes.
 */
class ReservationController extends CrudController
{
    const MODEL = Reservation::class;
    const ROUTE = 'reservations';
    const PERMISSION = 'reservas';

    protected array $with = ['lot.project', 'client.person', 'advisor.person', 'status'];
    protected array $searchable = ['reservations.notes', 'lot.block', 'lot.lot_number', 'client.client_code', 'client.person.first_name', 'client.person.last_name'];
    protected array $actions = ['create' => true, 'edit' => false, 'delete' => false];
    protected string $orderBy = 'reservations.reserved_at';

    public function __construct(private ReservationService $reservations) {}

    protected function meta(): array
    {
        return [
            'title'    => 'Reservas',
            'subtitle' => 'Separación temporal de lotes. Al vencer, el lote vuelve a estar disponible (RN-03).',
            'icon'     => 'ph-bookmark-simple',
            'singular' => 'reserva',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'reserved_at', 'label' => 'Fecha', 'type' => 'date'],
            ['key' => 'lot.project.name', 'label' => 'Proyecto', 'type' => 'strong'],
            ['key' => 'lot.label', 'label' => 'Lote'],
            ['key' => 'client.person.full_name', 'label' => 'Cliente'],
            ['key' => 'deposit_amount', 'label' => 'Separación', 'type' => 'money'],
            ['key' => 'expires_at', 'label' => 'Vence', 'type' => 'date'],
            ['key' => 'status.name', 'label' => 'Estado', 'type' => 'badge', 'colors' => [
                'Activa' => 'warning', 'Convertida en negociación' => 'success', 'Vencida' => 'neutral', 'Anulada' => 'danger',
            ]],
        ];
    }

    protected function filters(): array
    {
        return [
            ['name' => 'status_id', 'label' => 'Estado', 'column' => 'reservations.status_id', 'options' => ReservationStatus::pluck('name', 'id')->all()],
            ['name' => 'project_id', 'label' => 'Proyecto', 'column' => 'lots.project_id', 'options' => Project::orderBy('name')->pluck('name', 'id')->all()],
        ];
    }

    protected function query(): Builder
    {
        return parent::query()->select('reservations.*')->join('lots', 'lots.id', '=', 'reservations.lot_id');
    }

    protected function rowActions(): array
    {
        return [['label' => 'Ver reserva', 'icon' => 'ph-eye', 'route' => 'reservations.show', 'permission' => 'reservas.ver']];
    }

    // ── Registro ───────────────────────────────────────────────────────────
    public function create(): View
    {
        $lot = request('lot_id') ? Lot::with('project')->find(request('lot_id')) : null;

        return view('commercial.reservation-form', [
            'projects'    => Project::orderBy('name')->get(['id', 'name', 'code']),
            'lot'         => $lot,
            'defaultDays' => (int) config('lothub.reservations.default_days'),
            'maxDays'     => (int) config('lothub.reservations.max_days'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lot_id'         => ['required', 'exists:lots,id'],
            'client_id'      => ['required', 'exists:clients,id'],
            'expires_at'     => ['required', 'date', 'after:now'],
            'deposit_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'notes'          => ['nullable', 'string', 'max:200'],
        ], [], ['lot_id' => 'lote', 'client_id' => 'cliente', 'expires_at' => 'vencimiento', 'deposit_amount' => 'valor de separación']);

        try {
            $reservation = $this->reservations->reserve(
                Lot::with('status')->findOrFail($data['lot_id']),
                Client::findOrFail($data['client_id']),
                Carbon::parse($data['expires_at']),
                (float) $data['deposit_amount'],
                $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            return back()->withInput()->with('error', $this->dbErrorMessage($e));
        }

        return redirect()->route('reservations.show', $reservation->id)->with('success', 'Reserva registrada. El lote quedó RESERVADO.');
    }

    public function show(int $id): View
    {
        return view('commercial.reservation-show', [
            'reservation' => Reservation::with(['lot.project', 'lot.stage', 'client.person', 'advisor.person', 'status', 'sale'])->findOrFail($id),
        ]);
    }

    /** CU-11 4b Anular reserva. */
    public function cancel(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:150']], [], ['reason' => 'motivo']);

        try {
            $this->reservations->cancel(Reservation::findOrFail($id), $data['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reserva anulada. El lote volvió a estar disponible.');
    }

    protected function recordLabel(Model $reservation): string
    {
        return "Reserva #{$reservation->id}";
    }
}
