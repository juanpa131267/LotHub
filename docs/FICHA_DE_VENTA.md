# Ficha de venta: fotos y precios

Cada proyecto se configura desde sus pestañas **Datos · Plano · Fotos · Precios**
(`resources/views/commercial/partials/project-tabs.blade.php`). Al crear un proyecto sin plano,
el sistema lleva directamente a **Fotos**; con plano, a la revisión del **Plano**.

## Fotos

Se muestran en la página pública del proyecto (encabezado con la portada + galería con visor a
pantalla completa) y la portada en la tarjeta del proyecto en el inicio.

| Forma de carga | Para qué |
|---|---|
| **1 · Masiva** | Arrastrar o elegir muchas fotos a la vez, sin anotación. El navegador las envía una por una (no choca con los límites de PHP por petición) y muestra el avance de cada una. |
| **2 · Una por una** | Una foto con su anotación: «Piscina», «Logo del conjunto», «Zona común»… |

Después, en la galería, cada foto permite: escribir o cambiar su anotación (también las cargadas en
bloque), marcarla como **portada** (una por proyecto, garantizado por la BD), cambiar el orden y
eliminarla.

Procesamiento (`app/Services/Photos/PhotoService.php`, requiere la extensión **GD** de PHP):

- Corrige la rotación de las fotos de celular (EXIF).
- Guarda una versión de máx. 2000 px y una miniatura de 560 px (JPEG; PNG/WebP con transparencia → WebP).
- Archivos en `storage/app/photos/{proyecto}/`, servidos por la ruta pública `/media/fotos/{id}/{full|thumb}`
  con caché del navegador (no depende del enlace `public/storage`).
- Formatos JPG, PNG y WebP, hasta 20 MB por foto (o el límite de PHP si es menor).

Permisos: ver `proyectos.ver`; cargar, editar y eliminar `proyectos.editar`. Tabla `project_photos` (parche `002`).

## Precios

El precio de lista de cada lote se define de dos formas (`app/Services/Pricing/PriceService.php`).
Los lotes **vendidos nunca cambian** de precio: su valor real está en la negociación.

### 1 · Automático: valor general por m²

Precio = área × valor por m², redondeado al millar. Se aplica a los lotes disponibles o a todos los
no vendidos (disponibles, reservados y bloqueados). Al sincronizar los lotes desde el plano también
se usa un valor por m² inicial.

### 2 · Planilla de Excel, lote por lote

Para precios según la ubicación (frente al lago, esquinas, centro…):

1. **Descargar** la planilla `.xlsx` desde la pestaña Precios. Trae todos los lotes ordenados por
   manzana y número: código `A-12`, manzana, lote, etapa, referencia del plano original, área,
   estado y precio actual.
2. **Llenar** la columna **«Precio nuevo»** (resaltada en verde). Es la única editable junto con
   «Observación»; la hoja está protegida (sin contraseña) para no alterar los códigos, y permite
   ordenar y filtrar. La columna «Valor m² nuevo» se calcula sola. Celdas vacías = sin cambio.
3. **Cargar** el archivo. La importación es **atómica**: si alguna fila tiene un error (código que
   no existe, código repetido, precio no numérico o menor a $1.000) no se aplica nada y se muestra
   una tabla con la fila y qué corregir.

Detalles:

- Acepta precios como número de Excel o texto (`$ 25.200.000`, `25,200,000`).
- Una hoja oculta de control (firmada con la clave de la app) impide cargar la planilla de otro proyecto.
- Se lee con el lector XLSX explícito de PhpSpreadsheet en modo "solo datos" (sin evaluar fórmulas
  ni detectar formatos automáticamente), versión ≥ 5.8.1 por avisos de seguridad de las anteriores.
- Cada aplicación queda en auditoría (`PRECIO_POR_M2`, `PRECIOS_PLANILLA`).

Permisos: ver y descargar `proyectos.ver`; aplicar precios `lotes.editar`.

## Nomenclatura de los lotes

Todos los lotes usan el código **MANZANA-NÚMERO** (`A-12`):

- Planos con manzanas rotuladas (`A-1`, `B-12`…): se respeta la del plano.
- Planos numerados por etapa (Civil 3D: `664` en la capa de la Etapa I…): el procesador **genera las
  manzanas** —lotes contiguos separados por vías—, les asigna letras siguiendo la numeración del
  plano (A…Z, AA, AB…) y numera los lotes de 1 a n dentro de cada una. La etapa y la referencia
  original (`E1-1502`) se conservan: la etapa en `project_stages` y la referencia en las
  observaciones del lote, en el visor y en la planilla de precios.

Si un proyecto ya tenía lotes con la nomenclatura anterior (`E1-1502`), al reprocesar el plano y
**sincronizar**, cada lote se **renombra** a su nuevo código conservando su id, precio y estado.
Luego hay que **publicar la nueva versión** del plano (la pantalla lo advierte).

## Versiones del plano

«Reprocesar» un plano listo crea una **nueva versión** con el mismo archivo; la versión publicada
sigue en el sitio hasta que se publique la nueva. Cada versión guarda su propio JSON
(`…_v{id}.json`). Un plano con error se reintenta en el mismo registro.
