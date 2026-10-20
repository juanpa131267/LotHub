<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Crud\CrudController;
use App\Models\Catalogs\City;
use App\Models\Catalogs\IdentificationType;
use App\Models\Commercial\Client;
use App\Models\Commercial\DataConsent;
use App\Models\Security\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * CU-10 Gestionar clientes (RF-07). Crea/edita la persona y su ficha de cliente,
 * y registra la autorización de tratamiento de datos (Ley 1581, RN-22).
 */
class ClientController extends CrudController
{
    const MODEL = Client::class;
    const ROUTE = 'clients';
    const PERMISSION = 'clientes';

    protected array $with = ['person.identificationType', 'person.city', 'consents'];
    protected array $searchable = ['client_code', 'person.first_name', 'person.last_name', 'person.identification_number', 'person.email', 'person.phone'];

    protected function meta(): array
    {
        return [
            'title'    => 'Clientes',
            'subtitle' => 'Compradores registrados y su autorización de tratamiento de datos.',
            'icon'     => 'ph-address-book',
            'singular' => 'cliente',
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'client_code', 'label' => 'Código', 'type' => 'code'],
            ['key' => 'person.full_name', 'label' => 'Nombre', 'type' => 'strong'],
            ['key' => 'document', 'label' => 'Identificación'],
            ['key' => 'person.phone', 'label' => 'Teléfono', 'type' => 'muted'],
            ['key' => 'person.city.name', 'label' => 'Municipio', 'type' => 'muted'],
            ['key' => 'consent', 'label' => 'Datos', 'type' => 'badge', 'colors' => ['Autorizado' => 'success', 'Sin autorización' => 'warning']],
        ];
    }

    protected function row(Model $client): array
    {
        $p = $client->person;

        return $client->toArray() + [
            'document' => trim(($p->identificationType?->code ?? '').' '.$p->identification_number),
            'consent'  => $client->consents->whereNull('revoked_at')->isNotEmpty() ? 'Autorizado' : 'Sin autorización',
        ];
    }

    protected function recordLabel(Model $client): string
    {
        return "{$client->client_code} · {$client->person->full_name}";
    }

    protected function fields(?Model $client): array
    {
        $p = $client?->person;
        $hasConsent = $client?->consents->whereNull('revoked_at')->isNotEmpty() ?? false;

        return [
            ['type' => 'section', 'label' => 'Identificación'],
            ['name' => 'identification_type_id', 'label' => 'Tipo', 'type' => 'select', 'required' => true, 'width' => 'third',
             'options' => IdentificationType::pluck('name', 'id')->all(), 'value' => $p?->identification_type_id],
            ['name' => 'identification_number', 'label' => 'Número', 'required' => true, 'width' => 'third', 'value' => $p?->identification_number],
            ['name' => 'occupation', 'label' => 'Ocupación', 'width' => 'third'],
            ['name' => 'first_name', 'label' => 'Nombres', 'required' => true, 'width' => 'half', 'value' => $p?->first_name],
            ['name' => 'last_name', 'label' => 'Apellidos', 'required' => true, 'width' => 'half', 'value' => $p?->last_name],

            ['type' => 'section', 'label' => 'Contacto'],
            ['name' => 'email', 'label' => 'Correo electrónico', 'type' => 'email', 'width' => 'half', 'value' => $p?->email],
            ['name' => 'phone', 'label' => 'Teléfono', 'width' => 'half', 'value' => $p?->phone],
            ['name' => 'city_id', 'label' => 'Municipio de residencia', 'type' => 'select', 'width' => 'half',
             'options' => City::options(), 'value' => $p?->city_id],
            ['name' => 'address', 'label' => 'Dirección', 'width' => 'half', 'value' => $p?->address],

            ['type' => 'section', 'label' => 'Protección de datos'],
            $hasConsent
                ? ['type' => 'note', 'label' => 'Autorización vigente', 'text' => 'El titular autorizó el tratamiento de sus datos personales.']
                : ['name' => 'consent', 'label' => 'Autorización', 'type' => 'checkbox', 'value' => false,
                   'checkbox_label' => 'El titular autorizó presencialmente el tratamiento de sus datos (política v'.config('lothub.privacy_policy_version').')'],
        ];
    }

    protected function rules(?Model $client): array
    {
        $personId = $client?->person_id ?? $this->existingPerson()?->id;

        return [
            'identification_type_id' => ['required', 'exists:identification_types,id'],
            'identification_number'  => ['required', 'string', 'max:25'],
            'first_name'             => ['required', 'string', 'max:100'],
            'last_name'              => ['required', 'string', 'max:100'],
            'occupation'             => ['nullable', 'string', 'max:100'],
            'email'                  => ['nullable', 'email', 'max:191', Rule::unique('people', 'email')->ignore($personId)],
            'phone'                  => ['nullable', 'string', 'max:20'],
            'city_id'                => ['nullable', 'exists:cities,id'],
            'address'                => ['nullable', 'string', 'max:255'],
            'consent'                => ['nullable', 'boolean'],
        ];
    }

    protected function save(array $data, ?Model $client): Model
    {
        $person = $client?->person ?? $this->existingPerson() ?? new Person;

        if (! $client && $person->exists && $person->client()->withTrashed()->exists()) {
            abort(back()->withInput()->with('error', 'Ya existe un cliente con esa identificación.'));
        }

        $person->fill(collect($data)->only([
            'identification_type_id', 'identification_number', 'first_name', 'last_name', 'email', 'phone', 'city_id', 'address',
        ])->all())->save();

        $client ??= new Client([
            'person_id'     => $person->id,
            'client_code'   => Client::nextCode(),
            'registered_by' => auth()->id(),
        ]);
        $client->fill(['occupation' => $data['occupation'] ?? null])->save();

        if (! empty($data['consent'])) {
            DataConsent::firstOrCreate(
                ['person_id' => $person->id, 'policy_version' => config('lothub.privacy_policy_version')],
                ['channel' => 'PRESENCIAL', 'granted_at' => now(), 'ip_address' => request()->ip()],
            );
        }

        return $client;
    }

    protected function deleteBlockedReason(Model $client): ?string
    {
        return $client->hasCommercialHistory() ? 'No se puede eliminar: el cliente tiene reservas o negociaciones.' : null;
    }

    private function existingPerson(): ?Person
    {
        return Person::where('identification_type_id', request('identification_type_id'))
            ->where('identification_number', request('identification_number'))
            ->first();
    }
}
