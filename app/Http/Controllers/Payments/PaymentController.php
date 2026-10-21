<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\PaymentMethod;
use App\Models\Catalogs\PaymentStatus;
use App\Models\Commercial\Client;
use App\Models\Financing\Obligation;
use App\Models\Payments\Payment;
use App\Models\Payments\PaymentAllocation;
use App\Services\Documents\DocumentService;
use App\Services\Payments\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Abonos: listado, CU-17.1 Registrar abono manual (con soporte), detalle de su aplicación a
 * cuotas (CU-18) y CU-19 Reversar.
 */
class PaymentController extends CrudController
{
    const MODEL = Payment::class;
    const ROUTE = 'payments';
    const PERMISSION = 'abonos';

    protected array $with = ['obligation.agreement.sale.lot.project', 'payer.person', 'method', 'status', 'statementLine'];
    protected array $searchable = ['bank_reference', 'obligation.obligation_code', 'payer.person.first_name', 'payer.person.last_name', 'payer.client_code'];
    protected array $actions = ['create' => false, 'edit' => false, 'delete' => false];
    protected string $orderBy = 'payments.id';

    public function __construct(private PaymentService $payments, private DocumentService $documents) {}

    protected function meta(): array
    {
        return [
            'title'    => 'Abonos',
            'subtitle' => 'Pagos recibidos y su aplicación a las cuotas. Los abonos no se eliminan: se reversan (RN-15).',
            'icon'     => 'ph-coins',
            'singular' => 'abono',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'payment_date', 'label' => 'Fecha', 'type' => 'date'],
            ['key' => 'obligation.obligation_code', 'label' => 'Obligación', 'type' => 'code'],
            ['key' => 'lot', 'label' => 'Lote', 'type' => 'strong'],
            ['key' => 'payer.person.full_name', 'label' => 'Pagador'],
            ['key' => 'amount', 'label' => 'Valor', 'type' => 'money'],
            ['key' => 'method.name', 'label' => 'Medio', 'type' => 'muted'],
            ['key' => 'origin', 'label' => 'Origen', 'type' => 'muted'],
            ['key' => 'status.name', 'label' => 'Estado', 'type' => 'badge', 'colors' => ['Aplicado' => 'success', 'Reversado' => 'danger']],
        ];
    }

    protected function row(Model $payment): array
    {
        $lot = $payment->obligation->agreement->sale->lot;

        return $payment->toArray() + ['lot' => "{$lot->project->name} · {$lot->label}", 'origin' => $payment->originLabel()];
    }

    protected function filters(): array
    {
        return [
            ['name' => 'status_id', 'label' => 'Estado', 'column' => 'payments.status_id', 'options' => PaymentStatus::pluck('name', 'id')->all()],
            ['name' => 'payment_method_id', 'label' => 'Medio', 'column' => 'payments.payment_method_id', 'options' => PaymentMethod::pluck('name', 'id')->all()],
        ];
    }

    protected function query(): Builder
    {
        return parent::query()->select('payments.*');
    }

    protected function rowActions(): array
    {
        return [['label' => 'Ver abono', 'icon' => 'ph-eye', 'route' => 'payments.show', 'permission' => 'abonos.ver']];
    }

    public function show(int $id): View
    {
        $payment = Payment::with([
            'obligation.agreement.sale.lot.project', 'payer.person', 'method', 'status', 'registeredBy.person', 'reversedBy.person',
            'allocations.installment', 'documents.type', 'statementLine.statement',
        ])->findOrFail($id);

        return view('payments.payment-show', [
            'payment'  => $payment,
            'concepts' => PaymentAllocation::CONCEPTS,
        ]);
    }

    // ── CU-17.1 Abono manual ───────────────────────────────────────────────
    public function createFor(int $obligationId): View|RedirectResponse
    {
        $obligation = Obligation::with('agreement.sale.clients.person', 'agreement.sale.lot.project')->findOrFail($obligationId);
        if (! $obligation->isActive()) {
            return redirect()->route('obligations.show', $obligation->id)->with('error', 'Solo se registran abonos a obligaciones activas.');
        }

        return view('payments.payment-form', [
            'obligation' => $obligation,
            'summary'    => $obligation->summary(),
            'buyers'     => $obligation->agreement->sale->clients,
            'methods'    => PaymentMethod::where('is_online', false)->orderBy('id')->get(),
        ]);
    }

    public function storeFor(Request $request, int $obligationId): RedirectResponse
    {
        $obligation = Obligation::with('agreement.sale')->findOrFail($obligationId);

        $data = $request->validate([
            'payer_client_id'   => ['required', 'exists:clients,id'],
            'payment_date'      => ['required', 'date', 'before_or_equal:today'],
            'amount'            => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('is_online', false)],
            'bank_reference'    => [Rule::requiredIf(fn () => PaymentMethod::whereKey($request->payment_method_id)->value('code') !== 'EFECTIVO'), 'nullable', 'string', 'max:80'],
            'support_file'      => ['required', ...array_slice(DocumentService::RULES, 1)],
            'confirm_duplicate' => ['nullable', 'boolean'],
        ], [
            'bank_reference.required' => 'La referencia bancaria es obligatoria para consignaciones y transferencias.',
        ], [
            'payer_client_id' => 'pagador', 'payment_date' => 'fecha del pago', 'amount' => 'valor', 'payment_method_id' => 'medio de pago',
            'bank_reference' => 'referencia bancaria', 'support_file' => 'soporte de pago',
        ]);

        // CU-17.1 2a: referencia ya registrada → se advierte y se pide confirmación.
        if (($dup = $this->payments->referenceInUse($data['bank_reference'] ?? null)) && empty($data['confirm_duplicate'])) {
            return back()->withInput()->with('duplicate', $dup->id)
                ->with('error', "La referencia «{$data['bank_reference']}» ya está en el abono #{$dup->id} del {$dup->payment_date->format('Y-m-d')}. Confirma si no es un duplicado.");
        }

        try {
            $payment = DB::transaction(function () use ($obligation, $data, $request) {
                $payment = $this->payments->register(
                    $obligation, Client::findOrFail($data['payer_client_id']), $data['payment_date'], (float) $data['amount'],
                    (int) $data['payment_method_id'], $data['bank_reference'] ?? null, auth()->id(),
                );
                $this->documents->store($request->file('support_file'), 'SOPORTE_PAGO', ['payment_id' => $payment->id]);

                return $payment;
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return back()->withInput()->with('error', $this->dbErrorMessage($e));
        }

        return redirect()->route('payments.show', $payment->id)->with('success', 'Abono registrado y aplicado a las cuotas.');
    }

    // ── CU-19 Reversar ─────────────────────────────────────────────────────
    public function reverse(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'reversal_reason'  => ['required', 'string', 'min:10', 'max:255'],
            'gateway_voided'   => ['nullable', 'boolean'],
        ], [], ['reversal_reason' => 'motivo']);

        try {
            $this->payments->reverse(Payment::findOrFail($id), $data['reversal_reason'], (bool) ($data['gateway_voided'] ?? false));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Abono reversado: el saldo de la obligación se recalculó.');
    }

    protected function recordLabel(Model $payment): string
    {
        return "Abono #{$payment->id}";
    }
}
