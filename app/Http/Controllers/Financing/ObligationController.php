<?php

namespace App\Http\Controllers\Financing;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\ObligationStatus;
use App\Models\Commercial\Project;
use App\Models\Financing\Obligation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;

/** Obligaciones de cartera con su saldo (v_installment_balance) y su plan de cuotas. */
class ObligationController extends CrudController
{
    const MODEL = Obligation::class;
    const ROUTE = 'obligations';
    const PERMISSION = 'acuerdos';

    protected array $with = ['agreement.sale.lot.project', 'agreement.sale.clients.person', 'agreement.frequency', 'status'];
    protected array $searchable = ['obligation_code', 'agreement.sale.sale_code', 'agreement.sale.clients.person.first_name',
        'agreement.sale.clients.person.last_name', 'agreement.sale.clients.client_code'];
    protected array $actions = ['create' => false, 'edit' => false, 'delete' => false];
    protected string $orderBy = 'obligations.id';

    protected function meta(): array
    {
        return [
            'title'    => 'Obligaciones',
            'subtitle' => 'Cartera generada por los acuerdos de pago, con su saldo y la mora de cada una.',
            'icon'     => 'ph-receipt',
            'singular' => 'obligación',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'obligation_code', 'label' => 'Obligación', 'type' => 'code'],
            ['key' => 'agreement.sale.sale_code', 'label' => 'Negociación', 'type' => 'muted'],
            ['key' => 'lot', 'label' => 'Lote', 'type' => 'strong'],
            ['key' => 'holder', 'label' => 'Titular'],
            ['key' => 'version', 'label' => 'Versión'],
            ['key' => 'balance', 'label' => 'Saldo', 'type' => 'money'],
            ['key' => 'next_due_date', 'label' => 'Próximo vencimiento', 'type' => 'date'],
            ['key' => 'state', 'label' => 'Estado', 'type' => 'badge', 'colors' => [
                'Al día' => 'success', 'En mora' => 'danger', 'Cancelada (saldo cero)' => 'info', 'Reestructurada' => 'neutral', 'Anulada' => 'neutral',
            ]],
        ];
    }

    protected function row(Model $obligation): array
    {
        $sale = $obligation->agreement->sale;
        $summary = $obligation->summary();

        return $obligation->toArray() + [
            'lot'           => "{$sale->lot->project->name} · {$sale->lot->label}",
            'holder'        => $sale->primaryClient()?->person->full_name,
            'version'       => 'v'.$obligation->agreement->version,
            'balance'       => $summary->balance,
            'next_due_date' => $obligation->isActive() ? $summary->next_due_date : null,
            'state'         => $obligation->isActive() ? ($summary->overdue_installments ? 'En mora' : 'Al día') : $obligation->status->name,
        ];
    }

    protected function filters(): array
    {
        return [
            ['name' => 'status_id', 'label' => 'Estado', 'column' => 'obligations.status_id', 'options' => ObligationStatus::pluck('name', 'id')->all()],
            ['name' => 'project_id', 'label' => 'Proyecto', 'column' => 'lots.project_id', 'options' => Project::orderBy('name')->pluck('name', 'id')->all()],
        ];
    }

    protected function query(): Builder
    {
        return parent::query()->select('obligations.*')
            ->join('payment_agreements', 'payment_agreements.id', '=', 'obligations.agreement_id')
            ->join('sales', 'sales.id', '=', 'payment_agreements.sale_id')
            ->join('lots', 'lots.id', '=', 'sales.lot_id');
    }

    protected function rowActions(): array
    {
        return [['label' => 'Ver obligación', 'icon' => 'ph-eye', 'route' => 'obligations.show', 'permission' => 'acuerdos.ver']];
    }

    /** Detalle: plan de cuotas con saldo y estado calculado, versiones del acuerdo y acciones. */
    public function show(int $id): View
    {
        $obligation = Obligation::with([
            'agreement.sale.lot.project', 'agreement.sale.clients.person', 'agreement.sale.modality',
            'agreement.frequency', 'agreement.creator.person', 'status',
        ])->findOrFail($id);
        $sale = $obligation->agreement->sale;

        return view('financing.obligation-show', [
            'obligation' => $obligation,
            'sale'       => $sale,
            'summary'    => $obligation->summary(),
            'rows'       => $obligation->balances(),
            'versions'   => $sale->agreements()->with(['obligation.status', 'frequency', 'creator.person'])->get(),
        ]);
    }

    protected function recordLabel(Model $obligation): string
    {
        return $obligation->obligation_code;
    }
}
