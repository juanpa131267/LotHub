<?php

namespace App\Http\Controllers\Financing;

use App\Http\Controllers\Controller;
use App\Models\Catalogs\PaymentFrequency;
use App\Models\Catalogs\SaleStatus;
use App\Models\Commercial\Sale;
use App\Models\Financing\Obligation;
use App\Models\Financing\PaymentAgreement;
use App\Services\Documents\DocumentService;
use App\Services\Financing\AgreementService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * CU-13 Definir acuerdo de pago (con vista previa del plan, CU-14) y CU-16 Reestructurar.
 * Lo usa el Auxiliar de cartera a partir de las negociaciones vigentes sin acuerdo.
 */
class AgreementController extends Controller
{
    public function __construct(private AgreementService $agreements) {}

    /** Negociaciones vigentes que aún no tienen acuerdo de pago (CU-12 paso 6). */
    public function pending(): View
    {
        return view('financing.pending-agreements', [
            'sales' => Sale::with(['lot.project', 'clients.person', 'modality', 'advisor.person'])
                ->where('status_id', SaleStatus::VIGENTE)->whereDoesntHave('agreements')
                ->oldest('sale_date')->oldest('id')->get(),
        ]);
    }

    public function create(int $saleId): View|RedirectResponse
    {
        $sale = Sale::with(['lot.project', 'clients.person', 'modality'])->findOrFail($saleId);

        if ($obligation = $this->currentObligation($sale)) {
            return redirect()->route('obligations.show', $obligation->id)->with('error', 'La negociación ya tiene acuerdo de pago.');
        }

        return view('financing.agreement-form', [
            'sale'        => $sale,
            'frequencies' => PaymentFrequency::orderBy('months_interval')->get(),
            'usury'       => config('lothub.financing.max_monthly_rate'),
            'mode'        => 'create',
            'base'        => (float) $sale->agreed_price,
            'action'      => route('agreements.store', $sale->id),
            'preview'     => route('agreements.simulate', $sale->id),
        ]);
    }

    public function store(Request $request, int $saleId): RedirectResponse
    {
        $sale = Sale::with('modality')->findOrFail($saleId);

        try {
            $obligation = $this->agreements->create($sale, $this->terms($request));
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return back()->withInput()->with('error', 'Los datos no cumplen una regla de integridad del acuerdo.');
        }

        return redirect()->route('obligations.show', $obligation->id)
            ->with('success', "Acuerdo definido: obligación {$obligation->obligation_code} con su plan de cuotas.");
    }

    public function simulate(Request $request, int $saleId): JsonResponse
    {
        $sale = Sale::with('modality')->findOrFail($saleId);

        try {
            $result = $this->agreements->simulate($this->terms($request), $sale);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $result);
    }

    public function sign(Request $request, int $agreementId): RedirectResponse
    {
        $agreement = PaymentAgreement::findOrFail($agreementId);
        $data = $request->validate([
            'signed_at'   => ['required', 'date', 'before_or_equal:today'],
            'signed_file' => DocumentService::RULES,
        ], [], ['signed_at' => 'fecha de firma', 'signed_file' => 'acuerdo firmado']);

        $this->agreements->sign($agreement, $data['signed_at'], $request->file('signed_file'));

        return back()->with('success', 'Firma del acuerdo registrada.');
    }

    // ── CU-16 Reestructuración ─────────────────────────────────────────────
    public function restructureForm(int $obligationId): View|RedirectResponse
    {
        $obligation = Obligation::with(['agreement.sale.lot.project', 'agreement.sale.clients.person', 'agreement.sale.modality'])->findOrFail($obligationId);
        $balance = round($obligation->summary()->balance);

        if (! $obligation->isActive() || $balance <= 0) {
            return redirect()->route('obligations.show', $obligation->id)->with('error', 'Solo se reestructura una obligación activa con saldo.');
        }

        return view('financing.agreement-form', [
            'sale'        => $obligation->agreement->sale,
            'obligation'  => $obligation,
            'frequencies' => PaymentFrequency::orderBy('months_interval')->get(),
            'usury'       => config('lothub.financing.max_monthly_rate'),
            'mode'        => 'restructure',
            'base'        => $balance,
            'current'     => $obligation->agreement,
            'action'      => route('obligations.restructure.store', $obligation->id),
            'preview'     => route('agreements.simulate', $obligation->agreement->sale_id),
        ]);
    }

    public function restructure(Request $request, int $obligationId): RedirectResponse
    {
        $obligation = Obligation::with('agreement.sale.modality')->findOrFail($obligationId);
        $request->validate(['restructure_reason' => ['required', 'string', 'min:10', 'max:255']], [], ['restructure_reason' => 'motivo']);

        try {
            $new = $this->agreements->restructure($obligation, $this->terms($request), $request->restructure_reason);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('obligations.show', $new->id)
            ->with('success', "Acuerdo reestructurado: la obligación {$obligation->obligation_code} quedó REESTRUCTURADA y se creó {$new->obligation_code}.");
    }

    private function terms(Request $request): array
    {
        return $request->validate([
            'down_payment'          => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'financed_amount'       => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'installments_count'    => ['required', 'integer', 'between:1,360'],
            'frequency_id'          => ['required', 'exists:payment_frequencies,id'],
            'monthly_interest_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'late_interest_rate'    => ['required', 'numeric', 'min:0', 'max:100'],
            'first_due_date'        => ['required', 'date'],
        ], [], [
            'down_payment' => 'cuota inicial', 'financed_amount' => 'capital financiado', 'installments_count' => 'número de cuotas',
            'frequency_id' => 'periodicidad', 'monthly_interest_rate' => 'tasa corriente', 'late_interest_rate' => 'tasa de mora',
            'first_due_date' => 'primer vencimiento',
        ]);
    }

    private function currentObligation(Sale $sale): ?Obligation
    {
        return Obligation::whereIn('agreement_id', $sale->agreements()->pluck('id'))->latest('id')->first();
    }
}
