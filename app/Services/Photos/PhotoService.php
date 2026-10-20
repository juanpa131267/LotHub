<?php

namespace App\Services\Photos;

use App\Models\Commercial\Project;
use App\Models\Commercial\ProjectPhoto;
use App\Services\AuditLogger;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fotos de la ficha de venta. Cada foto se guarda optimizada (máx. 2000 px, JPEG/WebP) más una
 * miniatura, con la orientación corregida (las fotos de celular traen la rotación en EXIF).
 * Requiere la extensión GD de PHP.
 */
class PhotoService
{
    public const MAX_SIDE = 2000;
    public const THUMB_SIDE = 560;
    public const MAX_UPLOAD_MB = 20;
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function store(Project $project, UploadedFile $file, ?string $caption, int $userId): ProjectPhoto
    {
        $image = $this->open($file);
        $dir = "photos/{$project->id}";
        $base = now()->format('Ymd_His').'_'.Str::random(6);
        File::ensureDirectoryExists(storage_path("app/{$dir}"));

        // PNG con transparencia (p. ej. el logo del conjunto) se guarda en WebP para conservarla.
        $keepAlpha = in_array(strtolower($file->getClientOriginalExtension()), ['png', 'webp'], true);
        $ext = $keepAlpha ? 'webp' : 'jpg';

        $full = $this->fit($image, self::MAX_SIDE);
        $thumb = $this->fit($image, self::THUMB_SIDE);
        $this->save($full, storage_path("app/{$dir}/{$base}.{$ext}"), $ext, 84);
        $this->save($thumb, storage_path("app/{$dir}/{$base}_thumb.{$ext}"), $ext, 78);

        $photo = ProjectPhoto::create([
            'project_id'    => $project->id,
            'path'          => "{$dir}/{$base}.{$ext}",
            'thumb_path'    => "{$dir}/{$base}_thumb.{$ext}",
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'caption'       => $caption ? Str::limit(trim($caption), 250, '') : null,
            'width'         => imagesx($full),
            'height'        => imagesy($full),
            'sort_order'    => (int) ProjectPhoto::where('project_id', $project->id)->max('sort_order') + 1,
            'uploaded_by'   => $userId,
        ]);

        AuditLogger::log('CARGAR_FOTO', 'project_photos', $photo->id, [], ['proyecto' => $project->code, 'archivo' => $photo->original_name, 'anotacion' => $photo->caption]);

        return $photo;
    }

    public function updateCaption(ProjectPhoto $photo, ?string $caption): void
    {
        $photo->update(['caption' => $caption ? Str::limit(trim($caption), 250, '') : null]);
    }

    /** Marca la portada (una por proyecto, garantizado también por la BD). */
    public function setCover(ProjectPhoto $photo): void
    {
        DB::transaction(function () use ($photo) {
            ProjectPhoto::where('project_id', $photo->project_id)->where('is_cover', true)->update(['is_cover' => false]);
            $photo->update(['is_cover' => true]);
        });
    }

    /** @param int[] $ids fotos del proyecto en el nuevo orden */
    public function reorder(Project $project, array $ids): void
    {
        DB::transaction(function () use ($project, $ids) {
            foreach (array_values($ids) as $position => $id) {
                ProjectPhoto::where('project_id', $project->id)->where('id', $id)->update(['sort_order' => $position + 1]);
            }
        });
    }

    public function delete(ProjectPhoto $photo): void
    {
        AuditLogger::log('ELIMINAR_FOTO', 'project_photos', $photo->id, ['archivo' => $photo->original_name, 'anotacion' => $photo->caption]);
        foreach ([$photo->path, $photo->thumb_path] as $path) {
            @unlink(storage_path('app/'.$path));
        }
        $photo->delete();
    }

    // ── Procesamiento de imagen ────────────────────────────────────────────
    private function open(UploadedFile $file): GdImage
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('El servidor no tiene la extensión GD de PHP, necesaria para procesar fotos.');
        }

        $path = $file->getRealPath();
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if (! $image) {
            throw new RuntimeException("No se pudo leer «{$file->getClientOriginalName()}» como imagen.");
        }

        // Rotación EXIF de las fotos de celular (solo JPEG la trae).
        $orientation = function_exists('exif_read_data') ? (@exif_read_data($path)['Orientation'] ?? 1) : 1;
        $image = match ((int) $orientation) {
            3       => imagerotate($image, 180, 0),
            6       => imagerotate($image, -90, 0),
            8       => imagerotate($image, 90, 0),
            default => $image,
        };

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    private function fit(GdImage $image, int $maxSide): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, $maxSide / max($w, $h));
        if ($scale === 1) {
            return $image;
        }

        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $out;
    }

    private function save(GdImage $image, string $path, string $ext, int $quality): void
    {
        $ok = $ext === 'webp' ? imagewebp($image, $path, $quality) : imagejpeg($image, $path, $quality);
        if (! $ok) {
            throw new RuntimeException('No se pudo guardar la imagen procesada.');
        }
    }
}
