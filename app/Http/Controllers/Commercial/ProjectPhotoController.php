<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Commercial\Project;
use App\Models\Commercial\ProjectPhoto;
use App\Services\Photos\PhotoService;
use App\Services\Plans\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fotos de la ficha de venta del proyecto. Dos formas de carga:
 *  - masiva: varias fotos a la vez, sin anotación (el navegador las envía una por una);
 *  - una por una: con anotación ("Piscina", "Logo del conjunto", "Zona común"…).
 * Las anotaciones se pueden editar después, también en las fotos cargadas en bloque.
 */
class ProjectPhotoController extends Controller
{
    public function __construct(private PhotoService $photos) {}

    public function index(int $id): View
    {
        $project = Project::with('photos')->findOrFail($id);

        return view('commercial.photos', [
            'project' => $project,
            'maxMb'   => min(PhotoService::MAX_UPLOAD_MB, PlanService::maxUploadMb()),
        ]);
    }

    /** POST (una foto por petición, con anotación opcional). Responde JSON para la carga masiva. */
    public function store(int $id, Request $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $request->validate([
            'photo'   => ['required', 'file', 'extensions:'.implode(',', PhotoService::EXTENSIONS), 'max:'.(PhotoService::MAX_UPLOAD_MB * 1024)],
            'caption' => ['nullable', 'string', 'max:250'],
        ], [], ['photo' => 'foto', 'caption' => 'anotación']);

        try {
            $photo = $this->photos->store($project, $request->file('photo'), $request->input('caption'), $request->user()->id);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'photo' => $photo]);
    }

    public function update(int $id, int $photoId, Request $request): JsonResponse
    {
        $request->validate(['caption' => ['nullable', 'string', 'max:250']], [], ['caption' => 'anotación']);
        $this->photos->updateCaption($this->photoOf($id, $photoId), $request->input('caption'));

        return response()->json(['ok' => true, 'message' => 'Anotación guardada.']);
    }

    public function cover(int $id, int $photoId): JsonResponse
    {
        $this->photos->setCover($this->photoOf($id, $photoId));

        return response()->json(['ok' => true, 'message' => 'Portada actualizada.']);
    }

    public function reorder(int $id, Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        $this->photos->reorder(Project::findOrFail($id), $data['ids']);

        return response()->json(['ok' => true]);
    }

    public function destroy(int $id, int $photoId): JsonResponse
    {
        $this->photos->delete($this->photoOf($id, $photoId));

        return response()->json(['ok' => true, 'message' => 'Foto eliminada.']);
    }

    /**
     * GET público /media/fotos/{id}/{full|thumb}: sirve la imagen con caché del navegador.
     * Se sirve por controlador (no con el enlace public/storage) para no depender de symlinks.
     */
    public function media(int $photoId, string $size): BinaryFileResponse
    {
        $photo = ProjectPhoto::findOrFail($photoId);
        $path = storage_path('app/'.($size === 'thumb' ? $photo->thumb_path : $photo->path));
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'public, max-age=2592000, immutable']);
    }

    private function photoOf(int $projectId, int $photoId): ProjectPhoto
    {
        return ProjectPhoto::where('project_id', $projectId)->findOrFail($photoId);
    }
}
