<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Catalogs\PaymentMethod;
use App\Models\Financing\Obligation;
use App\Models\Payments\BankStatement;
use App\Models\Payments\BankStatementLine;
use App\Services\Payments\BankStatementParser;
use App\Services\Payments\BankStatementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Motor de asignación automática (Sprint 6): carga del extracto bancario, resultado por
 * movimiento y cola de revisión para lo que el motor no pudo asignar solo.
 */
class BankStatementController extends Controller
{
    public function __construct(private BankStatementService $statements) {}

    public function index(): View
    {
        return view('payments.statements', [
            'statements' => BankStatement::with('uploader.person', 'method')
                ->withCount([
                    'lines as assigned_count' => fn ($q) => $q->where('status', BankStatementLine::ASSIGNED),
                    'lines as review_count'   => fn ($q) => $q->where('status', BankStatementLine::REVIEW),
                ])
                ->latest('id')->paginate(20),
            'methods'    => PaymentMethod::whereIn('code', ['CONSIGNACION', 'TRANSFERENCIA'])->get(),
            'result'     => session('statement_result'),
            'threshold'  => config('lothub.allocation.auto_threshold'),
            'margin'     => config('lothub.allocation.min_margin'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bank_name'         => ['required', 'string', 'max:80'],
            'account_label'     => ['nullable', 'string', 'max:80'],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('is_online', false)],
            'statement_file'    => ['required', 'file', 'extensions:xlsx,xls,csv,txt', 'max:10240'],
        ], [], ['bank_name' => 'banco', 'account_label' => 'cuenta', 'payment_method_id' => 'medio de pago', 'statement_file' => 'extracto']);

        try {
            $result = $this->statements->import($request->file('statement_file'), $data['bank_name'], $data['account_label'] ?? null, (int) $data['payment_method_id']);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($result['errors']) {
            return back()->withInput()->with('statement_result', $result)
                ->with('error', 'El extracto tiene '.count($result['errors']).' fila(s) con error; no se procesó. Corrígelas y vuelve a cargarlo.');
        }

        return redirect()->route('statements.show', $result['statement']->id)->with('success',
            "Extracto procesado: {$result['assigned']} abono(s) asignado(s) automáticamente, {$result['review']} en revisión y {$result['duplicate']} duplicado(s).");
    }

    public function show(Request $request, int $id): View
    {
        $statement = BankStatement::with('uploader.person', 'method')->findOrFail($id);
        $status = $request->query('estado');

        return view('payments.statement-show', [
            'statement' => $statement,
            'lines'     => $statement->lines()->with(['obligation', 'payment', 'reviewer.person'])
                ->when($status, fn ($q) => $q->where('status', $status))->get(),
            'counts'    => $statement->lines()->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status'),
            'status'    => $status,
        ]);
    }

    public function template(BankStatementParser $parser): BinaryFileResponse
    {
        return response()->download($parser->template(), 'plantilla_extracto.xlsx')->deleteFileAfterSend();
    }

    // ── Cola de revisión ──────────────────────────────────────────────────
    public function queue(): View
    {
        return view('payments.review-queue', [
            'lines' => BankStatementLine::with('statement')->where('status', BankStatementLine::REVIEW)
                ->orderBy('transaction_date')->orderBy('id')->paginate(30),
        ]);
    }

    public function review(int $lineId): View
    {
        $line = BankStatementLine::with(['statement', 'payment', 'obligation'])->findOrFail($lineId);

        return view('payments.review-line', [
            'line'       => $line,
            'candidates' => $line->status === BankStatementLine::REVIEW ? $this->statements->rank($line)->take(8) : collect(),
            'next'       => BankStatementLine::where('status', BankStatementLine::REVIEW)->where('id', '>', $line->id)->orderBy('id')->value('id'),
        ]);
    }

    public function assign(Request $request, int $lineId): RedirectResponse
    {
        $line = BankStatementLine::with('statement')->findOrFail($lineId);
        $data = $request->validate([
            'obligation_id'   => ['required', 'exists:obligations,id'],
            'payer_client_id' => ['nullable', 'exists:clients,id'],
            'review_notes'    => ['nullable', 'string', 'max:255'],
        ], [], ['obligation_id' => 'obligación']);

        try {
            $this->statements->assign($line, Obligation::with('agreement.sale')->findOrFail($data['obligation_id']), $data['payer_client_id'] ?? null, 'MANUAL', $data['review_notes'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->next($line, 'Movimiento asignado: se registró el abono.');
    }

    public function discard(Request $request, int $lineId): RedirectResponse
    {
        $line = BankStatementLine::findOrFail($lineId);
        $data = $request->validate(['review_notes' => ['required', 'string', 'min:5', 'max:255']], [], ['review_notes' => 'motivo']);

        try {
            $this->statements->discard($line, $data['review_notes']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->next($line, 'Movimiento descartado.');
    }

    /** Después de resolver un movimiento se pasa al siguiente de la cola. */
    private function next(BankStatementLine $line, string $message): RedirectResponse
    {
        $next = BankStatementLine::where('status', BankStatementLine::REVIEW)->orderBy('transaction_date')->orderBy('id')->value('id');

        return ($next ? redirect()->route('allocation.review', $next) : redirect()->route('allocation.queue'))->with('success', $message);
    }
}
