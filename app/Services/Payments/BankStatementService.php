<?php

namespace App\Services\Payments;

use App\Models\Catalogs\ObligationStatus;
use App\Models\Commercial\Client;
use App\Models\Financing\Obligation;
use App\Models\Payments\BankStatement;
use App\Models\Payments\BankStatementLine;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Motor de asignación automática (Sprint 6):
 *  1. Carga del extracto: se guarda el archivo (con su SHA-256 para no procesarlo dos veces)
 *     y sus movimientos crédito.
 *  2. Cada movimiento se compara con todas las obligaciones activas (PaymentMatcher).
 *  3. Si el mejor candidato supera el umbral con margen suficiente, se registra el abono
 *     automáticamente (PaymentService, CU-17/CU-18); si no, el movimiento va a la cola de revisión.
 */
class BankStatementService
{
    public const DIRECTORY = 'statements';

    /** Candidatos por obligación activa, recalculados cuando un abono cambia su saldo. */
    private ?Collection $candidates = null;

    public function __construct(
        private BankStatementParser $parser,
        private PaymentMatcher $matcher,
        private PaymentService $payments,
    ) {}

    /** @return array{statement:BankStatement, assigned:int, review:int, duplicate:int, skipped:int, errors:array} */
    public function import(UploadedFile $file, string $bankName, ?string $accountLabel, int $paymentMethodId): array
    {
        $hash = hash_file('sha256', $file->getRealPath());
        if ($existing = BankStatement::where('file_hash', $hash)->first()) {
            throw new RuntimeException("Este extracto ya se cargó el {$existing->uploaded_at->format('Y-m-d H:i')} (extracto #{$existing->id}).");
        }

        $parsed = $this->parser->parse($file->getRealPath(), $file->getClientOriginalExtension());
        if ($parsed['errors']) {
            return ['statement' => null, 'assigned' => 0, 'review' => 0, 'duplicate' => 0, 'skipped' => $parsed['skipped'], 'errors' => $parsed['errors']];
        }

        $path = $file->storeAs(self::DIRECTORY.'/'.now()->format('Y/m'), Str::uuid().'.'.Str::lower($file->getClientOriginalExtension()), 'local');
        $lines = collect($parsed['lines']);

        $statement = DB::transaction(function () use ($file, $bankName, $accountLabel, $paymentMethodId, $hash, $path, $lines) {
            $statement = BankStatement::create([
                'bank_name'         => $bankName,
                'account_label'     => $accountLabel,
                'original_name'     => mb_substr($file->getClientOriginalName(), 0, 255),
                'file_hash'         => $hash,
                'storage_path'      => $path,
                'period_start'      => $lines->min('transaction_date'),
                'period_end'        => $lines->max('transaction_date'),
                'lines_count'       => $lines->count(),
                'credits_total'     => $lines->sum('amount'),
                'payment_method_id' => $paymentMethodId,
                'uploaded_by'       => auth()->id(),
                'uploaded_at'       => now(),
            ]);
            BankStatementLine::insert($lines->map(fn ($l) => $l + ['statement_id' => $statement->id, 'status' => BankStatementLine::PENDING])->all());

            return $statement;
        });

        $summary = $this->process($statement);
        AuditLogger::log('CARGAR_EXTRACTO', 'bank_statements', $statement->id, [], $summary + ['archivo' => $statement->original_name]);

        return ['statement' => $statement, 'skipped' => $parsed['skipped'], 'errors' => []] + $summary;
    }

    /** Procesa los movimientos PENDIENTES del extracto, del más antiguo al más reciente. */
    public function process(BankStatement $statement): array
    {
        $summary = ['assigned' => 0, 'review' => 0, 'duplicate' => 0];
        $threshold = (int) config('lothub.allocation.auto_threshold');
        $margin = (int) config('lothub.allocation.min_margin');

        $lines = $statement->lines()->where('status', BankStatementLine::PENDING)->orderBy('transaction_date')->orderBy('line_number')->get();

        foreach ($lines as $line) {
            if ($this->isDuplicate($line)) {
                $line->update(['status' => BankStatementLine::DUPLICATE, 'review_notes' => 'El movimiento ya está registrado en otro extracto o abono.']);
                $summary['duplicate']++;
                continue;
            }

            $ranked = $this->rank($line);
            $line->fill(['candidates' => $ranked->take(5)->values()->all(), 'score' => $ranked->first()['score'] ?? 0]);

            if ($this->matcher->decide($ranked->all(), $threshold, $margin)) {
                try {
                    $this->assign($line, Obligation::findOrFail($ranked->first()['obligation_id']), $ranked->first()['payer_client_id'], 'AUTOMATICA');
                    $summary['assigned']++;
                    continue;
                } catch (RuntimeException $e) {
                    $line->review_notes = 'No se pudo asignar automáticamente: '.mb_substr($e->getMessage(), 0, 200);
                }
            }

            $line->fill(['status' => BankStatementLine::REVIEW])->save();
            $summary['review']++;
        }

        return $summary;
    }

    /** Candidatos ordenados por puntaje para un movimiento (también para la pantalla de revisión). */
    public function rank(BankStatementLine $line): Collection
    {
        $data = $line->only(['amount', 'transaction_date', 'description', 'reference', 'payer_document']);
        $data['transaction_date'] = $line->transaction_date->toDateString();

        return $this->candidates()
            ->map(function (array $candidate) use ($data) {
                $result = $this->matcher->score($data, $candidate);

                return [
                    'obligation_id'   => $candidate['obligation_id'],
                    'label'           => $candidate['label'],
                    'score'           => $result['score'],
                    'reasons'         => $result['reasons'],
                    'payer_client_id' => $candidate['buyers'][$result['buyer_index'] ?? $candidate['primary_index']]['client_id'],
                    'balance'         => $candidate['balance'],
                    'next_due'        => $candidate['next_due'],
                ];
            })
            ->filter(fn ($c) => $c['score'] > 0)
            ->sortByDesc('score')
            ->values();
    }

    /** Crea el abono de un movimiento (automático o decidido en la revisión). */
    public function assign(BankStatementLine $line, Obligation $obligation, ?int $payerClientId, string $mode, ?string $notes = null): void
    {
        if (in_array($line->status, [BankStatementLine::ASSIGNED, BankStatementLine::DISCARDED], true)) {
            throw new RuntimeException('El movimiento ya fue resuelto.');
        }

        DB::transaction(function () use ($line, $obligation, $payerClientId, $mode, $notes) {
            $statement = $line->statement;
            $payer = $payerClientId ? Client::find($payerClientId) : null;
            $payer ??= $obligation->agreement->sale->primaryClient();

            $payment = $this->payments->register(
                $obligation, $payer, $line->transaction_date->toDateString(), (float) $line->amount,
                $statement->payment_method_id, $line->reference ?: "EXT-{$statement->id}-{$line->line_number}",
                $mode === 'AUTOMATICA' ? $statement->uploaded_by : auth()->id(),
            );

            $line->fill([
                'status'                => BankStatementLine::ASSIGNED,
                'assignment_mode'       => $mode,
                'matched_obligation_id' => $obligation->id,
                'payment_id'            => $payment->id,
                'reviewed_by'           => $mode === 'MANUAL' ? auth()->id() : null,
                'reviewed_at'           => $mode === 'MANUAL' ? now() : null,
                'review_notes'          => $notes ?? $line->review_notes,
            ])->save();

            $this->refresh($obligation->id);
        });
    }

    public function discard(BankStatementLine $line, string $notes): void
    {
        if ($line->status === BankStatementLine::ASSIGNED) {
            throw new RuntimeException('El movimiento ya generó un abono; para deshacerlo reversa el abono.');
        }

        $line->update(['status' => BankStatementLine::DISCARDED, 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_notes' => $notes]);
        AuditLogger::log('DESCARTAR_MOVIMIENTO', 'bank_statement_lines', $line->id, [], ['motivo' => $notes]);
    }

    // ── Internos ───────────────────────────────────────────────────────────

    /** Mismo día, valor y referencia que un movimiento ya asignado, o referencia usada en un abono. */
    private function isDuplicate(BankStatementLine $line): bool
    {
        $sameLine = BankStatementLine::where('statement_id', '!=', $line->statement_id)
            ->whereIn('status', [BankStatementLine::ASSIGNED, BankStatementLine::REVIEW, BankStatementLine::PENDING])
            ->whereDate('transaction_date', $line->transaction_date)->where('amount', $line->amount)
            ->where(fn ($q) => $line->reference ? $q->where('reference', $line->reference) : $q->whereNull('reference')->where('description', $line->description))
            ->exists();

        return $sameLine || ($line->reference && $this->payments->referenceInUse($line->reference));
    }

    private function candidates(): Collection
    {
        return $this->candidates ??= Obligation::where('status_id', ObligationStatus::ACTIVA)->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => $this->candidate($id)]);
    }

    private function refresh(int $obligationId): void
    {
        if ($this->candidates === null) {
            return;
        }
        $obligation = Obligation::find($obligationId);
        $obligation?->isActive()
            ? $this->candidates->put($obligationId, $this->candidate($obligationId))
            : $this->candidates->forget($obligationId);
    }

    /** Datos que usa PaymentMatcher para una obligación: códigos, compradores y saldos. */
    private function candidate(int $obligationId): array
    {
        $obligation = Obligation::with(['agreement.sale.clients.person', 'agreement.sale.lot.project'])->findOrFail($obligationId);
        $sale = $obligation->agreement->sale;
        $open = $obligation->balances()->filter(fn ($r) => (float) $r->balance > 0)->values();
        $next = $open->first();

        $buyers = $sale->clients->values()->map(fn ($c) => [
            'client_id'  => $c->id,
            'document'   => (string) $c->person->identification_number,
            'first_name' => (string) $c->person->first_name,
            'last_name'  => (string) $c->person->last_name,
            'primary'    => (bool) $c->pivot->is_primary,
            'code'       => $c->client_code,
        ]);

        return [
            'obligation_id' => $obligation->id,
            'label'         => "{$obligation->obligation_code} · {$sale->lot->project->name} · {$sale->lot->label} · ".($sale->primaryClient()?->person->full_name ?? ''),
            'codes'         => array_values(array_filter([$obligation->obligation_code, $sale->sale_code, ...$buyers->pluck('code')->all()])),
            'buyers'        => $buyers->all(),
            'primary_index' => max(0, (int) $buyers->search(fn ($b) => $b['primary'])),
            'next_due'      => $next ? ['balance' => round((float) $next->balance, 2), 'due_date' => $next->due_date] : null,
            'overdue'       => round((float) $open->where('days_overdue', '>', 0)->sum('balance'), 2),
            'balance'       => round((float) $open->sum('balance'), 2),
            'open'          => $open->pluck('balance')->map(fn ($b) => round((float) $b, 2))->all(),
        ];
    }
}
