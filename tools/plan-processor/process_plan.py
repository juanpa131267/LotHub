"""
LotHub · Procesador de planos de loteo (DXF → JSON para el plano interactivo).

Uso:
    python process_plan.py --dxf plano.dxf --out plano.json [--progress progreso.json]

Cada oficina dibuja distinto, así que se prueban dos estrategias y se usa la que
reconstruya más lotes:

 A. «Manzana-lote» (planos de urbanismo tradicionales): los lotes son líneas
    divisorias sueltas y cada uno tiene un rótulo "A-1", "B-12"…
      1. Cierra huecos de dibujo (prolonga extremos sueltos ≤ 1 m y alarga
         cada segmento 2 cm para que las intersecciones se nodeen).
      2. Poligoniza las líneas y asigna a cada rótulo la cara más pequeña
         que lo contiene.

 B. «Parcelas numeradas» (Civil 3D y similares): cada lote ya es una polilínea
    cerrada en una capa de lotes y su número es un texto en una capa de
    rótulos (C-PROP-TEXT…). La etapa sale del nombre de la capa
    (C-PROP-TEXT-ETAPA-II → Etapa II) porque la numeración se reinicia.

En ambas: se valida contra el área escrita ("A: 95.51 m2"), se descartan copias
y detalles alejados del loteo, se extrae el contexto público (vías, lagos,
parques, nomenclatura) y la orientación (rosa de los vientos o coordenadas
georreferenciadas).

Coordenadas de salida en metros, con origen en la esquina inferior izquierda
del proyecto (eje Y hacia arriba, como en CAD). Requiere: ezdxf, shapely.
"""

import argparse
import json
import math
import os
import re
import statistics
import sys
import tempfile
import time
from collections import Counter, defaultdict

# ezdxf busca su configuración en la carpeta del usuario. Cuando lo ejecuta un
# servidor web (php artisan serve, Apache, un servicio) esa variable no existe.
for _var in ('HOME', 'USERPROFILE'):
    os.environ.setdefault(_var, tempfile.gettempdir())

from ezdxf import path as ezpath
from ezdxf import recover
from ezdxf.addons import iterdxf
from shapely.geometry import LineString, Point, Polygon, box
from shapely.ops import polygonize, unary_union
from shapely.strtree import STRtree

# ── Convenciones de dibujo ─────────────────────────────────────────────────
# Capas que nunca son linderos ni contexto público (topografía, redes, cotas…).
INFRA_LAYERS = re.compile(
    r'(^CVL_|TOPO|COTA|DIM|PIPE|NODE|HTPDU|ALCANT|PLUVIAL|DOMICIL|SUMIDERO|ACUEDUCT|COORD|BRNG|'
    r'ACHU|HACH|HATCH|STAN|PROF|GRID|DEFPOINTS|MUROS|PUERTAS|PARAMENTO|ELEV|IMPRESION_MARCO)', re.I)
TEXT_LAYERS = re.compile(r'TEXT|TXT|ROT', re.I)
LOT_LABEL = re.compile(r'^([A-Z]{1,2})\s*-\s*(\d+[A-Z]?)$')                 # estrategia A: "A-12"
PARCEL_NUMBER = re.compile(r'^(\d{1,5}[A-Z]?)$')                            # estrategia B: "664"
PARCEL_POLY_LAYERS = re.compile(r'LOTE|PARCEL|PREDIO|C-PROP', re.I)
PARCEL_TEXT_LAYERS = re.compile(r'TEXT|NUM|LOTE|PROP|NOMENC|ROT', re.I)
STAGE_IN_LAYER = re.compile(r'ETAPA[\s_-]*([IVX]+|\d+)\b|\b([IVX]+|\d+)[\s_-]*ETAPA', re.I)
AREA_LABEL = re.compile(r'^(A|AREA|ÁREA)\s*[:=]', re.I)
DECLARED_LOTS = re.compile(r'LOTES\s*=\s*(\d+)', re.I)
BROKEN_UNICODE = re.compile(rb'\\U\+(?![0-9A-Fa-f]{4})[0-9A-Fa-f]{0,3}')

# Contexto visible para el público. Los nombres de colindantes se omiten (Ley 1581).
PUBLIC_CONTEXT = re.compile(
    r'\b(V[IÍ]A|CANAL|R[IÍ]O|QUEBRADA|LAGO|LAGUNA|CALLE|CARRERA|AVENIDA|PARQUE|CANCHA|ZONA|LOCAL|PORTER[IÍ]A|'
    r'ADMINISTRACI[OÓ]N|PISCINA|SAL[OÓ]N|ACCESO|PEATONAL|SENDERO|PARQUEADEROS?|CLUB|CAPILLA|TANQUE)\b'
    r'|^(CRA|CLL|CL|KR|AV|DG|TV|TR)\.?\s*\d', re.I)
CONTEXT_CLASSES = [  # (clase, patrón de capa, ¿polígono relleno?)
    ('water',     re.compile(r'LAGO|LAGUNA|ESPEJO.?AGUA', re.I), True),
    ('parking',   re.compile(r'PARQUE?ADERO|PARQUEO', re.I), False),   # antes que "parque"
    ('green',     re.compile(r'PARQUE(?!ADERO|O)|ZONA.?VERDE|RONDA|SENDERO|RESERVA|CESION|AREA.?A.?RETENER', re.I), True),
    ('boundary',  re.compile(r'LINDERO|LIMITE', re.I), False),
    ('sidewalks', re.compile(r'ANDEN|SARDINEL', re.I), False),
    ('streams',   re.compile(r'QUEBRADA|R[IÍ]O\b|CANAL|CAÑO', re.I), False),
    ('roads',     re.compile(r'(^|[-_ .])VIA|ROAD|CAMINO|CALZADA|VIAL|MANZANEO', re.I), False),
]

GAP_TOLERANCE = 1.0      # metros máximos para cerrar un hueco de dibujo
NODE_EPSILON = 0.02      # prolongación de cada segmento para garantizar el nodo
FLATTEN = 0.05           # precisión al convertir arcos en segmentos (m)
AREA_WARNING = 0.03      # diferencia relativa de área que se reporta
KEEP_GROUP_RATIO = 0.15  # grupos de lotes menores a este % del principal se tratan como copias
MAX_LABELS = 160


class Progress:
    """Escribe el avance en un archivo JSON que Laravel lee para mostrarlo al usuario."""

    def __init__(self, path):
        self.path = path
        self.start = time.time()

    def __call__(self, step, pct):
        if not self.path:
            return
        tmp = self.path + '.tmp'
        with open(tmp, 'w', encoding='utf8') as fh:
            json.dump({'step': step, 'pct': pct, 'elapsed': round(time.time() - self.start, 1)}, fh, ensure_ascii=False)
        os.replace(tmp, self.path)


# ── Lectura ────────────────────────────────────────────────────────────────
class Drawing:
    """Lo único que el algoritmo necesita del DXF, en geometrías de shapely."""

    def __init__(self):
        self.lines = []    # (capa, LineString, cerrada)
        self.texts = []    # (texto, Point, capa)
        self.inserts = []  # (nombre de bloque, Point, capa)


def sanitized_copy(path):
    clean = path + '.clean.dxf'
    with open(path, 'rb') as src, open(clean, 'wb') as dst:
        for line in src:
            dst.write(BROKEN_UNICODE.sub(b'', line))
    return clean


def iter_entities(path):
    """Recorre el espacio modelo. iterdxf lee por flujo (≈5× más rápido y con poca memoria);
    si el archivo está mal formado se usa el lector tolerante de ezdxf."""
    try:
        doc = iterdxf.opendxf(path)
        try:
            yield from doc.modelspace()
        finally:
            doc.close()
    except Exception:
        drawing, _auditor = recover.readfile(path)
        yield from drawing.modelspace()


def load(path):
    try:
        return _load(path)
    except (ValueError, UnicodeDecodeError):
        # LibreDWG con DWG 2018+ escribe escapes Unicode truncados (\U+02 en vez de \U+00F1).
        clean = sanitized_copy(path)
        try:
            return _load(clean)
        finally:
            os.remove(clean)


def _load(path):
    d = Drawing()
    for e in iter_entities(path):
        kind, layer = e.dxftype(), e.dxf.layer
        try:
            if kind in ('TEXT', 'MTEXT'):
                text = (e.dxf.text if kind == 'TEXT' else e.plain_text()).strip()
                if text:
                    p = e.dxf.insert
                    d.texts.append((text, Point(p.x, p.y), layer))
            elif kind == 'INSERT':
                p = e.dxf.insert
                d.inserts.append((e.dxf.name, Point(p.x, p.y), layer))
            elif kind == 'LINE':
                s, t = e.dxf.start, e.dxf.end
                if (s.x, s.y) != (t.x, t.y):
                    d.lines.append((layer, LineString([(s.x, s.y), (t.x, t.y)]), False))
            elif kind in ('ARC', 'LWPOLYLINE', 'POLYLINE', 'CIRCLE'):
                pts = [(v.x, v.y) for v in ezpath.make_path(e).flattening(FLATTEN)]
                if len(pts) > 1:
                    gap = math.dist(pts[0], pts[-1])
                    closed = kind == 'CIRCLE' or bool(getattr(e, 'closed', False) or getattr(e, 'is_closed', False)) or (len(pts) > 3 and gap < 0.05)
                    d.lines.append((layer, LineString(pts), closed))
        except Exception:
            continue  # entidad corrupta: se ignora sin detener el proceso
    return d


# ── Utilidades ─────────────────────────────────────────────────────────────
def parse_number(text):
    m = re.search(r'\d+(?:[.,\s]+\d+)?', text)
    return float(re.sub(r'[.,\s]+', '.', m.group())) if m else None


def roman_to_int(token):
    if token.isdigit():
        return int(token)
    values, total = {'I': 1, 'V': 5, 'X': 10}, 0
    token = token.upper()
    for i, ch in enumerate(token):
        v = values[ch]
        total += -v if i + 1 < len(token) and values[token[i + 1]] > v else v
    return total


def to_roman(n):
    return {1: 'I', 2: 'II', 3: 'III', 4: 'IV', 5: 'V', 6: 'VI', 7: 'VII', 8: 'VIII', 9: 'IX', 10: 'X'}.get(n, str(n))


def clusters(points, cell):
    """Agrupa puntos por celdas vecinas de `cell` metros. Devuelve listas de índices, de mayor a menor."""
    cells = defaultdict(list)
    for i, p in enumerate(points):
        cells[(int(p.x // cell), int(p.y // cell))].append(i)
    seen, groups = set(), []
    for start in cells:
        if start in seen:
            continue
        stack, group = [start], []
        seen.add(start)
        while stack:
            cx, cy = stack.pop()
            group += cells[(cx, cy)]
            for dx in (-1, 0, 1):
                for dy in (-1, 0, 1):
                    n = (cx + dx, cy + dy)
                    if n in cells and n not in seen:
                        seen.add(n)
                        stack.append(n)
        groups.append(group)
    return sorted(groups, key=len, reverse=True)


def keep_main_groups(points, cell):
    """Índices de los grupos relevantes (el principal y los que tengan ≥ 15 % de su tamaño).
    Un proyecto por etapas puede tener sectores separados (p. ej. por un lago)."""
    groups = clusters(points, cell)
    if not groups:
        return [], 0
    keep = [g for g in groups if len(g) >= KEEP_GROUP_RATIO * len(groups[0])]
    return [i for g in keep for i in g], len(groups) - len(keep)


def split_segments(lines):
    segs = []
    for line in lines:
        c = list(line.coords)
        segs += [LineString([c[i], c[i + 1]]) for i in range(len(c) - 1) if c[i] != c[i + 1]]
    return segs


def close_gaps(lines, tolerance):
    """Prolonga extremos sueltos hasta la línea más cercana en su dirección."""
    segs = split_segments(lines)
    tree = STRtree(segs)
    extensions = []
    for i, s in enumerate(segs):
        (x0, y0), (x1, y1) = s.coords
        length = math.hypot(x1 - x0, y1 - y0)
        ux, uy = (x1 - x0) / length, (y1 - y0) / length
        for (px, py), (dx, dy) in (((x0, y0), (-ux, -uy)), ((x1, y1), (ux, uy))):
            end = Point(px, py)
            if any(j != i for j in tree.query(end.buffer(1e-3), predicate='intersects')):
                continue
            ray = LineString([(px, py), (px + dx * tolerance, py + dy * tolerance)])
            best = None
            for j in tree.query(ray, predicate='intersects'):
                if j == i:
                    continue
                hit = ray.intersection(segs[j])
                candidates = [hit] if hit.geom_type == 'Point' else [Point(c) for g in getattr(hit, 'geoms', [hit]) for c in g.coords]
                for q in candidates:
                    dist = end.distance(q)
                    if dist > 1e-6 and (best is None or dist < best[0]):
                        best = (dist, q)
            if best:
                extensions.append(LineString([(px, py), (best[1].x, best[1].y)]))

    out = []
    for s in segs + extensions:
        (x0, y0), (x1, y1) = s.coords
        length = math.hypot(x1 - x0, y1 - y0)
        if length < 1e-6:
            continue
        ex, ey = (x1 - x0) / length * NODE_EPSILON, (y1 - y0) / length * NODE_EPSILON
        out.append(LineString([(x0 - ex, y0 - ey), (x1 + ex, y1 + ey)]))
    return out, len(extensions)


def smallest_containing(tree, polys, point):
    hits = tree.query(point, predicate='within')
    return min(hits, key=lambda i: polys[i].area) if len(hits) else None


def written_areas(drawing):
    labels = [(parse_number(t), p) for t, p, _ in drawing.texts if AREA_LABEL.match(t)]
    labels = [(a, p) for a, p in labels if a]
    return labels, (STRtree([p for _, p in labels]) if labels else None)


def area_written_in(poly, area_labels, area_tree):
    if area_tree is None:
        return None
    for i in area_tree.query(poly, predicate='contains'):
        return area_labels[i][0]
    return None


# ── Estrategia A: manzana-lote ("A-1") ─────────────────────────────────────
def strategy_blocks(drawing, progress):
    labels = [(t, p) for t, p, _ in drawing.texts if LOT_LABEL.match(t)]
    if len(labels) < 5:
        return None
    idx, dropped = keep_main_groups([p for _, p in labels], 40.0)
    labels = [labels[i] for i in idx]
    xs, ys = [p.x for _, p in labels], [p.y for _, p in labels]
    area = box(min(xs) - 40, min(ys) - 40, max(xs) + 40, max(ys) + 40)

    lot_layers = {layer for layer, _, _ in drawing.lines if not INFRA_LAYERS.search(layer) and not TEXT_LAYERS.search(layer)}
    progress('Cerrando huecos del dibujo', 45)
    lines, gap_fixes = close_gaps([g for layer, g, _ in drawing.lines if layer in lot_layers and g.intersects(area)], GAP_TOLERANCE)
    progress('Reconstruyendo lotes', 60)
    faces = [f for f in polygonize(unary_union(lines)) if f.area > 10]
    tree = STRtree(faces)

    found = []
    for text, point in labels:
        block, number = LOT_LABEL.match(text).groups()
        found.append({'code': f'{block}-{number}', 'block': block, 'number': number, 'group_label': f'Manzana {block}',
                      'face': smallest_containing(tree, faces, point)})
    return {'scheme': 'blocks', 'found': found, 'faces': faces, 'dropped_groups': dropped,
            'layers_used': sorted(lot_layers), 'gap_fixes': gap_fixes}


# ── Estrategia B: parcelas numeradas por etapa ─────────────────────────────
def strategy_parcels(drawing, progress):
    polys, seen = [], set()
    for layer, g, closed in drawing.lines:
        if not closed or not PARCEL_POLY_LAYERS.search(layer) or INFRA_LAYERS.search(layer) or TEXT_LAYERS.search(layer):
            continue
        poly = Polygon(g.coords)
        if not poly.is_valid:
            poly = poly.buffer(0)
            if poly.geom_type != 'Polygon':
                continue
        if not 5 < poly.area < 200_000:
            continue
        key = (round(poly.area, 1),) + tuple(round(v, 1) for v in poly.bounds)
        if key not in seen:  # el mismo lote dibujado dos veces en capas distintas
            seen.add(key)
            polys.append(poly)
    if not polys:
        return None
    tree = STRtree(polys)

    # Números de lote en capas de rótulos; se conservan las capas donde la mayoría cae dentro de un lote.
    candidates = defaultdict(list)
    for text, p, layer in drawing.texts:
        if PARCEL_NUMBER.match(text) and PARCEL_TEXT_LAYERS.search(layer) and not INFRA_LAYERS.search(layer):
            candidates[layer].append((text, p))
    label_layers = {}
    for layer, items in candidates.items():
        hits = [(t, p, smallest_containing(tree, polys, p)) for t, p in items]
        inside = [h for h in hits if h[2] is not None]
        if len(inside) >= 5 and len(inside) >= 0.5 * len(items):
            label_layers[layer] = inside
    if not label_layers:
        return None

    # Etapa: sale del nombre de la capa (la numeración se reinicia en cada etapa).
    stages = {layer: (roman_to_int(next(g for g in m.groups() if g)) if (m := STAGE_IN_LAYER.search(layer)) else None) for layer in label_layers}
    staged = any(v is not None for v in stages.values())
    notes = []
    if staged:
        for layer, value in stages.items():
            if value is None:
                stages[layer] = 1
                notes.append(f'La capa «{layer}» no indica etapa; sus lotes se asignaron a la Etapa I.')

    found = []
    for layer, items in label_layers.items():
        stage = stages[layer]
        block = f'E{stage}' if staged else 'L'
        group_label = f'Etapa {to_roman(stage)}' if staged else 'Lotes'
        for text, p, face_idx in items:
            found.append({'code': f'{block}-{text}', 'block': block, 'number': text, 'group_label': group_label,
                          'stage_order': stage or 0, 'face': face_idx, 'point': p})

    # Copias o detalles alejados del loteo (cuadros de áreas, ampliaciones…).
    median_area = statistics.median(polys[f['face']].area for f in found)
    idx, dropped = keep_main_groups([f['point'] for f in found], max(40.0, 4 * math.sqrt(median_area)))
    found = [found[i] for i in sorted(idx)]
    return {'scheme': 'stages' if staged else 'numbers', 'found': found, 'faces': polys, 'dropped_groups': dropped,
            'layers_used': sorted(label_layers), 'gap_fixes': 0, 'notes': notes}


# ── Nomenclatura MANZANA-NÚMERO para planos numerados por etapa ────────────
def block_name(index):
    """0 → A, 25 → Z, 26 → AA, 27 → AB… (como las columnas de Excel)."""
    name = ''
    index += 1
    while index:
        index, rest = divmod(index - 1, 26)
        name = chr(65 + rest) + name
    return name


def natural(number):
    return int(re.sub(r'\D', '', number) or 0), number


def assign_generated_blocks(lots, polys):
    """Agrupa los lotes contiguos (separados de los demás por vías) en manzanas, les asigna letras
    siguiendo la numeración original del plano (etapa y número) y numera los lotes de 1 a n dentro
    de cada manzana. Devuelve {código original: código nuevo}."""
    shape = unary_union(polys).buffer(1.2, join_style=2)
    parts = list(getattr(shape, 'geoms', [shape]))
    tree = STRtree(parts)
    members = defaultdict(list)
    for i, poly in enumerate(polys):
        hits = tree.query(poly.representative_point(), predicate='within')
        members[int(hits[0]) if len(hits) else -1 - i].append(i)

    key = lambda i: (lots[i]['_stage_order'], natural(lots[i]['number']))
    renamed = {}
    for n, part in enumerate(sorted(members, key=lambda k: min(key(i) for i in members[k]))):
        letter = block_name(n)
        for number, i in enumerate(sorted(members[part], key=key), start=1):
            lot = lots[i]
            renamed[lot['code']] = f'{letter}-{number}'
            lot.update(plan_ref=lot['code'], block=letter, number=str(number),
                       code=f'{letter}-{number}', group_label=f'Manzana {letter}')
    return renamed


# ── Contexto ───────────────────────────────────────────────────────────────
def rounded(coords, origin, step=2):
    ox, oy = origin
    return [[round(x - ox, step), round(y - oy, step)] for x, y in coords]


def context_layers(drawing, view, origin, lot_union):
    out = {name: [] for name, _, _ in CONTEXT_CLASSES}
    for layer, g, closed in drawing.lines:
        if INFRA_LAYERS.search(layer) or TEXT_LAYERS.search(layer) or not g.intersects(view):
            continue
        cls = next(((name, fill) for name, rx, fill in CONTEXT_CLASSES if rx.search(layer)), None)
        if not cls:
            continue
        name, fill = cls
        if fill:
            if not closed:
                continue
            poly = Polygon(g.coords).buffer(0)
            if poly.area < 20:
                continue
            for part in getattr(poly.intersection(view), 'geoms', [poly.intersection(view)]):
                if part.geom_type == 'Polygon' and part.area > 20:
                    out[name].append(rounded(part.simplify(0.2).exterior.coords, origin, 1))
        else:
            clipped = g.intersection(view)
            for part in getattr(clipped, 'geoms', [clipped]):
                if part.geom_type == 'LineString' and part.length > 1:
                    out[name].append(rounded(part.simplify(0.05).coords, origin))
    return out


def context_labels(drawing, view, origin, size):
    labels = []
    for t, p, layer in drawing.texts:
        if view.contains(p) and len(t) < 40 and not INFRA_LAYERS.search(layer) and PUBLIC_CONTEXT.search(t) and not LOT_LABEL.match(t):
            labels.append({'text': t, 'xy': rounded([(p.x, p.y)], origin)[0], 'kind': 'context'})
    for name, p, layer in drawing.inserts:
        label = name.replace('_', ' ')
        if view.contains(p) and PUBLIC_CONTEXT.search(label) and not INFRA_LAYERS.search(layer):
            labels.append({'text': label.title(), 'xy': rounded([(p.x, p.y)], origin)[0], 'kind': 'zone'})
    # Un rótulo repetido muy cerca (p. ej. "Parqueadero" en cada cupo) se muestra una vez.
    radius = max(15.0, max(size) / 40)
    deduped = []
    for label in labels:
        if not any(l['text'].lower() == label['text'].lower() and math.dist(l['xy'], label['xy']) < radius for l in deduped):
            deduped.append(label)
    return deduped[:MAX_LABELS]


def north_angle(drawing, area):
    """(grados horarios del norte respecto a +Y, ¿detectado?, nota)."""
    letters = {}
    for t, p, _ in drawing.texts:
        if t.upper() in ('N', 'S') and area.contains(p):
            letters.setdefault(t.upper(), p)
    if 'N' in letters and 'S' in letters and letters['N'].distance(letters['S']) > 0.5:
        n, s = letters['N'], letters['S']
        # Redondeo al grado: el desfase de pocas décimas viene del ancho de las letras.
        return float(round(math.degrees(math.atan2(n.x - s.x, n.y - s.y)))), True, None
    minx, miny, _, _ = area.bounds
    if minx > 100_000 and miny > 100_000:
        return 0.0, True, 'Plano georreferenciado (coordenadas de cuadrícula): el norte coincide con el eje vertical.'
    return 0.0, False, 'No se encontró la rosa de los vientos (letras N y S); se asume el norte hacia arriba.'


# ── Proceso completo ───────────────────────────────────────────────────────
def summarize(codes, limit=12):
    codes = list(codes)
    return ', '.join(codes[:limit]) + (f' y {len(codes) - limit} más' if len(codes) > limit else '')


def process(dxf_path, progress=Progress(None)):
    progress('Leyendo el plano', 10)
    drawing = load(dxf_path)
    if not drawing.lines:
        raise ValueError('El archivo no contiene geometría legible.')

    progress('Buscando lotes numerados', 30)
    results = [r for r in (strategy_parcels(drawing, progress),) if r]
    best_b = results[0] if results else None
    block_labels = sum(1 for t, _, _ in drawing.texts if LOT_LABEL.match(t))
    # La estrategia A es costosa en planos grandes: solo se intenta si puede competir.
    if block_labels >= 5 and (not best_b or block_labels > 0.5 * len(best_b['found'])):
        progress('Buscando lotes por manzana', 40)
        result_a = strategy_blocks(drawing, progress)
        if result_a:
            results.append(result_a)
    if not results:
        raise ValueError('No se reconocieron lotes. Rotula cada lote como MANZANA-NÚMERO (p. ej. "A-1") '
                         'o dibújalos como polilíneas cerradas en una capa de lotes con su número en una capa de rótulos.')
    chosen = max(results, key=lambda r: sum(1 for f in r['found'] if f['face'] is not None))

    progress('Validando áreas', 75)
    faces = chosen['faces']
    area_labels, area_tree = written_areas(drawing)
    warnings = list(chosen.get('notes', []))
    if chosen['dropped_groups']:
        warnings.append(f'El archivo trae {chosen["dropped_groups"]} grupo(s) de lotes apartados (copias, detalles o cuadros); se omitieron.')

    lots, unmatched, mismatches, shared, duplicated = [], [], [], [], []
    used, codes = {}, set()
    order = lambda f: (f['block'], int(re.sub(r'\D', '', f['number']) or 0), f['number'])
    for f in sorted(chosen['found'], key=order):
        if f['face'] is None:
            unmatched.append(f['code'])
            continue
        if f['code'] in codes:
            duplicated.append(f['code'])
            continue
        if f['face'] in used:
            shared.append(f'{used[f["face"]]}/{f["code"]}')
            continue
        used[f['face']] = f['code']
        codes.add(f['code'])
        face = faces[f['face']]
        written = area_written_in(face, area_labels, area_tree)
        if written and not (face.area / 3 < written < face.area * 3):
            written = None  # el texto pertenece a otro objeto (p. ej. el área de la manzana)
        diff = abs(face.area - written) / written if written else None
        if diff is not None and diff > AREA_WARNING:
            mismatches.append({'code': f['code'], 'computed': round(face.area, 2), 'written': written})
        rp = face.representative_point()
        lots.append({
            'code': f['code'], 'block': f['block'], 'number': f['number'], 'group_label': f['group_label'],
            'stage_label': f['group_label'] if chosen['scheme'] == 'stages' else None,
            '_stage_order': f.get('stage_order', 0),
            'points': list(face.simplify(0.01).exterior.coords)[:-1],
            'area': round(face.area, 2), 'area_label': written, 'label_xy': (rp.x, rp.y),
            'check': 'area_mismatch' if diff is not None and diff > AREA_WARNING else 'ok',
        })
    if not lots:
        raise ValueError('Se encontraron rótulos de lote, pero ningún polígono cerrado que los contenga.')

    lot_polys = [faces[i] for i in used]
    naming = 'plan'
    if chosen['scheme'] != 'blocks':
        progress('Asignando manzanas y nomenclatura', 80)
        renamed = assign_generated_blocks(lots, lot_polys)
        mismatches = [{**m, 'plan_ref': m['code'], 'code': renamed.get(m['code'], m['code'])} for m in mismatches]
        naming = 'generated'
        warnings.append('El plano numera los lotes por etapa y no trae letras de manzana: se generaron las manzanas '
                        '(lotes contiguos entre vías) con nomenclatura MANZANA-NÚMERO. La referencia original queda en «plan_ref».')

    progress('Dibujando manzanas y contexto', 85)
    lot_union = unary_union(lot_polys)
    minx, miny, maxx, maxy = lot_union.bounds
    pad = max(18.0, 0.03 * max(maxx - minx, maxy - miny))
    view = box(minx - pad, miny - pad, maxx + pad, maxy + pad)
    origin = (minx - pad, miny - pad)
    size = [round(maxx - minx + 2 * pad, 2), round(maxy - miny + 2 * pad, 2)]

    # Manzanas: unión de los lotes de cada manzana ampliada 1,2 m (andén).
    blocks = []
    groups = defaultdict(list)
    for lot, poly in zip(lots, lot_polys):
        groups[lot['block']].append(poly)
    for name, polys in sorted(groups.items(), key=lambda kv: (len(kv[0]), kv[0])):
        shape = unary_union(polys).buffer(1.2, join_style=2).buffer(0)
        for part in getattr(shape, 'geoms', [shape]):
            c = part.representative_point()
            blocks.append({'name': name, 'points': rounded(part.simplify(0.05).exterior.coords, origin), 'label_xy': rounded([(c.x, c.y)], origin)[0]})

    # Área del proyecto: manzanas + vías internas (cierre morfológico).
    reach = 9 if chosen['scheme'] == 'blocks' else 12
    site_shape = lot_union.buffer(reach, join_style=2).buffer(-(reach - 4), join_style=2)
    site = [rounded(p.simplify(0.2).exterior.coords, origin, 1) for p in getattr(site_shape, 'geoms', [site_shape]) if p.area > 100]

    layers = {'site': site, 'blocks': blocks, **context_layers(drawing, view, origin, lot_union)}
    labels = context_labels(drawing, view, origin, size)

    declared = next((int(m.group(1)) for t, _, _ in drawing.texts if (m := DECLARED_LOTS.search(t))), None)
    if declared and declared != len(lots):
        warnings.append(f'El plano declara {declared} lotes y se reconstruyeron {len(lots)}.')
    if unmatched:
        warnings.append(f'{len(unmatched)} rótulo(s) sin polígono cerrado: {summarize(unmatched)}.')
    if shared:
        warnings.append(f'{len(shared)} polígono(s) con dos rótulos (se conservó el primero): {summarize(shared)}.')
    if duplicated:
        warnings.append(f'{len(duplicated)} rótulo(s) repetido(s): {summarize(sorted(set(duplicated)))}.')

    north, north_found, north_note = north_angle(drawing, view)
    if north_note:
        warnings.append(north_note)

    errors = [abs(l['area'] - l['area_label']) / l['area_label'] for l in lots if l['area_label']]
    group_names = {l['block']: l['group_label'] for l in lots}
    for lot in lots:
        lot.pop('_stage_order', None)
        if lot.get('stage_label') is None:
            lot.pop('stage_label', None)

    return {
        'version': 2,
        'units': 'm',
        'scheme': chosen['scheme'],
        'naming': naming,
        'groups': group_names,
        'size': size,
        'north_deg': north,
        'layers': layers,
        'labels': labels,
        'lots': [{**l, 'points': rounded(l['points'], origin), 'label_xy': rounded([l['label_xy']], origin)[0]} for l in lots],
        'report': {
            'scheme': chosen['scheme'],
            'lots_detected': len(lots),
            'lots_declared': declared,
            'groups': Counter(l['group_label'] for l in lots),
            'stages': Counter(l['stage_label'] for l in lots if l.get('stage_label')),
            'naming': naming,
            'labels_unmatched': unmatched[:200],
            'area_mismatches': mismatches[:200],
            'area_mismatches_total': len(mismatches),
            'mean_area_error_pct': round(100 * sum(errors) / len(errors), 2) if errors else None,
            'areas_compared': len(errors),
            'project_area_m2': round(lot_union.area, 0),
            'layers_used': chosen['layers_used'],
            'gap_fixes': chosen['gap_fixes'],
            'north_detected': north_found,
            'warnings': warnings,
        },
    }


def main():
    parser = argparse.ArgumentParser(description='Convierte un plano DXF de loteo en JSON para LotHub.')
    parser.add_argument('--dxf', required=True)
    parser.add_argument('--out', required=True)
    parser.add_argument('--progress', help='archivo JSON donde se informa el avance')
    args = parser.parse_args()
    progress = Progress(args.progress)
    try:
        result = process(args.dxf, progress)
    except Exception as exc:  # el mensaje llega al usuario a través de Laravel
        print(json.dumps({'error': str(exc)}, ensure_ascii=False), file=sys.stderr)
        sys.exit(2)
    progress('Guardando el resultado', 95)
    with open(args.out, 'w', encoding='utf8') as fh:
        json.dump(result, fh, ensure_ascii=False, separators=(',', ':'))
    r = result['report']
    print(json.dumps({'ok': True, 'lots': r['lots_detected'], 'warnings': len(r['warnings'])}))


if __name__ == '__main__':
    main()
