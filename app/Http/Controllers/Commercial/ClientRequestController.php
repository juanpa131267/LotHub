<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Commercial\ClientRequest;
use App\Services\Communications\ClientRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** CU-29 Atender consultas y reclamos con su plazo legal en días hábiles (RN-24). */
class ClientRequestController extends CrudController
{
    const MODEL = ClientRequest::class;
    const ROUTE = 'client-requests';
    const PERMISSION = 'reclamos';

    protected array $with = ['client.person'];
    protected array $searchable = ['subject', 'client.client_code', 'client.person.first_name', 'client.person.last_name'];
    protected array $actions = ['create' => false, 'edit' => false, 'delete' => false];
    protected string $orderBy = 'due_date';
    protected string $orderDir = 'asc';

    public function __construct(private ClientRequestService $requests) {}

    protected function meta(): array
    {
        return [
            'title'    => 'Consultas y reclamos',
            'subtitle' => 'Solicitudes de los titulares. Consultas: 10 días hábiles; reclamos: 15 días hábiles (Ley 1581, RN-24).',
            'icon'     => 'ph-chat-centered-text',
            'singular' => 'solicitud',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'filed_at', 'label' => 'Radicada', 'type' => 'date'],
            ['key' => 'client.person.full_name', 'label' => 'Titular', 'type' => 'strong'],
            ['key' => 'type_label', 'label' => 'Tipo'],
            ['key' => 'subject', 'label' => 'Asunto'],
            ['key' => 'due_date', 'label' => 'Vence', 'type' => 'date'],
            ['key' => 'term', 'label' => 'Plazo', 'type' => 'badge', 'colors' => ['Vencida' => 'danger', 'Por vencer' => 'warning', 'En plazo' => 'success', 'Atendida' => 'neutral']],
            ['key' => 'status_label', 'label' => 'Estado', 'type' => 'badge', 'colors' => ['Radicada' => 'warning', 'En trámite' => 'info', 'Respondida' => 'success', 'Cerrada' => 'neutral']],
        ];
    }

    protected function row(Model $r): array
    {
        $term = match (true) {
            ! $r->isOpen()                              => 'Atendida',
            $r->isOverdue()                             => 'Vencida',
            $r->due_date->lte(now()->addDays(2))        => 'Por vencer',
            default                                     => 'En plazo',
        };

        return $r->toArray() + ['type_label' => $r->type_label, 'status_label' => $r->status_label, 'term' => $term];
    }

    protected function filters(): array
    {
        return [
            ['name' => 'status', 'label' => 'Estado', 'options' => ClientRequest::STATUSES],
            ['name' => 'request_type', 'label' => 'Tipo', 'options' => ClientRequest::TYPES],
        ];
    }

    protected function rowActions(): array
    {
        return [['label' => 'Atender', 'icon' => 'ph-chat-centered-dots', 'route' => 'client-requests.show', 'permission' => 'reclamos.ver']];
    }

    public function show(int $id): View
    {
        return view('commercial.client-request-show', ['request' => ClientRequest::with(['client.person', 'answeredBy.person'])->findOrFail($id)]);
    }

    public function process(int $id): RedirectResponse
    {
        return $this->attempt(fn () => $this->requests->startProcessing(ClientRequest::findOrFail($id)), 'Solicitud en trámite.');
    }

    public function answer(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['response' => ['required', 'string', 'min:20', 'max:5000']], [], ['response' => 'respuesta']);

        return $this->attempt(fn () => $this->requests->answer(ClientRequest::with('client.person')->findOrFail($id), $data['response']), 'Respuesta registrada y enviada al titular.');
    }

    public function close(int $id): RedirectResponse
    {
        return $this->attempt(fn () => $this->requests->close(ClientRequest::findOrFail($id)), 'Solicitud cerrada.');
    }

    private function attempt(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }

    protected function recordLabel(Model $r): string
    {
        return "Solicitud #{$r->id}";
    }
}
