<?php

namespace App\Http\Controllers\Catalogs;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\FinancingModality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class FinancingModalityController extends CrudController
{
    const MODEL = FinancingModality::class;
    const ROUTE = 'financing-modalities';
    const PERMISSION = 'catalogos';

    protected array $searchable = ['code', 'name'];
    protected string $orderDir = 'asc';

    protected function meta(): array
    {
        return [
            'title'    => 'Modalidades de pago',
            'subtitle' => 'Contado, financiación directa o mixta. Las que exigen acuerdo generan plan de cuotas (RN-10).',
            'icon'     => 'ph-hand-coins',
            'singular' => 'modalidad',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Código', 'type' => 'strong'],
            ['key' => 'name', 'label' => 'Nombre'],
            ['key' => 'requires_agreement', 'label' => 'Exige acuerdo', 'type' => 'bool'],
        ];
    }

    protected function fields(?Model $record): array
    {
        return [
            ['name' => 'code', 'label' => 'Código', 'required' => true, 'width' => 'third', 'uppercase' => true],
            ['name' => 'name', 'label' => 'Nombre', 'required' => true, 'width' => 'two-thirds'],
            ['name' => 'requires_agreement', 'label' => 'Acuerdo de pago', 'type' => 'checkbox',
             'checkbox_label' => 'Exige acuerdo de pago firmado', 'default' => true],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'code'               => ['required', 'string', 'max:30', Rule::unique('financing_modalities')->ignore($record)],
            'name'               => ['required', 'string', 'max:80'],
            'requires_agreement' => ['boolean'],
        ];
    }
}
