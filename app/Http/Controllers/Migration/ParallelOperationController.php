<?php

namespace App\Http\Controllers\Migration;

use App\Http\Controllers\Controller;
use App\Services\Migration\LegacyMigrationService;
use App\Services\Migration\ParallelRunService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Sprint 10 · Operación en paralelo: migración de la cartera vigente y cortes de comparación. */
class ParallelOperationController extends Controller
{
    public function __construct(private LegacyMigrationService $migration, private ParallelRunService $parallel) {}

    public function index(): View
    {
        return view('migration.index', [
            'imports' => DB::table('legacy_imports as i')->join('users as u', 'u.id', '=', 'i.imported_by')
                ->select('i.*', 'u.username')->latest('i.id')->get(),
            'runs'    => DB::table('parallel_runs as r')->join('users as u', 'u.id', '=', 'r.run_by')
                ->select('r.id', 'r.cut_off_date', 'r.original_name', 'r.compared', 'r.matched', 'r.differences', 'r.tolerance', 'r.run_at', 'u.username')
                ->latest('r.id')->limit(20)->get(),
            'migrated'=> DB::table('legacy_import_rows')->count(),
            'result'  => session('migration_result'),
        ]);
    }

    public function template(): BinaryFileResponse
    {
        return response()->download($this->migration->template(), 'plantilla_migracion_cartera.xlsx')->deleteFileAfterSend();
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'migration_file'  => ['required', 'file', 'extensions:xlsx', 'max:10240'],
            'late_from_today' => ['nullable', 'boolean'],
        ], [], ['migration_file' => 'plantilla']);

        try {
            $result = $this->migration->import($request->file('migration_file'), (bool) $request->boolean('late_from_today', true));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $result['errors']
            ? back()->with('migration_result', $result)->with('error', 'La plantilla tiene '.count($result['errors']).' error(es); no se migró ninguna fila.')
            : back()->with('migration_result', $result)->with('success', "Se migraron {$result['created']} negociaciones con su plan de cuotas.");
    }

    public function cutTemplate(): BinaryFileResponse
    {
        return response()->download($this->parallel->template(), 'corte_paralelo_'.now()->format('Ymd').'.xlsx')->deleteFileAfterSend();
    }

    public function compare(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cut_file'     => ['required', 'file', 'extensions:xlsx,csv', 'max:10240'],
            'cut_off_date' => ['required', 'date', 'before_or_equal:today'],
            'tolerance'    => ['required', 'numeric', 'min:0', 'max:10000000'],
        ], [], ['cut_file' => 'archivo del corte', 'cut_off_date' => 'fecha de corte', 'tolerance' => 'tolerancia']);

        try {
            $id = $this->parallel->compare($request->file('cut_file'), CarbonImmutable::parse($data['cut_off_date']), (float) $data['tolerance']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('parallel.show', $id)->with('success', 'Corte comparado.');
    }

    public function show(int $id): View
    {
        $run = DB::table('parallel_runs')->find($id) ?? abort(404);

        return view('migration.run', [
            'run'      => $run,
            'rows'     => collect(json_decode($run->result, true))->sortBy(fn ($r) => array_search($r['status'], ['DIFERENCIA', 'NO_MIGRADA', 'SOLO_SISTEMA', 'COINCIDE']))->values(),
            'statuses' => ParallelRunService::STATUSES,
        ]);
    }

    public function export(int $id): BinaryFileResponse
    {
        $run = DB::table('parallel_runs')->find($id) ?? abort(404);

        return response()->download($this->parallel->export($run), "corte_paralelo_{$run->cut_off_date}.xlsx")->deleteFileAfterSend();
    }
}
