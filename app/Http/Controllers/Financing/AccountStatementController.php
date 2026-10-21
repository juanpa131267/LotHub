<?php

namespace App\Http\Controllers\Financing;

use App\Http\Controllers\Controller;
use App\Models\Financing\AccountStatement;
use App\Models\Financing\Obligation;
use App\Services\Financing\AccountStatementService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CU-24 Estado de cuenta en PDF: lo genera el personal de cartera para cualquier obligación y
 * el cliente desde el portal solo para las suyas (RN-21).
 */
class AccountStatementController extends Controller
{
    public function __construct(private AccountStatementService $statements) {}

    public function generate(int $obligationId): BinaryFileResponse
    {
        $statement = $this->statements->generate(Obligation::findOrFail($obligationId), auth()->id());

        return $this->download($statement);
    }

    public function show(int $id): BinaryFileResponse
    {
        $statement = AccountStatement::with('obligation')->findOrFail($id);
        abort_unless(is_file($this->statements->absolutePath($statement)), 404);

        return $this->download($statement);
    }

    /** Portal: el comprador descarga el estado de cuenta de una de sus obligaciones. */
    public function portal(Request $request, int $obligationId, PaymentService $payments): BinaryFileResponse
    {
        $client = $request->user()->client() ?? abort(403);
        $obligation = Obligation::with('agreement')->findOrFail($obligationId);
        abort_unless($payments->isBuyer($obligation, $client), 403);

        return $this->download($this->statements->generate($obligation, null));
    }

    private function download(AccountStatement $statement): BinaryFileResponse
    {
        return response()->download($this->statements->absolutePath($statement), $this->statements->downloadName($statement), [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
