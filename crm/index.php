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
$wonDelta = (float) $kpis['won']['delta'];
$winRate  = (float) $kpis['win_rate']['value'];
$openTickets = (int) $kpis['open_tickets']['value'];
$criticalTickets = $hasDb && table_exists('tickets') ? db_count('tickets', "priority IN ('Critica','Alta') AND status NOT IN ('Resuelto','Cerrado')") : ($demo ? 3 : 0);

$stats = ['open' => $openTickets, 'quotes' => $openQuoteCount];

// Financial figures (pipeline, ingresos, montos) are role-gated: only users with
// the "Datos financieros" permission (finanzas.view) see money on the dashboard.
$canFinance = current_can('finanzas.view');
$defaultMetric = $canFinance ? 'ingresos' : 'tickets';

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
    $bq = fetch_one('SELECT clients.name, quotes.total FROM quotes LEFT JOIN clients ON clients.id = quotes.client_id ORDER BY quotes.total DESC LIMIT 1');
    $bestQuote = $bq ? ['client' => $bq['name'] ?? 'Cliente', 'amount' => (float) $bq['total']] : ['client' => '—', 'amount' => 0.0];
} else {
    $bestQuote = ['client' => 'Hospital Metropolitano', 'amount' => 486200.0];
}

/* ---- Presence flags ----------------------------------------------------- */
$linesHas  = !empty($lines) && array_sum(array_column($lines, 'amount')) > 0;
$brandTotal = array_sum(array_map(fn ($b) => (int) $b['total'], $brandRows)) ?: 0;
$brandsHas = $brandTotal > 0;
$trendHas  = array_sum($trend['ingresos_raw']) > 0 || array_sum($trend['cotizaciones']) > 0 || array_sum($trend['tickets']) > 0;
$brandPalette = ['#0a7d36', '#0666b3', '#1bb6c2', '#9c7d34', '#94a3b8'];

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
$delta_chip = function (float $d): string {
    $up = $d >= 0;
    return '<span class="dash-delta ' . ($up ? 'dash-delta--up' : 'dash-delta--down') . '"><i data-lucide="' . ($up ? 'trending-up' : 'trending-down') . '"></i>' . ($up ? '+' : '') . e((string) $d) . '%</span>';
};

/** Inline SVG area sparkline from a numeric series. */
function spark_svg(array $pts, string $id, string $stroke = '#0a7d36'): string
{
    $n = count($pts);
    if ($n < 2) return '';
    $min = min($pts); $max = max($pts); $range = ($max - $min) ?: 1;
    $w = 100.0; $h = 40.0; $pad = 4.0;
    $coords = [];
    foreach (array_values($pts) as $i => $p) {
        $x = $i / ($n - 1) * $w;
        $y = $h - $pad - (($p - $min) / $range) * ($h - 2 * $pad);
        $coords[] = round($x, 2) . ',' . round($y, 2);
    }
    $line = 'M' . implode(' L', $coords);
    $area = $line . " L{$w},{$h} L0,{$h} Z";
    $lastParts = explode(',', end($coords));
    return '<svg viewBox="0 0 100 40" preserveAspectRatio="none" role="img" aria-hidden="true">'
        . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0%" stop-color="' . $stroke . '" stop-opacity=".22"/>'
        . '<stop offset="100%" stop-color="' . $stroke . '" stop-opacity="0"/>'
        . '</linearGradient></defs>'
        . '<path d="' . $area . '" fill="url(#' . $id . ')"/>'
        . '<path d="' . $line . '" fill="none" stroke="' . $stroke . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>'
        . '<circle cx="' . $lastParts[0] . '" cy="' . $lastParts[1] . '" r="2.6" fill="' . $stroke . '" vector-effect="non-scaling-stroke"/>'
        . '</svg>';
}

$heroParts = explode('.', number_format($pipelineValue, 2, '.', ','));

/* Monthly accent-chart metadata derived from real trend */
$avgOf = fn (array $a) => count($a) ? array_sum($a) / count($a) : 0;
$lastIngreso = $trend['ingresos_raw'] ? (float) end($trend['ingresos_raw']) : 0.0;
$lastCot = $trend['cotizaciones'] ? (int) end($trend['cotizaciones']) : 0;
$lastTk = $trend['tickets'] ? (int) end($trend['tickets']) : 0;
$monthlyMeta = [
    'ingresos'     => ['label' => 'RD$ ' . $kfmt($lastIngreso), 'avg' => 'RD$ ' . $kfmt($avgOf($trend['ingresos_raw'])), 'meta' => 'RD$ ' . $kfmt($trend['ingresos_raw'] ? max($trend['ingresos_raw']) : 0)],
    'cotizaciones' => ['label' => $lastCot . ' cotiz.', 'avg' => round($avgOf($trend['cotizaciones'])) . ' cotiz.', 'meta' => ($trend['cotizaciones'] ? max($trend['cotizaciones']) : 0) . ' cotiz.'],
    'tickets'      => ['label' => $lastTk . ' tickets', 'avg' => round($avgOf($trend['tickets'])) . ' tickets', 'meta' => ($trend['tickets'] ? max($trend['tickets']) : 0) . ' tickets'],
];

$crmTitle = 'Panel de operaciones';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-xs font-semibold text-amber-800">
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

            <a href="<?= url('crm/reportes.php') ?>" class="dash-chip"><i data-lucide="bar-chart-3"></i><span class="dash-chip__hide">Reportes</span></a>
            <button type="button" class="dash-iconbtn" @click="window.dashExport()" aria-label="Exportar CSV" title="Exportar CSV"><i data-lucide="download"></i></button>
            <button type="button" class="dash-iconbtn dash-iconbtn--solid" @click="window.dashShare()" aria-label="Compartir panel" title="Compartir panel"><i data-lucide="share-2"></i></button>
        </div>
    </div>

    <!-- ============ Summary band: hero + KPI cluster ============ -->
    <section class="dash-summary">
        <?php if ($canFinance): ?>
        <article class="dash-hero">
            <p class="dash-hero__label"><span class="dot"></span> Valor del pipeline activo</p>
            <div class="dash-hero__value">
                <span class="cur">RD$</span><?= e($heroParts[0]) ?><span class="dec">.<?= e($heroParts[1] ?? '00') ?></span>
            </div>
            <div class="dash-hero__meta">
                <?= $delta_chip($wonDelta) ?>
                <span class="dash-delta dash-delta--solid">Ganado <?= e($money0($wonValue)) ?></span>
            </div>
            <p class="dash-hero__sub"><b><?= e((string) $openQuoteCount) ?></b> cotizaciones abiertas · <?= e($periodLabel) ?></p>
            <?php if ($trendHas): ?><div class="dash-hero__spark"><?= spark_svg($trend['ingresos'], 'heroSpark', '#0a7d36') ?></div><?php endif; ?>
        </article>
        <?php else: ?>
        <article class="dash-hero">
            <p class="dash-hero__label"><span class="dot"></span> Tickets abiertos</p>
            <div class="dash-hero__value"><?= e((string) $openTickets) ?></div>
            <div class="dash-hero__meta">
                <span class="dash-delta dash-delta--solid"><?= e((string) $criticalTickets) ?> de alta prioridad</span>
            </div>
            <p class="dash-hero__sub"><b><?= e((string) count($overdueServices)) ?></b> mantenimientos vencidos · <?= e($periodLabel) ?></p>
        </article>
        <?php endif; ?>

        <div class="dash-kpis">
            <article class="dash-kpi dash-kpi--feature">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label">Top técnico</span>
                    <span class="dash-kpi__icon"><i data-lucide="award"></i></span>
                </div>
                <div class="dash-kpi__value"><?= e((string) $topTech['metric']) ?> <small><?= e($topTech['metricLabel']) ?></small></div>
                <div class="dash-kpi__foot">
                    <span class="av av--green"><?= e($topTech['initials']) ?></span>
                    <span><?= e($topTech['name']) ?></span>
                </div>
            </article>

            <?php if ($canFinance): ?>
            <article class="dash-kpi dash-kpi--dark">
                <span class="dash-kpi__star"><i data-lucide="star"></i></span>
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label">Mejor cotización</span>
                </div>
                <div class="dash-kpi__value"><span class="amt"><?= e($money0($bestQuote['amount'])) ?></span></div>
                <div class="dash-kpi__foot">
                    <i data-lucide="building-2" class="h-4 w-4" style="color:#9fb2c4"></i>
                    <span><?= e($bestQuote['client']) ?></span>
                </div>
            </article>
            <?php endif; ?>

            <article class="dash-kpi">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label">Tickets abiertos</span>
                    <span class="dash-kpi__icon dash-kpi__icon--amber"><i data-lucide="life-buoy"></i></span>
                </div>
                <div class="dash-kpi__value"><?= e((string) $stats['open']) ?></div>
                <div class="dash-kpi__foot"><span class="dash-sub"><?= e((string) $criticalTickets) ?> de alta prioridad</span></div>
            </article>

            <?php if ($canFinance): ?>
            <article class="dash-kpi">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label">Valor ganado</span>
                    <span class="dash-kpi__icon dash-kpi__icon--gold"><i data-lucide="wallet"></i></span>
                </div>
                <div class="dash-kpi__value">RD$ <?= e($kfmt($wonValue)) ?></div>
                <div class="dash-kpi__foot"><?= $delta_chip($wonDelta) ?><span class="dash-sub"><?= e((string) $stats['quotes']) ?> activas</span></div>
            </article>
            <?php endif; ?>

            <article class="dash-kpi">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label">Tasa de cierre</span>
                    <span class="dash-kpi__icon"><i data-lucide="target"></i></span>
                </div>
                <div class="dash-kpi__value"><?= e((string) $winRate) ?>%</div>
                <div class="dash-kpi__foot"><span class="dash-sub">aprobadas / cerradas · histórico</span></div>
            </article>

            <?php if (!$canFinance): ?>
            <article class="dash-kpi">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label">Mantenimientos vencidos</span>
                    <span class="dash-kpi__icon dash-kpi__icon--amber"><i data-lucide="alert-triangle"></i></span>
                </div>
                <div class="dash-kpi__value"><?= e((string) count($overdueServices)) ?></div>
                <div class="dash-kpi__foot"><span class="dash-sub">requieren atención</span></div>
            </article>
            <article class="dash-kpi">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label">Garantías por vencer</span>
                    <span class="dash-kpi__icon"><i data-lucide="shield-alert"></i></span>
                </div>
                <div class="dash-kpi__value"><?= e((string) count($maintenance)) ?></div>
                <div class="dash-kpi__foot"><span class="dash-sub">próximas a expirar</span></div>
            </article>
            <?php endif; ?>
        </div>
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

    <!-- ============ Mid row: business lines + monthly accent chart ============ -->
    <section class="dash-mid">
        <?php if ($canFinance): ?>
        <article class="dash-card">
            <div class="dash-card__head">
                <h3><i data-lucide="layers"></i> Ingresos por línea de negocio</h3>
                <a class="dash-card__meta" href="<?= url('crm/reportes.php') ?>">Reporte <i data-lucide="arrow-up-right" class="h-3.5 w-3.5"></i></a>
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

        <article class="dash-accent" x-data="{ metric: '<?= $defaultMetric ?>' }">
            <div class="dash-accent__head">
                <div>
                    <h3>Promedio mensual</h3>
                    <p>Últimos 6 meses · <?= date('Y') ?></p>
                </div>
                <?php if ($trendHas): ?>
                    <div class="dash-seg" role="tablist" aria-label="Métrica del gráfico">
                        <?php if ($canFinance): ?><button type="button" class="is-active" :class="{ 'is-active': metric==='ingresos' }" @click="metric='ingresos'; dashSetMonthly('ingresos')">Ingresos</button><?php endif; ?>
                        <button type="button" class="<?= $defaultMetric === 'cotizaciones' ? 'is-active' : '' ?>" :class="{ 'is-active': metric==='cotizaciones' }" @click="metric='cotizaciones'; dashSetMonthly('cotizaciones')">Cotiz.</button>
                        <button type="button" class="<?= $defaultMetric === 'tickets' ? 'is-active' : '' ?>" :class="{ 'is-active': metric==='tickets' }" @click="metric='tickets'; dashSetMonthly('tickets')">Tickets</button>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($trendHas): ?>
                <div class="dash-accent__value">
                    <span id="dashMonthlyValue"><?= e($monthlyMeta[$defaultMetric]['label']) ?></span>
                </div>
                <div class="dash-accent__chart"><canvas id="dashMonthly"></canvas></div>
                <div class="dash-accent__foot"><span>Promedio: <b id="dashMonthlyAvg" style="color:#fff"><?= e($monthlyMeta[$defaultMetric]['avg']) ?></b></span><span>Máximo: <b id="dashMonthlyMeta" style="color:#fff"><?= e($monthlyMeta[$defaultMetric]['meta']) ?></b></span></div>
            <?php else: ?>
                <div class="dash-accent__chart" style="display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.75);text-align:center;padding:1.5rem">
                    <div><i data-lucide="bar-chart-2" style="width:28px;height:28px;opacity:.7"></i><p style="margin-top:.5rem;font-size:.85rem">Aún no hay actividad mensual registrada.</p></div>
                </div>
            <?php endif; ?>
        </article>
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
                            <span class="dash-brand__mono" style="background:<?= e($color) ?>1a;color:<?= e($color) ?>"><?= e($mono) ?></span>
                            <div class="dash-brand__main">
                                <div class="dash-brand__row"><b><?= e($b['brand']) ?></b><span style="color:<?= e($color) ?>"><?= e((string) $pct) ?>%</span></div>
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

    <!-- ============ Service dynamics line chart ============ -->
    <article class="dash-card">
        <div class="dash-card__head">
            <h3><i data-lucide="activity"></i> Dinámica mensual de servicio</h3>
            <div class="dash-legend">
                <span style="--c:#0666b3">Tickets nuevos</span>
                <span style="--c:#0a7d36">Resueltos</span>
                <span style="--c:#9c7d34">Cotizaciones</span>
            </div>
        </div>
        <div class="dash-card__body" style="padding-bottom:.6rem">
            <?php if ($trendHas): ?>
                <div class="dash-dynamic__chart"><canvas id="dashDynamic"></canvas></div>
            <?php else: ?>
                <div class="chart-empty"><i data-lucide="activity"></i><strong>Sin movimiento aún</strong><p>La dinámica de tickets y cotizaciones aparecerá con la operación diaria.</p></div>
            <?php endif; ?>
        </div>
    </article>

    <p class="dash-section-label">Operación en vivo</p>

    <?php if ($overdueServices): ?>
        <article class="ops-card" style="border-color:#fecaca;background:linear-gradient(180deg,#fef2f2,#fff)">
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
                                <td class="text-right"><span class="ops-status bg-red-50 text-red-700 ring-1 ring-red-200"><?= e((string) $days) ?> d</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    <?php endif; ?>

    <!-- ============ Live operational tables (real CRM records) ============ -->
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

    <div class="ops-row ops-row--split">
        <?php if ($selectedTicket): ?>
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
    </div>

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
                                <?php if ($canFinance): ?><td class="text-right ops-nowrap"><?= money($quote['total']) ?></td><?php endif; ?>
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

    <div class="ops-row ops-row--split">
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
                                    <td><span class="ops-status <?= $days < 90 ? 'bg-amber-50 text-amber-700 ring-1 ring-amber-200' : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' ?>"><?= $days < 90 ? 'Por vencer' : 'Vigente' ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="crm-empty"><i data-lucide="shield-check" class="h-6 w-6"></i><strong>Sin garantías próximas</strong><p>No hay equipos con garantía por vencer.</p></div>
            <?php endif; ?>
        </article>

        <article class="ops-card">
            <header class="ops-card__head">
                <h3><i data-lucide="history" class="h-4 w-4 text-sch-blue"></i>Actividad reciente</h3>
                <a href="<?= url('crm/reportes.php') ?>">Reportes</a>
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
    </div>
</div>

<script>
(function () {
<?php
    // Only emit financial series/values when the user holds finanzas.view, so
    // the numbers never reach the browser (not even in page source) otherwise.
    $jsTrend = ['cotizaciones' => $trend['cotizaciones'], 'tickets' => $trend['tickets'], 'resueltos' => $trend['resueltos']];
    $jsMonthlyMeta = ['cotizaciones' => $monthlyMeta['cotizaciones'], 'tickets' => $monthlyMeta['tickets']];
    $jsDashData = [
        'periodo' => $periodLabel,
        'equipo'  => array_map(fn ($t) => array_merge(
            ['nombre' => $t['name'], 'rol' => $t['role'] ?? '', 'cotizaciones' => (int) $t['cotizaciones'], 'resueltos' => (int) $t['resueltos']],
            $canFinance ? ['ingresos' => (float) $t['ingresos']] : []
        ), $team),
        'marcas'  => array_map(fn ($b) => ['marca' => $b['brand'], 'equipos' => (int) $b['total']], $brandRows),
    ];
    if ($canFinance) {
        $jsTrend['ingresos'] = $trend['ingresos'];
        $jsMonthlyMeta['ingresos'] = $monthlyMeta['ingresos'];
        $jsDashData['pipeline'] = $pipelineValue;
        $jsDashData['ganado'] = $wonValue;
        $jsDashData['stages'] = array_values(array_map(fn ($k, $v) => ['etapa' => $k, 'monto' => $v['amount'], 'cotizaciones' => $v['count']], array_keys($stages), $stages));
        $jsDashData['lineas'] = array_map(fn ($l) => ['linea' => $l['line'], 'pct' => $l['pct'], 'monto' => $l['amount']], $lines);
    }
?>
    var trend = <?= json_encode($jsTrend, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var trendLabels = <?= json_encode($trend['labels'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var monthlyMeta = <?= json_encode($jsMonthlyMeta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

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
        rows.push([]);
        rows.push(['Inventario por marca', 'Equipos']);
        dashData.marcas.forEach(function (b) { rows.push([b.marca, b.equipos]); });
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

    var monthlyChart = null;
    function buildMonthly(metric) {
        var ctx = document.getElementById('dashMonthly');
        if (!ctx || !window.Chart) return;
        var data = trend[metric];
        var last = data.length - 1;
        var colors = data.map(function (_, i) { return i === last ? 'rgba(255,255,255,.95)' : 'rgba(255,255,255,.38)'; });
        var fmt = metric === 'ingresos' ? function (v) { return 'RD$ ' + v + 'k'; } : (metric === 'cotizaciones' ? function (v) { return v + ' cotiz.'; } : function (v) { return v + ' tickets'; });
        if (monthlyChart) {
            monthlyChart.data.datasets[0].data = data;
            monthlyChart.data.datasets[0].backgroundColor = colors;
            monthlyChart.options.plugins.tooltip.callbacks.label = function (c) { return fmt(c.parsed.y); };
            monthlyChart.update();
            return;
        }
        monthlyChart = new Chart(ctx, {
            type: 'bar',
            data: { labels: trendLabels, datasets: [{ data: data, backgroundColor: colors, borderRadius: 6, borderSkipped: false, maxBarThickness: 30 }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(8,18,30,.92)', padding: 10, displayColors: false, callbacks: { label: function (c) { return fmt(c.parsed.y); } } } },
                scales: { x: { grid: { display: false }, border: { display: false }, ticks: { color: 'rgba(255,255,255,.78)', font: { weight: '600', size: 11 } } }, y: { display: false, beginAtZero: true, grace: '12%' } }
            }
        });
    }

    window.dashSetMonthly = function (metric) {
        buildMonthly(metric);
        var m = monthlyMeta[metric];
        if (!m) return;
        var vEl = document.getElementById('dashMonthlyValue');
        var aEl = document.getElementById('dashMonthlyAvg');
        var mEl = document.getElementById('dashMonthlyMeta');
        if (vEl) vEl.textContent = m.label;
        if (aEl) aEl.textContent = m.avg;
        if (mEl) mEl.textContent = m.meta;
    };

    function buildDynamic() {
        var ctx = document.getElementById('dashDynamic');
        if (!ctx || !window.Chart) return;
        function ds(data, color) {
            return { data: data, borderColor: color, backgroundColor: color + '22', borderWidth: 2.5, tension: .4, fill: true, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: color, pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2 };
        }
        new Chart(ctx, {
            type: 'line',
            data: { labels: trendLabels, datasets: [
                Object.assign({ label: 'Tickets nuevos' }, ds(trend.tickets, '#0666b3')),
                Object.assign({ label: 'Resueltos' }, ds(trend.resueltos, '#0a7d36')),
                Object.assign({ label: 'Cotizaciones' }, ds(trend.cotizaciones, '#9c7d34'))
            ] },
            options: {
                responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(8,18,30,.92)', padding: 10, cornerRadius: 8, usePointStyle: true } },
                scales: { x: { grid: { display: false }, border: { display: false }, ticks: { color: '#56697b', font: { weight: '600', size: 11 } } }, y: { beginAtZero: true, border: { display: false }, grid: { color: '#eef3f8' }, ticks: { color: '#8696a6', maxTicksLimit: 5, font: { size: 11 } } } }
            }
        });
    }

    function init() {
        if (!window.Chart) { return setTimeout(init, 120); }
        Chart.defaults.font.family = "Inter, system-ui, sans-serif";
        buildMonthly('<?= $defaultMetric ?>');
        buildDynamic();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
</script>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
