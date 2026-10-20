<?php

namespace App\Http\Controllers\Catalogs;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\IdentificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class IdentificationTypeController extends CrudController
{
    const MODEL = IdentificationType::class;
    const ROUTE = 'identification-types';
    const PERMISSION = 'catalogos';

    protected array $searchable = ['code', 'name'];
    protected string $orderBy = 'id';
    protected string $orderDir = 'asc';

    protected function meta(): array
    {
        return [
            'title'    => 'Tipos de identificación',
            'subtitle' => 'Documentos de identidad admitidos para personas y clientes.',
            'icon'     => 'ph-identification-card',
            'singular' => 'tipo de identificación',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Código', 'type' => 'strong'],
            ['key' => 'name', 'label' => 'Nombre'],
        ];
    }

    protected function fields(?Model $record): array
    {
        return [
            ['name' => 'code', 'label' => 'Código', 'required' => true, 'width' => 'third', 'placeholder' => 'CC', 'uppercase' => true],
            ['name' => 'name', 'label' => 'Nombre', 'required' => true, 'width' => 'two-thirds'],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:10', Rule::unique('identification_types')->ignore($record)],
            'name' => ['required', 'string', 'max:60'],
        ];
    }
}
