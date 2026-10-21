<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Portal del comprador (CU-24). RN-21: el cliente solo ve las obligaciones de
 * las negociaciones en las que figura como comprador (sale_clients).
 */
class PortalController extends Controller
{
    public function index(): View
    {
        $client = auth()->user()->client();

        $obligations = $client
            ? DB::table('v_obligation_balance as ob')
                ->join('sale_clients as sc', 'sc.sale_id', '=', 'ob.sale_id')
                ->join('sales as s', 's.id', '=', 'ob.sale_id')
                ->join('lots as l', 'l.id', '=', 's.lot_id')
                ->join('projects as p', 'p.id', '=', 'l.project_id')
                ->where('sc.client_id', $client->id)
                ->select('ob.*', 'p.name as project_name', 'l.block', 'l.lot_number', 'sc.ownership_pct')
                ->get()
            : collect();

        // Plan de cuotas de cada obligación con su saldo y estado calculados.
        $installments = DB::table('v_installment_balance')
            ->whereIn('obligation_id', $obligations->pluck('obligation_id'))
            ->orderBy('installment_number')->get()->groupBy('obligation_id');

        return view('portal.index', compact('client', 'obligations', 'installments'));
    }
}
