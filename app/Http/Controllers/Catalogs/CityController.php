<?php

namespace App\Http\Controllers\Catalogs;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\City;
use App\Models\Catalogs\State;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class CityController extends CrudController
{
    const MODEL = City::class;
    const ROUTE = 'cities';
    const PERMISSION = 'catalogos';

    protected array $with = ['state'];
    protected array $searchable = ['name', 'dane_code', 'state.name'];
    protected string $orderBy = 'name';
    protected string $orderDir = 'asc';

    protected function meta(): array
    {
        return [
            'title'    => 'Municipios',
            'subtitle' => 'Municipios por departamento (código DANE de 5 dígitos).',
            'icon'     => 'ph-map-pin-area',
            'singular' => 'municipio',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Municipio', 'type' => 'strong'],
            ['key' => 'state.name', 'label' => 'Departamento'],
            ['key' => 'dane_code', 'label' => 'Código DANE', 'type' => 'muted'],
        ];
    }

    protected function filters(): array
    {
        return [
            ['name' => 'state_id', 'label' => 'Departamento', 'options' => State::orderBy('name')->pluck('name', 'id')->all()],
        ];
    }

    protected function fields(?Model $record): array
    {
        return [
            ['name' => 'state_id', 'label' => 'Departamento', 'type' => 'select', 'required' => true, 'width' => 'half',
             'options' => State::orderBy('name')->pluck('name', 'id')->all()],
            ['name' => 'name', 'label' => 'Nombre', 'required' => true, 'width' => 'half'],
            ['name' => 'dane_code', 'label' => 'Código DANE', 'width' => 'third', 'placeholder' => '25307', 'maxlength' => 5],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'state_id'  => ['required', 'exists:states,id'],
            'name'      => ['required', 'string', 'max:100',
                Rule::unique('cities')->where('state_id', request('state_id'))->ignore($record)],
            'dane_code' => ['nullable', 'digits:5', Rule::unique('cities')->ignore($record)],
        ];
    }
}
