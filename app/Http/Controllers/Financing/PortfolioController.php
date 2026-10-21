<?php

namespace App\Http\Controllers\Financing;

use App\Http\Controllers\Controller;
use App\Models\Commercial\Project;
use App\Services\AuditLogger;
use App\Services\Financing\PortfolioService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** CU-23 Tablero de cartera y mora con filtros y exportación. */
class PortfolioController extends Controller
{
    public function __construct(private PortfolioService $portfolio) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $obligations = $this->portfolio->query($filters)->orderByDesc('ob.max_days_overdue')->orderByDesc('ob.balance')->paginate(25)->withQueryString();

        return view('financing.portfolio', [
            'filters'     => $filters,
            'summary'     => $this->portfolio->summary($filters),
            'obligations' => $obligations,
            'overdue'     => $this->portfolio->overdueAmounts(collect($obligations->items())->pluck('obligation_id')),
            'projects'    => Project::orderBy('name')->pluck('name', 'id'),
            'buckets'     => PortfolioService::BUCKETS,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filters = $this->filters($request);
        $title = collect([
            ! empty($filters['project_id']) ? 'Proyecto '.Project::whereKey($filters['project_id'])->value('name') : 'Todos los proyectos',
            ! empty($filters['bucket']) ? PortfolioService::BUCKETS[$filters['bucket']][0] : null,
            ! empty($filters['q']) ? "búsqueda «{$filters['q']}»" : null,
        ])->filter()->implode(' · ');

        AuditLogger::log('EXPORTAR_CARTERA', 'obligations', 0, [], $filters);

        return response()->download($this->portfolio->export($filters, $title), 'cartera_'.now()->format('Ymd_His').'.xlsx')->deleteFileAfterSend();
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'project_id' => ['nullable', 'integer'],
            'bucket'     => ['nullable', 'in:'.implode(',', array_keys(PortfolioService::BUCKETS))],
            'q'          => ['nullable', 'string', 'max:80'],
        ]);
    }
}
