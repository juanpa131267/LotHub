<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Payments\GatewayNotification;
use App\Models\Payments\GatewayTransaction;
use App\Services\Payments\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** CU-22 Conciliar transacciones de la pasarela por periodo. */
class ReconciliationController extends Controller
{
    public function __construct(private ReconciliationService $reconciliation) {}

    public function index(Request $request): View
    {
        $request->validate(['desde' => ['nullable', 'date'], 'hasta' => ['nullable', 'date', 'after_or_equal:desde']]);
        $from = CarbonImmutable::parse($request->query('desde', now()->startOfMonth()->toDateString()));
        $to = CarbonImmutable::parse($request->query('hasta', now()->toDateString()));

        $report = $this->reconciliation->report($from, $to);

        return view('payments.reconciliation', [
            'from'     => $from,
            'to'       => $to,
            'report'   => $report,
            'totals'   => $this->reconciliation->totals($from, $to),
            'clean'    => collect($report)->every->isEmpty(),
        ]);
    }

    public function reprocess(int $id): RedirectResponse
    {
        return $this->attempt(fn () => $this->reconciliation->reprocess(GatewayTransaction::findOrFail($id)), 'Transacción reprocesada.');
    }

    public function applyReported(int $id): RedirectResponse
    {
        return $this->attempt(fn () => $this->reconciliation->applyReportedAmount(GatewayTransaction::with('client')->findOrFail($id)), 'Abono registrado con el valor informado por la pasarela.');
    }

    public function review(Request $request, string $type, int $id): RedirectResponse
    {
        $data = $request->validate(['notes' => ['required', 'string', 'min:5', 'max:255']], [], ['notes' => 'observación']);
        $item = $type === 'notificacion' ? GatewayNotification::findOrFail($id) : GatewayTransaction::findOrFail($id);

        $this->reconciliation->markReviewed($item, $data['notes']);

        return back()->with('success', 'Diferencia marcada como revisada.');
    }

    private function attempt(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }
}
