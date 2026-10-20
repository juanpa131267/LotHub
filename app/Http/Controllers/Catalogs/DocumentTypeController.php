<?php

namespace App\Http\Controllers\Catalogs;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Ejemplo mínimo de módulo CRUD: modelo + columnas + campos + reglas.
 * Todo lo demás (listado, búsqueda, paginación, formularios) lo hereda.
 */
class DocumentTypeController extends CrudController
{
    const MODEL = DocumentType::class;
    const ROUTE = 'document-types';
    const PERMISSION = 'catalogos';

    protected array $searchable = ['code', 'name'];
    protected string $orderBy = 'name';
    protected string $orderDir = 'asc';

    protected function meta(): array
    {
        return [
            'title'    => 'Tipos de documento',
            'subtitle' => 'Documentos soporte que se cargan a clientes, negociaciones y abonos.',
            'icon'     => 'ph-files',
            'singular' => 'tipo de documento',
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
            ['name' => 'code', 'label' => 'Código', 'required' => true, 'width' => 'third', 'placeholder' => 'PROMESA', 'uppercase' => true],
            ['name' => 'name', 'label' => 'Nombre', 'required' => true, 'width' => 'two-thirds'],
        ];
    }

    protected function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('document_types')->ignore($record)],
            'name' => ['required', 'string', 'max:100'],
        ];
    }
}
