# Plano interactivo de proyectos

El comprador ve el loteo **a escala real**, con norte, manzanas, vías y zonas comunes, y escoge
su lote directamente sobre el plano. El personal carga el plano de AutoCAD y el sistema lo
convierte solo.

```
Carga (DWG/DXF) ─▶ DWG→DXF (LibreDWG) ─▶ process_plan.py (Python) ─▶ JSON ─▶ visor SVG (navegador)
                                                                       │
                                     estado de cada lote en la BD ─────┘  (disponible / reservado / vendido)
```

## Flujo para el usuario

1. **Proyectos → Nuevo proyecto** (o el botón <i>mapa</i> de un proyecto existente) → campo
   *Plano arquitectónico*.
2. El sistema procesa el archivo en segundos y abre **Plano interactivo** del proyecto con:
   lotes detectados vs. declarados, diferencia de áreas, advertencias y los lotes a revisar
   (borde rojo punteado).
3. **Sincronizar lotes**: crea en el inventario los lotes del plano que no existen, con el área
   oficial escrita en el plano y el precio por m² indicado. No toca precio ni estado de los existentes.
4. **Publicar en el sitio**: el detalle público del proyecto muestra el plano como vista principal
   (pestañas *Plano / Lista*). Solo puede haber un plano publicado por proyecto; cada carga queda
   en el historial.

El color de cada lote sale **en vivo** de la base de datos: cuando un lote se reserva o se vende,
el plano lo refleja sin volver a cargar nada.

## Formato recomendado

| Formato | Soporte | Recomendación |
|---|---|---|
| **DXF** | Nativo | **Preferido.** Formato abierto; en AutoCAD: *Guardar como → DXF (2010 o posterior)*. No depende de conversores. |
| **DWG** | Con LibreDWG (`PLAN_DWG2DXF`) | Funciona (probado con AutoCAD 2010, `AC1024`). DWG es un formato cerrado: versiones muy nuevas o con objetos de Civil 3D pueden convertirse con advertencias. Si falla, exportar a DXF. |
| PDF / imagen | No | No traen geometría: no se puede medir ni identificar lotes. |

## Cómo debe venir el plano (para que el algoritmo lo entienda)

El procesador reconoce **dos convenciones** y usa automáticamente la que reconstruya más lotes:

| | A · Manzana-lote | B · Parcelas numeradas (Civil 3D) |
|---|---|---|
| Lote | Líneas divisorias (pueden ser sueltas y en cualquier capa) | Polilínea **cerrada** en una capa cuyo nombre contenga `LOTE`, `PARCEL`, `PREDIO` o `C-PROP` |
| Rótulo | Texto `A-1`, `B-12`… dentro del lote | Número (`664`) en una capa de rótulos (`C-PROP-TEXT…`, `…TEXT`, `…NUM`) |
| Agrupación | Manzana (la letra) | Etapa, tomada del nombre de la capa (`…ETAPA-II` → Etapa II); la numeración se reinicia por etapa |
| Ejemplo probado | `areanas mod.2010` (AutoCAD 2010, 123 lotes) | `LAGOS DEL OLIMPO` (AutoCAD 2018, 44 MB, **4.542 lotes**, 3 etapas) |

En la convención B el procesador **genera las manzanas** (lotes contiguos entre vías) y numera los
lotes como `A-12`; la etapa y la referencia original (`E1-664`) se conservan (ver
[FICHA_DE_VENTA.md](FICHA_DE_VENTA.md#nomenclatura-de-los-lotes)). Si una capa de rótulos no indica
etapa mientras las demás sí, se asigna a la Etapa I y se avisa en el reporte.

Recomendaciones para ambas:

1. **Cada lote con su rótulo** dentro del polígono. Es lo único estrictamente obligatorio.
2. El área como texto dentro del lote (`A: 95.51 m2`), opcional pero muy recomendable: se usa
   como **área oficial** y para validar la geometría.
3. Los linderos de los lotes cerrados (pueden ser líneas sueltas en cualquier capa; el algoritmo
   cierra huecos de hasta 1 m).
4. Una rosa de los vientos con las letras **N** y **S** para la orientación (si no, se asume norte arriba).
5. Unidades en **metros**.
6. Opcional: un texto `LOTES= 124` para validar el total.

Capas que se ignoran por nombre (no son linderos): `CVL_*` (topografía), `COTAS`, capas de texto,
`PARAMENTO` (retiro de construcción, que atraviesa los lotes), `MUROS`, `PUERTAS`, `DEFPOINTS`, achurados.
También se ignoran redes y anotaciones de Civil 3D (alcantarillado, `PIPE`, `NODE`, `BRNG`, cuadrículas…).
Se ajustan en `INFRA_LAYERS` de `tools/plan-processor/process_plan.py`.

**Privacidad:** el sitio público solo muestra textos de contexto genéricos (vías, canales,
parques, canchas, locales…). Los nombres de propietarios colindantes que suelen venir en los
planos **no se publican** (Ley 1581 de 2012).

## Cómo funciona el algoritmo

`tools/plan-processor/process_plan.py` (documentado en el propio archivo):

1. Lee líneas, arcos, polilíneas, textos y bloques (por flujo, para soportar archivos grandes).
2. Prueba las dos convenciones y se queda con la que reconstruya más lotes. Descarta los grupos
   de lotes apartados (copias, detalles o cuadros) pero conserva sectores grandes separados, como
   etapas al otro lado de un lago.
   Los pasos 3 y 4 aplican a la convención A; en la B cada lote ya es un polígono cerrado y se
   asigna cada número al polígono más pequeño que lo contiene.
3. **Cierra huecos de dibujo**: prolonga extremos sueltos hasta la línea más cercana (≤ 1 m) y
   alarga cada segmento 2 cm para que las intersecciones se nodeen.
4. **Poligoniza** las líneas y asigna a cada etiqueta la cara cerrada más pequeña que la contiene.
5. Valida el área calculada contra la escrita (avisa si difieren más de 3 %).
6. Calcula las manzanas (unión de sus lotes + andén), el área del proyecto, el norte (rosa de los
   vientos o, si el plano está georreferenciado, la cuadrícula) y el contexto público: vías, lagos,
   quebradas, parques, parqueaderos y nomenclatura (`CRA. 2A`, `CLL. 1 SUR`).

Resultados con los planos de prueba:

| Plano | Archivo | Convención | Lotes | Tiempo total* |
|---|---|---|---|---|
| areanas mod.2010 | DWG 0,4 MB (AutoCAD 2010) | A | 123 · 10 manzanas · 0,41 % dif. de área | ~3 s |
| LAGOS DEL OLIMPO | DWG 43,7 MB → DXF 221 MB (AutoCAD 2018, 115 capas) | B | 4.542 · 3 etapas · lago, quebradas, parques y nomenclatura | ~40 s |

\* conversión DWG→DXF + procesamiento, en el equipo de desarrollo.

Planos grandes: se lee el DXF por flujo (`iterdxf`, ≈5× más rápido que cargarlo completo) y las
búsquedas usan índices espaciales (`STRtree`). Los textos con escapes Unicode truncados que
LibreDWG produce con DWG 2018+ se limpian automáticamente.

### Procesamiento en segundo plano

La carga responde de inmediato: `PlanService::dispatch()` lanza `php artisan plans:process {id}`
como proceso independiente y la pantalla muestra el avance (paso y porcentaje que el script
escribe en `storage/app/plans/{proyecto}/{id}.progress.json`). Si el proceso muere sin informar,
tras `lothub.plans.timeout` el plano se marca con error. Para reprocesar a mano:

```bash
php artisan plans:process 7
```

El DXF intermedio de un DWG (hasta 5× más pesado) se borra al terminar; se conservan el original y el JSON.

Salida (JSON en `storage/app/plans/{proyecto}/`; 33 KB para 123 lotes, 1,1 MB para 4.542): `scheme`
(`blocks` | `stages`), `groups`, `size` (m), `north_deg`, `layers` (site, blocks, water, green,
streams, roads, sidewalks, boundary, parking), `labels`, `lots` (`code`, `block`, `number`,
`group_label`, `points`, `area`, `area_label`, `label_xy`, `check`) y `report`.

## Instalación

```bash
# 1. Esquema
mysql -u <usuario> -p cartera_inmobiliaria < database/schema/patches/001_project_plans.sql

# 2. Procesador (entorno virtual propio: no depende del Python del usuario)
python -m venv tools/plan-processor/.venv
tools/plan-processor/.venv/Scripts/pip install -r tools/plan-processor/requirements.txt   # Linux: .venv/bin/pip

# 3. (Opcional) DWG: descargar LibreDWG y apuntar a dwg2dxf
#    https://github.com/LibreDWG/libredwg/releases  → descomprimir en tools/plan-processor/bin/libredwg/
```

`.env`:

```ini
PLAN_PYTHON=tools/plan-processor/.venv/Scripts/python.exe   # Linux: tools/plan-processor/.venv/bin/python
PLAN_DWG2DXF=tools/plan-processor/bin/libredwg/dwg2dxf.exe  # vacío = solo DXF
```

**Límite de tamaño.** El máximo real es el menor entre `PLAN_MAX_MB` (50 por defecto) y los
límites de PHP en `php.ini`:

```ini
upload_max_filesize = 50M
post_max_size = 55M     ; un poco más que el archivo, por los demás campos del formulario
```

Después de cambiarlos hay que **reiniciar** `php artisan serve` (o Apache). Si alguien sube un
archivo mayor, el navegador lo avisa antes de enviarlo y, si aun así llega, el servidor responde
una página clara (HTTP 413) en lugar de un error.

Notas:
- Usa rutas con `/` en el `.env` (el lector del `.env` no acepta `\` entre comillas).
- `php artisan serve` lanza los procesos con un entorno reducido (sin `PATH` de usuario ni carpeta
  personal); por eso se usa el entorno virtual y el script define `HOME` si falta.
- LibreDWG es GPL y se invoca como programa externo; no se distribuye dentro del repositorio
  (`tools/plan-processor/bin/` está en `.gitignore`).

## Piezas del código

| Pieza | Archivo |
|---|---|
| Procesador DXF → JSON | `tools/plan-processor/process_plan.py` |
| Orquestación (carga, conversión, sincronización, publicación) | `app/Services/Plans/PlanService.php` |
| Pantalla de revisión interna | `ProjectPlanController` + `resources/views/commercial/plan.blade.php` |
| Carga desde el formulario del proyecto | `ProjectController` (campo `plan_file`) |
| Visor interactivo (zoom, arrastre, pellizco, brújula, escala, filtros) | `public/js/plan-viewer.js` + sección 8 de `app.css` |
| Vista pública | `SiteController::project` + `resources/views/public/project.blade.php` |
| Tabla | `project_plans` (parche `001`) |

Permisos: ver el plano requiere `proyectos.ver`; cargar, sincronizar y publicar, `proyectos.editar`.

## Ideas siguientes

- **Ubicación real en el mapa:** ambos planos de prueba están georreferenciados (Lagos del Olimpo en
  coordenadas MAGNA-SIRGAS); con ellas se puede superponer el loteo a un mapa satelital.
- Mostrar al comprador su lote resaltado en **Mi estado de cuenta**.
- Pasar el procesamiento a una cola (`QUEUE_CONNECTION=database` + `queue:work`) en producción, en lugar
  del proceso independiente que se lanza hoy.
