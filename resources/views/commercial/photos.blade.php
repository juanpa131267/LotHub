{{-- Fotos de la ficha de venta (ProjectPhotoController). La lógica de carga está en public/js/photos.js. --}}
@extends('layouts.panel')

@section('title', 'Fotos · '.$project->name)

@section('breadcrumb')
    <a href="{{ route('projects.index') }}">Proyectos</a>
    <i class="ph ph-caret-right"></i>
    <span class="current">Fotos · {{ $project->name }}</span>
@endsection

@section('content')
    @php
        $canEdit = auth()->user()->hasPermission('proyectos.editar');
    @endphp

    <div class="page-head">
        <div class="page-title">
            <span class="page-icon"><i class="ph ph-images"></i></span>
            <div>
                <h1>{{ $project->name }}</h1>
                <p>{{ $project->code }} · Fotos de la ficha de venta: se muestran en la página pública del proyecto. La portada encabeza la ficha y la tarjeta del proyecto.</p>
            </div>
        </div>
        <a href="{{ route('projects.index') }}" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Proyectos</a>
    </div>

    @include('commercial.partials.project-tabs', ['active' => 'photos'])

    @if ($canEdit)
        <div class="option-grid">
            <section class="card option-card">
                <div class="card-head"><span class="option-num">1</span><div><h3>Carga masiva</h3><p class="muted" style="font-size:.82rem">Varias fotos a la vez, sin anotación. Puedes agregarla después en cada foto.</p></div></div>
                <div class="card-body">
                    <label class="dropzone" id="bulk-drop">
                        <i class="ph ph-cloud-arrow-up"></i>
                        <strong>Arrastra las fotos aquí o haz clic para elegirlas</strong>
                        <small>JPG, PNG o WebP · hasta {{ $maxMb }} MB cada una</small>
                        <input type="file" id="bulk-input" accept=".jpg,.jpeg,.png,.webp" multiple hidden data-limit-bytes="{{ $maxMb * 1048576 }}">
                    </label>
                    <div class="upload-queue" id="bulk-queue"></div>
                </div>
            </section>

            <section class="card option-card">
                <div class="card-head"><span class="option-num">2</span><div><h3>Una por una, con anotación</h3><p class="muted" style="font-size:.82rem">Para describir cada foto: «Piscina», «Logo del conjunto», «Zona común»…</p></div></div>
                <form class="card-body" id="single-form">
                    <img class="single-preview" id="single-preview" alt="">
                    <div class="field">
                        <label for="single-input">Foto <span class="req">*</span></label>
                        <input type="file" id="single-input" class="input" style="padding:.55rem .7rem;height:auto" accept=".jpg,.jpeg,.png,.webp" required data-max-bytes="{{ $maxMb * 1048576 }}">
                    </div>
                    <div class="field">
                        <label for="single-caption">Anotación</label>
                        <input type="text" id="single-caption" class="input" maxlength="250" placeholder="Ej.: Piscina con vista al lago">
                    </div>
                    <button class="btn btn-primary" id="single-submit"><i class="ph ph-upload-simple"></i> Agregar foto</button>
                </form>
            </section>
        </div>
    @endif

    <section class="card">
        <div class="card-head">
            <h3>Galería <span class="muted" id="photo-count">({{ $project->photos->count() }})</span></h3>
            <span class="muted" style="font-size:.82rem">El orden es el que verá el comprador. <i class="ph ph-star"></i> marca la portada.</span>
        </div>
        <div class="card-body">
            <div class="photo-grid" id="photo-grid"></div>
            <div class="empty" id="photo-empty" @if ($project->photos->isNotEmpty()) hidden @endif>
                <i class="ph ph-images"></i>Aún no hay fotos. La ficha de venta luce mucho mejor con imágenes del proyecto.
            </div>
        </div>
    </section>

    @php
        $photosConfig = [
            'photos'  => $project->photos->values(),
            'canEdit' => $canEdit,
            'routes'  => [
                'store'   => route('projects.photos.store', $project->id),
                'reorder' => route('projects.photos.reorder', $project->id),
                'update'  => route('projects.photos.update', [$project->id, '__ID__']),
                'cover'   => route('projects.photos.cover', [$project->id, '__ID__']),
                'destroy' => route('projects.photos.destroy', [$project->id, '__ID__']),
            ],
        ];
    @endphp
    <script type="application/json" id="photos-config">{!! json_encode($photosConfig, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
@endsection

@push('scripts')
    <script src="{{ asset('js/photos.js') }}?v={{ filemtime(public_path('js/photos.js')) }}"></script>
@endpush
