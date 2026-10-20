<?php

namespace App\Services\Plans;

use App\Models\Catalogs\LotStatus;
use App\Models\Commercial\Lot;
use App\Models\Commercial\Project;
use App\Models\Commercial\ProjectPlan;
use App\Models\Commercial\ProjectStage;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ciclo de vida del plano de un proyecto:
 *   1. upload()    guarda el archivo y lanza el procesamiento en segundo plano.
 *   2. process()   DWG → DXF → JSON (lo ejecuta `php artisan plans:process {id}`).
 *   3. status()    avance que consulta la pantalla mientras se procesa.
 *   4. syncLots()  crea/actualiza los lotes del proyecto a partir del plano.
 *   5. publish()   lo deja visible en el sitio público.
 *
 * El procesamiento lo hace tools/plan-processor/process_plan.py (Python: ezdxf + shapely).
 * La conversión DWG → DXF usa el conversor configurado en PLAN_DWG2DXF (LibreDWG).
 * Guía: docs/PLANOS.md
 */
class PlanService
{
    /** Estado del lote en la BD → clase que usa el visor (public/js/plan-viewer.js). */
    private const VIEWER_STATUS = [
        LotStatus::DISPONIBLE => 'available',
        LotStatus::RESERVADO  => 'reserved',
        LotStatus::VENDIDO    => 'sold',
        LotStatus::BLOQUEADO  => 'blocked',
    ];

    public static function acceptsDwg(): bool
    {
        return (bool) self::converterPath();
    }

    /** Tamaño máximo real: el menor entre lothub.plans.max_mb y los límites de PHP. */
    public static function maxUploadBytes(): int
    {
        return (int) min(
            config('lothub.plans.max_mb') * 1024 * 1024,
            self::iniBytes(ini_get('upload_max_filesize')) ?: PHP_INT_MAX,
            self::iniBytes(ini_get('post_max_size')) ?: PHP_INT_MAX,
        );
    }

    public static function maxUploadMb(): int
    {
        return intdiv(self::maxUploadBytes(), 1024 * 1024);
    }

    // ── 1. Carga ───────────────────────────────────────────────────────────
    public function upload(Project $project, UploadedFile $file, int $userId): ProjectPlan
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $dir = config('lothub.plans.directory')."/{$project->id}";
        $name = now()->format('Ymd_His').'_'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).".{$extension}";

        File::ensureDirectoryExists(storage_path("app/{$dir}"));
        $file->move(storage_path("app/{$dir}"), $name);

        $plan = ProjectPlan::create([
            'project_id'    => $project->id,
            'original_name' => $file->getClientOriginalName(),
            'source_path'   => "{$dir}/{$name}",
            'source_hash'   => hash_file('sha256', storage_path("app/{$dir}/{$name}")),
            'status'        => ProjectPlan::PROCESSING,
            'uploaded_by'   => $userId,
        ]);

        AuditLogger::log('CARGAR_PLANO', 'project_plans', $plan->id, [], ['proyecto' => $project->code, 'archivo' => $plan->original_name]);

        $this->dispatch($plan);

        return $plan;
    }

    /**
     * Lanza `php artisan plans:process {id}` como proceso independiente, para que la petición
     * web responda de inmediato (un plano grande puede tardar minutos en convertirse y procesarse).
     */
    public function dispatch(ProjectPlan $plan): void
    {
        $plan->update(['status' => ProjectPlan::PROCESSING, 'error_message' => null]);
        $this->writeProgress($plan, 'En cola', 2);

        $php = escapeshellarg(config('lothub.plans.php'));
        $artisan = escapeshellarg(base_path('artisan'));

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start \"\" /B {$php} {$artisan} plans:process {$plan->id} > NUL 2>&1", 'r'));
        } else {
            exec("nohup {$php} {$artisan} plans:process {$plan->id} > /dev/null 2>&1 &");
        }
    }

    /**
     * Vuelve a procesar un plano. Uno con error se reintenta en el mismo registro; uno listo (y quizá
     * publicado) genera una NUEVA versión con el mismo archivo, para que el sitio siga mostrando la
     * versión publicada hasta que se revise y publique la nueva.
     */
    public function reprocess(ProjectPlan $plan): ProjectPlan
    {
        if (! $plan->isReady()) {
            $this->dispatch($plan);

            return $plan;
        }

        $copy = $plan->replicate(['data_path', 'report', 'lots_detected', 'is_published', 'processed_at', 'error_message', 'published_project_id']);
        $copy->fill(['status' => ProjectPlan::PROCESSING, 'uploaded_by' => auth()->id() ?? $plan->uploaded_by])->save();
        AuditLogger::log('REPROCESAR_PLANO', 'project_plans', $copy->id, [], ['version_anterior' => $plan->id]);
        $this->dispatch($copy);

        return $copy;
    }

    // ── 2. Procesamiento (síncrono; lo llama el comando plans:process) ────
    public function process(ProjectPlan $plan): ProjectPlan
    {
        $converted = null;

        try {
            $source = storage_path('app/'.$plan->source_path);
            if (str_ends_with(strtolower($source), '.dwg')) {
                $this->writeProgress($plan, 'Convirtiendo DWG a DXF', 5);
                $dxf = $converted = $this->convertDwg($source, $plan->id);
            } else {
                $dxf = $source;
            }

            // Un JSON por versión: reprocesar no debe tocar el resultado de la versión publicada.
            $jsonRelative = preg_replace('/\.(dwg|dxf)$/i', "_v{$plan->id}.json", $plan->source_path);
            $result = Process::timeout(config('lothub.plans.timeout'))->run([
                self::binary(config('lothub.plans.python')), config('lothub.plans.script'),
                '--dxf', $dxf, '--out', storage_path('app/'.$jsonRelative), '--progress', $this->progressPath($plan),
            ]);

            if (! $result->successful()) {
                $error = json_decode(trim(last(explode("\n", trim($result->errorOutput())))), true)['error'] ?? null;
                throw new RuntimeException($error ?: 'El procesador de planos falló: '.Str::limit($result->errorOutput(), 300));
            }

            $data = json_decode(File::get(storage_path('app/'.$jsonRelative)), true);
            $plan->update([
                'status'        => ProjectPlan::READY,
                'data_path'     => $jsonRelative,
                'lots_detected' => $data['report']['lots_detected'],
                'report'        => $data['report'],
                'error_message' => null,
                'processed_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Plano {$plan->id}: {$e->getMessage()}");
            $plan->update(['status' => ProjectPlan::FAILED, 'error_message' => Str::limit($e->getMessage(), 490), 'processed_at' => now()]);
        } finally {
            // El DXF intermedio de un DWG puede pesar 5× el original (221 MB para 44 MB): no se conserva.
            if ($converted && is_file($converted)) {
                @unlink($converted);
            }
            @unlink($this->progressPath($plan));
        }

        return $plan->refresh();
    }

    // ── 3. Avance ──────────────────────────────────────────────────────────
    /** Estado para la pantalla: {status, step, pct, elapsed, lots, error}. */
    public function status(ProjectPlan $plan): array
    {
        // Un proceso que murió sin informar (servidor reiniciado, sin memoria…) no debe quedar "procesando" para siempre.
        if ($plan->status === ProjectPlan::PROCESSING && $plan->updated_at->diffInSeconds(now()) > config('lothub.plans.timeout') + 120) {
            $plan->update(['status' => ProjectPlan::FAILED, 'error_message' => 'El procesamiento se interrumpió o superó el tiempo máximo. Intenta de nuevo.']);
        }

        $progress = is_file($this->progressPath($plan)) ? json_decode((string) @file_get_contents($this->progressPath($plan)), true) : null;

        return [
            'status'  => $plan->status,
            'step'    => $progress['step'] ?? ($plan->status === ProjectPlan::PROCESSING ? 'Iniciando' : null),
            'pct'     => $progress['pct'] ?? ($plan->status === ProjectPlan::PROCESSING ? 1 : 100),
            'elapsed' => (int) $plan->updated_at->diffInSeconds(now()),
            'lots'    => $plan->lots_detected,
            'error'   => $plan->error_message,
        ];
    }

    // ── 4. Lotes ───────────────────────────────────────────────────────────
    /**
     * Crea los lotes del plano que no existen y, opcionalmente, actualiza el área de los existentes.
     * El área oficial es la escrita en el plano; si no hay, la calculada del polígono.
     * Los lotes nuevos se insertan por bloques (un plano puede traer miles) y la operación queda
     * en auditoría como un solo registro con el resumen.
     *
     * Si el plano generó la nomenclatura MANZANA-NÚMERO (planos por etapa) y el lote ya existía con
     * su código original (p. ej. E1-1502), se RENOMBRA a su nuevo código (A-12) conservando su id,
     * precio y estado, en lugar de crear un duplicado.
     *
     * @return array{created:int, renamed:int, updated:int, unchanged:int}
     */
    public function syncLots(ProjectPlan $plan, float $pricePerM2, ?int $stageId, bool $updateAreas): array
    {
        $data = $plan->data() ?? throw new RuntimeException('El plano no está listo.');
        $counts = ['created' => 0, 'renamed' => 0, 'updated' => 0, 'unchanged' => 0];

        DB::transaction(function () use ($plan, $data, $pricePerM2, $stageId, $updateAreas, &$counts) {
            $existing = Lot::where('project_id', $plan->project_id)->get()->keyBy(fn ($l) => "{$l->block}-{$l->lot_number}");
            $stages = $this->stagesFromPlan($plan->project_id, $data['lots']);
            $now = now();
            $new = [];

            foreach ($data['lots'] as $p) {
                $area = $p['area_label'] ?: $p['area'];
                $lot = $existing[$p['code']] ?? null;

                if (! $lot && isset($p['plan_ref'], $existing[$p['plan_ref']])) {
                    $old = $existing[$p['plan_ref']];
                    Lot::whereKey($old->id)->update([
                        'block' => $p['block'], 'lot_number' => $p['number'], 'updated_at' => $now,
                        'stage_id' => $stages[$p['stage_label'] ?? ''] ?? $old->stage_id,
                        'notes' => Str::limit("Ref. plano: {$p['plan_ref']}", 250),
                    ]);
                    $counts['renamed']++;

                    continue;
                }

                if (! $lot) {
                    $new[] = [
                        'project_id' => $plan->project_id,
                        'stage_id'   => $stages[$p['stage_label'] ?? ''] ?? $stageId,
                        'block'      => $p['block'],
                        'lot_number' => $p['number'],
                        'area_m2'    => $area,
                        'list_price' => max(1000, round($area * $pricePerM2, -3)),
                        'status_id'  => LotStatus::DISPONIBLE,
                        'notes'      => Str::limit(isset($p['plan_ref']) ? "Ref. plano: {$p['plan_ref']}" : 'Creado desde el plano '.$plan->original_name, 250),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                } elseif ($updateAreas && abs((float) $lot->area_m2 - $area) > 0.005) {
                    $lot->update(['area_m2' => $area]);
                    $counts['updated']++;
                } else {
                    $counts['unchanged']++;
                }
            }

            foreach (array_chunk($new, 500) as $chunk) {
                Lot::insert($chunk);
            }
            $counts['created'] = count($new);
        });

        AuditLogger::log('SINCRONIZAR_LOTES_PLANO', 'project_plans', $plan->id, [], $counts + ['precio_m2' => $pricePerM2]);

        return $counts;
    }

    /**
     * Etapas que trae el plano (Etapa I, II…) → etapas del proyecto (project_stages), creándolas si
     * no existen. Devuelve [nombre => id].
     */
    private function stagesFromPlan(int $projectId, array $lots): array
    {
        $names = collect($lots)->pluck('stage_label')->filter()->unique()->values();
        $order = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10];

        return $names->mapWithKeys(function ($name) use ($projectId, $order) {
            $position = $order[trim(str_ireplace('Etapa', '', $name))] ?? 1;
            $stage = ProjectStage::firstOrCreate(['project_id' => $projectId, 'name' => $name], ['display_order' => $position]);

            return [$name => $stage->id];
        })->all();
    }

    // ── 5. Publicación ─────────────────────────────────────────────────────
    public function publish(ProjectPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            ProjectPlan::where('project_id', $plan->project_id)->where('id', '!=', $plan->id)->update(['is_published' => false]);
            $plan->update(['is_published' => true]);
        });
        AuditLogger::log('PUBLICAR_PLANO', 'project_plans', $plan->id);
    }

    public function unpublish(ProjectPlan $plan): void
    {
        $plan->update(['is_published' => false]);
        AuditLogger::log('DESPUBLICAR_PLANO', 'project_plans', $plan->id);
    }

    /**
     * Estado de cada lote del proyecto para el visor, por código "GRUPO-NÚMERO" (A-12, E2-15…).
     * Los lotes del plano que no estén en la BD se muestran como "Sin publicar".
     */
    public function lotStates(Project $project): array
    {
        return Lot::with('stage')->where('project_id', $project->id)->get()
            ->mapWithKeys(fn (Lot $l) => ["{$l->block}-{$l->lot_number}" => [
                'id'       => $l->id,
                'status'   => self::VIEWER_STATUS[$l->status_id] ?? 'blocked',
                'price'    => (float) $l->list_price,
                'area'     => (float) $l->area_m2,
                'stage'    => $l->stage?->name,
                'stage_id' => $l->stage_id,
            ]])->all();
    }

    // ── Internos ───────────────────────────────────────────────────────────
    private function progressPath(ProjectPlan $plan): string
    {
        return storage_path('app/'.config('lothub.plans.directory')."/{$plan->project_id}/{$plan->id}.progress.json");
    }

    private function writeProgress(ProjectPlan $plan, string $step, int $pct): void
    {
        File::ensureDirectoryExists(dirname($this->progressPath($plan)));
        File::put($this->progressPath($plan), json_encode(['step' => $step, 'pct' => $pct], JSON_UNESCAPED_UNICODE));
    }

    private static function iniBytes(string|false $value): int
    {
        $value = trim((string) $value);
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G'     => $number * 1024 ** 3,
            'M'     => $number * 1024 ** 2,
            'K'     => $number * 1024,
            default => $number,
        };
    }

    private static function converterPath(): ?string
    {
        $path = self::binary(config('lothub.plans.dwg2dxf'));

        return $path && is_file($path) ? $path : null;
    }

    /**
     * Rutas relativas (p. ej. tools/plan-processor/.venv/...) se resuelven desde la raíz del
     * proyecto; un nombre de comando suelto ("python") se deja tal cual para que lo busque el PATH.
     */
    private static function binary(?string $path): ?string
    {
        $isRelativePath = $path && preg_match('#[\\\\/]#', $path) && ! preg_match('#^([a-zA-Z]:|[\\\\/])#', $path);

        return $isRelativePath ? base_path($path) : $path;
    }

    private function convertDwg(string $source, int $planId): string
    {
        $converter = self::converterPath()
            ?? throw new RuntimeException('Este servidor no tiene conversor de DWG. Exporta el plano como DXF desde AutoCAD (Guardar como → DXF) y vuelve a cargarlo.');

        $target = preg_replace('/\.dwg$/i', "_v{$planId}.dxf", $source);
        Process::timeout(config('lothub.plans.timeout'))->run([$converter, '-y', '-o', $target, $source]);

        // LibreDWG emite advertencias aun cuando convierte bien: el criterio es que exista el DXF.
        if (! is_file($target) || filesize($target) < 100) {
            throw new RuntimeException('No se pudo convertir el DWG. Prueba exportándolo como DXF desde AutoCAD.');
        }

        return $target;
    }
}
