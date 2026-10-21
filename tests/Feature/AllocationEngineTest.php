<?php

namespace Tests\Feature;

use App\Models\Payments\BankStatement;
use App\Models\Payments\BankStatementLine;
use App\Models\Payments\Payment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Sprint 6: carga del extracto, puntaje de asignación y cola de revisión. */
class AllocationEngineTest extends TestCase
{
    private function csv(array $rows): UploadedFile
    {
        $content = "Fecha;Descripción;Referencia;NIT/Cédula;Débito;Crédito\n"
            .implode("\n", array_map(fn ($r) => implode(';', $r), $rows))."\n";

        return UploadedFile::fake()->createWithContent('extracto_'.md5($content).'.csv', $content);
    }

    private function upload($user, UploadedFile $file)
    {
        return $this->actingAs($user)->post(route('statements.store'), [
            'bank_name' => 'Bancolombia', 'account_label' => 'Ahorros ****1234', 'payment_method_id' => 2, 'statement_file' => $file,
        ]);
    }

    public function test_statement_assigns_clear_matches_and_queues_the_rest(): void
    {
        Storage::fake('local');
        $clerk = $this->user(['AUXILIAR_CARTERA']);
        $buyer = $this->client(person: ['identification_number' => '1070123456', 'first_name' => 'Marta', 'last_name' => 'Quintero Ruiz']);
        $obligation = $this->obligation($buyer);
        $today = now()->format('d/m/Y');

        $response = $this->upload($clerk, $this->csv([
            [$today, 'CONSIGNACION NACIONAL', 'C-1001', '1.070.123.456', '', '10.000.000'],   // documento + valor de la cuota que sigue
            [$today, 'TRANSFERENCIA DESCONOCIDA', 'T-2002', '', '', '777.777'],               // sin candidato claro
            [$today, 'COMISION MANEJO', '', '', '15.900', ''],                                 // débito: se ignora
        ]));

        $statement = BankStatement::sole();
        $response->assertRedirect(route('statements.show', $statement->id));
        $this->assertSame(2, $statement->lines_count);

        $auto = BankStatementLine::where('reference', 'C-1001')->sole();
        $this->assertSame(BankStatementLine::ASSIGNED, $auto->status);
        $this->assertSame('AUTOMATICA', $auto->assignment_mode);
        $this->assertSame($obligation->id, $auto->matched_obligation_id);
        $this->assertGreaterThanOrEqual(75, $auto->score);
        $this->assertEquals(10_000_000, Payment::find($auto->payment_id)->amount);
        $this->assertSame('Extracto · automático', Payment::find($auto->payment_id)->originLabel());

        $pending = BankStatementLine::where('reference', 'T-2002')->sole();
        $this->assertSame(BankStatementLine::REVIEW, $pending->status);

        $this->actingAs($clerk)->get(route('statements.show', $statement->id))->assertOk()->assertSee('C-1001');
        $this->actingAs($clerk)->get(route('allocation.queue'))->assertOk()->assertSee('T-2002');
        $this->actingAs($clerk)->get(route('allocation.review', $pending->id))->assertOk();

        // Revisión manual: se asigna a la obligación correcta.
        $this->actingAs($clerk)->post(route('allocation.assign', $pending->id), ['obligation_id' => $obligation->id, 'review_notes' => 'Confirmado con el cliente'])
            ->assertRedirect(route('allocation.queue'));
        $pending->refresh();
        $this->assertSame(BankStatementLine::ASSIGNED, $pending->status);
        $this->assertSame('MANUAL', $pending->assignment_mode);
        $this->assertSame($clerk->id, $pending->reviewed_by);
        $this->assertEquals(10_777_777, Payment::where('obligation_id', $obligation->id)->sum('amount'));
    }

    public function test_same_file_is_rejected_and_repeated_movements_are_marked_duplicate(): void
    {
        Storage::fake('local');
        $clerk = $this->user(['AUXILIAR_CARTERA']);
        $this->obligation($this->client(person: ['identification_number' => '52987654']));
        $row = [now()->format('d/m/Y'), 'CONSIGNACION', 'C-5005', '52987654', '', '10000000'];

        $this->upload($clerk, $this->csv([$row]))->assertSessionHas('success');
        $this->upload($clerk, $this->csv([$row]))->assertSessionHas('error');                       // mismo archivo (SHA-256)
        $this->upload($clerk, $this->csv([$row, [now()->format('d/m/Y'), 'OTRO', 'X-1', '', '', '1000']]))->assertSessionHas('success');

        $this->assertSame(BankStatementLine::DUPLICATE, BankStatementLine::where('reference', 'C-5005')->latest('id')->value('status'));
        $this->assertSame(1, Payment::count());
    }

    public function test_discarding_a_movement_and_reversal_returns_it_to_the_queue(): void
    {
        Storage::fake('local');
        $clerk = $this->user(['AUXILIAR_CARTERA']);
        $this->obligation($this->client(person: ['identification_number' => '80111222']));

        $this->upload($clerk, $this->csv([
            [now()->format('d/m/Y'), 'PAGO LOTE', 'P-1', '80111222', '', '10000000'],
            [now()->format('d/m/Y'), 'RENDIMIENTOS FINANCIEROS', 'R-1', '', '', '12345'],
        ]));

        $income = BankStatementLine::where('reference', 'R-1')->sole();
        $this->actingAs($clerk)->post(route('allocation.discard', $income->id), ['review_notes' => 'Rendimientos de la cuenta'])->assertSessionHas('success');
        $this->assertSame(BankStatementLine::DISCARDED, $income->fresh()->status);

        $assigned = BankStatementLine::where('reference', 'P-1')->sole();
        $this->actingAs($clerk)->post(route('payments.reverse', $assigned->payment_id), ['reversal_reason' => 'Asignado al cliente equivocado']);
        $this->assertSame(BankStatementLine::REVIEW, $assigned->fresh()->status);
    }
}
