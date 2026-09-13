# Design

Dos superficies con lenguajes distintos y deliberadamente separados:

- **El sitio público** (`index.php`, servicios, contacto): sin cambios. No se
  arrastra al lenguaje del CRM.
- **El CRM** (`crm/*.php`): panel de operaciones moderno, descrito abajo. Vive
  en `assets/css/sch.css`, cargado **después** de `assets/css/app.css`, que
  sigue aportando la estructura y el layout.

---

## CRM · Panel de operaciones

### Tesis

El CRM es el panel de control de la operación de SCH MEDICOS: equipos
instalados en hospitales, cotizaciones, comprobantes fiscales con NCF y una
cola de soporte que no puede esperar.

Se lee como una herramienta financiera moderna: **la cifra manda, la variación
la acompaña, el detalle está a un clic.** Nada decora.

### El color sale del logo, medido píxel a píxel

El logo (`assets/media/logo_SCH_-removebg-preview.png`) se analizó por
frecuencia de píxel. De ahí salen los tres tonos del sistema — no se inventaron:

| Token | Valor | Origen en el logo | Papel |
|---|---|---|---|
| `--verde` | `#027F31` | las letras SCH (2 480 px) | **Único acento de acción.** Botón primario, módulo activo, dato positivo |
| `--verde-tinta` | `#016627` | el mismo, un paso más oscuro | Enlaces y texto pequeño sobre claro |
| `--verde-vivo` | `#0BA344` | derivado | **Solo gráficos y degradados.** 3.3:1 — nunca texto |
| `--bronce` | `#6C5E3D` | las alas del caduceo | Acento secundario: atención, aviso, prioridad media |
| `--bronce-tinta` | `#7A6320` | derivado | El bronce cuando es texto |
| `--baja` | `#C2202C` | — | **Reservado.** Vencido, crítico, prioridad alta |

**Reglas del color:**

- No hay azul de sistema. El azul del lenguaje anterior desapareció por
  completo: en este mundo el verde es lo seleccionado, lo primario y lo bueno.
- El rojo nunca decora. Si algo es rojo, está vencido o es crítico.
- El estado nunca depende solo del tono: cada cápsula lleva punto, anillo o
  palabra además del color.

### Neutros y material

| Token | Valor | Qué es |
|---|---|---|
| `--papel` | `#FFFFFF` | La tarjeta |
| `--nube` | `#F6F8F7` | El área de contenido y las superficies hundidas |
| `--linea` / `--linea-2` | `#EDF0F2` / `#E1E6E9` | Filete de tarjeta / de control |
| `--tinta` | `#0F1B14` | Titulares y cifras |
| `--tinta-2` | `#5B6B62` | Texto secundario |
| `--tinta-3` | `#66746D` | Rótulos y marcas de eje |

`--tinta-3` **tiene que leerse**: a `#7C8A83` se quedaba en 3.61:1 sobre blanco
y el barrido lo rechazó. Está calibrado en 4.90:1.

**Forma:** el CRM ocupa la **pantalla completa, de borde a borde**. Es una
herramienta de trabajo que se tiene abierta todo el día, y cada pixel de ancho
es una columna más de tabla: no hay marco, ni lienzo alrededor, ni esquina
redondeada en el armazón. El cabezal queda pegajoso arriba (60px) y el riel de
244px llega de arriba abajo.

Dentro, tarjetas a 18px, interiores a 13px, controles a 11px, cápsulas a 999px.
Sombras cortas y de contacto — `--sombra` y `--sombra-alta`, nunca un halo de
color.

### Los componentes propios

- **`.sch-head`** — el encabezado de cada pantalla: título, subtítulo y una
  cápsula con el dato vivo. Lo emite `sch_encabezado($nombre, $descripcion,
  $dato)` desde `includes/functions.php`, colocado justo después de incluir
  `crm_header.php`, que es el único punto que toda pantalla ejecuta.
  `gas_banda()` sigue existiendo como alias del nombre anterior.
- **`.sch-lectura`** — el bloque que define el lenguaje: rótulo pequeño, cifra
  grande en `tabular-nums` con tracking negativo, y un pie con la cápsula de
  variación más la salida «Ver más».
- **`.sch-delta`** — la cápsula de variación. `--sube` y `--baja` añaden flecha
  por CSS; **`--ok` y `--riesgo` no la llevan**, porque una flecha sobre un
  rótulo («Al día», «En plazo») o sobre un monto suelto no significa nada.
- **`.sch-acciones` / `.sch-accion`** — la rejilla de atajos del panel.
- **`.sch-fila`** — el patrón de lista: marca a la izquierda, dos líneas de
  texto, cifra a la derecha.
- **`.sch-barra`** — barra de avance con degradado de marca.

### Honestidad de los datos

`analytics_delta()` devuelve **`null`** cuando el periodo anterior está en cero,
y la interfaz lo rotula **«Sin base de comparación»**. Antes devolvía `100.0`,
y la pantalla mostraba «+100%» donde la verdad era que no había con qué
comparar: pasar de RD$0 a RD$1.5M no es un aumento del 100%. Quien lee el panel
toma decisiones con esa cifra.

Todo consumidor de un delta acepta `?float`: `$sch_delta()` en el panel y
`$delta_html()` en reportes.

### Componentes compartidos

Todo lo que se repite entre pantallas vive en `sch.css` y se nombra por la
clase que ya usa el marcado, no por una etiqueta genérica:

- **Formularios** — los campos del CRM no llevan atributo `type`
  (`<input name="company_name">`), así que un selector `input[type="text"]`
  nunca los alcanza. Se nombran por `.crm-input`, `.crm-select`, `.crm-textarea`
  y `.crm-filter`. El desplegable trae su propia flecha dibujada y el campo de
  archivo su propio botón: el del sistema no combinaba con nada.
- **Acciones de fila** — fantasma. Tres botones con borde en cada fila pesaban
  tanto como el dato; ahora toman cuerpo al pasar por encima y solo el
  destructivo se tiñe.
- **Tablero de módulo** — `.crm-cockpit__top` reparte dos columnas, pero el
  bloque de métricas es condicional. Si no está, la tarjeta ocupa el ancho:
  antes media pantalla quedaba vacía en Cobranza.
- **Agenda** — calendario, tarjeta de próximo servicio y filas de evento. El
  mes se abrevia a tres letras porque el nombre completo se salía encima del
  título en su columna de 54px.
- **Modales, paginación y estados vacíos** comparten el material de la tarjeta.

### Los gráficos del panel

Cuatro lecturas que no caben en una cifra, todas alimentadas por `analytics_*()`.
Si una serie no existe, la tarjeta lo dice en vez de dibujar una línea plana.

1. **Facturado contra cobrado** (`schFlujo`) — combinado: barras para lo emitido
   y lo que entró en caja, más una línea con el **% cobrado** en su propio eje.
   La brecha entre las barras *es* la lectura. Selector de 6 o 12 meses, y su
   leyenda apaga y enciende cada serie (`schAlternar`). Cuando un mes facturó
   cero, el punto del porcentaje se omite (`null`) en vez de dibujar un 0 que se
   leería como «no se cobró nada».
2. **Cartera por antigüedad** — dónut con el total en el centro; sin el centro,
   un dónut es un adorno. Cada tramo enlaza a los comprobantes.
3. **Cotizaciones por etapa** — barras horizontales. Es una **foto**, no una
   cohorte: `analytics_quote_funnel()` cuenta por estado *hoy*, así que la
   columna final es la parte del total, no una conversión. Medir conversión
   exigiría el historial de cambios de estado, que el CRM todavía no guarda.
4. **Cola de soporte** — tickets que entran contra los que se cierran.

Reemplazaron dos tarjetas del lenguaje anterior: «Promedio mensual», que
alternaba métricas en vez de compararlas, y «Dinámica mensual de servicio».

### Paleta de los gráficos

Una sola familia, derivada del verde del logo, más el bronce:

- **Barras** — `#027F31` para lo facturado y `#9FD4B2` para lo cobrado, con
  esquina de 6px. Dos tonos del mismo verde, porque son dos momentos del mismo
  dinero.
- **Líneas** — trazo de 2.5px con relleno en degradado vertical (`schArea()`,
  que necesita el contexto del canvas). Verde para lo resuelto, bronce para lo
  que entra o queda pendiente.
- **Etapas de cotización** (`analytics_stage_meta()`) — avanzan del neutro al
  verde: el color mide **avance**, no categoría. El bronce marca la
  negociación, que es donde el trato todavía puede caerse.
- **Líneas de negocio** (`quote_categories()`) — siete tonos entre el verde y
  el bronce. Ningún azul ni cian.
- Los ejes en `--tinta-3`, la cuadrícula en `--linea`, `Chart.defaults.font`
  en Aptos.
- **Tablero de soporte** — las vías recorren la misma progresión. Eran cinco
  familias de color para cinco estados; ahora son etapas de un mismo trabajo.
- Ejes y cuadrícula en `--tinta-3` y `--linea`. Tooltip `rgba(15,27,20,.94)`.

### Modo oscuro

Se activa con `data-tema="oscuro"` en `<html>`, escrito por un script en línea
del `<head>` **antes del primer pintado** — con `defer` habría un destello
blanco en cada carga. Sin preferencia guardada sigue a `prefers-color-scheme`,
también si el sistema cambia con la pestaña abierta. El interruptor está en la
barra superior y guarda la elección en `localStorage`.

La estrategia es de **dos capas de variables**, porque hay dos juegos:

- `app.css` define `--surface`, `--ink`, `--line`, `--page`… y las usa en
  cientos de reglas. Redefinirlas arrastra a todo el CRM de golpe, incluidos
  los componentes que `sch.css` nunca nombró.
- `sch.css` define `--papel`, `--tinta`, `--linea`… la capa de este rediseño.

`:root` también declara **`color-scheme: dark`**, que pone en oscuro lo que
pinta el navegador y ningún CSS alcanza: barras de desplazamiento, selectores
de fecha, autocompletado.

**Los tonos están medidos.** Sobre la tarjeta `#141D18` el texto principal da
14.9:1, el secundario 9.8:1 y el terciario 6.8:1. El verde del logo solo da
3.35:1 sobre ese fondo, así que como **texto** sube a `#35C46B` (7.6:1) y como
**relleno** de botón se queda en `#027F31`, donde el blanco encima da 5.14:1.

**Un color en el atributo `style` gana a cualquier hoja**, así que el tema no
podía alcanzarlos: eran islas blancas garantizadas. Se convirtieron en clases
—`.sch-caja--ok/aviso/alarma` y `.sch-monto--sube/baja`— que siguen al tema.

**Cuidado con la abreviatura `background`**: reinicia `background-image`,
`-repeat` y `-position`. Al usarla sobre `.crm-select` el chevron dibujado
perdía su `no-repeat` y se repetía en mosaico por todo el control. En el tema
oscuro los controles usan `background-color`.

Los gráficos llevan sus colores en JS y no se enteran de un cambio de CSS:
`schPaleta()` los lee en cada pintado y `pintarTodo()` los rehace al recibir el
evento `sch:tema`.

**Una propiedad personalizada se resuelve desde el ancestro más cercano que la
declare.** `site-v2.css` declara su paleta en `.sx`, que es el propio `<body>`,
así que esa declaración le gana a la de `:root` para todo lo que hay dentro —
por muy alta que sea la especificidad del selector. Se veía solo en móvil, donde
la pantalla de acceso cambia de marca y la suya quedaba en 1.02:1.

**Un degradado no vive en `background-color`.** `background: linear-gradient(…)`
se guarda en `background-image` y deja el color en transparente, así que ni las
variables ni una regla que solo toque el color lo alcanzan: hay que reemplazar
la imagen (`background-image: none`). La cabecera de cada tabla de datos y la
barra de filtros de tickets eran degradados blancos.

La verificación es una sonda propia (`oscuro.mjs`) que recorre las 16 pantallas
con **todos los `<dialog>` abiertos** y busca *islas claras* y texto por debajo
de AA contra el fondo que realmente tiene detrás. **Su primera versión solo leía
`background-color` y daba 0 fallos mientras esas cabeceras seguían en blanco**;
ahora promedia también las paradas del degradado. Cierra en **0 y 0**.

La lección vale para cualquier revisión futura: una sonda que da cero no prueba
que no haya fallos, solo que no encontró los que sabe buscar.
### Tipografía

`Aptos`, `Segoe UI`, `Arial`, sans-serif — la del sistema, sin descarga. La
pantalla de acceso cargaba **Geist desde Google Fonts**, bloqueando el render de
la primera vista del día para una familia que el CRM no usa; se eliminó.

Cifras siempre en `font-variant-numeric: tabular-nums` con tracking negativo.
Monoespaciada (`--font-mono`) solo para códigos: NCF, RNC, referencias de
ticket, series. Nunca para frases.

### Movimiento

Entrada de pantalla una sola vez (`sch-entrar`, 340ms escalonado en los
primeros bloques) y respuesta a hover/foco/apertura. Nada se mueve por su
cuenta; no hay `translateY` en hover. Se respeta `prefers-reduced-motion`,
que además reduce toda transición a 0.

### Foco de teclado

Quien no usa ratón navega con Tab, y si el anillo no se ve el CRM no se puede
usar. El anillo de los campos era un halo al **13 % de opacidad**: mezclado
sobre el papel da menos de 1.5:1, así que servía de adorno, no de indicador.
WCAG pide **3:1** para un indicador no textual.

Ahora el anillo es **sólido** —el verde de marca da 5.14:1— con el halo detrás
solo para suavizar. La cápsula de búsqueda lo dibuja ella, no su campo: antes
había dos anillos compitiendo y el del campo iba a 1.36:1.

Verificado con `foco.mjs`, que recorre cada elemento interactivo en los dos
temas. Dos cosas que la sonda tuvo que aprender:

- **Desactivar transiciones antes de medir.** Leyendo justo tras `focus()` se
  obtiene el estado *inicial* de la transición —dos sombras transparentes en
  cero— y todo parece «sin anillo».
- **Buscar el anillo en la cápsula.** Un campo puede delegarlo en su
  contenedor con `:focus-within`; es un patrón válido y hay que subir a
  buscarlo.
### Puntero y tacto

**Hover.** Un elemento pulsable que no cambia al pasar por encima parece
muerto, y eso solo se ve con el ratón encima: ninguna captura estática lo
enseña. `hover.mjs` fuerza el estado con `CSS.forcePseudoState` del protocolo
del navegador y comprueba dos cosas — que el texto siga cumpliendo AA sobre el
nuevo fondo, y que el hover **cambie algo** (fondo, color, borde, sombra,
transformación, opacidad o filtro).

Un detalle que la sonda enseñó al añadir el hover del botón de acceso en
oscuro: **aclarar el verde baja el contraste con el blanco encima**. `#0D8F3C`
da 4.19:1 y no llega. El hover oscurece a `#016627` (7.16:1).

**Tacto.** WCAG 2.2 (2.5.8) pide 24×24 px CSS. Los enlaces de salida de las
tarjetas medían 20 px de alto y las casillas de la matriz de permisos 18 px.
Se crece la **caja**, no la letra: `min-height` con centrado vertical deja el
texto donde estaba. Las casillas solo crecen en puntero grueso, porque con
ratón la precisión no es el problema. Verificado con `tactil.mjs`.

### Pantallas de detalle y modales

Tres pantallas nunca se habían mirado —ficha de cliente, búsqueda y perfil— ni
tampoco los **modales abiertos**, que solo se habían medido. De ahí:

- **Tablas de resumen dentro de una tarjeta.** `app.css` fija `min-width: 680px`
  a toda `.crm-table`. Está bien para las tablas a ancho completo, que deben
  desplazarse en vez de apretarse; pero las tarjetas de la ficha de cliente
  miden 638px y solo llevan tres o cuatro columnas, así que el importe de la
  cotización quedaba fuera de la vista y había que arrastrar la tabla para
  leerlo. `scroll.mjs` vigila justo eso: un resumen que a 1440px obliga a
  arrastrar.
- **Avatares que usan `--brand-strong`.** Ese token se **aclara** en oscuro
  porque ahí sirve de texto; como relleno con blanco encima da 1.89:1. Los
  avatares toman el verde sólido.
- **Fondos translúcidos.** El bloque «sin notas» del ticket lleva un blanco al
  **70% de opacidad**: sobre el modal oscuro es una losa clara, y mi sonda lo
  ignoraba por estar debajo del 85%. Ahora **compone el alfa** sobre lo que
  tiene detrás en vez de descartarlo, que es lo que hace el ojo.
### Cómo se ve una instalación nueva

Los estados vacíos no los había visto nadie: la base local siempre tiene
registros. Se levanta una base con **solo el esquema** (`sch_vacia`) y se
renderiza cada pantalla capturando avisos de PHP — un panel recién instalado
divide entre cero con facilidad. Las 15 pantallas salen **limpias**.

Dos hallazgos de ahí. El primero solo aparece **sin datos**: «Eliminar rol»
medía 41x17 px, porque ese botón solo existe cuando hay roles propios. Un
botón destructivo pequeño es la peor combinación —cuesta acertarlo y
equivocarse borra algo— y con la base de pruebas cargada no salía.

El segundo: la cartera es un **permiso nominal** —una lista blanca
vacía al instalar— así que el módulo simplemente no aparece y nadie sabe por
qué. `cartera_aviso_config()` muestra una nota **solo** a quien puede
arreglarlo (permiso `usuarios.manage`) y **solo** mientras la lista esté
vacía; en cualquier otro caso devuelve cadena vacía y la pantalla no cambia.

`config/database.php` protege ahora sus constantes con `defined() ||`, para que
un script de pruebas pueda apuntar la app a otra base sin seis avisos de
«Constant already defined».
### Volumen y contenido extremo

Lo que rompe un diseño no es el dato promedio, es el caso límite. Se siembra
una base (`sch_estres`) con 120 facturas, 90 tickets, nombres reales del sector
público dominicano, importes de nueve cifras y una cadena de 74 caracteres sin
espacios. De ahí salieron cuatro cosas:

- **La paginación no se había visto nunca** — con pocos registros no se dibuja.
  Traía 20 px de alto y el enlace deshabilitado en 2.05:1. Un control inactivo
  está exento de AA, pero «exento» no es «invisible»: si no se lee, nadie sabe
  que existe la página anterior.
- **Una cadena sin espacios se lleva el ancho de su columna** y empuja a las
  demás fuera del área visible. `overflow-wrap: anywhere` en las celdas.
- **La referencia del documento se partía en tres líneas.** Un número de
  comprobante partido no se puede leer ni dictar por teléfono: va en `nowrap`.
- **«RD$» se separaba de su cifra** al final de una línea. Arreglado de raíz en
  `money()` y `money_cur()` con espacio duro (U+00A0), no pantalla por pantalla.

`cortado.mjs` vigila lo contrario de envolver: texto **recortado** por un
`overflow: hidden`, que pierde información en vez de solo afearla.

### Mensajes y errores

El CRM no tenía forma de decir «esto falló»: los 120 avisos usaban el mismo
tipo `warning`, así que «no se pudo registrar el cobro» salía con el mismo
ámbar que «quedan 3 NCF». Se separó lo inequívoco —los 12 mensajes que empiezan
por «No se pudo»— a un tipo `error` con su rojo, su icono y `role="alert"`,
que el lector de pantalla anuncia solo. Los otros avisos siguen siendo
advertencias legítimas; reclasificarlos uno a uno es una decisión del negocio,
no del diseño.

**Sesión vencida.** Esto le pasa a gente normal: dejas la pestaña abierta
durante el almuerzo y pulsas «Guardar». La respuesta eran ocho palabras en
texto plano sobre fondo blanco, sin marca, sin explicación y sin salida — y con
estado **500**, que le dice al navegador y a los registros que el servidor
falló. No falló: la petición no está autorizada. Ahora hay una página con la
marca, en los dos temas, que explica qué pasó y ofrece volver a entrar, con
estado **403**.
### Accesibilidad

- **AA en todo el texto.** El barrido de contraste pasa las 16 pantallas
  (15 del CRM más el acceso) en 1440×950 y 390×844 sin fallos.
- Foco visible: `outline: 2px solid var(--verde)`, offset 2px.
- El estado nunca depende solo del color.
- El relleno de una barra de avance necesita `display: block`: como `<span>`
  en línea su `height` se ignora y la barra se ve como un subrayado.
- **Ninguna pantalla desborda la página.** Las tablas anchas se desplazan
  dentro de `.crm-table-wrap`; los hijos de `.crm-cockpit`, `.crm-body` y
  `.crm-content` llevan `min-width: 0` para que el mínimo intrínseco de una
  tabla no empuje el documento.

---

## Módulo DGII

### Lo que genera y está verificado

`607` y `608` en TXT, con cabecera `codigo|RNC|AAAAMM|registros`, separador
`|` y saltos **CRLF**. Verificado con un validador propio
(`valida_dgii.php`) que comprueba estructura, conteos y cada campo:

- 23 campos en el 607 y 3 en el 608, en el orden de la Norma 06-2018;
- el conteo de la cabecera coincide con las líneas reales;
- identificación 1/2/3 coherente con la longitud (9 = RNC, 11 = cédula);
- NCF válido: **11 caracteres serie B, 13 serie E (e-CF)**;
- fechas `AAAAMMDD`, importes con punto decimal y sin separador de miles;
- **ningún importe negativo** — una nota de crédito va con montos positivos y
  su NCF modificado en el campo 4, que es lo que el 607 admite;
- las formas de pago (campos 17–23) **suman el total del comprobante**, y una
  nota no lleva ninguna;
- ningún NCF aparece a la vez en el 607 y en el 608.

**840 comprobaciones sobre 12 meses** de una base con volumen, facturas en USD,
retenciones de ITBIS/ISR y notas de crédito: 0 fallos.

### Saltos de secuencia

Un número que no está ni en el 607 ni en el 608 es lo primero que la DGII
pregunta. El libro ya ordenaba por número «para que el salto quede a la vista»,
pero eso dejaba el trabajo al ojo. `dgii_sequence_gaps()` cruza ambos formatos y
avisa en pantalla antes de descargar, diciendo también las causas legítimas
—rango empezado en otro mes, número liberado para reutilizarlo— para no alarmar
sin motivo.

### Lo que NO cubre

- **606 (Compras).** No se genera y el módulo dice por qué: el CRM no registra
  compras ni gastos con NCF de suplidor. Es una declaración **mensual
  obligatoria**, así que hoy se lleva por fuera.
- **IT-1.** La vista de ITBIS da el débito fiscal y lo rotula como *saldo
  parcial de ventas*, no como el ITBIS a pagar: falta restar el crédito fiscal
  de las compras, que sale del 606.
- **Transmisión de e-CF.** El CRM emite NCF de serie E y los marca
  `ecf_status = Manual`, pero no firma ni transmite XML a la DGII.
- **Tipo de ingreso** (campo 5) es un ajuste global, no una clasificación por
  comprobante.
- Formatos **609** (pagos al exterior) y **623** (retenciones de ITBIS), que
  aplican según el perfil del contribuyente.
---

## Los PDF

Siete documentos: factura, cotización, recibo, cartera, libro de ventas,
reporte y recordatorio. Se auditan **rasterizando el PDF terminado** con
PDF.js, no revisando el HTML que lo origina: Dompdf tiene sus propias fuentes y
su propio motor de saltos, así que un fallo de tipografía o de corte solo
aparece en el archivo que recibe el cliente.

**`page-break-inside: avoid` sobre un bloque de tamaño desconocido.** Era el
fallo grave. La cartera lo tenía en `.section`, que contiene una tabla que
crece con los datos. Con cinco facturas no se notaba; con las de un mes real
—30 facturas— la sección medía más de una página, Dompdf no podía evitar
partirla y la empujaba entera: **la primera hoja salía con la tira de totales y
70% de papel en blanco.** La regla vale para lo pequeño y fijo —una firma, una
caja de total—, no para una tabla. La protección bajó a la **fila**, que sí
tiene tamaño acotado, más `page-break-after: avoid` en el encabezado de sección
para que no quede huérfano. De 4 páginas a 3, sin hueco.

**Cabecera de tabla que no se repetía.** La fila de encabezado de la cartera
iba suelta, sin `<thead>`. Dompdf repite el `<thead>` en cada página de una
tabla partida; una fila suelta, no. Las hojas 2 y 3 salían con ocho columnas de
números sin decir cuál era el total y cuál el saldo.

**Hoja entera para el cierre.** El total y la nota legal de la cartera no
cabían por poco y arrastraban una segunda hoja con nada más que el pie. Se
comprobó por bisección —quitando el total cabía; quitando la nota también;
juntos no— y se recuperó espacio de los márgenes del propio cierre. De 2
páginas a 1.

**Tipografía: la letra huérfana.** «SERVICIOS PARA CLINICAS Y / HOSPITALES,
SRL» dejaba la «Y» sola al final de la primera línea, en la cabecera de cada
factura, cotización y recibo. `sin_viudas()` ata las palabras de una sola letra
—en español y, o, a, e, u— a la siguiente con espacio duro.

**Comprobado:** el espacio duro que `money()` introdujo se imprime bien en
Dompdf; no salen cajas ni signos de interrogación. Los siete documentos generan
sin un solo aviso de PHP, con datos normales y con 120 facturas.

### Lo que queda

El **reporte de operaciones** desperdicia ~25% de su primera página. La causa
no es una regla suelta sino la estructura: las dos columnas son celdas de una
misma fila de tabla, y una fila no se puede partir, así que si el bloque no
cabe en el espacio restante salta entero. Arreglarlo exige rehacer el layout a
dos columnas independientes; el documento es correcto y legible como está, así
que se deja anotado en vez de reestructurarlo.
---

## Concurrencia

Auditada con procesos PHP en paralelo contra una base dedicada, no por
lectura de código: cada fallo se **reprodujo** antes de tocarlo y se volvió a
correr la misma prueba después.

| Camino | Antes | Ahora |
|---|---|---|
| Emisión de NCF | ✅ ya era correcto | 8 emisiones → 8 NCF distintos |
| Número de factura | 1 guardada, **7 errores SQL** en la cara del usuario | 8 → 8 números distintos |
| Número de recibo | **8 recibos con el mismo número**, sin un solo error | 8 → 8 números distintos |
| Cobro simultáneo | **RD$80,000 aplicados a una factura de RD$40,000** | el segundo recibe un conflicto explicado |

**Lo que ya estaba bien.** `invoice_emit()` toma el NCF con
`SELECT … FOR UPDATE` dentro de una transacción. Es lo más delicado de un CRM
fiscal dominicano y estaba correcto: ocho emisiones simultáneas dieron ocho
números distintos.

**Unicidad en la base, no solo en el código.** `invoices.ncf` tenía índice
normal y `invoice_payments.receipt_number` ninguno. El código protegía el NCF,
la base no: cualquier cosa que entrara por fuera de `invoice_emit()` —una
importación, dos rangos que solapan, un respaldo restaurado a medias— metía
duplicados en silencio y el 607 declaraba el mismo comprobante dos veces. Ahora
ambas columnas llevan `UNIQUE`; admiten NULL, así que los borradores conviven.

**Numeración atómica.** `reserve_serial()` inserta o incrementa el contador en
**una sola sentencia** y devuelve el valor nuevo por `LAST_INSERT_ID()`. No hay
ventana entre leer y escribir. La primera versión usaba `SELECT … FOR UPDATE` y
sí quitaba los duplicados, pero con la fila del contador aún inexistente cada
proceso bloqueaba el hueco y MySQL los mataba entre sí: **siete de ocho cobros
terminaban en interbloqueo**. La versión de una sentencia no tiene ese problema.

**El saldo se revalida dentro del bloqueo.** La validación del cobro se hacía
al pintar la pantalla; el envío llega minutos después. Dos cajeros que vieron
el mismo saldo aplicaban cada uno RD$40,000 a una factura de RD$40,000 y los
dos tenían éxito. Ahora `cobro.php` relee la factura con `FOR UPDATE` dentro de
la transacción y, si el saldo cambió, aborta con un mensaje que dice qué pasó y
qué recargar — no con el genérico «inténtalo de nuevo».
---

## Sitio público

Fuera del alcance del lenguaje del CRM y sin cambios.

- Contenedor de 1200–1280px, secciones guiadas por imagen, jerarquía SEO clara.
- Medios reales de `assets/media`.
- Movimiento corto: 150–220ms en hover, foco y despliegue.
