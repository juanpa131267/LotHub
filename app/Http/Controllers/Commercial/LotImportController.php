<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Commercial\Project;
use App\Services\Lots\LotInventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** US-LOT-004 Carga masiva de lotes desde la plantilla de Excel del proyecto. */
class LotImportController extends Controller
{
    public function __construct(private LotInventoryService $inventory) {}

    public function index(Request $request): View
    {
        return view('commercial.lot-import', [
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
            'selected' => (int) $request->query('project_id', session('import_project')),
            'result'   => session('import_result'),
            'maxRows'  => LotInventoryService::MAX_ROWS,
        ]);
    }

    public function template(Request $request): BinaryFileResponse
    {
        $project = Project::findOrFail($request->validate(['project_id' => ['required', 'integer']])['project_id']);

        return response()->download($this->inventory->template($project), 'carga_lotes_'.Str::slug($project->code).'.xlsx')->deleteFileAfterSend();
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'lots_file'  => ['required', 'file', 'extensions:xlsx', 'max:10240'],
        ], [], ['project_id' => 'proyecto', 'lots_file' => 'plantilla']);

        $project = Project::findOrFail($request->project_id);

        try {
            $result = $this->inventory->import($project, $request->file('lots_file'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->with('import_project', $project->id);
        }

        $redirect = back()->with('import_result', $result)->with('import_project', $project->id);

        return $result['errors']
            ? $redirect->with('error', 'La plantilla tiene '.count($result['errors']).' error(es); no se creó ningún lote.')
            : $redirect->with('success', "Se crearon {$result['created']} lotes en {$project->name}.");
    }
}
