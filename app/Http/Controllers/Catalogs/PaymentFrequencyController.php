<?php

namespace App\Http\Controllers\Catalogs;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\PaymentFrequency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class PaymentFrequencyController extends CrudController
{
    const MODEL = PaymentFrequency::class;
    const ROUTE = 'payment-frequencies';
    const PERMISSION = 'catalogos';

    protected array $searchable = ['code', 'name'];
    protected string $orderBy = 'months_interval';
    protected string $orderDir = 'asc';

    protected function meta(): array
    {
        return [
            'title'    => 'Periodicidades',
            'subtitle' => 'Intervalo en meses entre cuotas del plan de pagos.',
            'icon'     => 'ph-calendar-dots',
            'singular' => 'periodicidad',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Código', 'type' => 'strong'],
            ['key' => 'name', 'label' => 'Nombre'],
            ['key' => 'months_interval', 'label' => 'Meses', 'type' => 'number'],
        ];
    }

    protected function fields(?Model $record): array
    {
        return [
            ['name' => 'code', 'label' => 'Código', 'required' => true, 'width' => 'third', 'uppercase' => true],
            ['name' => 'name', 'label' => 'Nombre', 'required' => true, 'width' => 'third'],
            ['name' => 'months_interval', 'label' => 'Intervalo (meses)', 'type' => 'number', 'required' => true,
             'width' => 'third', 'min' => 1, 'max' => 12],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'code'            => ['required', 'string', 'max:30', Rule::unique('payment_frequencies')->ignore($record)],
            'name'            => ['required', 'string', 'max:60'],
            'months_interval' => ['required', 'integer', 'between:1,12'],
        ];
    }
}
