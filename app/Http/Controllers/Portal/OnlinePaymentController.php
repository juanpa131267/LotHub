<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Financing\Obligation;
use App\Models\Payments\GatewayTransaction;
use App\Services\Payments\OnlinePaymentService;
use App\Services\Payments\WompiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * CU-20 Pagar en línea desde el portal: el cliente elige la cuota vencida, la próxima cuota o un
 * valor libre; se crea la transacción PENDIENTE y se le redirige a Wompi. Al volver, el portal
 * muestra el estado; el abono solo se crea cuando llega la notificación firmada (CU-21).
 */
class OnlinePaymentController extends Controller
{
    public function __construct(private OnlinePaymentService $online) {}

    public function pay(Request $request, int $obligationId): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1', 'max:999999999999']], [], ['amount' => 'valor']);
        $client = $request->user()->client();
        if (! $client) {
            return redirect()->route('portal.index')->with('error', 'Solo los compradores pueden pagar en línea.');
        }
        $obligation = Obligation::with('agreement')->findOrFail($obligationId);

        try {
            [, $url] = $this->online->start($obligation, $client, (float) $data['amount'], route('portal.payments.result', '__REF__'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->away($url);
    }

    public function result(Request $request, string $reference, WompiClient $wompi): View
    {
        $client = $request->user()->client() ?? abort(403);
        $transaction = GatewayTransaction::with(['obligation', 'status', 'payment'])
            ->where('internal_reference', $reference)->where('client_id', $client->id)->firstOrFail();

        // Lo que informa Wompi solo se muestra; el abono lo crea la notificación firmada (RN-17).
        $provider = null;
        if ((int) $transaction->status_id === 1 && $request->filled('id') && $wompi->isConfigured()) {
            $provider = $wompi->transaction((string) $request->query('id'))['status'] ?? null;
        }

        return view('portal.payment-result', compact('transaction', 'provider'));
    }
}
