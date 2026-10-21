<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * S11 Respaldo diario: volcado de la base (con triggers, vistas, rutinas y eventos) comprimido con
 * gzip y archivo .zip de storage/app/private (documentos, extractos y estados de cuenta).
 * Conserva los últimos lothub.backup.retention_days días. Lo ejecuta el planificador a las 2:00 a. m.
 * Copia los respaldos fuera del servidor (otro disco, nube) para protegerlos ante una falla del equipo.
 */
class BackupCommand extends Command
{
    protected $signature = 'lothub:backup {--only-db : solo la base de datos}';

    protected $description = 'Respalda la base de datos y los archivos privados de LotHub';

    public function handle(): int
    {
        $dir = rtrim(config('lothub.backup.path'), '/\\').DIRECTORY_SEPARATOR.now()->format('Ymd_His');
        File::ensureDirectoryExists($dir);

        $db = config('database.connections.'.config('database.default'));
        $dump = $dir.DIRECTORY_SEPARATOR.$db['database'].'.sql';

        $process = new Process([
            config('lothub.backup.mysqldump'), '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'],
            '--single-transaction', '--routines', '--triggers', '--events', '--default-character-set=utf8mb4',
            '--result-file='.$dump, $db['database'],
        ], null, ['MYSQL_PWD' => (string) $db['password']], null, 1800);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($dump)) {
            $this->error('No se pudo volcar la base de datos: '.trim($process->getErrorOutput()));

            return self::FAILURE;
        }
        $this->gzip($dump);
        $this->info('Base de datos: '.basename($dump).'.gz');

        if (! $this->option('only-db')) {
            $zip = $dir.DIRECTORY_SEPARATOR.'archivos-privados.zip';
            $count = $this->zipDirectory(storage_path('app/private'), $zip);
            $this->info($count ? "Archivos privados: {$count} archivo(s) en ".basename($zip) : 'Archivos privados: no hay archivos que respaldar.');
        }

        $this->prune();

        return self::SUCCESS;
    }

    private function gzip(string $path): void
    {
        $in = fopen($path, 'rb');
        $out = gzopen($path.'.gz', 'wb6');
        while (! feof($in)) {
            gzwrite($out, fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
        unlink($path);
    }

    private function zipDirectory(string $source, string $target): int
    {
        if (! is_dir($source)) {
            return 0;
        }
        $files = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS)), false);
        if (! $files) {
            return 0;
        }

        $zip = new ZipArchive();
        $zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $file) {
            $zip->addFile($file->getPathname(), ltrim(str_replace($source, '', $file->getPathname()), '/\\'));
        }
        $zip->close();

        return count($files);
    }

    private function prune(): void
    {
        $limit = now()->subDays((int) config('lothub.backup.retention_days'))->format('Ymd_His');
        foreach (File::directories(config('lothub.backup.path')) as $folder) {
            if (preg_match('/^\d{8}_\d{6}$/', basename($folder)) && basename($folder) < $limit) {
                File::deleteDirectory($folder);
                $this->line('Eliminado respaldo antiguo: '.basename($folder));
            }
        }
    }
}
