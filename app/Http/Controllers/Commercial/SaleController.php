<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\FinancingModality;
use App\Models\Catalogs\SaleStatus;
use App\Models\Commercial\Lot;
use App\Models\Commercial\Project;
use App\Models\Commercial\Reservation;
use App\Models\Commercial\Sale;
use App\Models\Commercial\SaleCancellationRequest;
use App\Services\Commercial\SaleService;
use App\Services\Documents\DocumentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * CU-12 Registrar negociación (RF-09) y CU-15 Solicitar / aprobar anulación o desistimiento.
 * El listado usa el CRUD genérico; el registro tiene un formulario propio con varios compradores.
 */
class SaleController extends CrudController
{
    const MODEL = Sale::class;
    const ROUTE = 'sales';
    const PERMISSION = 'negociaciones';

    protected array $with = ['lot.project', 'clients.person', 'modality', 'status', 'pendingCancellation'];
    protected array $searchable = ['sale_code', 'lot.block', 'lot.lot_number', 'clients.person.first_name', 'clients.person.last_name', 'clients.client_code'];
    protected array $actions = ['create' => true, 'edit' => false, 'delete' => false];
    protected string $orderBy = 'sales.id';

    public function __construct(private SaleService $sales) {}

    protected function meta(): array
    {
        return [
            'title'    => 'Negociaciones',
            'subtitle' => 'Ventas de lotes con sus compradores, precio pactado y modalidad de pago.',
            'icon'     => 'ph-handshake',
            'singular' => 'negociación',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'sale_code', 'label' => 'Código', 'type' => 'code'],
            ['key' => 'sale_date', 'label' => 'Fecha', 'type' => 'date'],
            ['key' => 'lot.project.name', 'label' => 'Proyecto', 'type' => 'strong'],
            ['key' => 'lot.label', 'label' => 'Lote'],
            ['key' => 'buyer', 'label' => 'Titular'],
            ['key' => 'agreed_price', 'label' => 'Precio pactado', 'type' => 'money'],
            ['key' => 'modality.name', 'label' => 'Modalidad', 'type' => 'muted'],
            ['key' => 'state', 'label' => 'Estado', 'type' => 'badge', 'colors' => [
                'Vigente' => 'success', 'Pagada en su totalidad' => 'info', 'Anulada' => 'danger',
                'Desistida por el comprador' => 'danger', 'Anulación pendiente' => 'warning',
            ]],
        ];
    }

    protected function row(Model $sale): array
    {
        return $sale->toArray() + [
            'buyer' => $sale->primaryClient()?->person->full_name.($sale->clients->count() > 1 ? ' +'.($sale->clients->count() - 1) : ''),
            'state' => $sale->pendingCancellation ? 'Anulación pendiente' : $sale->status->name,
        ];
    }

    protected function filters(): array
    {
        return [
            ['name' => 'status_id', 'label' => 'Estado', 'column' => 'sales.status_id', 'options' => SaleStatus::pluck('name', 'id')->all()],
            ['name' => 'project_id', 'label' => 'Proyecto', 'column' => 'lots.project_id', 'options' => Project::orderBy('name')->pluck('name', 'id')->all()],
        ];
    }

    protected function query(): Builder
    {
        return parent::query()->select('sales.*')->join('lots', 'lots.id', '=', 'sales.lot_id');
    }

    protected function rowActions(): array
    {
        return [['label' => 'Ver negociación', 'icon' => 'ph-eye', 'route' => 'sales.show', 'permission' => 'negociaciones.ver']];
    }

    // ── Registro ───────────────────────────────────────────────────────────
    public function create(): View
    {
        $reservation = request('reservation_id')
            ? Reservation::with(['lot.project', 'client.person'])->active()->find(request('reservation_id'))
            : null;
        $lot = $reservation?->lot ?? (request('lot_id') ? Lot::with('project')->find(request('lot_id')) : null);

        return view('commercial.sale-form', [
            'projects'    => Project::orderBy('name')->get(['id', 'name', 'code']),
            'modalities'  => FinancingModality::orderBy('id')->get(),
            'reservation' => $reservation,
            'lot'         => $lot,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lot_id'                 => ['required', 'exists:lots,id'],
            'agreed_price'           => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'financing_modality_id'  => ['required', 'exists:financing_modalities,id'],
            'sale_date'              => ['required', 'date', 'before_or_equal:today'],
            'buyers'                 => ['required', 'array', 'min:1', 'max:6'],
            'buyers.*.client_id'     => ['required', 'distinct', 'exists:clients,id'],
            'buyers.*.ownership_pct' => ['required', 'numeric', 'gt:0', 'max:100'],
            'primary'                => ['required', 'integer'],
            'promise_file'           => DocumentService::RULES,
            'identity_file'          => DocumentService::RULES,
        ], [
            'buyers.*.client_id.distinct' => 'Un mismo cliente aparece más de una vez.',
            'primary.required'            => 'Marca quién es el titular principal.',
        ], [
            'lot_id' => 'lote', 'agreed_price' => 'precio pactado', 'financing_modality_id' => 'modalidad de pago',
            'sale_date' => 'fecha de la negociación', 'buyers' => 'compradores', 'buyers.*.client_id' => 'comprador',
            'buyers.*.ownership_pct' => 'participación', 'promise_file' => 'promesa de compraventa', 'identity_file' => 'documento de identidad',
        ]);

        // Cada fila del formulario tiene su índice; el radio «titular principal» envía ese índice.
        $data['buyers'] = collect($data['buyers'])
            ->map(fn ($buyer, $index) => $buyer + ['is_primary' => (string) $index === (string) $data['primary']])
            ->values()->all();

        try {
            $sale = $this->sales->create($data, [
                'PROMESA'       => $request->file('promise_file'),
                'DOC_IDENTIDAD' => $request->file('identity_file'),
            ]);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return back()->withInput()->with('error', $this->dbErrorMessage($e));
        }

        return redirect()->route('sales.show', $sale->id)
            ->with('success', "Negociación {$sale->sale_code} registrada. El lote quedó VENDIDO; cartera definirá el acuerdo de pago.");
    }

    public function show(int $id): View
    {
        $sale = Sale::with([
            'lot.project', 'lot.stage', 'reservation', 'clients.person.identificationType', 'advisor.person', 'approver.person',
            'modality', 'status', 'documents.type', 'documents.uploader.person', 'cancellationRequests.requester.person', 'cancellationRequests.resolver.person',
        ])->findOrFail($id);

        return view('commercial.sale-show', [
            'sale'           => $sale,
            'pending'        => $sale->cancellationRequests->firstWhere('status', SaleCancellationRequest::PENDING),
            'types'          => SaleCancellationRequest::TYPES,
            'appliedPayments'=> $this->sales->appliedPayments($sale),
        ]);
    }

    // ── CU-15 Anulación o desistimiento ───────────────────────────────────
    public function requestCancellation(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'request_type' => ['required', Rule::in(array_keys(SaleCancellationRequest::TYPES))],
            'reason'       => ['required', 'string', 'min:10', 'max:255'],
        ], [], ['request_type' => 'tipo', 'reason' => 'motivo']);

        try {
            $this->sales->requestCancellation(Sale::findOrFail($id), $data['request_type'], $data['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Solicitud registrada. Queda pendiente de aprobación del Director comercial.');
    }

    public function resolveCancellation(Request $request, int $id, int $requestId): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'notes'    => [$request->input('decision') === 'reject' ? 'required' : 'nullable', 'string', 'max:500'],
        ], [], ['decision' => 'decisión', 'notes' => 'observaciones']);

        $cancellation = SaleCancellationRequest::with('sale')->where('sale_id', $id)->findOrFail($requestId);

        try {
            if ($data['decision'] === 'approve') {
                $this->sales->approveCancellation($cancellation, $data['notes'] ?? null);
                $message = 'Solicitud aprobada: la negociación se cerró y el lote volvió a estar disponible.';
            } else {
                $this->sales->rejectCancellation($cancellation, $data['notes']);
                $message = 'Solicitud rechazada: la negociación sigue vigente.';
            }
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }

    /** Solicitudes de anulación pendientes (bandeja del Director comercial). */
    public function cancellations(): View
    {
        return view('commercial.cancellations', [
            'requests' => SaleCancellationRequest::with(['sale.lot.project', 'sale.clients.person', 'requester.person'])
                ->where('status', SaleCancellationRequest::PENDING)->oldest('requested_at')->get(),
        ]);
    }

    protected function recordLabel(Model $sale): string
    {
        return $sale->sale_code;
    }
}
