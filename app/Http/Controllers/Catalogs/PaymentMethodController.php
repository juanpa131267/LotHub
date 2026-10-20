<?php

namespace App\Http\Controllers\Catalogs;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class PaymentMethodController extends CrudController
{
    const MODEL = PaymentMethod::class;
    const ROUTE = 'payment-methods';
    const PERMISSION = 'catalogos';

    protected array $searchable = ['code', 'name'];
    protected string $orderDir = 'asc';

    protected function meta(): array
    {
        return [
            'title'    => 'Medios de pago',
            'subtitle' => 'Canales por los que se reciben los abonos.',
            'icon'     => 'ph-credit-card',
            'singular' => 'medio de pago',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Código', 'type' => 'strong'],
            ['key' => 'name', 'label' => 'Nombre'],
            ['key' => 'is_online', 'label' => 'En línea', 'type' => 'bool'],
        ];
    }

    protected function fields(?Model $record): array
    {
        return [
            ['name' => 'code', 'label' => 'Código', 'required' => true, 'width' => 'third', 'uppercase' => true],
            ['name' => 'name', 'label' => 'Nombre', 'required' => true, 'width' => 'two-thirds'],
            ['name' => 'is_online', 'label' => 'Canal', 'type' => 'checkbox', 'checkbox_label' => 'Es un pago en línea (pasarela)'],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'code'      => ['required', 'string', 'max:30', Rule::unique('payment_methods')->ignore($record)],
            'name'      => ['required', 'string', 'max:60'],
            'is_online' => ['boolean'],
        ];
    }
}
