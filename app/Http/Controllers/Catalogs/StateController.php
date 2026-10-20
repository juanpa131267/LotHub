<?php

namespace App\Http\Controllers\Catalogs;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\State;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class StateController extends CrudController
{
    const MODEL = State::class;
    const ROUTE = 'states';
    const PERMISSION = 'catalogos';

    protected array $searchable = ['name', 'dane_code'];
    protected string $orderBy = 'name';
    protected string $orderDir = 'asc';

    protected function meta(): array
    {
        return [
            'title'    => 'Departamentos',
            'subtitle' => 'División territorial con su código DANE.',
            'icon'     => 'ph-map-trifold',
            'singular' => 'departamento',
        ];
    }

    protected function query(): Builder
    {
        return parent::query()->withCount('cities');
    }

    protected function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Departamento', 'type' => 'strong'],
            ['key' => 'dane_code', 'label' => 'Código DANE', 'type' => 'muted'],
            ['key' => 'cities_count', 'label' => 'Municipios', 'type' => 'number'],
        ];
    }

    protected function fields(?Model $record): array
    {
        return [
            ['name' => 'name', 'label' => 'Nombre', 'required' => true, 'width' => 'two-thirds'],
            ['name' => 'dane_code', 'label' => 'Código DANE', 'width' => 'third', 'placeholder' => '25', 'maxlength' => 2],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'name'      => ['required', 'string', 'max:100', Rule::unique('states')->ignore($record)],
            'dane_code' => ['nullable', 'digits:2', Rule::unique('states')->ignore($record)],
        ];
    }
}
