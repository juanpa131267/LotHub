{{-- Formulario de solicitud de información (RF-21). Variables opcionales: $projects (select), $projectId (fijo). --}}
<form method="POST" action="{{ route('public.requests.store') }}" class="card contact-card" novalidate>
    @csrf
    @if ($errors->any())
        <div class="alert alert-error"><i class="ph ph-warning-circle"></i><div>Revisa los datos del formulario.</div></div>
    @endif

    <div class="form-grid">
        @isset($projectId)
            <input type="hidden" name="project_id" value="{{ $projectId }}" id="contact-project">
        @else
            <div class="field w-full">
                <label for="project_id">Proyecto de interés</label>
                <select id="project_id" name="project_id" class="select">
                    <option value="">Información general</option>
                    @foreach ($projects ?? [] as $p)
                        <option value="{{ $p->id }}" @selected(old('project_id') == $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
        @endisset

        <div class="field w-full">
            <label for="full_name">Nombre completo <span class="req">*</span></label>
            <input id="full_name" name="full_name" class="input @error('full_name') is-invalid @enderror" value="{{ old('full_name') }}" autocomplete="name" required>
            @error('full_name') <div class="error">{{ $message }}</div> @enderror
        </div>
        <div class="field w-half">
            <label for="email">Correo <span class="req">*</span></label>
            <input id="email" type="email" name="email" class="input @error('email') is-invalid @enderror" value="{{ old('email') }}" autocomplete="email" required>
            @error('email') <div class="error">{{ $message }}</div> @enderror
        </div>
        <div class="field w-half">
            <label for="phone">Teléfono / WhatsApp</label>
            <input id="phone" name="phone" class="input" value="{{ old('phone') }}" autocomplete="tel">
        </div>
        <div class="field w-full">
            <label for="message">Mensaje</label>
            <textarea id="message" name="message" rows="3" class="textarea" placeholder="Cuéntanos qué lote te interesa o en qué te podemos ayudar">{{ old('message') }}</textarea>
        </div>
        <div class="field w-full">
            <label class="check">
                <input type="checkbox" name="consent_accepted" value="1" @checked(old('consent_accepted'))>
                <span>Autorizo el tratamiento de mis datos personales para ser contactado, conforme a la Ley 1581 de 2012.</span>
            </label>
            @error('consent_accepted') <div class="error">{{ $message }}</div> @enderror
        </div>
        <div class="w-full">
            <button type="submit" class="btn btn-primary btn-lg btn-block"><i class="ph ph-paper-plane-tilt"></i> Solicitar información</button>
        </div>
    </div>
</form>
