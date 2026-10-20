<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Commercial\InformationRequest;
use App\Models\Commercial\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * CU-31 Atender solicitudes de información. Las solicitudes se crean desde el
 * sitio público (Public\InformationRequestController); aquí solo se gestionan.
 */
class InformationRequestController extends CrudController
{
    const MODEL = InformationRequest::class;
    const ROUTE = 'information-requests';
    const PERMISSION = 'solicitudes';

    protected array $with = ['project', 'attendedBy'];
    protected array $searchable = ['full_name', 'email', 'phone', 'message', 'project.name'];
    protected array $actions = ['create' => false, 'edit' => true, 'delete' => false];

    protected function meta(): array
    {
        return [
            'title'    => 'Solicitudes de información',
            'subtitle' => 'Contactos recibidos desde el sitio público.',
            'icon'     => 'ph-chat-circle-text',
            'singular' => 'solicitud',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'created_at', 'label' => 'Recibida', 'type' => 'muted'],
            ['key' => 'full_name', 'label' => 'Nombre', 'type' => 'strong'],
            ['key' => 'contact', 'label' => 'Contacto'],
            ['key' => 'project.name', 'label' => 'Proyecto', 'type' => 'muted'],
            ['key' => 'status_label', 'label' => 'Estado', 'type' => 'badge',
             'colors' => ['Nueva' => 'warning', 'Atendida' => 'success', 'Descartada' => 'neutral']],
        ];
    }

    protected function filters(): array
    {
        return [
            ['name' => 'status', 'label' => 'Estado', 'options' => InformationRequest::STATUSES],
            ['name' => 'project_id', 'label' => 'Proyecto', 'options' => Project::orderBy('name')->pluck('name', 'id')->all()],
        ];
    }

    protected function row(Model $req): array
    {
        return $req->toArray() + [
            'contact'      => trim($req->email.($req->phone ? ' · '.$req->phone : '')),
            'status_label' => InformationRequest::STATUSES[$req->status] ?? $req->status,
        ];
    }

    protected function recordLabel(Model $req): string
    {
        return $req->full_name;
    }

    protected function fields(?Model $req): array
    {
        return [
            ['type' => 'note', 'label' => 'Mensaje del visitante', 'text' => $req?->message ?: '(sin mensaje)'],
            ['type' => 'note', 'width' => 'half', 'label' => 'Contacto', 'text' => trim($req?->email.' '.$req?->phone)],
            ['type' => 'note', 'width' => 'half', 'label' => 'Proyecto de interés', 'text' => $req?->project?->name ?? 'General'],
            ['name' => 'status', 'label' => 'Estado', 'type' => 'select', 'required' => true, 'options' => InformationRequest::STATUSES],
        ];
    }

    protected function rules(?Model $req): array
    {
        return ['status' => ['required', Rule::in(array_keys(InformationRequest::STATUSES))]];
    }

    protected function save(array $data, ?Model $req): Model
    {
        $req->status = $data['status'];
        $req->attended_by = $data['status'] === 'NUEVA' ? null : auth()->id();
        $req->save();

        return $req;
    }
}
