<?php

namespace App\Services\Financing;

use App\Models\Financing\AccountStatement;
use App\Models\Financing\Obligation;
use App\Models\Payments\Payment;
use App\Services\AuditLogger;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CU-24 Generar y descargar el estado de cuenta en PDF. Cada PDF queda archivado en
 * storage/app/private/account-statements/ con su SHA-256 en account_statements; generated_by es nulo
 * cuando lo genera el propio cliente desde el portal.
 */
class AccountStatementService
{
    public const DIRECTORY = 'account-statements';

    public function generate(Obligation $obligation, ?int $generatedBy): AccountStatement
    {
        $obligation->loadMissing(['agreement.sale.lot.project.city', 'agreement.sale.clients.person.identificationType', 'agreement.frequency', 'status']);

        $html = view('pdf.account-statement', [
            'obligation' => $obligation,
            'sale'       => $obligation->agreement->sale,
            'summary'    => $obligation->summary(),
            'rows'       => $obligation->balances(),
            'payments'   => Payment::with('method')->where('obligation_id', $obligation->id)->where('status_id', 1)->orderBy('payment_date')->get(),
            'cutOff'     => now(),
            'company'    => config('lothub.company'),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('letter');
        $pdf->render();
        $content = $pdf->output();

        $path = self::DIRECTORY.'/'.now()->format('Y/m').'/'.$obligation->obligation_code.'_'.now()->format('Ymd_His').'_'.Str::random(6).'.pdf';
        Storage::disk('local')->put($path, $content);

        $statement = AccountStatement::create([
            'obligation_id' => $obligation->id,
            'cut_off_date'  => now()->toDateString(),
            'file_path'     => $path,
            'file_hash'     => hash('sha256', $content),
            'generated_by'  => $generatedBy,
            'generated_at'  => now(),
        ]);
        AuditLogger::log('GENERAR_ESTADO_CUENTA', 'account_statements', $statement->id, [], ['obligacion' => $obligation->obligation_code], $generatedBy);

        return $statement;
    }

    public function absolutePath(AccountStatement $statement): string
    {
        return Storage::disk('local')->path($statement->file_path);
    }

    public function downloadName(AccountStatement $statement): string
    {
        return 'estado_cuenta_'.$statement->obligation->obligation_code.'_'.$statement->cut_off_date->format('Ymd').'.pdf';
    }
}
