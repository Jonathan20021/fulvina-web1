<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('panel.view');
if (db(false)) { ensure_quote_schema(); }

$hasDb = db(false) && table_exists('clients');
$demo  = !$hasDb;           // sample data ONLY when MySQL is unavailable
$today = date('Y-m-d');

$initialsOf = static function (string $name): string {
    $name = preg_replace('/^(Ing\.|Lic\.|Dr\.|Dra\.|Sr\.|Sra\.)\s+/u', '', trim($name));
    $p = preg_split('/\s+/', $name) ?: [];
    return strtoupper(mb_substr($p[0] ?? 'S', 0, 1) . (isset($p[1]) ? mb_substr($p[1], 0, 1) : ''));
};

/* ---- Period (real, server-side) ----------------------------------------- */
$period = analytics_period((string) ($_GET['period'] ?? 'month'));
$periodLabel = $period['label'];

/* ---- Real analytics ----------------------------------------------------- */
$kpis    = analytics_kpis($period);
$stages  = analytics_pipeline_by_stage();
$trend   = analytics_monthly_trend(6);
$lines   = analytics_revenue_by_line();
$team    = analytics_team_performance(5);
$brandRows = analytics_equipment_by_brand(5);
$resolution = analytics_resolution($period);

$pipelineTotal = array_sum(array_column($stages, 'amount')) ?: 1;
$pipelineValue = (float) $kpis['pipeline']['value'];
$openQuoteCount = array_sum(array_column($stages, 'count'));
$wonValue = (float) $kpis['won']['value'];
$openTickets = (int) $kpis['open_tickets']['value'];
$criticalTickets = $hasDb && table_exists('tickets') ? db_count('tickets', "priority IN ('Critica','Alta') AND status NOT IN ('Resuelto','Cerrado')") : ($demo ? 3 : 0);

$stats = ['open' => $openTickets, 'quotes' => $openQuoteCount];

// Financial figures (pipeline, ingresos, montos) are role-gated: only users with
// the "Datos financieros" permission (finanzas.view) see money on the dashboard.
/* El panel resume módulos enteros: si una tarjeta muestra tickets, cotizaciones
   o equipos, tiene que pedir el mismo permiso que la pantalla de la que resume.
   Antes solo se protegía el dinero, y un rol sin acceso a Cotizaciones veía en
   el panel qué hospital estaba cotizando qué, con el enlace «Ver todas» que
   luego devuelve 403. La frontera la marca el permiso, no la pantalla. */
$canFinance = current_can('finanzas.view');
$canTickets = current_can('tickets.view');
$canQuotes  = current_can('cotizaciones.view');
$canAssets  = current_can('equipos.view');
$canAgenda  = current_can('agenda.view');
$defaultMetric = $canFinance ? 'facturado' : 'tickets';
// Facturación real: lo emitido y lo cobrado, que no es lo mismo que lo aprobado.
$billing = analytics_billing($period);
// La cartera es permiso NOMINAL, no de rol: la caja de zona solo aparece
// para quien esté en la lista blanca.
$canCartera = can_view_cartera();

/* ---- Team (real): tone + presence --------------------------------------- */
$tones = ['green', 'blue', 'teal', 'gold', 'slate'];
foreach ($team as $i => &$tm) { $tm['tone'] = $tones[$i % count($tones)]; }
unset($tm);
$teamHas = false;
foreach ($team as $tm) {
    if ((float) $tm['ingresos'] > 0 || (int) $tm['cotizaciones'] > 0 || (int) $tm['resueltos'] > 0) { $teamHas = true; break; }
}
$topTech = ($teamHas && !empty($team))
    ? ['name' => $team[0]['name'], 'metric' => (int) $team[0]['resueltos'], 'metricLabel' => 'resueltos']
    : ['name' => 'Sin actividad', 'metric' => 0, 'metricLabel' => 'resueltos'];
$topTech['initials'] = $initialsOf($topTech['name']);

/* ---- Best quote (real) -------------------------------------------------- */
if ($hasDb) {
    // Se ordena y muestra por el equivalente en RD$ para no coronar una propuesta
    // en USD solo porque su cifra nominal es menor (o mayor) que las de pesos.
    $dop = quote_total_dop_sql();
    $bq = fetch_one("SELECT clients.name, {$dop} AS total_dop FROM quotes LEFT JOIN clients ON clients.id = quotes.client_id ORDER BY total_dop DESC LIMIT 1");
    $bestQuote = $bq ? ['client' => $bq['name'] ?? 'Cliente', 'amount' => (float) $bq['total_dop']] : ['client' => '—', 'amount' => 0.0];
} else {
    $bestQuote = ['client' => 'Hospital Metropolitano', 'amount' => 486200.0];
}

/* ---- Presence flags ----------------------------------------------------- */
$linesHas  = !empty($lines) && array_sum(array_column($lines, 'amount')) > 0;
$brandTotal = array_sum(array_map(fn ($b) => (int) $b['total'], $brandRows)) ?: 0;
$brandsHas = $brandTotal > 0;
$trendHas  = array_sum($trend['ingresos_raw']) > 0 || array_sum($trend['cotizaciones']) > 0 || array_sum($trend['tickets']) > 0
    || array_sum($trend['facturado_raw']) > 0 || array_sum($trend['cobrado_raw']) > 0;
/* Una sola familia, derivada del verde del logo, más el bronce de las alas.
   Nada de azul ni cian: en esta interfaz el color no es decoración. */
$brandPalette = ['#027F31', '#0BA344', '#6C5E3D', '#7C8A83', '#014D1E'];

/* ---- Live operational tables (real when DB present) --------------------- */
$recentTickets = $hasDb && table_exists('tickets')
    ? fetch_all('SELECT tickets.*, clients.name AS client_name, equipment.name AS equipment_name, equipment.serial, users.name AS assigned_name FROM tickets LEFT JOIN clients ON clients.id = tickets.client_id LEFT JOIN equipment ON equipment.id = tickets.equipment_id LEFT JOIN users ON users.id = tickets.assigned_to WHERE tickets.status NOT IN ("Resuelto","Cerrado") ORDER BY FIELD(tickets.priority, "Critica","Alta","Media","Baja"), tickets.created_at DESC LIMIT 5')
    : ($demo ? [
        ['id' => 267, 'client_name' => 'Hospital Metropolitano de Santiago', 'equipment_name' => 'Tomógrafo Siemens', 'serial' => '12345ABC', 'subject' => 'Tomógrafo intermitente, error 8042', 'priority' => 'Alta', 'status' => 'Abierto', 'assigned_name' => 'Ing. R. Mena', 'created_at' => $today . ' 08:10:00', 'description' => 'El equipo se detiene durante el escaneo y muestra error 8042.', 'reported_phone' => '809-555-2266'],
        ['id' => 263, 'client_name' => 'Plaza de la Salud', 'equipment_name' => 'Ventilador Puritan Bennett', 'serial' => 'PB-840', 'subject' => 'Falla de encendido', 'priority' => 'Alta', 'status' => 'En proceso', 'assigned_name' => 'Ing. L. García', 'created_at' => $today . ' 09:25:00', 'description' => 'Equipo no completa secuencia de encendido.'],
        ['id' => 258, 'client_name' => 'CAID', 'equipment_name' => 'Sistema central de gases', 'serial' => 'SCH-CAID-02', 'subject' => 'Alarma de presión baja', 'priority' => 'Critica', 'status' => 'Abierto', 'assigned_name' => 'Ing. C. Reyes', 'created_at' => $today . ' 07:05:00'],
    ] : []);

$selectedTicket = $recentTickets[0] ?? null;

$quotes = $hasDb
    ? fetch_all('SELECT quotes.*, clients.name AS client_name FROM quotes LEFT JOIN clients ON clients.id = quotes.client_id ORDER BY quotes.created_at DESC LIMIT 5')
    : [
        ['id' => 1042, 'quote_number' => 'SCH-2026-0142', 'client_name' => 'Hospital Metropolitano', 'title' => 'Renovación de monitores UCI', 'status' => 'Negociacion', 'total' => 486200, 'updated_at' => $today],
        ['id' => 1041, 'quote_number' => 'SCH-2026-0141', 'client_name' => 'Plaza de la Salud', 'title' => 'Central de gases medicinales', 'status' => 'Enviado', 'total' => 312800, 'updated_at' => date('Y-m-d', strtotime('-1 day'))],
        ['id' => 1040, 'quote_number' => 'SCH-2026-0140', 'client_name' => 'CEDIMAT', 'title' => 'Ventiladores de transporte (x4)', 'status' => 'Cotizado', 'total' => 198400, 'updated_at' => date('Y-m-d', strtotime('-2 day'))],
    ];

$maintenance = analytics_warranties_expiring(4);
$overdueServices = analytics_overdue_services(8);

/* ---- Helpers ------------------------------------------------------------- */
$money0 = fn ($v) => 'RD$ ' . number_format((float) $v, 0, '.', ',');
$kfmt = fn ($v) => $v >= 1000000 ? number_format($v / 1000000, 2) . 'M' : ($v >= 1000 ? number_format($v / 1000, 0) . 'k' : (string) (int) $v);


$heroParts = explode('.', number_format($pipelineValue, 2, '.', ','));

$crmTitle = 'Panel de operaciones';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="gas-aviso">
        MySQL aún no está instalado. Este panel muestra datos de muestra. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para conectar datos reales.
    </div>
<?php endif; ?>

<div class="dash">

    <!-- ============ Toolbar ============ -->
    <div class="dash-bar">
        <div class="dash-bar__title">
            <h2>
                <i data-lucide="layout-dashboard" class="h-5 w-5 text-sch-blue"></i>
                Panel de operaciones
                <?php if ($demo): ?>
                    <span class="dash-live" style="background:var(--gold-soft);color:var(--gold-strong)"><i data-lucide="flask-conical" class="h-3.5 w-3.5"></i> Datos de muestra</span>
                <?php else: ?>
                    <span class="dash-live"><span class="dash-live-dot" style="width:6px;height:6px;border-radius:9px;background:#0a7d36;display:inline-block"></span> En vivo</span>
                <?php endif; ?>
            </h2>
            <p>Soporte, ventas y mantenimiento de SCH MEDICOS en un solo lugar.</p>
        </div>
        <div class="dash-bar__tools" x-data="{ tfOpen: false }">
            <?php if ($teamHas): ?>
                <div class="dash-avatars" aria-label="Equipo de servicio">
                    <?php foreach (array_slice($team, 0, 4) as $m): ?>
                        <span class="av av--<?= e($m['tone']) ?>" title="<?= e($m['name']) ?>"><?= e($initialsOf($m['name'])) ?></span>
                    <?php endforeach; ?>
                    <a class="dash-avatars__add" href="<?= url(current_can('usuarios.manage') ? 'crm/usuarios.php' : 'crm/perfil.php') ?>" aria-label="Gestionar equipo" title="Gestionar equipo"><i data-lucide="plus" class="h-4 w-4"></i></a>
                </div>
            <?php endif; ?>

            <div class="dash-dd" @click.outside="tfOpen = false">
                <button type="button" class="dash-chip dash-chip--accent" @click="tfOpen = !tfOpen" :aria-expanded="tfOpen"><i data-lucide="calendar-days"></i><span><?= e($periodLabel) ?></span><i data-lucide="chevron-down"></i></button>
                <div class="dash-pop dash-pop--left" x-show="tfOpen" x-transition.origin.top.left x-cloak>
                    <div class="dash-pop__label">Periodo</div>
                    <?php foreach (analytics_period_options() as $k => $label): ?>
                        <a class="dash-pop__item <?= $period['key'] === $k ? 'is-active' : '' ?>" href="<?= url('crm/index.php?period=' . $k) ?>"><i data-lucide="calendar-check"></i><?= e($label) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (current_can('reportes.view')): ?>
            <a href="<?= url('crm/reportes.php') ?>" class="dash-chip"><i data-lucide="bar-chart-3"></i><span class="dash-chip__hide">Reportes</span></a>
            <?php endif; ?>
            <button type="button" class="dash-iconbtn" @click="window.dashExport()" aria-label="Exportar CSV" title="Exportar CSV"><i data-lucide="download"></i></button>
            <button type="button" class="dash-iconbtn dash-iconbtn--solid" @click="window.dashShare()" aria-label="Compartir panel" title="Compartir panel"><i data-lucide="share-2"></i></button>
        </div>
    </div>

    <!-- ============ Encabezado + lecturas del periodo ============ -->
    <?php
    /*
     * El primer viewport responde cuatro preguntas en orden de dinero: cuánto
     * se facturó, cuánto entró en caja, cuánto falta por cobrar y qué soporte
     * está abierto. Cada lectura da la cifra grande, su variación contra el
     * periodo anterior y la salida a los registros que la componen.
     *
     * La primera tarjeta lleva además los accesos que se usan a diario, porque
     * quien abre el panel por la mañana viene a emitir o a cobrar.
     */

    /** Cápsula de variación. La flecha la pone el CSS: el color no va solo. */
    $sch_delta = static function (?float $d, string $ref = 'contra el periodo anterior'): string {
        if ($d === null) {
            // Sin periodo anterior no hay porcentaje: decirlo es más útil que
            // enseñar la referencia temporal sin cifra al lado.
            return '<span class="sch-lectura__ref">Sin base de comparación</span>';
        }
        $cls = abs($d) < 0.05 ? 'neutro' : ($d > 0 ? 'sube' : 'baja');
        $txt = ($d > 0 ? '+' : '') . number_format($d, 1) . '%';
        return '<span class="sch-delta sch-delta--' . $cls . '">' . e($txt) . '</span>'
             . '<span class="sch-lectura__ref">' . e($ref) . '</span>';
    };
    ?>
    <?php /* El panel ya se presenta en .dash-bar, con su periodo y sus
             herramientas: un segundo encabezado sería decir lo mismo dos veces. */ ?>
    <?= cartera_aviso_config() ?>

    <section class="sch-lecturas" aria-label="Lecturas del periodo">
        <?php if ($canFinance): ?>
            <article class="sch-tarjeta">
                <div class="sch-lectura">
                    <div class="sch-lectura__cab">
                        <span class="sch-lectura__t">Facturado</span>
                        <span class="gas-placa"><?= e((string) $billing['billed']['count']) ?> NCF</span>
                    </div>
                    <div class="sch-lectura__v">RD$ <?= e($kfmt($billing['billed']['value'])) ?></div>
                    <div class="sch-lectura__pie">
                        <span style="display:flex;align-items:center;gap:.4rem">
                            <?= $sch_delta($billing['billed']['delta'] ?? null) ?>
                        </span>
                        <a class="sch-mas" href="<?= url('crm/facturas.php') ?>">Ver más <i data-lucide="arrow-up-right"></i></a>
                    </div>
                </div>
                <div class="sch-acciones">
                    <?php if (current_can('facturas.edit')): ?>
                        <a class="sch-accion" href="<?= url('crm/facturas.php?new=1') ?>">
                            <span class="sch-accion__ic"><i data-lucide="file-plus-2"></i></span>Facturar
                        </a>
                        <a class="sch-accion" href="<?= url('crm/cobro.php') ?>">
                            <span class="sch-accion__ic"><i data-lucide="hand-coins"></i></span>Cobrar
                        </a>
                    <?php endif; ?>
                    <?php if (current_can('cotizaciones.edit')): ?>
                        <a class="sch-accion" href="<?= url('crm/cotizaciones.php?new=1') ?>">
                            <span class="sch-accion__ic"><i data-lucide="file-text"></i></span>Cotizar
                        </a>
                    <?php endif; ?>
                    <a class="sch-accion" href="<?= url('crm/dgii.php') ?>">
                        <span class="sch-accion__ic"><i data-lucide="landmark"></i></span>DGII
                    </a>
                </div>
            </article>

            <article class="sch-tarjeta">
                <div class="sch-lectura">
                    <div class="sch-lectura__cab">
                        <span class="sch-lectura__t">Cobrado</span>
                        <span class="gas-placa"><?= e((string) $billing['collected']['count']) ?> recibos</span>
                    </div>
                    <div class="sch-lectura__v">RD$ <?= e($kfmt($billing['collected']['value'])) ?></div>
                    <div class="sch-lectura__pie">
                        <span style="display:flex;align-items:center;gap:.4rem">
                            <?= $sch_delta($billing['collected']['delta'] ?? null) ?>
                        </span>
                        <a class="sch-mas" href="<?= url('crm/cobro.php') ?>">Registrar <i data-lucide="arrow-up-right"></i></a>
                    </div>
                </div>

                <?php if ($canCartera):
                    $porCobrar = (float) $billing['outstanding']['value'];
                    $vencido   = (float) $billing['outstanding']['overdue_value'];
                    $pctVenc   = $porCobrar > 0.009 ? min(100.0, $vencido / $porCobrar * 100) : 0.0;
                ?>
                    <div class="sch-lectura" style="border-top:1px solid var(--linea);padding-top:.9rem">
                        <div class="sch-lectura__cab">
                            <span class="sch-lectura__t">Por cobrar</span>
                            <span class="gas-placa"><?= e((string) $billing['outstanding']['count']) ?> docs</span>
                        </div>
                        <div class="sch-lectura__v">RD$ <?= e($kfmt($porCobrar)) ?></div>
                        <div class="sch-lectura__pie">
                            <?php if ($vencido > 0.009): ?>
                                <span class="sch-delta sch-delta--riesgo">RD$ <?= e($kfmt($vencido)) ?></span>
                                <span class="sch-lectura__ref">vencido · <?= e((string) round($pctVenc)) ?>% de la cartera</span>
                            <?php else: ?>
                                <span class="sch-delta sch-delta--ok">Al día</span>
                                <span class="sch-lectura__ref">nada vencido</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </article>
        <?php endif; ?>

        <?php if ($canTickets || $canAgenda || $canAssets): ?>
        <article class="sch-tarjeta">
            <?php if ($canTickets): ?>
            <div class="sch-lectura">
                <div class="sch-lectura__cab">
                    <span class="sch-lectura__t">Soporte abierto</span>
                    <?php if ($criticalTickets > 0): ?>
                        <span class="status-chip gas-estado--alarma"><?= e((string) $criticalTickets) ?> de prioridad alta</span>
                    <?php endif; ?>
                </div>
                <div class="sch-lectura__v"><?= e((string) $openTickets) ?> <small>tickets</small></div>
                <div class="sch-lectura__pie">
                    <?php if ($resolution['overdue'] > 0): ?>
                        <span class="sch-delta sch-delta--riesgo"><?= e((string) $resolution['overdue']) ?></span>
                        <span class="sch-lectura__ref">fuera de plazo</span>
                    <?php else: ?>
                        <span class="sch-delta sch-delta--ok">En plazo</span>
                        <span class="sch-lectura__ref">ninguno vencido</span>
                    <?php endif; ?>
                    <a class="sch-mas" href="<?= url('crm/tickets.php') ?>">Atender <i data-lucide="arrow-up-right"></i></a>
                </div>
            </div>
            <?php endif; ?>

            <?php
            /* Mantenimientos vencidos: el trabajo de campo que ya debió hacerse.
               Va aquí porque nace de la misma cola que los tickets. */
            $mantVenc = count($overdueServices);
            ?>
            <?php if ($canAgenda || $canAssets): ?>
            <div class="sch-lectura"<?= $canTickets ? ' style="border-top:1px solid var(--linea);padding-top:.9rem"' : '' ?>>
                <div class="sch-lectura__cab">
                    <span class="sch-lectura__t">Mantenimientos vencidos</span>
                </div>
                <div class="sch-lectura__v"><?= e((string) $mantVenc) ?> <small>equipos</small></div>
                <div class="sch-lectura__pie">
                    <span class="sch-lectura__ref">programados y sin ejecutar</span>
                    <a class="sch-mas" href="<?= url('crm/agenda.php') ?>">Agenda <i data-lucide="arrow-up-right"></i></a>
                </div>
            </div>
            <?php endif; ?>
        </article>
        <?php endif; ?>
    </section>

    <!-- ============ Pipeline stage pills ============ -->
    <?php if ($canFinance && $openQuoteCount > 0): ?>
        <div class="dash-pills">
            <?php foreach ($stages as $name => $s): $pct = round($s['amount'] / $pipelineTotal * 100, 1); ?>
                <a class="dash-pill" href="<?= e(url('crm/cotizaciones.php') . '?status=' . rawurlencode($name)) ?>">
                    <span class="dash-pill__dot" style="background:<?= e($s['color']) ?>"></span>
                    <span class="dash-pill__txt">
                        <b><?= e($money0($s['amount'])) ?></b>
                        <span><?= e($name) ?> · <?= e((string) $pct) ?>% · <?= e((string) $s['count']) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
            <a class="dash-pill dash-pill--cta" href="<?= url('crm/cotizaciones.php') ?>"><span>Detalles <i data-lucide="arrow-right"></i></span></a>
        </div>
    <?php endif; ?>

    <!-- ============ Gráficos del panel ============ -->
    <?php
    /*
     * Cuatro lecturas que no caben en una cifra:
     *
     *   1. Flujo de facturación y cobro — la brecha entre lo emitido y lo que
     *      entró en caja, mes a mes. Es la pregunta que manda en una empresa
     *      que vende a crédito.
     *   2. Cartera por antigüedad — dónde está parado el dinero que falta.
     *   3. Embudo comercial — cuántas propuestas sobreviven cada etapa.
     *   4. Soporte — si la cola crece o se drena.
     *
     * Todo sale de analytics_*(). Ningún dato se inventa: si una serie no
     * existe, la tarjeta lo dice en vez de dibujar una línea plana.
     */
    $trend12 = analytics_monthly_trend(12);
    $flujo = [
        'labels'    => $trend12['labels'],
        'facturado' => array_map(fn ($v) => round((float) $v, 2), $trend12['facturado_raw']),
        'cobrado'   => array_map(fn ($v) => round((float) $v, 2), $trend12['cobrado_raw']),
        'tickets'   => array_map('intval', $trend12['tickets']),
        'resueltos' => array_map('intval', $trend12['resueltos']),
    ];
    $hayFlujo = array_sum($flujo['facturado']) > 0 || array_sum($flujo['cobrado']) > 0;
    $haySoporte = array_sum($flujo['tickets']) > 0 || array_sum($flujo['resueltos']) > 0;

    $forecast = $canCartera ? analytics_cashflow_forecast() : null;
    $embudo = analytics_quote_funnel();
    $embudoTope = max(1, (int) ($embudo[0]['count'] ?? 1));
    ?>

    <?php if ($canFinance || $canTickets): ?>
    <section class="sch-graficos" aria-label="Gráficos del periodo">

        <?php if ($canFinance): ?>
        <!-- Flujo de facturación y cobro -->
        <article class="sch-tarjeta sch-gr sch-gr--ancha" x-data="{ meses: 6 }">
            <div class="sch-gr__cab">
                <div>
                    <h3 class="sch-gr__t">Facturado contra cobrado</h3>
                    <p class="sch-gr__d">Lo emitido y lo que entró en caja, mes a mes. La línea es qué proporción se cobró.</p>
                </div>
                <?php if ($hayFlujo): ?>
                    <div class="dash-seg" role="tablist" aria-label="Meses a mostrar">
                        <button type="button" role="tab" :class="{ 'is-active': meses === 6 }" class="is-active"
                                @click="meses = 6; schFlujo(6)" :aria-selected="meses === 6">6 meses</button>
                        <button type="button" role="tab" :class="{ 'is-active': meses === 12 }"
                                @click="meses = 12; schFlujo(12)" :aria-selected="meses === 12">12 meses</button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($hayFlujo): ?>
                <div class="sch-gr__leyenda" role="list">
                    <button type="button" class="sch-llave is-on" role="listitem" data-serie="0" onclick="schAlternar(0, this)">
                        <i style="background:#027F31"></i>Facturado
                    </button>
                    <button type="button" class="sch-llave is-on" role="listitem" data-serie="1" onclick="schAlternar(1, this)">
                        <i style="background:#9FD4B2"></i>Cobrado
                    </button>
                    <button type="button" class="sch-llave is-on" role="listitem" data-serie="2" onclick="schAlternar(2, this)">
                        <i class="sch-llave__linea" style="background:#6C5E3D"></i>% cobrado
                    </button>
                </div>
                <div class="sch-gr__lienzo sch-gr__lienzo--alta"><canvas id="schFlujoCv"></canvas></div>
            <?php else: ?>
                <div class="sch-gr__vacio">
                    <i data-lucide="bar-chart-3"></i>
                    <strong>Todavía no hay comprobantes emitidos</strong>
                    <p>El gráfico aparece en cuanto exista la primera factura del periodo.</p>
                </div>
            <?php endif; ?>
        </article>

        <!-- Cartera por antigüedad -->
        <?php if ($canCartera && $forecast !== null): ?>
            <?php
            $carteraTotal = (float) $forecast['total']['amount'];
            $anillo = [];
            $tonos = [
                'vencido' => '#C2202C',
                '0-30'    => '#027F31',
                '31-60'   => '#0BA344',
                '61-90'   => '#6C5E3D',
                '90+'     => '#9A8348',
            ];
            foreach ($forecast['buckets'] as $k => $b) {
                if ($b['amount'] <= 0.009) { continue; }
                $anillo[] = [
                    'k'     => $k,
                    'label' => $b['label'],
                    'monto' => round((float) $b['amount'], 2),
                    'docs'  => (int) $b['count'],
                    'color' => $tonos[$k] ?? '#66746D',
                    'pct'   => $carteraTotal > 0.009 ? round($b['amount'] / $carteraTotal * 100) : 0,
                ];
            }
            ?>
            <article class="sch-tarjeta sch-gr">
                <div class="sch-gr__cab">
                    <div>
                        <h3 class="sch-gr__t">Cartera por antigüedad</h3>
                        <p class="sch-gr__d">Dónde está parado lo que falta por cobrar.</p>
                    </div>
                </div>

                <?php if ($anillo): ?>
                    <div class="sch-anillo">
                        <div class="sch-anillo__cv"><canvas id="schCarteraCv"></canvas></div>
                        <div class="sch-anillo__centro">
                            <span>Total</span>
                            <b>RD$ <?= e($kfmt($carteraTotal)) ?></b>
                            <small><?= e((string) $forecast['total']['count']) ?> comprobantes</small>
                        </div>
                    </div>
                    <div class="sch-anillo__lista">
                        <?php foreach ($anillo as $a): ?>
                            <a class="sch-anillo__f" href="<?= url('crm/facturas.php') ?>">
                                <i style="background:<?= e($a['color']) ?>"></i>
                                <span class="sch-anillo__n"><?= e($a['label']) ?></span>
                                <span class="sch-anillo__p"><?= e((string) $a['pct']) ?>%</span>
                                <b>RD$ <?= e($kfmt($a['monto'])) ?></b>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="sch-gr__vacio">
                        <i data-lucide="check-circle-2"></i>
                        <strong>Sin cartera pendiente</strong>
                        <p>Todos los comprobantes emitidos están saldados.</p>
                    </div>
                <?php endif; ?>
            </article>
        <?php endif; ?>

        <!-- Embudo comercial -->
        <article class="sch-tarjeta sch-gr">
            <div class="sch-gr__cab">
                <div>
                    <h3 class="sch-gr__t">Cotizaciones por etapa</h3>
                    <p class="sch-gr__d">Dónde están paradas las propuestas ahora mismo.</p>
                </div>
                <a class="sch-mas" href="<?= url('crm/cotizaciones.php') ?>">Cotizaciones <i data-lucide="arrow-up-right"></i></a>
            </div>
            <div class="sch-embudo">
                <?php
                /*
                 * Esto es una FOTO, no una cohorte. analytics_quote_funnel() cuenta
                 * cotizaciones por estado hoy: la que está en «Aprobado» no pasó
                 * por el «Negociación» de hoy, es otra propuesta distinta. Por eso
                 * la última columna es la parte del total, que sí es cierta, y no
                 * un porcentaje de conversión, que no lo sería.
                 */
                $embudoTotal = max(1, array_sum(array_column($embudo, 'count')));
                foreach ($embudo as $e):
                    $n = (int) $e['count'];
                    // Cero es cero: un mínimo de ancho pintaría color donde no hay nada.
                    $ancho = $n > 0 ? max(3, round($n / $embudoTope * 100)) : 0;
                    $parte = round($n / $embudoTotal * 100);
                    $color = $stages[$e['stage']]['color'] ?? '#66746D';
                ?>
                    <div class="sch-embudo__f">
                        <span class="sch-embudo__n"><?= e($e['stage']) ?></span>
                        <span class="sch-embudo__b">
                            <i style="width:<?= e((string) $ancho) ?>%;background:<?= e($color) ?>"></i>
                        </span>
                        <b class="sch-embudo__v"><?= e((string) $n) ?></b>
                        <span class="sch-embudo__c"><?= e((string) $parte) ?>%</span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="sch-gr__pie">Cuántas hay hoy en cada estado y qué parte del total representa. No mide conversión: para eso haría falta el historial de cambios de estado, que el CRM todavía no guarda.</p>
        </article>
        <?php endif; ?>

        <?php if ($canTickets): ?>
        <!-- Soporte: entrada contra resolución.
             Vivía dentro del bloque financiero, así que el técnico de soporte
             era justo quien NO veía su propia cola. Cuenta tickets, no dinero:
             su permiso es tickets.view. -->
        <article class="sch-tarjeta sch-gr sch-gr--ancha">
            <div class="sch-gr__cab">
                <div>
                    <h3 class="sch-gr__t">Cola de soporte</h3>
                    <p class="sch-gr__d">Tickets que entran contra los que se cierran.</p>
                </div>
                <a class="sch-mas" href="<?= url('crm/tickets.php') ?>">Soporte <i data-lucide="arrow-up-right"></i></a>
            </div>
            <?php if ($haySoporte): ?>
                <div class="sch-gr__leyenda">
                    <span class="sch-llave is-on"><i class="sch-llave__linea" style="background:#6C5E3D"></i>Nuevos</span>
                    <span class="sch-llave is-on"><i class="sch-llave__linea" style="background:#027F31"></i>Resueltos</span>
                </div>
                <div class="sch-gr__lienzo"><canvas id="schSoporteCv"></canvas></div>
            <?php else: ?>
                <div class="sch-gr__vacio">
                    <i data-lucide="life-buoy"></i>
                    <strong>Sin tickets registrados</strong>
                    <p>El gráfico aparece con el primer caso de soporte.</p>
                </div>
            <?php endif; ?>
        </article>
        <?php endif; ?>

    </section>

    <?php if ($canFinance): ?>
    <script>
    window.SCH_FLUJO = <?= json_encode($flujo, JSON_UNESCAPED_UNICODE) ?>;
    window.SCH_CARTERA = <?= json_encode($anillo ?? [], JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ============ Mid row: business lines + monthly accent chart ============ -->
    <section class="dash-mid">
        <?php if ($canFinance): ?>
        <article class="dash-card">
            <div class="dash-card__head">
                <h3><i data-lucide="layers"></i> Línea de negocio <span style="font-weight:600;color:var(--muted);font-size:.78rem">· cotizado</span></h3>
                <?php if (current_can('reportes.view')): ?><a class="dash-card__meta" href="<?= url('crm/reportes.php') ?>">Reporte <i data-lucide="arrow-up-right" class="h-3.5 w-3.5"></i></a><?php endif; ?>
            </div>
            <div class="dash-card__body">
                <?php if ($linesHas): ?>
                    <div class="dash-lines">
                        <?php foreach ($lines as $l): ?>
                            <div class="dash-line">
                                <span class="dash-line__icon" style="background:<?= e($l['color']) ?>1a;color:<?= e($l['color']) ?>"><i data-lucide="<?= e($l['icon']) ?>"></i></span>
                                <div class="dash-line__main">
                                    <b><?= e($l['line']) ?></b>
                                    <div class="dash-line__track"><span class="dash-line__fill" style="width:<?= e((string) max(3, $l['pct'])) ?>%;background:<?= e($l['color']) ?>"></span></div>
                                </div>
                                <div class="dash-line__val"><b><?= e($money0($l['amount'])) ?></b><span><?= e((string) $l['pct']) ?>%</span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="chart-empty"><i data-lucide="layers"></i><strong>Sin ingresos por línea</strong><p>Asigna una línea de negocio a tus cotizaciones para ver este desglose real.</p></div>
                <?php endif; ?>
            </div>
        </article>
        <?php endif; ?>
    </section>

    <!-- ============ Team performance ============ -->
    <article class="dash-card">
        <div class="dash-card__head">
            <h3><i data-lucide="users-round"></i> Desempeño del equipo</h3>
            <span class="dash-card__meta"><?= e($periodLabel) ?></span>
        </div>
        <div class="dash-card__body" style="padding-top:0">
            <?php if ($teamHas): ?>
                <div class="dash-team-wrap">
                    <table class="dash-team-table">
                        <thead>
                            <tr>
                                <th>Integrante</th>
                                <th>Rol</th>
                                <?php if ($canFinance): ?><th>Ingresos</th><?php endif; ?>
                                <th>Cotiz.</th>
                                <th>Resueltos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($team as $i => $m): ?>
                                <tr class="<?= $i === 0 ? 'is-top' : '' ?>">
                                    <td>
                                        <div class="dash-person">
                                            <span class="av av--<?= e($m['tone']) ?>"><?= e($initialsOf($m['name'])) ?></span>
                                            <span class="dash-person__id"><b><?= e($m['name']) ?></b></span>
                                        </div>
                                    </td>
                                    <td><span class="dash-sub" style="text-transform:capitalize"><?= e((string) ($m['role'] ?? '—')) ?></span></td>
                                    <?php if ($canFinance): ?><td><span class="dash-money"><?= e($money0($m['ingresos'])) ?></span></td><?php endif; ?>
                                    <td><span class="dash-badge dash-badge--soft"><?= e((string) (int) $m['cotizaciones']) ?></span></td>
                                    <td><span class="dash-badge dash-badge--green"><?= e((string) (int) $m['resueltos']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="chart-empty"><i data-lucide="users-round"></i><strong>Sin actividad del equipo</strong><p>El desempeño se calcula de cotizaciones creadas y tickets resueltos por cada usuario.</p></div>
            <?php endif; ?>
        </div>
    </article>

    <!-- ============ Inventory by brand ============ -->
    <?php if ($canAssets): ?>
    <article class="dash-card">
        <div class="dash-card__head">
            <h3><i data-lucide="package"></i> Inventario instalado por marca</h3>
            <a class="dash-card__meta" href="<?= url('crm/equipos.php') ?>">Equipos <i data-lucide="arrow-up-right" class="h-3.5 w-3.5"></i></a>
        </div>
        <div class="dash-card__body">
            <?php if ($brandsHas): ?>
                <div class="dash-brands">
                    <?php foreach ($brandRows as $i => $b): $pct = round($b['total'] / max(1, $brandTotal) * 100); $color = $brandPalette[$i % count($brandPalette)]; $mono = mb_strtoupper(mb_substr((string) $b['brand'], 0, 2)); ?>
                        <div class="dash-brand">
                            <span class="dash-brand__mono"><?= e($mono) ?></span>
                            <div class="dash-brand__main">
                                <div class="dash-brand__row"><b><?= e($b['brand']) ?></b><span><?= e((string) $pct) ?>%</span></div>
                                <div class="dash-brand__track"><span class="dash-brand__fill" style="width:<?= e((string) max(3, $pct)) ?>%;background:<?= e($color) ?>"></span></div>
                                <span class="dash-brand__amt"><?= e((string) (int) $b['total']) ?> equipo<?= (int) $b['total'] === 1 ? '' : 's' ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="chart-empty"><i data-lucide="package"></i><strong>Sin inventario registrado</strong><p>Registra equipos instalados para ver la mezcla por fabricante.</p></div>
            <?php endif; ?>
        </div>
    </article>
    <?php endif; ?>

    <?php if ($canTickets || $canAgenda || $canAssets || $canQuotes): ?>
    <p class="dash-section-label">Operación en vivo</p>
    <?php endif; ?>

    <?php if (($canAgenda || $canAssets) && $overdueServices): ?>
        <article class="ops-card sch-caja--alarma">
            <header class="ops-card__head">
                <h3><i data-lucide="alert-triangle" class="h-4 w-4 text-red-600"></i>Mantenimientos vencidos <span class="ops-status bg-red-100 text-red-700"><?= e((string) count($overdueServices)) ?></span></h3>
                <a href="<?= url('crm/agenda.php') ?>">Agenda</a>
            </header>
            <div class="overflow-x-auto">
                <table class="ops-table">
                    <thead><tr><th>Cliente</th><th>Equipo</th><th>Área</th><th>Programado</th><th class="text-right">Días vencido</th></tr></thead>
                    <tbody>
                        <?php foreach ($overdueServices as $ov): $days = max(0, (int) floor((time() - strtotime((string) $ov['next_service_at'])) / 86400)); ?>
                            <tr>
                                <td><strong><?= e($ov['client_name'] ?? 'Cliente') ?></strong></td>
                                <td><?= e($ov['name'] ?? 'Equipo') ?></td>
                                <td><?= e($ov['area'] ?: '—') ?></td>
                                <td class="ops-nowrap"><?= e(date_es($ov['next_service_at'])) ?></td>
                                <td class="text-right"><span class="ops-status gas-estado--alarma"><?= e((string) $days) ?> d</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    <?php endif; ?>

    <!-- ============ Live operational tables (real CRM records) ============ -->
    <?php if ($canTickets): ?>
    <article class="ops-card">
        <header class="ops-card__head">
            <h3><i data-lucide="life-buoy" class="h-4 w-4 text-red-500"></i>Tickets urgentes <span class="ops-status bg-red-100 text-red-700"><?= e((string) count($recentTickets)) ?></span></h3>
            <a href="<?= url('crm/tickets.php') ?>">Ver todos</a>
        </header>
        <?php if ($recentTickets): ?>
            <div class="overflow-x-auto">
                <table class="ops-table ops-table--tickets">
                    <thead>
                        <tr><th>ID</th><th>Cliente</th><th>Asunto</th><th>Prioridad</th><th>Estado</th><th>Técnico</th><th>Creado</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTickets as $ticket): ?>
                            <tr>
                                <td><a href="<?= url('crm/tickets.php?id=' . (int) $ticket['id']) ?>">TK-<?= date('Y') ?>-<?= str_pad((string) $ticket['id'], 4, '0', STR_PAD_LEFT) ?></a></td>
                                <td><?= e($ticket['client_name'] ?? 'Sin cliente') ?></td>
                                <td><?= e($ticket['subject']) ?></td>
                                <td><span class="ops-status <?= e(priority_class($ticket['priority'])) ?>"><?= e($ticket['priority']) ?></span></td>
                                <td><span class="ops-status <?= e(status_class($ticket['status'])) ?>"><?= e($ticket['status']) ?></span></td>
                                <td><?= e($ticket['assigned_name'] ?? 'Sin asignar') ?></td>
                                <td class="ops-nowrap"><?= e(date('d/m H:i', strtotime($ticket['created_at'] ?? 'now'))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="crm-empty"><i data-lucide="check-circle-2" class="h-6 w-6"></i><strong>No hay tickets urgentes</strong><p>Todos los casos están resueltos o no hay tickets abiertos.</p></div>
        <?php endif; ?>
    </article>
    <?php endif; ?>

    <div class="ops-row ops-row--split">
        <?php if ($canTickets && $selectedTicket): ?>
            <article class="ops-card ops-focus">
                <header class="ops-card__head">
                    <h3><i data-lucide="crosshair" class="h-4 w-4 text-sch-blue"></i>Ticket en foco</h3>
                    <a href="<?= url('crm/tickets.php?id=' . (int) $selectedTicket['id']) ?>">Abrir ticket</a>
                </header>
                <div class="ops-focus__body">
                    <div class="ops-focus__id">
                        <strong>TK-<?= date('Y') ?>-<?= str_pad((string) $selectedTicket['id'], 4, '0', STR_PAD_LEFT) ?></strong>
                        <span class="ops-status <?= e(priority_class($selectedTicket['priority'])) ?>"><?= e($selectedTicket['priority']) ?></span>
                        <span class="ops-status <?= e(status_class($selectedTicket['status'])) ?>"><?= e($selectedTicket['status']) ?></span>
                    </div>
                    <p class="ops-focus__subject"><?= e($selectedTicket['subject']) ?></p>
                    <dl class="ops-focus__grid">
                        <div><dt>Cliente</dt><dd><?= e($selectedTicket['client_name'] ?? 'Sin cliente') ?></dd></div>
                        <div><dt>Técnico</dt><dd><?= e($selectedTicket['assigned_name'] ?? 'Sin asignar') ?></dd></div>
                        <div><dt>Equipo</dt><dd><?= e($selectedTicket['equipment_name'] ?? 'Sin equipo') ?><?= !empty($selectedTicket['serial']) ? ' · ' . e($selectedTicket['serial']) : '' ?></dd></div>
                        <div><dt>Creado</dt><dd><?= e(date('d/m/Y H:i', strtotime($selectedTicket['created_at'] ?? 'now'))) ?></dd></div>
                    </dl>
                    <?php if (!empty($selectedTicket['description'])): ?><p class="ops-focus__desc"><?= e($selectedTicket['description']) ?></p><?php endif; ?>
                </div>
                <div class="ops-focus__actions">
                    <a class="ops-action-blue" href="<?= url('crm/tickets.php?id=' . (int) $selectedTicket['id']) ?>"><i data-lucide="user-round-plus" class="h-4 w-4"></i>Gestionar</a>
                    <a class="ops-action-green" href="<?= url('crm/tickets.php?id=' . (int) $selectedTicket['id']) ?>"><i data-lucide="circle-check" class="h-4 w-4"></i>Resolver</a>
                </div>
            </article>
        <?php endif; ?>

        <?php if ($canAgenda): ?>
        <article class="ops-card">
            <header class="ops-card__head">
                <h3><i data-lucide="calendar-days" class="h-4 w-4 text-sch-blue"></i>Próximos servicios</h3>
                <a href="<?= url('crm/agenda.php') ?>">Agenda</a>
            </header>
            <?php $upcoming = analytics_upcoming_services(6); ?>
            <?php if ($upcoming): ?>
                <div class="dash-card__body" style="display:grid;gap:.55rem">
                    <?php foreach ($upcoming as $u): $d = strtotime((string) ($u['next_service_at'] ?? 'now')); ?>
                        <div class="agenda-up">
                            <div class="agenda-up__date">
                                <b><?= e(date('d', $d)) ?></b>
                                <span><?= e(['','ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'][(int) date('n', $d)]) ?></span>
                            </div>
                            <div class="agenda-up__body">
                                <b><?= e($u['client_name'] ?? 'Cliente') ?></b>
                                <span><?= e($u['name'] ?? 'Equipo') ?> · <?= e($u['area'] ?? 'Área') ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="crm-empty"><i data-lucide="calendar-check" class="h-6 w-6"></i><strong>Sin servicios próximos</strong><p>Programa mantenimientos desde la agenda.</p></div>
            <?php endif; ?>
        </article>
        <?php endif; ?>
    </div>

    <?php if ($canQuotes): ?>
    <article class="ops-card">
        <header class="ops-card__head">
            <h3><i data-lucide="file-text" class="h-4 w-4 text-sch-blue"></i>Cotizaciones recientes</h3>
            <a href="<?= url('crm/cotizaciones.php') ?>">Ver todas</a>
        </header>
        <?php if ($quotes): ?>
            <div class="overflow-x-auto">
                <table class="ops-table">
                    <thead>
                        <tr><th>Número</th><th>Cliente</th><th>Asunto</th><th>Etapa</th><?php if ($canFinance): ?><th class="text-right">Valor</th><?php endif; ?><th>Actualizado</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quotes as $quote): ?>
                            <tr>
                                <td><a href="<?= url('crm/cotizaciones.php?action=view&id=' . (int) $quote['id']) ?>"><?= e($quote['quote_number']) ?></a></td>
                                <td><?= e($quote['client_name'] ?? 'Cliente') ?></td>
                                <td><?= e($quote['title']) ?></td>
                                <td><span class="ops-status <?= e(status_class($quote['status'])) ?>"><?= e($quote['status']) ?></span></td>
                                <?php if ($canFinance): ?><td class="text-right ops-nowrap"><?= money_cur($quote['total'], (string) ($quote['currency'] ?? 'DOP')) ?></td><?php endif; ?>
                                <td class="ops-nowrap"><?= e(date_es($quote['updated_at'] ?? $quote['created_at'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="crm-empty"><i data-lucide="file-text" class="h-6 w-6"></i><strong>Aún no hay cotizaciones</strong><p>Crea la primera desde el módulo de cotizaciones.</p></div>
        <?php endif; ?>
    </article>
    <?php endif; ?>

    <div class="ops-row ops-row--split">
        <?php if ($canAssets): ?>
        <article class="ops-card">
            <header class="ops-card__head">
                <h3><i data-lucide="shield-alert" class="h-4 w-4 text-amber-500"></i>Garantías por vencer</h3>
                <a href="<?= url('crm/equipos.php') ?>">Ver todas</a>
            </header>
            <?php if ($maintenance): ?>
                <div class="overflow-x-auto">
                    <table class="ops-table">
                        <thead>
                            <tr><th>Equipo</th><th>Cliente</th><th>Vence</th><th class="text-right">Días</th><th>Estado</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($maintenance as $item): ?>
                                <?php $days = !empty($item['warranty_until']) ? max(0, (int) floor((strtotime($item['warranty_until']) - time()) / 86400)) : 0; ?>
                                <tr>
                                    <td><?= e($item['name']) ?></td>
                                    <td><?= e($item['client_name'] ?? 'Cliente') ?></td>
                                    <td class="ops-nowrap"><?= e(date_es($item['warranty_until'] ?? null)) ?></td>
                                    <td class="text-right"><?= e((string) $days) ?></td>
                                    <td><span class="ops-status <?= $days < 90 ? 'gas-estado--espera' : 'gas-estado--ok' ?>"><?= $days < 90 ? 'Por vencer' : 'Vigente' ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="crm-empty"><i data-lucide="shield-check" class="h-6 w-6"></i><strong>Sin garantías próximas</strong><p>No hay equipos con garantía por vencer.</p></div>
            <?php endif; ?>
        </article>
        <?php endif; ?>

        <?php if ($canTickets): ?>
        <article class="ops-card">
            <header class="ops-card__head">
                <h3><i data-lucide="history" class="h-4 w-4 text-sch-blue"></i>Actividad reciente</h3>
                <?php if (current_can('reportes.view')): ?><a href="<?= url('crm/reportes.php') ?>">Reportes</a><?php endif; ?>
            </header>
            <?php if ($recentTickets): ?>
                <div class="ops-activity">
                    <?php foreach (array_slice($recentTickets, 0, 5) as $i => $ticket): ?>
                        <div class="ops-activity-row">
                            <time><?= e(date('d/m', strtotime($ticket['created_at'] ?? 'now'))) ?></time>
                            <i data-lucide="<?= $i % 2 === 0 ? 'ticket' : 'send' ?>" class="h-4 w-4"></i>
                            <span><b>TK-<?= date('Y') ?>-<?= str_pad((string) $ticket['id'], 4, '0', STR_PAD_LEFT) ?></b> · <?= e($ticket['client_name'] ?? 'Cliente') ?></span>
                            <span><?= e($ticket['assigned_name'] ?? 'Sin asignar') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="crm-empty"><i data-lucide="history" class="h-6 w-6"></i><strong>Sin actividad reciente</strong><p>Los movimientos del CRM aparecerán aquí.</p></div>
            <?php endif; ?>
        </article>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
<?php
    // Only emit financial series/values when the user holds finanzas.view, so
    // the numbers never reach the browser (not even in page source) otherwise.
    $jsTrend = ['cotizaciones' => $trend['cotizaciones'], 'tickets' => $trend['tickets'], 'resueltos' => $trend['resueltos']];
    $jsDashData = [
        'periodo' => $periodLabel,
        'equipo'  => array_map(fn ($t) => array_merge(
            ['nombre' => $t['name'], 'rol' => $t['role'] ?? '', 'cotizaciones' => (int) $t['cotizaciones'], 'resueltos' => (int) $t['resueltos']],
            $canFinance ? ['ingresos' => (float) $t['ingresos']] : []
        ), $team),
        'marcas'  => $canAssets ? array_map(fn ($b) => ['marca' => $b['brand'], 'equipos' => (int) $b['total']], $brandRows) : [],
    ];
    if ($canFinance) {
        $jsTrend['ingresos'] = $trend['ingresos'];
        $jsTrend['facturado'] = $trend['facturado'];
        $jsTrend['cobrado'] = $trend['cobrado'];
        $jsDashData['facturado'] = $billing['billed']['value'];
        $jsDashData['cobrado'] = $billing['collected']['value'];
        $jsDashData['pipeline'] = $pipelineValue;
        $jsDashData['ganado'] = $wonValue;
        $jsDashData['stages'] = array_values(array_map(fn ($k, $v) => ['etapa' => $k, 'monto' => $v['amount'], 'cotizaciones' => $v['count']], array_keys($stages), $stages));
        $jsDashData['lineas'] = array_map(fn ($l) => ['linea' => $l['line'], 'pct' => $l['pct'], 'monto' => $l['amount']], $lines);
    }
?>
    var trend = <?= json_encode($jsTrend, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    var dashData = <?= json_encode($jsDashData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    window.dashExport = function () {
        var fin = (dashData.pipeline != null); // financial data present only with permission
        var rows = [];
        rows.push(['SCH MEDICOS — Panel de operaciones']);
        rows.push(['Periodo', dashData.periodo]);
        if (fin) {
            rows.push(['Valor del pipeline activo (RD$)', dashData.pipeline]);
            rows.push(['Valor ganado (RD$)', dashData.ganado]);
            rows.push([]);
            rows.push(['Pipeline por etapa', 'Monto (RD$)', 'Cotizaciones']);
            dashData.stages.forEach(function (s) { rows.push([s.etapa, s.monto, s.cotizaciones]); });
            rows.push([]);
            rows.push(['Ingresos por línea de negocio', '%', 'Monto (RD$)']);
            dashData.lineas.forEach(function (l) { rows.push([l.linea, l.pct, l.monto]); });
        }
        rows.push([]);
        rows.push(fin ? ['Equipo', 'Rol', 'Ingresos (RD$)', 'Cotizaciones', 'Resueltos'] : ['Equipo', 'Rol', 'Cotizaciones', 'Resueltos']);
        dashData.equipo.forEach(function (t) { rows.push(fin ? [t.nombre, t.rol, t.ingresos, t.cotizaciones, t.resueltos] : [t.nombre, t.rol, t.cotizaciones, t.resueltos]); });
        // Sin permiso de Equipos el arreglo llega vacío: no se escribe ni el
        // encabezado, para no dejar una sección huérfana en el CSV.
        if (dashData.marcas.length) {
            rows.push([]);
            rows.push(['Inventario por marca', 'Equipos']);
            dashData.marcas.forEach(function (b) { rows.push([b.marca, b.equipos]); });
        }
        var csv = rows.map(function (r) {
            return r.map(function (c) {
                c = (c == null ? '' : String(c));
                return /[",\n;]/.test(c) ? '"' + c.replace(/"/g, '""') + '"' : c;
            }).join(';');
        }).join('\r\n');
        window.crmDownload('panel-sch-' + new Date().toISOString().slice(0, 10) + '.csv', '﻿' + csv);
        if (window.crmToast) window.crmToast('Panel exportado a CSV', 'download');
    };

    window.dashShare = function () {
        var url = window.location.href;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(
                function () { if (window.crmToast) window.crmToast('Enlace del panel copiado', 'link'); },
                function () { if (window.crmToast) window.crmToast('Copia manual: ' + url, 'link'); }
            );
        } else if (window.crmToast) {
            window.crmToast('Enlace: ' + url, 'link');
        }
    };

    /* =====================================================================
       GRÁFICOS DEL PANEL
       =====================================================================
       Tres piezas: el flujo de facturación y cobro, el anillo de cartera y la
       cola de soporte. Comparten paleta y comportamiento; ninguna anima sola
       más allá de su entrada. */

    /* Los colores de un gráfico viven en JS, así que no se enteran de que el
       CSS cambió de tema. Se leen en cada pintado y los lienzos se rehacen
       cuando el tema cambia. */
    function schOscuro() { return document.documentElement.getAttribute('data-tema') === 'oscuro'; }

    var SCH;
    function schPaleta() {
        SCH = schOscuro()
            ? { verde: '#0BA344', claro: '#2E7A4C', bronce: '#C0A25C', tinta: '#E9F0EB',
                eje: '#93A79A', red: '#24312A', papel: '#141D18' }
            : { verde: '#027F31', claro: '#9FD4B2', bronce: '#6C5E3D', tinta: '#0F1B14',
                eje: '#66746D', red: '#EDF0F2', papel: '#FFFFFF' };
        return SCH;
    }
    schPaleta();

    /* Formato corto de dinero: el eje no puede llevar siete dígitos por marca. */
    function schCorto(v) {
        v = Number(v) || 0;
        if (Math.abs(v) >= 1e6) { return (v / 1e6).toFixed(2).replace(/\.00$/, '') + 'M'; }
        if (Math.abs(v) >= 1e3) { return Math.round(v / 1e3) + 'k'; }
        return String(Math.round(v));
    }
    function schPesos(v) { return 'RD$ ' + schCorto(v); }

    /* Relleno de área: degradado vertical que da cuerpo a la traza sin tapar
       la cuadrícula. Necesita el contexto del canvas, así que es una función. */
    function schArea(color) {
        return function (ctx) {
            var a = ctx.chart.chartArea;
            if (!a) { return color + '00'; }
            var g = ctx.chart.ctx.createLinearGradient(0, a.top, 0, a.bottom);
            g.addColorStop(0, color + '3D');
            g.addColorStop(1, color + '00');
            return g;
        };
    }

    function schTip() { return Object.assign({}, schTooltip, { backgroundColor: schOscuro() ? 'rgba(6,12,9,.96)' : 'rgba(15,27,20,.95)' }); }
    var schTooltip = {
        backgroundColor: 'rgba(15,27,20,.95)',
        padding: 11,
        cornerRadius: 10,
        titleFont: { weight: '600', size: 12 },
        bodyFont: { size: 12 },
        displayColors: true,
        usePointStyle: true,
        boxPadding: 5
    };

    /* ---- 1 · Facturado contra cobrado --------------------------------------
       Barras para las dos series y una línea con la proporción cobrada en un
       eje propio. La brecha entre las barras ES la lectura. */
    var flujoChart = null;

    window.schFlujo = function (meses) {
        mesesActuales = meses;
        var d = window.SCH_FLUJO;
        var cv = document.getElementById('schFlujoCv');
        if (!d || !cv || !window.Chart) { return; }

        var n = Math.min(meses, d.labels.length);
        var corte = function (a) { return a.slice(-n); };
        var fac = corte(d.facturado);
        var cob = corte(d.cobrado);
        /* Sin base no hay porcentaje: null deja el punto fuera en vez de
           dibujar un cero que se leería como «no se cobró nada». */
        var pct = fac.map(function (f, i) {
            return f > 0 ? Math.round(cob[i] / f * 100) : null;
        });

        if (flujoChart) { flujoChart.destroy(); }
        flujoChart = new Chart(cv, {
            data: {
                labels: corte(d.labels),
                datasets: [
                    {
                        type: 'bar', label: 'Facturado', data: fac,
                        backgroundColor: SCH.verde, borderRadius: 6, borderSkipped: false,
                        maxBarThickness: 26, order: 2, yAxisID: 'y'
                    },
                    {
                        type: 'bar', label: 'Cobrado', data: cob,
                        backgroundColor: SCH.claro, borderRadius: 6, borderSkipped: false,
                        maxBarThickness: 26, order: 3, yAxisID: 'y'
                    },
                    {
                        type: 'line', label: '% cobrado', data: pct,
                        borderColor: SCH.bronce, borderWidth: 2.5, tension: .38,
                        pointRadius: 0, pointHoverRadius: 5,
                        pointHoverBackgroundColor: SCH.bronce,
                        pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2,
                        spanGaps: true, fill: false, order: 1, yAxisID: 'pct'
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                animation: { duration: 520, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: Object.assign(schTip(), {
                        callbacks: {
                            label: function (c) {
                                if (c.dataset.yAxisID === 'pct') {
                                    return c.parsed.y === null ? '% cobrado: sin base' : '% cobrado: ' + c.parsed.y + '%';
                                }
                                return c.dataset.label + ': ' + schPesos(c.parsed.y);
                            }
                        }
                    })
                },
                scales: {
                    x: { grid: { display: false }, border: { display: false },
                         ticks: { color: SCH.eje, font: { weight: '600', size: 11 } } },
                    y: { beginAtZero: true, border: { display: false },
                         grid: { color: SCH.red },
                         ticks: { color: SCH.eje, maxTicksLimit: 5, font: { size: 11 },
                                  callback: function (v) { return schCorto(v); } } },
                    pct: { position: 'right', beginAtZero: true, max: 100,
                           border: { display: false }, grid: { display: false },
                           ticks: { color: SCH.eje, maxTicksLimit: 3, font: { size: 11 },
                                    callback: function (v) { return v + '%'; } } }
                }
            }
        });
    };

    /* Apagar y encender una serie desde la leyenda. */
    window.schAlternar = function (i, el) {
        if (!flujoChart) { return; }
        var visible = flujoChart.isDatasetVisible(i);
        flujoChart.setDatasetVisibility(i, !visible);
        flujoChart.update();
        el.classList.toggle('is-on', !visible);
        el.setAttribute('aria-pressed', String(!visible));
    };

    /* ---- 2 · Cartera por antigüedad ---------------------------------------- */
    var carteraChart = null;
    function buildCartera() {
        var cv = document.getElementById('schCarteraCv');
        if (!cv || !window.Chart) { return; }
        var d = window.SCH_CARTERA || [];
        if (!d.length) { return; }
        if (carteraChart) { carteraChart.destroy(); }
        carteraChart = new Chart(cv, {
            type: 'doughnut',
            data: {
                labels: d.map(function (x) { return x.label; }),
                datasets: [{
                    data: d.map(function (x) { return x.monto; }),
                    backgroundColor: d.map(function (x) { return x.color; }),
                    borderColor: SCH.papel,
                    borderWidth: 3,
                    hoverOffset: 8,
                    hoverBorderColor: SCH.papel
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '72%',
                animation: { duration: 620, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: Object.assign(schTip(), {
                        callbacks: {
                            label: function (c) {
                                var x = d[c.dataIndex];
                                return schPesos(x.monto) + ' · ' + x.docs + ' doc' + (x.docs === 1 ? '' : 's');
                            }
                        }
                    })
                }
            }
        });
    }

    /* ---- 3 · Cola de soporte ----------------------------------------------- */
    var soporteChart = null;
    function buildSoporte() {
        var d = window.SCH_FLUJO;
        var cv = document.getElementById('schSoporteCv');
        if (!d || !cv || !window.Chart) { return; }
        if (soporteChart) { soporteChart.destroy(); }
        var n = Math.min(6, d.labels.length);
        var corte = function (a) { return a.slice(-n); };

        function serie(label, data, color) {
            return {
                label: label, data: data, borderColor: color, backgroundColor: schArea(color),
                borderWidth: 2.5, tension: .38, fill: true,
                pointRadius: 0, pointHoverRadius: 5,
                pointHoverBackgroundColor: color, pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2
            };
        }

        soporteChart = new Chart(cv, {
            type: 'line',
            data: {
                labels: corte(d.labels),
                datasets: [
                    serie('Nuevos', corte(d.tickets), SCH.bronce),
                    serie('Resueltos', corte(d.resueltos), SCH.verde)
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                animation: { duration: 520, easing: 'easeOutQuart' },
                plugins: { legend: { display: false }, tooltip: schTip() },
                scales: {
                    x: { grid: { display: false }, border: { display: false },
                         ticks: { color: SCH.eje, font: { weight: '600', size: 11 } } },
                    y: { beginAtZero: true, border: { display: false }, grid: { color: SCH.red },
                         ticks: { color: SCH.eje, maxTicksLimit: 4, precision: 0, font: { size: 11 } } }
                }
            }
        });
    }

    var mesesActuales = 6;

    function pintarTodo() {
        schPaleta();
        Chart.defaults.color = SCH.eje;
        window.schFlujo(mesesActuales);
        buildCartera();
        buildSoporte();
    }

    function init() {
        if (!window.Chart) { return setTimeout(init, 120); }
        Chart.defaults.font.family = 'Aptos, "Segoe UI", system-ui, sans-serif';
        pintarTodo();
        window.addEventListener('sch:tema', pintarTodo);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
</script>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
