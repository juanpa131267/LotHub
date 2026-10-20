<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Commercial\Project;
use App\Services\Pricing\PriceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Precios de los lotes del proyecto: valor general por m² o planilla de Excel lote a lote. */
class ProjectPriceController extends Controller
{
    public function __construct(private PriceService $prices) {}

    public function index(int $id): View
    {
        $project = Project::with('publishedPlan')->findOrFail($id);

        return view('commercial.prices', [
            'project' => $project,
            'summary' => $this->prices->summary($project),
            'scopes'  => PriceService::SCOPES,
            'importResult' => session('import_result'),
        ]);
    }

    public function applyPerM2(int $id, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'price_per_m2' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'scope'        => ['required', Rule::in(array_keys(PriceService::SCOPES))],
        ], [], ['price_per_m2' => 'valor por m²', 'scope' => 'alcance']);

        $count = $this->prices->applyPerM2(Project::findOrFail($id), (float) $data['price_per_m2'], $data['scope']);

        return back()->with('success', "Precio actualizado en {$count} lotes (área × $".number_format($data['price_per_m2'], 0, ',', '.')." por m², redondeado al millar).");
    }

    public function template(int $id): BinaryFileResponse
    {
        $project = Project::findOrFail($id);
        $path = $this->prices->template($project);

        return response()->download($path, 'precios_'.Str::slug($project->code.'_'.$project->name).'_'.now()->format('Ymd').'.xlsx')
            ->deleteFileAfterSend();
    }

    public function import(int $id, Request $request): RedirectResponse
    {
        $request->validate(['prices_file' => ['required', 'file', 'extensions:xlsx', 'max:10240']], [], ['prices_file' => 'planilla']);

        try {
            $result = $this->prices->import(Project::findOrFail($id), $request->file('prices_file'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['errors']) {
            return back()->with('error', 'La planilla tiene '.count($result['errors']).' error(es); no se aplicó ningún cambio. Corrígelos y vuelve a cargarla.')
                ->with('import_result', $result);
        }

        return back()->with('success', "Planilla aplicada: {$result['updated']} precios actualizados, {$result['unchanged']} sin cambio, {$result['blank']} en blanco.")
            ->with('import_result', $result);
    }
}
