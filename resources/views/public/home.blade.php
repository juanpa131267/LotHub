@extends('layouts.public')

@section('title', 'Lotes con financiación directa')

@section('content')
    @php($money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.'))

    {{-- HERO --}}
    <section class="hero">
        <div class="container">
            <div>
                <span class="eyebrow"><i class="ph-fill ph-map-pin"></i> {{ config('lothub.company.city') }} y el Alto Magdalena</span>
                <h1>El lote para tu casa de descanso, <em>con cuotas a tu medida.</em></h1>
                <p class="lead">Elige tu lote, consulta la disponibilidad en tiempo real y págalo con financiación directa, sin bancos ni papeleo innecesario.</p>
                <div class="hero-cta">
                    <a href="#proyectos" class="btn btn-accent btn-lg"><i class="ph ph-grid-four"></i> Ver proyectos</a>
                    <a href="#contacto" class="btn btn-ghost btn-lg"><i class="ph ph-chat-circle-dots"></i> Hablar con un asesor</a>
                </div>
                <div class="hero-stats">
                    <div><strong>{{ $totals['projects'] }}</strong><span>proyectos en venta</span></div>
                    <div><strong>{{ $totals['available'] }}</strong><span>lotes disponibles</span></div>
                    <div><strong>{{ $totals['cities'] }}</strong><span>municipios</span></div>
                </div>
            </div>

            <div class="hero-visual" aria-hidden="true">
                <div class="plot-card">
                    <div class="plot-grid">
                        @foreach (str_split('aasaraaaraasaaaaaraasara') as $i => $c)
                            @if ($i === 12)<span class="road"></span>@endif
                            <span class="{{ $c === 'a' ? '' : $c }}"></span>
                        @endforeach
                    </div>
                </div>
                <div class="float-chip one"><i class="ph ph-check-circle"></i><div>Mz B · Lote 7<small>Disponible · 172 m²</small></div></div>
                <div class="float-chip two"><i class="ph ph-receipt"></i><div>Estado de cuenta<small>Consúltalo en línea</small></div></div>
            </div>
        </div>
    </section>

    {{-- PROYECTOS --}}
    <section class="section" id="proyectos">
        <div class="container">
            <div class="section-head">
                <div>
                    <div class="kicker">Proyectos en venta</div>
                    <h2>Encuentra tu próximo lote</h2>
                    <p>Cada proyecto muestra su disponibilidad actualizada: lo que ves es lo que hay.</p>
                </div>
            </div>

            @if ($projects->isEmpty())
                <div class="card empty"><i class="ph ph-buildings"></i>Pronto publicaremos nuevos proyectos. Déjanos tus datos y te avisamos.</div>
            @else
                <div class="project-grid">
                    @foreach ($projects as $project)
                        <a href="{{ route('public.project', $project->id) }}" class="project-card">
                            <div class="project-cover" aria-hidden="true">
                                @if ($cover = $project->coverPhoto())
                                    <img class="cover-photo" src="{{ $cover->thumb_url }}" alt="" loading="lazy">
                                @else
                                    <div class="mini-plots">
                                        @for ($i = 0; $i < 32; $i++)
                                            <span class="{{ ($i * 7 + $project->id) % 9 === 0 ? 'r' : (($i * 5 + $project->id) % 11 === 0 ? 's' : '') }}"></span>
                                        @endfor
                                    </div>
                                @endif
                                <span class="tag">{{ $project->available_lots }} lotes disponibles</span>
                                @if ($project->has_plan)
                                    <span class="tag tag-plan"><i class="ph ph-map-trifold"></i> Plano interactivo</span>
                                @endif
                            </div>
                            <div class="project-body">
                                <h3>{{ $project->name }}</h3>
                                <div class="loc"><i class="ph ph-map-pin"></i> {{ $project->city->name }}, {{ $project->city->state->name }}</div>
                                @if ($project->description)<p class="desc">{{ $project->description }}</p>@endif
                                <div class="project-meta">
                                    <div>
                                        <small>Desde</small>
                                        <strong>{{ $project->min_price ? $money($project->min_price) : 'Consultar' }}</strong>
                                    </div>
                                    <strong class="avail">Ver lotes <i class="ph ph-arrow-right"></i></strong>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- CÓMO FUNCIONA --}}
    <section class="section section-alt" id="como-funciona">
        <div class="container">
            <div class="section-head">
                <div>
                    <div class="kicker">Cómo funciona</div>
                    <h2>Comprar tu lote es así de simple</h2>
                </div>
            </div>
            <div class="features">
                <div class="feature"><i class="ph ph-magnifying-glass"></i><h3>1. Elige</h3><p>Explora los proyectos y revisa área, precio y ubicación de cada lote disponible.</p></div>
                <div class="feature"><i class="ph ph-bookmark-simple"></i><h3>2. Separa</h3><p>Un asesor reserva tu lote mientras definimos juntos la forma de pago.</p></div>
                <div class="feature"><i class="ph ph-calendar-check"></i><h3>3. Financia</h3><p>Cuota inicial y plan de cuotas mensuales directamente con nosotros.</p></div>
                <div class="feature"><i class="ph ph-device-mobile"></i><h3>4. Consulta</h3><p>Revisa tu saldo, abonos y próximos vencimientos desde tu cuenta en línea.</p></div>
            </div>
        </div>
    </section>

    {{-- CONTACTO --}}
    <section class="section" id="contacto">
        <div class="container contact-grid">
            <div>
                <div class="section-head" style="margin-bottom:0">
                    <div>
                        <div class="kicker">Contacto</div>
                        <h2>¿Te interesa un lote?</h2>
                        <p>Déjanos tus datos y un asesor comercial te contactará para resolver tus dudas y agendar una visita.</p>
                    </div>
                </div>
                <div class="contact-info">
                    <div><i class="ph ph-phone"></i><span><small>Teléfono / WhatsApp</small>{{ config('lothub.company.phone') }}</span></div>
                    <div><i class="ph ph-envelope-simple"></i><span><small>Correo</small>{{ config('lothub.company.email') }}</span></div>
                    <div><i class="ph ph-map-pin"></i><span><small>Oficina</small>{{ config('lothub.company.city') }}</span></div>
                </div>
            </div>
            @include('public.partials.contact-form', ['projects' => $projects])
        </div>
    </section>
@endsection
