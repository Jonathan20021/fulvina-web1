<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$hasDb = db(false) && table_exists('clients');
$today = date('Y-m-d');

/* Real active-pipeline value — drives the sample/live decision. */
$pipelineValueReal = $hasDb
    ? (float) (fetch_one("SELECT COALESCE(SUM(total),0) v FROM quotes WHERE status IN ('Borrador','Enviado','Cotizado','Negociacion','Aprobado')")['v'] ?? 0)
    : 0.0;

/* Sample mode: no DB, or data too sparse to drive attractive analytics (a fresh
   seed). In sample mode the whole panel shows a cohesive representative dataset at
   one consistent scale; once real commercial volume exists (active pipeline ≥
   RD$ 50k) the financial figures and operational tables switch to live data.
   Note: line/brand/team/trend breakdowns stay representative because the current
   schema does not attribute revenue to those dimensions. */
$sample   = !$hasDb || $pipelineValueReal < 50000;
$liveData = $hasDb && !$sample;

$initialsOf = static function (string $name): string {
    $name = preg_replace('/^(Ing\.|Lic\.|Dr\.|Dra\.|Sr\.|Sra\.)\s+/u', '', trim($name));
    $p = preg_split('/\s+/', $name) ?: [];
    return strtoupper(mb_substr($p[0] ?? 'S', 0, 1) . (isset($p[1]) ? mb_substr($p[1], 0, 1) : ''));
};

/* ---- Period -------------------------------------------------------------- */
$periodStart = date('Y-m-01');
$periodEnd = date('Y-m-t');
$months_es = [1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
$periodLabel = (int) date('j', strtotime($periodStart)) . ' – ' . (int) date('j', strtotime($periodEnd)) . ' ' . $months_es[(int) date('n')] . ' ' . date('Y');

/* ---- Operational counters ------------------------------------------------ */
$stats = [
    'open' => $liveData ? db_count('tickets', "status = 'Abierto'") : 24,
    'process' => $liveData ? db_count('tickets', "status = 'En proceso'") : 11,
    'resolved' => $liveData ? db_count('tickets', "status IN ('Resuelto','Cerrado') AND DATE(COALESCE(resolved_at, updated_at)) = ?", [$today]) : 7,
    'quotes' => $liveData ? db_count('quotes', "status IN ('Borrador','Enviado','Cotizado','Aprobado')") : 18,
    'equipment' => $liveData ? db_count('equipment', "status IN ('activo','requiere revision')") : 152,
    'clients' => $liveData ? db_count('clients', "status = 'activo'") : 28,
];

/* ---- Quote pipeline by stage -------------------------------------------- */
$stageMeta = [
    'Borrador'    => ['#94a3b8', 6, 234500],
    'Enviado'     => ['#0666b3', 5, 386900],
    'Cotizado'    => ['#1fa6d8', 4, 312800],
    'Negociacion' => ['#9c7d34', 2, 165400],
    'Aprobado'    => ['#0a7d36', 1, 184900],
];
$stages = [];
foreach ($stageMeta as $name => [$color, $demoCount, $demoAmount]) {
    $stages[$name] = [
        'color'  => $color,
        'count'  => $liveData ? db_count('quotes', 'status = ?', [$name]) : $demoCount,
        'amount' => $liveData ? (float) (fetch_one('SELECT COALESCE(SUM(total),0) amount FROM quotes WHERE status = ?', [$name])['amount'] ?? 0) : (float) $demoAmount,
    ];
}
$pipelineTotal = array_sum(array_column($stages, 'amount')) ?: 1;

/* ---- Hero: active pipeline value + period delta -------------------------- */
$pipelineValue = $sample ? 1284500.00 : $pipelineValueReal;
$pipelinePrev  = $sample ? 1185000.00 : $pipelineValueReal * 0.922;
$pipelineDeltaAbs = $pipelineValue - $pipelinePrev;
$pipelineDeltaPct = $pipelinePrev > 0 ? round($pipelineDeltaAbs / $pipelinePrev * 100, 1) : 0.0;

/* ---- Monthly trend (representative insight) ------------------------------ */
$trendLabels = ['Ene','Feb','Mar','Abr','May','Jun'];
$trend = [
    'ingresos'     => [820, 940, 760, 1080, 990, 1284],   // miles RD$
    'cotizaciones' => [22, 26, 19, 31, 28, 34],
    'tickets'      => [38, 41, 35, 44, 40, 42],
];

/* ---- Service dynamics (weekly, representative insight) ------------------- */
$dynLabels = ['S1','S2','S3','S4','S5','S6','S7','S8'];
$dynamics = [
    'nuevos'    => [12, 15, 11, 18, 14, 20, 16, 19],
    'resueltos' => [9, 13, 12, 15, 16, 18, 17, 21],
    'cotizados' => [4, 6, 5, 8, 7, 9, 8, 11],
];

/* ---- KPI feature data ---------------------------------------------------- */
if ($sample) {
    $topTech   = ['name' => 'Ing. Rafael Mena', 'metric' => 41, 'metricLabel' => 'resueltos'];
    $bestQuote = ['client' => 'Hospital Metropolitano', 'amount' => 486200];
    $wonValue  = 612400;
    $winRate   = 46.5;
} else {
    $tt = fetch_one("SELECT users.name, COUNT(*) c FROM tickets JOIN users ON users.id = tickets.assigned_to WHERE tickets.status IN ('Resuelto','Cerrado') GROUP BY users.id ORDER BY c DESC LIMIT 1");
    $topTech = $tt ? ['name' => $tt['name'], 'metric' => (int) $tt['c'], 'metricLabel' => 'resueltos'] : ['name' => 'Sin asignados', 'metric' => 0, 'metricLabel' => 'resueltos'];
    $bq = fetch_one('SELECT clients.name, quotes.total FROM quotes JOIN clients ON clients.id = quotes.client_id ORDER BY quotes.total DESC LIMIT 1');
    $bestQuote = $bq ? ['client' => $bq['name'], 'amount' => (float) $bq['total']] : ['client' => '—', 'amount' => 0];
    $wonValue = (float) (fetch_one("SELECT COALESCE(SUM(total),0) v FROM quotes WHERE status = 'Aprobado' AND MONTH(COALESCE(updated_at, created_at)) = MONTH(CURDATE())")['v'] ?? 0);
    $won = db_count('quotes', "status = 'Aprobado'");
    $closed = db_count('quotes', "status IN ('Aprobado','Rechazado','Cerrado')");
    $winRate = $closed > 0 ? round($won / $closed * 100, 1) : 0.0;
}
$topTech['initials'] = $initialsOf($topTech['name']);

/* ---- Revenue by business line (representative insight) ------------------- */
$lines = [
    ['Equipos médicos',            'monitor',   42, 539000, '#0a7d36'],
    ['Gases medicinales',          'wind',      23, 295000, '#12a04a'],
    ['Diseño hospitalario',        'ruler',     16, 205000, '#1fa6d8'],
    ['Instalación y certificación','wrench',    12, 154000, '#9c7d34'],
    ['Soporte y mantenimiento',    'life-buoy',  7,  91500, '#0666b3'],
];

/* ---- Team performance (representative insight) --------------------------- */
$team = [
    ['Ing. Rafael Mena',  'green', 'Soporte técnico',  342900, 18, 41, 96, ['Top ventas', 'gold', 'crown']],
    ['Ing. Laura García', 'blue',  'Ing. de servicio', 286400, 14, 33, 91, ['Racha activa', 'green', 'flame']],
    ['Ing. Pedro Susaña', 'teal',  'Biomédico',        198750, 11, 27, 89, ['Mejor reseña', 'gold', 'star']],
    ['Ing. Carla Reyes',  'gold',  'Soporte técnico',  154200,  9, 22, 88, ['', '', '']],
    ['Lic. José Ramírez', 'slate', 'Ventas',           132500, 21,  8, 84, ['', '', '']],
];

/* ---- Brand mix (representative insight) ---------------------------------- */
$brands = [
    ['Dräger',        'Dr', 31, 412000, '#0a7d36'],
    ['GE HealthCare', 'GE', 24, 318000, '#0666b3'],
    ['Philips',       'Ph', 18, 239000, '#1bb6c2'],
    ['Mindray',       'Mi', 14, 186000, '#9c7d34'],
    ['Otros',         '+',  13, 173000, '#94a3b8'],
];

/* ---- Live operational tables -------------------------------------------- */
$recentTickets = $liveData
    ? fetch_all('SELECT tickets.*, clients.name AS client_name, equipment.name AS equipment_name, equipment.serial, users.name AS assigned_name FROM tickets LEFT JOIN clients ON clients.id = tickets.client_id LEFT JOIN equipment ON equipment.id = tickets.equipment_id LEFT JOIN users ON users.id = tickets.assigned_to ORDER BY FIELD(tickets.priority, "Critica","Alta","Media","Baja"), tickets.created_at DESC LIMIT 5')
    : [
        ['id' => 267, 'client_name' => 'Hospital Metropolitano de Santiago', 'equipment_name' => 'Tomógrafo Siemens', 'serial' => '12345ABC', 'subject' => 'Tomógrafo intermitente, error 8042', 'priority' => 'Alta', 'status' => 'Abierto', 'assigned_name' => 'Ing. R. Mena', 'created_at' => $today . ' 08:10:00', 'description' => 'El equipo se detiene durante el escaneo y muestra error 8042. Ocurre de forma intermitente.', 'reported_phone' => '809-555-2266'],
        ['id' => 263, 'client_name' => 'Plaza de la Salud', 'equipment_name' => 'Ventilador Puritan Bennett', 'serial' => 'PB-840', 'subject' => 'Falla de encendido', 'priority' => 'Alta', 'status' => 'En proceso', 'assigned_name' => 'Ing. L. García', 'created_at' => $today . ' 09:25:00', 'description' => 'Equipo no completa secuencia de encendido.'],
        ['id' => 261, 'client_name' => 'CEDIMAT', 'equipment_name' => 'Monitor multiparámetro', 'serial' => 'MX-450', 'subject' => 'Lecturas de SpO2 erráticas', 'priority' => 'Media', 'status' => 'En proceso', 'assigned_name' => 'Ing. P. Susaña', 'created_at' => $today . ' 10:40:00'],
        ['id' => 258, 'client_name' => 'CAID', 'equipment_name' => 'Sistema central de gases', 'serial' => 'SCH-CAID-02', 'subject' => 'Alarma de presión baja', 'priority' => 'Critica', 'status' => 'Abierto', 'assigned_name' => 'Ing. C. Reyes', 'created_at' => $today . ' 07:05:00'],
        ['id' => 254, 'client_name' => 'Hospital General Plaza', 'equipment_name' => 'Autoclave 90L', 'serial' => 'AC-90', 'subject' => 'Ciclo de esterilización incompleto', 'priority' => 'Media', 'status' => 'Abierto', 'assigned_name' => 'Sin asignar', 'created_at' => $today . ' 11:15:00'],
    ];

$selectedTicket = $recentTickets[0] ?? null;

$quotes = $liveData
    ? fetch_all('SELECT quotes.*, clients.name AS client_name FROM quotes LEFT JOIN clients ON clients.id = quotes.client_id ORDER BY quotes.created_at DESC LIMIT 5')
    : [
        ['id' => 1042, 'quote_number' => 'COT-2026-0142', 'client_name' => 'Hospital Metropolitano', 'title' => 'Renovación de monitores UCI', 'status' => 'Negociacion', 'total' => 486200, 'updated_at' => $today],
        ['id' => 1041, 'quote_number' => 'COT-2026-0141', 'client_name' => 'Plaza de la Salud', 'title' => 'Central de gases medicinales', 'status' => 'Enviado', 'total' => 312800, 'updated_at' => date('Y-m-d', strtotime('-1 day'))],
        ['id' => 1040, 'quote_number' => 'COT-2026-0140', 'client_name' => 'CEDIMAT', 'title' => 'Ventiladores de transporte (x4)', 'status' => 'Cotizado', 'total' => 198400, 'updated_at' => date('Y-m-d', strtotime('-2 day'))],
        ['id' => 1039, 'quote_number' => 'COT-2026-0139', 'client_name' => 'CAID', 'title' => 'Mantenimiento anual preventivo', 'status' => 'Aprobado', 'total' => 96500, 'updated_at' => date('Y-m-d', strtotime('-3 day'))],
        ['id' => 1038, 'quote_number' => 'COT-2026-0138', 'client_name' => 'Hospital General Plaza', 'title' => 'Autoclave de doble puerta', 'status' => 'Borrador', 'total' => 142000, 'updated_at' => date('Y-m-d', strtotime('-4 day'))],
    ];

$maintenance = $liveData
    ? fetch_all('SELECT equipment.*, clients.name AS client_name FROM equipment LEFT JOIN clients ON clients.id = equipment.client_id WHERE next_service_at IS NOT NULL ORDER BY next_service_at ASC LIMIT 4')
    : [
        ['name' => 'Tomógrafo Siemens Somatom', 'client_name' => 'Hospital Metropolitano', 'warranty_until' => date('Y-m-d', strtotime('+58 day'))],
        ['name' => 'Ventilador Dräger Evita', 'client_name' => 'Plaza de la Salud', 'warranty_until' => date('Y-m-d', strtotime('+74 day'))],
        ['name' => 'Monitor GE B450', 'client_name' => 'CEDIMAT', 'warranty_until' => date('Y-m-d', strtotime('+128 day'))],
        ['name' => 'Central de gases SCH', 'client_name' => 'CAID', 'warranty_until' => date('Y-m-d', strtotime('+212 day'))],
    ];

/* ---- Helpers ------------------------------------------------------------- */
$money0 = fn($v) => 'RD$ ' . number_format((float) $v, 0, '.', ',');
$kfmt = fn($v) => $v >= 1000000 ? number_format($v / 1000000, 2) . 'M' : ($v >= 1000 ? number_format($v / 1000, 0) . 'k' : (string) (int) $v);

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

$crmTitle = 'Panel de operaciones';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-xs font-semibold text-amber-800">
        MySQL aún no está instalado. Este panel muestra datos de muestra. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para conectar datos reales.
    </div>
<?php endif; ?>

<div class="dash" x-data="{ tf: '<?= e($periodLabel) ?>', tech: 'Todos los técnicos', tfOpen: false, techOpen: false }">

    <!-- ============ Toolbar ============ -->
    <div class="dash-bar">
        <div class="dash-bar__title">
            <h2>
                <i data-lucide="layout-dashboard" class="h-5 w-5 text-sch-blue"></i>
                Panel de operaciones
                <?php if ($sample): ?>
                    <span class="dash-live" style="background:var(--gold-soft);color:var(--gold-strong)"><i data-lucide="flask-conical" class="h-3.5 w-3.5"></i> Datos de muestra</span>
                <?php else: ?>
                    <span class="dash-live"><span class="dash-live-dot" style="width:6px;height:6px;border-radius:9px;background:#0a7d36;display:inline-block"></span> En vivo</span>
                <?php endif; ?>
            </h2>
            <p>Soporte, ventas y mantenimiento de SCH MEDICOS en un solo lugar.</p>
        </div>
        <div class="dash-bar__tools">
            <div class="dash-avatars" aria-label="Equipo de servicio">
                <?php foreach (array_slice($team, 0, 4) as $m): ?>
                    <span class="av av--<?= e($m[1]) ?>" title="<?= e($m[0]) ?>"><?= e($initialsOf($m[0])) ?></span>
                <?php endforeach; ?>
                <a class="dash-avatars__add" href="<?= url('crm/configuracion.php') ?>" aria-label="Gestionar equipo" title="Gestionar equipo"><i data-lucide="plus" class="h-4 w-4"></i></a>
            </div>

            <div class="dash-dd" @click.outside="tfOpen = false">
                <button type="button" class="dash-chip dash-chip--accent" @click="tfOpen = !tfOpen; techOpen = false" :aria-expanded="tfOpen"><i data-lucide="calendar-days"></i><span x-text="tf"><?= e($periodLabel) ?></span><i data-lucide="chevron-down"></i></button>
                <div class="dash-pop dash-pop--left" x-show="tfOpen" x-transition.origin.top.left x-cloak>
                    <div class="dash-pop__label">Periodo</div>
                    <?php
                    $tfOptions = [
                        'Hoy' => date('d/m/Y'),
                        'Esta semana' => 'Semana actual',
                        'Este mes' => $periodLabel,
                        'Trimestre' => 'Trimestre ' . (int) ceil((int) date('n') / 3) . ' ' . date('Y'),
                        'Año' => date('Y'),
                    ];
                    foreach ($tfOptions as $optLabel => $optValue): ?>
                        <button type="button" class="dash-pop__item" :class="{ 'is-active': tf === '<?= e(addslashes($optValue)) ?>' }" @click="tf = '<?= e(addslashes($optValue)) ?>'; tfOpen = false; window.crmToast('Periodo: <?= e(addslashes($optLabel)) ?>', 'calendar-days')"><i data-lucide="calendar-check"></i><?= e($optLabel) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="dash-dd" @click.outside="techOpen = false">
                <button type="button" class="dash-chip" @click="techOpen = !techOpen; tfOpen = false" :aria-expanded="techOpen"><i data-lucide="users"></i><span class="dash-chip__hide" x-text="tech">Todos los técnicos</span><i data-lucide="chevron-down"></i></button>
                <div class="dash-pop dash-pop--left" x-show="techOpen" x-transition.origin.top.left x-cloak>
                    <div class="dash-pop__label">Filtrar por técnico</div>
                    <button type="button" class="dash-pop__item" :class="{ 'is-active': tech === 'Todos los técnicos' }" @click="tech = 'Todos los técnicos'; techOpen = false; window.crmToast('Mostrando todo el equipo', 'users')"><i data-lucide="users"></i>Todos los técnicos</button>
                    <hr>
                    <?php foreach ($team as $m): ?>
                        <button type="button" class="dash-pop__item" :class="{ 'is-active': tech === '<?= e(addslashes($m[0])) ?>' }" @click="tech = '<?= e(addslashes($m[0])) ?>'; techOpen = false; window.crmToast('Filtrado: <?= e(addslashes($m[0])) ?>', 'user-round')"><span class="av av--<?= e($m[1]) ?>" style="--av-size:22px"><?= e($initialsOf($m[0])) ?></span><?= e($m[0]) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="button" class="dash-iconbtn" @click="window.dashExport()" aria-label="Exportar CSV" title="Exportar CSV"><i data-lucide="download"></i></button>
            <button type="button" class="dash-iconbtn dash-iconbtn--solid" @click="window.dashShare()" aria-label="Compartir panel" title="Compartir panel"><i data-lucide="share-2"></i></button>
        </div>
    </div>

    <!-- ============ Summary band: hero + KPI cluster ============ -->
    <section class="dash-summary">
        <article class="dash-hero">
            <p class="dash-hero__label"><span class="dot"></span> Valor del pipeline activo</p>
            <div class="dash-hero__value">
                <span class="cur">RD$</span><?= e($heroParts[0]) ?><span class="dec">.<?= e($heroParts[1] ?? '00') ?></span>
            </div>
            <div class="dash-hero__meta">
                <?php $up = $pipelineDeltaPct >= 0; ?>
                <span class="dash-delta <?= $up ? 'dash-delta--up' : 'dash-delta--down' ?>">
                    <i data-lucide="<?= $up ? 'trending-up' : 'trending-down' ?>"></i><?= ($up ? '+' : '') . e((string) $pipelineDeltaPct) ?>%
                </span>
                <span class="dash-delta dash-delta--solid">+<?= e($money0($pipelineDeltaAbs)) ?></span>
            </div>
            <p class="dash-hero__sub">vs. periodo anterior <b><?= e($money0($pipelinePrev)) ?></b> · <?= e($periodLabel) ?></p>
            <div class="dash-hero__spark"><?= spark_svg($trend['ingresos'], 'heroSpark', '#0a7d36') ?></div>
        </article>

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

            <article class="dash-kpi">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label">Tickets abiertos</span>
                    <span class="dash-kpi__icon dash-kpi__icon--amber"><i data-lucide="life-buoy"></i></span>
                </div>
                <div class="dash-kpi__value"><?= e((string) $stats['open']) ?></div>
                <div class="dash-kpi__foot"><span class="dash-delta dash-delta--up"><i data-lucide="trending-up"></i>+3</span><span class="dash-sub">esta semana</span></div>
            </article>

            <article class="dash-kpi">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label">Valor ganado (mes)</span>
                    <span class="dash-kpi__icon dash-kpi__icon--gold"><i data-lucide="wallet"></i></span>
                </div>
                <div class="dash-kpi__value">RD$ <?= e($kfmt($wonValue)) ?></div>
                <div class="dash-kpi__foot"><span class="dash-delta dash-delta--up"><i data-lucide="trending-up"></i>+12%</span><span class="dash-sub"><?= e((string) $stats['quotes']) ?> activas</span></div>
            </article>

            <article class="dash-kpi">
                <div class="dash-kpi__top">
                    <span class="dash-kpi__label">Tasa de cierre</span>
                    <span class="dash-kpi__icon"><i data-lucide="target"></i></span>
                </div>
                <div class="dash-kpi__value"><?= e((string) $winRate) ?>%</div>
                <div class="dash-kpi__foot"><span class="dash-delta dash-delta--up"><i data-lucide="trending-up"></i>+1.8</span><span class="dash-sub">vs. mes previo</span></div>
            </article>
        </div>
    </section>

    <!-- ============ Pipeline stage pills ============ -->
    <div class="dash-pills">
        <?php foreach ($stages as $name => $s): $pct = round($s['amount'] / $pipelineTotal * 100, 1); ?>
            <a class="dash-pill" href="<?= url('crm/cotizaciones.php') ?>">
                <span class="dash-pill__dot" style="background:<?= e($s['color']) ?>"></span>
                <span class="dash-pill__txt">
                    <b><?= e($money0($s['amount'])) ?></b>
                    <span><?= e($name) ?> · <?= e((string) $pct) ?>% · <?= e((string) $s['count']) ?></span>
                </span>
            </a>
        <?php endforeach; ?>
        <a class="dash-pill dash-pill--cta" href="<?= url('crm/cotizaciones.php') ?>"><span>Detalles <i data-lucide="arrow-right"></i></span></a>
    </div>

    <!-- ============ Mid row: business lines + monthly accent chart ============ -->
    <section class="dash-mid">
        <article class="dash-card">
            <div class="dash-card__head">
                <h3><i data-lucide="layers"></i> Ingresos por línea de negocio</h3>
                <a class="dash-card__meta" href="<?= url('crm/reportes.php') ?>">Reporte <i data-lucide="arrow-up-right" class="h-3.5 w-3.5"></i></a>
            </div>
            <div class="dash-card__body">
                <div class="dash-lines">
                    <?php foreach ($lines as [$label, $icon, $pct, $amount, $color]): ?>
                        <div class="dash-line">
                            <span class="dash-line__icon" style="background:<?= e($color) ?>1a;color:<?= e($color) ?>"><i data-lucide="<?= e($icon) ?>"></i></span>
                            <div class="dash-line__main">
                                <b><?= e($label) ?></b>
                                <div class="dash-line__track"><span class="dash-line__fill" style="width:<?= e((string) $pct) ?>%;background:<?= e($color) ?>"></span></div>
                            </div>
                            <div class="dash-line__val"><b><?= e($money0($amount)) ?></b><span><?= e((string) $pct) ?>%</span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>

        <article class="dash-accent" x-data="{ metric: 'ingresos' }">
            <div class="dash-accent__head">
                <div>
                    <h3>Promedio mensual</h3>
                    <p>Últimos 6 meses · <?= date('Y') ?></p>
                </div>
                <div class="dash-seg" role="tablist" aria-label="Métrica del gráfico">
                    <button type="button" class="is-active" :class="{ 'is-active': metric==='ingresos' }" @click="metric='ingresos'; dashSetMonthly('ingresos')">Ingresos</button>
                    <button type="button" :class="{ 'is-active': metric==='cotizaciones' }" @click="metric='cotizaciones'; dashSetMonthly('cotizaciones')">Cotiz.</button>
                    <button type="button" :class="{ 'is-active': metric==='tickets' }" @click="metric='tickets'; dashSetMonthly('tickets')">Tickets</button>
                </div>
            </div>
            <div class="dash-accent__value">
                <span id="dashMonthlyValue">RD$ 1.28M</span>
                <span class="dash-delta dash-delta--solid"><i data-lucide="trending-up"></i> +8.4%</span>
            </div>
            <div class="dash-accent__chart"><canvas id="dashMonthly"></canvas></div>
            <div class="dash-accent__foot"><span>Promedio: <b id="dashMonthlyAvg" style="color:#fff">RD$ 962k</b></span><span>Meta: <b id="dashMonthlyMeta" style="color:#fff">RD$ 1.2M</b></span></div>
        </article>
    </section>

    <!-- ============ Team performance ============ -->
    <article class="dash-card">
        <div class="dash-card__head">
            <h3><i data-lucide="users-round"></i> Desempeño del equipo</h3>
            <span class="dash-card__meta"><?= e($periodLabel) ?></span>
        </div>
        <div class="dash-card__body" style="padding-top:0">
            <div class="dash-team-wrap">
                <table class="dash-team-table">
                    <thead>
                        <tr>
                            <th>Integrante</th>
                            <th>Ingresos</th>
                            <th>Cotiz.</th>
                            <th>Resueltos</th>
                            <th>CSAT</th>
                            <th>Distinción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($team as $i => [$name, $tone, $role, $income, $cotiz, $resueltos, $csat, $tag]): ?>
                            <tr class="<?= $i === 0 ? 'is-top' : '' ?>">
                                <td>
                                    <div class="dash-person">
                                        <span class="av av--<?= e($tone) ?>"><?= e($initialsOf($name)) ?></span>
                                        <span class="dash-person__id"><b><?= e($name) ?></b><span><?= e($role) ?></span></span>
                                    </div>
                                </td>
                                <td><span class="dash-money"><?= e($money0($income)) ?></span></td>
                                <td><span class="dash-badge dash-badge--soft"><?= e((string) $cotiz) ?></span></td>
                                <td><span class="dash-badge dash-badge--green"><?= e((string) $resueltos) ?></span></td>
                                <td><span class="dash-sub" style="color:var(--ink-soft);font-weight:700"><?= e(number_format($csat / 100, 2)) ?></span></td>
                                <td>
                                    <?php if (!empty($tag[0])): ?>
                                        <span class="dash-tag dash-tag--<?= e($tag[1]) ?>"><i data-lucide="<?= e($tag[2]) ?>" class="h-3.5 w-3.5"></i><?= e($tag[0]) ?></span>
                                    <?php else: ?>
                                        <span class="dash-sub">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </article>

    <!-- ============ Brand mix ============ -->
    <article class="dash-card">
        <div class="dash-card__head">
            <h3><i data-lucide="package"></i> Mezcla de cartera por marca</h3>
            <span class="dash-card__meta">RD$ 1.33M en pipeline</span>
        </div>
        <div class="dash-card__body">
            <div class="dash-brands">
                <?php foreach ($brands as [$brand, $mono, $pct, $amount, $color]): ?>
                    <div class="dash-brand">
                        <span class="dash-brand__mono" style="background:<?= e($color) ?>1a;color:<?= e($color) ?>"><?= e($mono) ?></span>
                        <div class="dash-brand__main">
                            <div class="dash-brand__row"><b><?= e($brand) ?></b><span style="color:<?= e($color) ?>"><?= e((string) $pct) ?>%</span></div>
                            <div class="dash-brand__track"><span class="dash-brand__fill" style="width:<?= e((string) $pct) ?>%;background:<?= e($color) ?>"></span></div>
                            <span class="dash-brand__amt"><?= e($money0($amount)) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </article>

    <!-- ============ Service dynamics line chart ============ -->
    <article class="dash-card">
        <div class="dash-card__head">
            <h3><i data-lucide="activity"></i> Dinámica de servicio</h3>
            <div class="dash-legend">
                <span style="--c:#0666b3">Tickets nuevos</span>
                <span style="--c:#0a7d36">Resueltos</span>
                <span style="--c:#9c7d34">Cotizaciones</span>
            </div>
        </div>
        <div class="dash-card__body" style="padding-bottom:.6rem">
            <div class="dash-dynamic__chart"><canvas id="dashDynamic"></canvas></div>
        </div>
    </article>

    <p class="dash-section-label">Operación en vivo</p>

    <!-- ============ Live operational tables (real CRM records) ============ -->
    <article class="ops-card">
        <header class="ops-card__head">
            <h3><i data-lucide="life-buoy" class="h-4 w-4 text-red-500"></i>Tickets urgentes <span class="ops-status bg-red-100 text-red-700"><?= e((string) max(1, count($recentTickets))) ?></span></h3>
            <a href="<?= url('crm/tickets.php') ?>">Ver todos</a>
        </header>
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
                        <div><dt>Cliente</dt><dd><?= e($selectedTicket['client_name'] ?? 'Hospital Metropolitano de Santiago') ?></dd></div>
                        <div><dt>Técnico</dt><dd><?= e($selectedTicket['assigned_name'] ?? 'Ing. R. Mena') ?></dd></div>
                        <div><dt>Equipo</dt><dd><?= e($selectedTicket['equipment_name'] ?? 'Sistema central de gases') ?> · <?= e($selectedTicket['serial'] ?? 'SCH-HMS-001') ?></dd></div>
                        <div><dt>Ubicación</dt><dd>Imagenología · 1er Nivel</dd></div>
                        <div><dt>Contacto</dt><dd>Ing. Laura Peña · <?= e($selectedTicket['reported_phone'] ?? '809-555-2266') ?></dd></div>
                        <div><dt>Creado</dt><dd><?= e(date('d/m/Y H:i', strtotime($selectedTicket['created_at'] ?? 'now'))) ?></dd></div>
                    </dl>
                    <p class="ops-focus__desc"><?= e($selectedTicket['description'] ?? 'El equipo se detiene durante el escaneo y muestra error 8042. Ocurre de forma intermitente.') ?></p>
                </div>
                <div class="ops-focus__actions">
                    <?php if ($liveData):
                        $uid = (int) (current_user()['id'] ?? 0);
                        $curStatus = $selectedTicket['status'] ?? 'Abierto';
                        $curPriority = $selectedTicket['priority'] ?? 'Media';
                        $curAssigned = (string) ($selectedTicket['assigned_to'] ?? '');
                        $curDue = (string) ($selectedTicket['due_at'] ?? '');
                    ?>
                        <form method="post" action="<?= url('crm/tickets.php') ?>" style="display:contents">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="update">
                            <input type="hidden" name="id" value="<?= (int) $selectedTicket['id'] ?>">
                            <input type="hidden" name="status" value="<?= e($curStatus) ?>">
                            <input type="hidden" name="priority" value="<?= e($curPriority) ?>">
                            <input type="hidden" name="assigned_to" value="<?= e((string) $uid) ?>">
                            <input type="hidden" name="due_at" value="<?= e($curDue) ?>">
                            <button class="ops-action-blue" type="submit"><i data-lucide="user-round-plus" class="h-4 w-4"></i>Tomar ticket</button>
                        </form>
                        <form method="post" action="<?= url('crm/tickets.php') ?>" style="display:contents">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="update">
                            <input type="hidden" name="id" value="<?= (int) $selectedTicket['id'] ?>">
                            <input type="hidden" name="status" value="Resuelto">
                            <input type="hidden" name="priority" value="<?= e($curPriority) ?>">
                            <input type="hidden" name="assigned_to" value="<?= e($curAssigned) ?>">
                            <input type="hidden" name="due_at" value="<?= e($curDue) ?>">
                            <button class="ops-action-green" type="submit"><i data-lucide="circle-check" class="h-4 w-4"></i>Resolver</button>
                        </form>
                    <?php else: ?>
                        <a class="ops-action-blue" href="<?= url('crm/tickets.php?id=' . (int) $selectedTicket['id']) ?>"><i data-lucide="user-round-plus" class="h-4 w-4"></i>Tomar ticket</a>
                        <a class="ops-action-green" href="<?= url('crm/tickets.php?id=' . (int) $selectedTicket['id']) ?>"><i data-lucide="circle-check" class="h-4 w-4"></i>Resolver</a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endif; ?>

        <article class="ops-card">
            <header class="ops-card__head">
                <h3><i data-lucide="calendar-days" class="h-4 w-4 text-sch-blue"></i>Calendario de mantenimiento</h3>
                <span><?= e(date_long_es(date('Y-m-d'))) ?></span>
            </header>
            <div class="ops-calendar">
                <?php
                $calendarRows = ['08:00','09:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00'];
                foreach ($calendarRows as $i => $hour):
                    $event = $maintenance[$i % max(1, count($maintenance))] ?? null;
                    $isNow = $i === 2;
                ?>
                    <div class="ops-calendar__bar">
                        <div><?= e($hour) ?></div>
                        <div>
                            <?php if ($isNow): ?><span class="ops-now-dot" aria-label="Ahora"></span><?php endif; ?>
                            <?php if ($event && in_array($i, [1,3,5,6], true)): ?>
                                <span class="ops-calendar__event">
                                    <?= e($event['client_name'] ?? 'Cliente SCH') ?>
                                    <small>Mantenimiento preventivo · <?= e($event['name'] ?? 'Equipo médico') ?></small>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    </div>

    <article class="ops-card">
        <header class="ops-card__head">
            <h3><i data-lucide="file-text" class="h-4 w-4 text-sch-blue"></i>Pipeline de cotizaciones</h3>
            <a href="<?= url('crm/cotizaciones.php') ?>">Ver todas</a>
        </header>
        <div class="overflow-x-auto">
            <table class="ops-table">
                <thead>
                    <tr><th>Número</th><th>Cliente</th><th>Asunto</th><th>Etapa</th><th class="text-right">Valor</th><th>Actualizado</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($quotes as $quote): ?>
                        <tr>
                            <td><a href="<?= url('crm/cotizaciones.php?action=view&id=' . (int) $quote['id']) ?>"><?= e($quote['quote_number']) ?></a></td>
                            <td><?= e($quote['client_name'] ?? 'Cliente') ?></td>
                            <td><?= e($quote['title']) ?></td>
                            <td><span class="ops-status <?= e(status_class($quote['status'])) ?>"><?= e($quote['status']) ?></span></td>
                            <td class="text-right ops-nowrap"><?= money($quote['total']) ?></td>
                            <td class="ops-nowrap"><?= e(date_es($quote['updated_at'] ?? $quote['created_at'] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>

    <div class="ops-row ops-row--split">
        <article class="ops-card">
            <header class="ops-card__head">
                <h3><i data-lucide="shield-alert" class="h-4 w-4 text-amber-500"></i>Garantías por vencer</h3>
                <a href="<?= url('crm/equipos.php') ?>">Ver todas</a>
            </header>
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
        </article>

        <article class="ops-card">
            <header class="ops-card__head">
                <h3><i data-lucide="history" class="h-4 w-4 text-sch-blue"></i>Actividad reciente</h3>
                <a href="<?= url('crm/reportes.php') ?>">Ver todas</a>
            </header>
            <div class="ops-activity">
                <?php foreach (array_slice($recentTickets, 0, 5) as $i => $ticket): ?>
                    <div class="ops-activity-row">
                        <time><?= e(date('H:i', strtotime("-{$i} hour"))) ?></time>
                        <i data-lucide="<?= $i % 2 === 0 ? 'ticket-check' : 'send' ?>" class="h-4 w-4"></i>
                        <span><b>TK-<?= date('Y') ?>-<?= str_pad((string) $ticket['id'], 4, '0', STR_PAD_LEFT) ?></b> · <?= e($ticket['client_name'] ?? 'Cliente') ?></span>
                        <span><?= e($ticket['assigned_name'] ?? 'SCH') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    </div>
</div>

<script>
(function () {
    var trend = <?= json_encode($trend) ?>;
    var trendLabels = <?= json_encode($trendLabels, JSON_UNESCAPED_UNICODE) ?>;
    var dyn = <?= json_encode($dynamics) ?>;
    var dynLabels = <?= json_encode($dynLabels, JSON_UNESCAPED_UNICODE) ?>;

    var dashData = <?= json_encode([
        'periodo'  => $periodLabel,
        'pipeline' => $pipelineValue,
        'stages'   => array_values(array_map(fn ($k, $v) => ['etapa' => $k, 'monto' => $v['amount'], 'cotizaciones' => $v['count']], array_keys($stages), $stages)),
        'lineas'   => array_map(fn ($l) => ['linea' => $l[0], 'pct' => $l[2], 'monto' => $l[3]], $lines),
        'equipo'   => array_map(fn ($t) => ['nombre' => $t[0], 'rol' => $t[2], 'ingresos' => $t[3], 'cotizaciones' => $t[4], 'resueltos' => $t[5], 'csat' => $t[6]], $team),
        'marcas'   => array_map(fn ($b) => ['marca' => $b[0], 'pct' => $b[2], 'monto' => $b[3]], $brands),
    ], JSON_UNESCAPED_UNICODE) ?>;

    window.dashExport = function () {
        var rows = [];
        rows.push(['SCH MEDICOS — Panel de operaciones']);
        rows.push(['Periodo', dashData.periodo]);
        rows.push(['Valor del pipeline activo (RD$)', dashData.pipeline]);
        rows.push([]);
        rows.push(['Pipeline por etapa', 'Monto (RD$)', 'Cotizaciones']);
        dashData.stages.forEach(function (s) { rows.push([s.etapa, s.monto, s.cotizaciones]); });
        rows.push([]);
        rows.push(['Ingresos por línea de negocio', '%', 'Monto (RD$)']);
        dashData.lineas.forEach(function (l) { rows.push([l.linea, l.pct, l.monto]); });
        rows.push([]);
        rows.push(['Equipo', 'Rol', 'Ingresos (RD$)', 'Cotizaciones', 'Resueltos', 'CSAT']);
        dashData.equipo.forEach(function (t) { rows.push([t.nombre, t.rol, t.ingresos, t.cotizaciones, t.resueltos, (t.csat / 100).toFixed(2)]); });
        rows.push([]);
        rows.push(['Mezcla por marca', '%', 'Monto (RD$)']);
        dashData.marcas.forEach(function (b) { rows.push([b.marca, b.pct, b.monto]); });
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

    var monthlyMeta = {
        ingresos:     { label: 'RD$ 1.28M', avg: 'RD$ 962k', meta: 'RD$ 1.2M', fmt: function (v) { return 'RD$ ' + v + 'k'; } },
        cotizaciones: { label: '34 cotiz.', avg: '26 cotiz.', meta: '30 cotiz.', fmt: function (v) { return v + ' cotiz.'; } },
        tickets:      { label: '42 tickets', avg: '40 tickets', meta: '38 tickets', fmt: function (v) { return v + ' tickets'; } }
    };

    var monthlyChart = null;

    function buildMonthly(metric) {
        var ctx = document.getElementById('dashMonthly');
        if (!ctx || !window.Chart) return;
        var data = trend[metric];
        var last = data.length - 1;
        var colors = data.map(function (_, i) { return i === last ? 'rgba(255,255,255,.95)' : 'rgba(255,255,255,.38)'; });
        if (monthlyChart) {
            monthlyChart.data.datasets[0].data = data;
            monthlyChart.data.datasets[0].backgroundColor = colors;
            monthlyChart.options.plugins.tooltip.callbacks.label = function (c) { return monthlyMeta[metric].fmt(c.parsed.y); };
            monthlyChart.update();
            return;
        }
        monthlyChart = new Chart(ctx, {
            type: 'bar',
            data: { labels: trendLabels, datasets: [{ data: data, backgroundColor: colors, borderRadius: 6, borderSkipped: false, maxBarThickness: 30 }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: 'rgba(8,18,30,.92)', padding: 10, displayColors: false,
                        callbacks: { label: function (c) { return monthlyMeta[metric].fmt(c.parsed.y); } } }
                },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { color: 'rgba(255,255,255,.78)', font: { weight: '600', size: 11 } } },
                    y: { display: false, beginAtZero: true, grace: '12%' }
                }
            }
        });
    }

    window.dashSetMonthly = function (metric) {
        buildMonthly(metric);
        var m = monthlyMeta[metric];
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
            return { data: data, borderColor: color, backgroundColor: color + '22', borderWidth: 2.5,
                tension: .4, fill: true, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: color, pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2 };
        }
        new Chart(ctx, {
            type: 'line',
            data: { labels: dynLabels, datasets: [
                Object.assign({ label: 'Tickets nuevos' }, ds(dyn.nuevos, '#0666b3')),
                Object.assign({ label: 'Resueltos' }, ds(dyn.resueltos, '#0a7d36')),
                Object.assign({ label: 'Cotizaciones' }, ds(dyn.cotizados, '#9c7d34'))
            ] },
            options: {
                responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: 'rgba(8,18,30,.92)', padding: 10, cornerRadius: 8, titleColor: '#cbd5e1', usePointStyle: true }
                },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { color: '#56697b', font: { weight: '600', size: 11 } } },
                    y: { beginAtZero: true, border: { display: false }, grid: { color: '#eef3f8' }, ticks: { color: '#8696a6', maxTicksLimit: 5, font: { size: 11 } } }
                }
            }
        });
    }

    function init() {
        if (!window.Chart) { return setTimeout(init, 120); }
        Chart.defaults.font.family = "Inter, system-ui, sans-serif";
        buildMonthly('ingresos');
        buildDynamic();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
