<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('reportes.view');
if (db(false)) { ensure_quote_schema(); }

$mode = analytics_mode();
$live = $mode === 'live';
$periodKey = (string) ($_GET['period'] ?? 'month');
$period = analytics_period($periodKey);

/* ---- Real analytics (sample only when no DB) ----------------------------- */
$kpis          = analytics_kpis($period);
$pipeline      = analytics_pipeline_by_stage();
$trend         = analytics_monthly_trend(12);
$byLine        = analytics_revenue_by_line();
$topClients    = analytics_top_clients(8);
$ticketStatus  = analytics_tickets_by_status();
$ticketPriority = analytics_tickets_by_priority();
$resolution    = analytics_resolution($period);
$equipStatus   = analytics_equipment_by_status();
$equipBrand    = analytics_equipment_by_brand(6);
$funnel        = analytics_quote_funnel();
$leads         = analytics_leads_summary();

/* ---- Helpers ------------------------------------------------------------- */
$money0 = fn ($v) => 'RD$ ' . number_format((float) $v, 0, '.', ',');
$kfmt = fn ($v) => $v >= 1000000 ? 'RD$ ' . number_format($v / 1000000, 2) . 'M' : ($v >= 1000 ? 'RD$ ' . number_format($v / 1000, 0) . 'k' : 'RD$ ' . number_format((float) $v, 0));
$delta_html = function (float $d): string {
    if ($d == 0.0) return '<span class="dash-sub">Sin variación</span>';
    $up = $d > 0;
    $cls = $up ? 'dash-delta--up' : 'dash-delta--down';
    $ic = $up ? 'trending-up' : 'trending-down';
    return '<span class="dash-delta ' . $cls . '"><i data-lucide="' . $ic . '"></i>' . ($up ? '+' : '') . e((string) $d) . '%</span>';
};

$statusColors = [
    'Abierto' => '#0666b3', 'En proceso' => '#1fa6d8', 'Cotizado' => '#9c7d34',
    'Resuelto' => '#0a7d36', 'Cerrado' => '#64748b', 'Pendiente' => '#d97706',
];
$priorityColors = ['Critica' => '#dc2626', 'Alta' => '#ea580c', 'Media' => '#d97706', 'Baja' => '#0a7d36'];
$equipColors = ['activo' => '#0a7d36', 'requiere revision' => '#d97706', 'fuera de servicio' => '#dc2626', 'retirado' => '#64748b'];

/* ---- Presence flags (drive honest empty states) -------------------------- */
$trendHas = array_sum($trend['ingresos_raw']) > 0 || array_sum($trend['cotizaciones']) > 0 || array_sum($trend['tickets']) > 0;
$funnelHas = array_sum(array_column($funnel, 'count')) > 0;
$lineHas = !empty($byLine) && array_sum(array_column($byLine, 'amount')) > 0;
$ticketStatusHas = array_sum(array_map('intval', array_column($ticketStatus, 'total'))) > 0;
$ticketPriorityHas = array_sum(array_map('intval', array_column($ticketPriority, 'total'))) > 0;
$equipStatusHas = array_sum(array_map('intval', array_column($equipStatus, 'total'))) > 0;
$equipBrandHas = array_sum(array_map('intval', array_column($equipBrand, 'total'))) > 0;
$leadHas = $leads['total'] > 0;
$clientHas = !empty($topClients) && array_sum(array_map(fn ($c) => (float) $c['quote_value'], $topClients)) > 0;

$pipelineCounts = array_map(fn ($f) => (int) $f['count'], $funnel);
$pipelineMax = $pipelineCounts ? max(1, max($pipelineCounts)) : 1;
$lineMax = $lineHas ? max(array_map(fn ($l) => (float) $l['amount'], $byLine)) : 1;
$clientMax = $clientHas ? max(array_map(fn ($c) => (float) $c['quote_value'], $topClients)) : 1;

$pdfUrl = url('crm/reporte_pdf.php?period=' . rawurlencode($period['key']));

$crmTitle = 'Centro de reportes';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$live): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demostración (sin MySQL). Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para reportes con datos reales.</div>
<?php endif; ?>

<div class="rep" id="rep-root">
    <!-- Toolbar -->
    <div class="rep-toolbar">
        <div class="rep-toolbar__title">
            <h2>
                <i data-lucide="bar-chart-3"></i> Centro de reportes
                <?php if ($live): ?>
                    <span class="rep-livechip rep-livechip--live"><span class="dot"></span> En vivo</span>
                <?php else: ?>
                    <span class="rep-livechip rep-livechip--demo"><i data-lucide="flask-conical" class="h-3.5 w-3.5"></i> Muestra</span>
                <?php endif; ?>
            </h2>
            <p>Indicadores comerciales, soporte técnico, inventario y SLA · <?= e($period['label']) ?></p>
        </div>
        <div class="rep-toolbar__tools">
            <nav class="rep-seg" aria-label="Periodo del reporte">
                <?php foreach (analytics_period_options() as $k => $label): ?>
                    <a href="<?= url('crm/reportes.php?period=' . $k) ?>" class="<?= $period['key'] === $k ? 'is-active' : '' ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
            <button type="button" class="dash-iconbtn" onclick="repExportCSV()" title="Exportar CSV" aria-label="Exportar CSV"><i data-lucide="file-down"></i></button>
            <button type="button" class="dash-iconbtn" onclick="repExportExcel()" title="Exportar Excel" aria-label="Exportar Excel"><i data-lucide="sheet"></i></button>
            <a href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener" class="dash-iconbtn dash-iconbtn--solid" title="Exportar PDF" aria-label="Exportar PDF"><i data-lucide="printer"></i></a>
        </div>
    </div>

    <!-- KPI row -->
    <section class="rep-kpis">
        <article class="rep-kpi">
            <div class="rep-kpi__top"><span class="rep-kpi__label">Pipeline activo</span><span class="rep-kpi__ic"><i data-lucide="trending-up"></i></span></div>
            <div class="rep-kpi__value"><?= e($kfmt($kpis['pipeline']['value'])) ?></div>
            <div class="rep-kpi__foot"><span><?= e((string) (int) ($kpis['pipeline']['open_count'] ?? 0)) ?> cotizaciones abiertas · snapshot</span></div>
        </article>
        <article class="rep-kpi rep-kpi--gold">
            <div class="rep-kpi__top"><span class="rep-kpi__label">Ganado (<?= e(analytics_period_options()[$period['key']] ?? 'periodo') ?>)</span><span class="rep-kpi__ic"><i data-lucide="wallet"></i></span></div>
            <div class="rep-kpi__value"><?= e($kfmt($kpis['won']['value'])) ?></div>
            <div class="rep-kpi__foot"><?= $delta_html($kpis['won']['delta']) ?><span>vs. periodo previo</span></div>
        </article>
        <article class="rep-kpi rep-kpi--blue">
            <div class="rep-kpi__top"><span class="rep-kpi__label">Tasa de cierre</span><span class="rep-kpi__ic"><i data-lucide="target"></i></span></div>
            <div class="rep-kpi__value"><?= e((string) $kpis['win_rate']['value']) ?><small>%</small></div>
            <div class="rep-kpi__foot"><span>aprobadas / cerradas · histórico</span></div>
        </article>
        <article class="rep-kpi">
            <div class="rep-kpi__top"><span class="rep-kpi__label">Cotizaciones</span><span class="rep-kpi__ic"><i data-lucide="file-text"></i></span></div>
            <div class="rep-kpi__value"><?= e((string) (int) $kpis['quotes']['value']) ?></div>
            <div class="rep-kpi__foot"><?= $delta_html($kpis['quotes']['delta']) ?><span>creadas</span></div>
        </article>
        <article class="rep-kpi rep-kpi--amber">
            <div class="rep-kpi__top"><span class="rep-kpi__label">Tickets abiertos</span><span class="rep-kpi__ic"><i data-lucide="life-buoy"></i></span></div>
            <div class="rep-kpi__value"><?= e((string) (int) $kpis['open_tickets']['value']) ?></div>
            <div class="rep-kpi__foot"><span><?= e((string) $resolution['overdue']) ?> vencidos</span></div>
        </article>
        <article class="rep-kpi rep-kpi--blue">
            <div class="rep-kpi__top"><span class="rep-kpi__label">Resolución prom.</span><span class="rep-kpi__ic"><i data-lucide="timer"></i></span></div>
            <div class="rep-kpi__value"><?= e((string) $resolution['avg_hours']) ?><small> h</small></div>
            <div class="rep-kpi__foot"><span><?= e((string) $resolution['sla_pct']) ?>% dentro de SLA</span></div>
        </article>
        <article class="rep-kpi">
            <div class="rep-kpi__top"><span class="rep-kpi__label">Clientes nuevos</span><span class="rep-kpi__ic"><i data-lucide="building-2"></i></span></div>
            <div class="rep-kpi__value"><?= e((string) (int) $kpis['clients']['value']) ?></div>
            <div class="rep-kpi__foot"><?= $delta_html($kpis['clients']['delta']) ?><span>en el periodo</span></div>
        </article>
        <article class="rep-kpi rep-kpi--gold">
            <div class="rep-kpi__top"><span class="rep-kpi__label">Ticket promedio</span><span class="rep-kpi__ic"><i data-lucide="receipt"></i></span></div>
            <div class="rep-kpi__value"><?= e($kfmt($kpis['avg_ticket']['value'])) ?></div>
            <div class="rep-kpi__foot"><span>por venta aprobada · histórico</span></div>
        </article>
    </section>

    <!-- Row A: revenue trend + conversion funnel -->
    <div class="rep-grid rep-grid--wide">
        <article class="dash-card">
            <div class="dash-card__head">
                <h3><i data-lucide="line-chart"></i> Tendencia de ingresos y actividad</h3>
                <div class="dash-seg" role="tablist" aria-label="Métrica del gráfico">
                    <button type="button" class="is-active" data-rep-metric="ingresos">Ingresos</button>
                    <button type="button" data-rep-metric="cotizaciones">Cotiz.</button>
                    <button type="button" data-rep-metric="tickets">Tickets</button>
                </div>
            </div>
            <div class="rep-chart rep-chart--lg">
                <?php if ($trendHas): ?>
                    <canvas id="repTrend"></canvas>
                <?php else: ?>
                    <div class="chart-empty"><i data-lucide="line-chart"></i><strong>Aún no hay actividad</strong><p>Cuando registres cotizaciones aprobadas y tickets, la tendencia mensual aparecerá aquí.</p></div>
                <?php endif; ?>
            </div>
        </article>

        <article class="dash-card">
            <div class="dash-card__head">
                <h3><i data-lucide="filter"></i> Embudo de conversión</h3>
                <span class="rep-card__sub"><?= e((string) array_sum(array_column($funnel, 'count'))) ?> cotiz.</span>
            </div>
            <?php if ($funnelHas): ?>
                <div class="rep-funnel">
                    <?php foreach ($funnel as $f): $pct = round($f['count'] / $pipelineMax * 100); ?>
                        <div class="rep-funnel__row">
                            <span class="rep-funnel__label"><?= e($f['stage']) ?></span>
                            <div class="rep-funnel__track"><div class="rep-funnel__fill" style="width:<?= e((string) max(4, $pct)) ?>%;background:<?= e($pipeline[$f['stage']]['color'] ?? '#0a7d36') ?>"></div></div>
                            <span class="rep-funnel__val"><?= e((string) $f['count']) ?> <small><?= $money0($pipeline[$f['stage']]['amount'] ?? 0) ?></small></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="chart-empty"><i data-lucide="filter"></i><strong>Sin cotizaciones</strong><p>El embudo mostrará cada etapa al crear cotizaciones.</p></div>
            <?php endif; ?>
        </article>
    </div>

    <!-- Row B: revenue by business line + top clients -->
    <div class="rep-grid rep-grid--2">
        <article class="dash-card">
            <div class="dash-card__head">
                <h3><i data-lucide="layers"></i> Ingresos por línea de negocio</h3>
                <span class="rep-card__sub">cotizaciones · histórico</span>
            </div>
            <?php if ($lineHas): ?>
                <div class="rep-rank">
                    <?php foreach ($byLine as $l): $w = round($l['amount'] / $lineMax * 100); ?>
                        <div class="rep-rank__row">
                            <span class="rep-rank__ic" style="background:<?= e($l['color']) ?>1a;color:<?= e($l['color']) ?>"><i data-lucide="<?= e($l['icon']) ?>"></i></span>
                            <div class="rep-rank__main">
                                <div class="rep-rank__row1"><b><?= e($l['line']) ?></b><span class="rep-rank__amt"><?= $money0($l['amount']) ?></span></div>
                                <div class="rep-rank__track"><div class="rep-rank__fill" style="width:<?= e((string) max(3, $w)) ?>%;background:<?= e($l['color']) ?>"></div></div>
                                <span class="rep-rank__pct"><?= e((string) $l['pct']) ?>% · <?= e((string) $l['count']) ?> cotiz.</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="chart-empty"><i data-lucide="layers"></i><strong>Sin datos por línea</strong><p>Asigna una línea de negocio a tus cotizaciones para ver este desglose real.</p></div>
            <?php endif; ?>
        </article>

        <article class="dash-card">
            <div class="dash-card__head">
                <h3><i data-lucide="award"></i> Clientes con mayor valor</h3>
                <span class="rep-card__sub">pipeline · equipos · tickets</span>
            </div>
            <div class="crm-table-wrap" style="padding:0 .4rem .6rem">
                <table class="crm-table">
                    <thead><tr><th>Cliente</th><th class="text-right">Pipeline</th><th class="text-right">Equipos</th><th class="text-right">Tickets</th></tr></thead>
                    <tbody>
                        <?php foreach ($topClients as $c): ?>
                            <tr>
                                <td><a href="<?= url('crm/cliente.php?id=' . (int) ($c['id'] ?? 0)) ?>"><strong><?= e($c['name']) ?></strong></a></td>
                                <td class="text-right"><strong><?= $money0($c['quote_value']) ?></strong></td>
                                <td class="text-right"><?= e((string) (int) $c['equipment_count']) ?></td>
                                <td class="text-right"><strong class="text-sch-blue"><?= e((string) (int) $c['ticket_count']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$topClients): ?><tr><td colspan="4" class="text-center" style="color:var(--muted);padding:1.5rem">Aún no hay clientes registrados.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </div>

    <!-- Row C: tickets status + priority + SLA -->
    <div class="rep-grid rep-grid--3">
        <article class="dash-card">
            <div class="dash-card__head"><h3><i data-lucide="pie-chart"></i> Tickets por estado</h3></div>
            <div class="rep-chart rep-chart--sm">
                <?php if ($ticketStatusHas): ?><canvas id="repTicketStatus"></canvas><?php else: ?>
                    <div class="chart-empty"><i data-lucide="pie-chart"></i><strong>Sin tickets</strong><p>La distribución por estado aparecerá al recibir casos.</p></div>
                <?php endif; ?>
            </div>
        </article>
        <article class="dash-card">
            <div class="dash-card__head"><h3><i data-lucide="signal"></i> Tickets por prioridad</h3></div>
            <div class="rep-chart rep-chart--sm">
                <?php if ($ticketPriorityHas): ?><canvas id="repTicketPriority"></canvas><?php else: ?>
                    <div class="chart-empty"><i data-lucide="signal"></i><strong>Sin tickets</strong><p>La carga por prioridad aparecerá al recibir casos.</p></div>
                <?php endif; ?>
            </div>
        </article>
        <article class="dash-card">
            <div class="dash-card__head"><h3><i data-lucide="gauge"></i> SLA de soporte</h3></div>
            <div class="rep-sla">
                <div class="rep-sla__cell <?= $resolution['avg_hours'] > 0 && $resolution['avg_hours'] <= 48 ? 'is-good' : ($resolution['avg_hours'] > 72 ? 'is-bad' : 'is-warn') ?>"><span>Resolución prom.</span><strong><?= e((string) $resolution['avg_hours']) ?> h</strong></div>
                <div class="rep-sla__cell <?= $resolution['sla_pct'] >= 80 ? 'is-good' : ($resolution['sla_pct'] >= 50 ? 'is-warn' : 'is-bad') ?>"><span>Dentro de SLA</span><strong><?= e((string) $resolution['sla_pct']) ?>%</strong></div>
                <div class="rep-sla__cell"><span>Resueltos</span><strong><?= e((string) $resolution['resolved']) ?></strong></div>
                <div class="rep-sla__cell <?= $resolution['overdue'] > 0 ? 'is-bad' : 'is-good' ?>"><span>Vencidos</span><strong><?= e((string) $resolution['overdue']) ?></strong></div>
                <div class="rep-sla__cell"><span>En backlog</span><strong><?= e((string) $resolution['backlog']) ?></strong></div>
            </div>
        </article>
    </div>

    <!-- Row D: equipment status + brand -->
    <div class="rep-grid rep-grid--2">
        <article class="dash-card">
            <div class="dash-card__head"><h3><i data-lucide="activity-square"></i> Equipos por estado</h3><a class="dash-card__meta" href="<?= url('crm/equipos.php') ?>">Inventario <i data-lucide="arrow-up-right" class="h-3.5 w-3.5"></i></a></div>
            <div class="rep-chart rep-chart--sm">
                <?php if ($equipStatusHas): ?><canvas id="repEquipStatus"></canvas><?php else: ?>
                    <div class="chart-empty"><i data-lucide="monitor"></i><strong>Sin equipos</strong><p>Registra el inventario instalado para ver su estado.</p></div>
                <?php endif; ?>
            </div>
        </article>
        <article class="dash-card">
            <div class="dash-card__head"><h3><i data-lucide="package"></i> Equipos por marca</h3></div>
            <div class="rep-chart rep-chart--sm">
                <?php if ($equipBrandHas): ?><canvas id="repEquipBrand"></canvas><?php else: ?>
                    <div class="chart-empty"><i data-lucide="package"></i><strong>Sin marcas</strong><p>El desglose por fabricante aparecerá con el inventario.</p></div>
                <?php endif; ?>
            </div>
        </article>
    </div>

    <!-- Row E: leads -->
    <div class="rep-grid rep-grid--wide">
        <article class="dash-card">
            <div class="dash-card__head"><h3><i data-lucide="inbox"></i> Leads recientes del sitio público</h3><span class="rep-card__sub"><?= e((string) $leads['total']) ?> total</span></div>
            <div class="crm-table-wrap" style="padding:0 .4rem .6rem">
                <table class="crm-table">
                    <thead><tr><th>Contacto</th><th>Tipo</th><th>Mensaje</th></tr></thead>
                    <tbody>
                        <?php foreach ($leads['recent'] as $lead): ?>
                            <tr>
                                <td><strong><?= e($lead['name']) ?></strong><p class="text-xs text-slate-500"><?= e($lead['company'] ?: 'Sin empresa') ?> · <?= e($lead['email']) ?></p></td>
                                <td><?= e($lead['type'] ?: 'General') ?></td>
                                <td class="text-slate-600" style="max-width:340px"><?= e(mb_strimwidth((string) $lead['message'], 0, 90, '…')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($leads['recent'])): ?>
                            <tr><td colspan="3"><div class="crm-empty" style="border:0;background:none"><i data-lucide="inbox" class="h-6 w-6"></i><strong>No hay leads registrados</strong><p>Las solicitudes del sitio público aparecerán aquí.</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
        <article class="dash-card">
            <div class="dash-card__head"><h3><i data-lucide="git-fork"></i> Leads por estado</h3></div>
            <div class="rep-chart rep-chart--sm">
                <?php if ($leadHas): ?><canvas id="repLeads"></canvas><?php else: ?>
                    <div class="chart-empty"><i data-lucide="inbox"></i><strong>Sin leads</strong><p>Los formularios del sitio público alimentarán este gráfico.</p></div>
                <?php endif; ?>
            </div>
        </article>
    </div>
</div>

<script>
(function () {
    var REP = <?= json_encode([
        'periodLabel' => $period['label'],
        'trend' => $trend,
        'ticketStatus' => ['labels' => array_column($ticketStatus, 'status'), 'data' => array_map('intval', array_column($ticketStatus, 'total')), 'colors' => array_map(fn ($s) => $statusColors[$s] ?? '#94a3b8', array_column($ticketStatus, 'status'))],
        'ticketPriority' => ['labels' => array_column($ticketPriority, 'priority'), 'data' => array_map('intval', array_column($ticketPriority, 'total')), 'colors' => array_map(fn ($s) => $priorityColors[$s] ?? '#94a3b8', array_column($ticketPriority, 'priority'))],
        'equipStatus' => ['labels' => array_column($equipStatus, 'status'), 'data' => array_map('intval', array_column($equipStatus, 'total')), 'colors' => array_map(fn ($s) => $equipColors[strtolower($s)] ?? '#94a3b8', array_column($equipStatus, 'status'))],
        'equipBrand' => ['labels' => array_column($equipBrand, 'brand'), 'data' => array_map('intval', array_column($equipBrand, 'total'))],
        'leads' => ['labels' => array_column($leads['by_status'], 'status'), 'data' => array_map('intval', array_column($leads['by_status'], 'total'))],
        'byLine' => array_map(fn ($l) => ['linea' => $l['line'], 'monto' => $l['amount'], 'pct' => $l['pct'], 'cotizaciones' => $l['count']], $byLine),
        'funnel' => $funnel,
        'topClients' => array_map(fn ($c) => ['cliente' => $c['name'], 'pipeline' => (float) $c['quote_value'], 'equipos' => (int) $c['equipment_count'], 'tickets' => (int) $c['ticket_count']], $topClients),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    var charts = {};
    var fmtK = function (v) { return v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v; };

    function donut(id, cfg) {
        var ctx = document.getElementById(id);
        if (!ctx || !window.Chart) return;
        charts[id] = new Chart(ctx, {
            type: 'doughnut',
            data: { labels: cfg.labels, datasets: [{ data: cfg.data, backgroundColor: cfg.colors, borderColor: '#fff', borderWidth: 2, hoverOffset: 6 }] },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '62%',
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#52616f', font: { size: 11, weight: '600' }, usePointStyle: true, pointStyle: 'circle', padding: 12 } },
                    tooltip: { backgroundColor: 'rgba(8,18,30,.92)', padding: 10, cornerRadius: 8, usePointStyle: true }
                }
            }
        });
    }

    function barH(id, labels, data, color) {
        var ctx = document.getElementById(id);
        if (!ctx || !window.Chart) return;
        charts[id] = new Chart(ctx, {
            type: 'bar',
            data: { labels: labels, datasets: [{ data: data, backgroundColor: color, borderRadius: 6, maxBarThickness: 30 }] },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(8,18,30,.92)', padding: 10, cornerRadius: 8, displayColors: false } },
                scales: {
                    x: { beginAtZero: true, border: { display: false }, grid: { color: '#eef3f8' }, ticks: { color: '#8696a6', font: { size: 11 }, precision: 0 } },
                    y: { grid: { display: false }, border: { display: false }, ticks: { color: '#52616f', font: { weight: '600', size: 11 } } }
                }
            }
        });
    }

    var trendMeta = {
        ingresos: { label: 'Ingresos', color: '#0a7d36', data: REP.trend.ingresos, fmt: function (v) { return 'RD$ ' + v + 'k'; } },
        cotizaciones: { label: 'Cotizaciones', color: '#9c7d34', data: REP.trend.cotizaciones, fmt: function (v) { return v + ' cotiz.'; } },
        tickets: { label: 'Tickets', color: '#0666b3', data: REP.trend.tickets, fmt: function (v) { return v + ' tickets'; } }
    };

    function buildTrend(metric) {
        var ctx = document.getElementById('repTrend');
        if (!ctx || !window.Chart) return;
        var m = trendMeta[metric];
        if (charts.repTrend) {
            charts.repTrend.data.datasets[0].data = m.data;
            charts.repTrend.data.datasets[0].label = m.label;
            charts.repTrend.data.datasets[0].borderColor = m.color;
            charts.repTrend.data.datasets[0].backgroundColor = m.color + '22';
            charts.repTrend.options.plugins.tooltip.callbacks.label = function (c) { return m.fmt(c.parsed.y); };
            charts.repTrend.update();
            return;
        }
        charts.repTrend = new Chart(ctx, {
            type: 'line',
            data: { labels: REP.trend.labels, datasets: [{ label: m.label, data: m.data, borderColor: m.color, backgroundColor: m.color + '22', borderWidth: 2.5, tension: .4, fill: true, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: m.color, pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2 }] },
            options: {
                responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(8,18,30,.92)', padding: 10, cornerRadius: 8, displayColors: false, callbacks: { label: function (c) { return m.fmt(c.parsed.y); } } } },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { color: '#56697b', font: { weight: '600', size: 11 } } },
                    y: { beginAtZero: true, border: { display: false }, grid: { color: '#eef3f8' }, ticks: { color: '#8696a6', maxTicksLimit: 6, font: { size: 11 } } }
                }
            }
        });
    }

    function init() {
        if (!window.Chart) return setTimeout(init, 120);
        Chart.defaults.font.family = "Inter, system-ui, sans-serif";
        <?php if ($trendHas): ?>buildTrend('ingresos');<?php endif; ?>
        <?php if ($ticketStatusHas): ?>donut('repTicketStatus', REP.ticketStatus);<?php endif; ?>
        <?php if ($ticketPriorityHas): ?>donut('repTicketPriority', { labels: REP.ticketPriority.labels, data: REP.ticketPriority.data, colors: REP.ticketPriority.colors });<?php endif; ?>
        <?php if ($equipStatusHas): ?>donut('repEquipStatus', REP.equipStatus);<?php endif; ?>
        <?php if ($equipBrandHas): ?>barH('repEquipBrand', REP.equipBrand.labels, REP.equipBrand.data, '#0a7d36');<?php endif; ?>
        <?php if ($leadHas): ?>donut('repLeads', { labels: REP.leads.labels, data: REP.leads.data, colors: ['#0666b3', '#9c7d34', '#0a7d36', '#94a3b8', '#1fa6d8'] });<?php endif; ?>

        document.querySelectorAll('[data-rep-metric]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('[data-rep-metric]').forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');
                buildTrend(btn.getAttribute('data-rep-metric'));
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

    /* ---- Exports ---- */
    function buildRows() {
        var rows = [];
        rows.push(['SCH MEDICOS — Centro de reportes']);
        rows.push(['Periodo', REP.periodLabel]);
        rows.push([]);
        rows.push(['Embudo de conversión', 'Cotizaciones']);
        REP.funnel.forEach(function (f) { rows.push([f.stage, f.count]); });
        rows.push([]);
        rows.push(['Ingresos por línea de negocio', 'Monto (RD$)', '%', 'Cotizaciones']);
        REP.byLine.forEach(function (l) { rows.push([l.linea, l.monto, l.pct, l.cotizaciones]); });
        rows.push([]);
        rows.push(['Clientes con mayor valor', 'Pipeline (RD$)', 'Equipos', 'Tickets']);
        REP.topClients.forEach(function (c) { rows.push([c.cliente, c.pipeline, c.equipos, c.tickets]); });
        rows.push([]);
        rows.push(['Tickets por estado', 'Total']);
        REP.ticketStatus.labels.forEach(function (l, i) { rows.push([l, REP.ticketStatus.data[i]]); });
        rows.push([]);
        rows.push(['Equipos por estado', 'Total']);
        REP.equipStatus.labels.forEach(function (l, i) { rows.push([l, REP.equipStatus.data[i]]); });
        return rows;
    }

    window.repExportCSV = function () {
        var csv = buildRows().map(function (r) {
            return r.map(function (c) { c = (c == null ? '' : String(c)); return /[",\n;]/.test(c) ? '"' + c.replace(/"/g, '""') + '"' : c; }).join(';');
        }).join('\r\n');
        window.crmDownload('reporte-sch-' + new Date().toISOString().slice(0, 10) + '.csv', '﻿' + csv);
        if (window.crmToast) window.crmToast('Reporte exportado a CSV', 'file-down');
    };

    window.repExportExcel = function () {
        var html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body><table border="1">';
        buildRows().forEach(function (r) {
            html += '<tr>' + (r.length ? r.map(function (c) { return '<td>' + (c == null ? '' : String(c).replace(/&/g, '&amp;').replace(/</g, '&lt;')) + '</td>'; }).join('') : '<td></td>') + '</tr>';
        });
        html += '</table></body></html>';
        window.crmDownload('reporte-sch-' + new Date().toISOString().slice(0, 10) + '.xls', html, 'application/vnd.ms-excel');
        if (window.crmToast) window.crmToast('Reporte exportado a Excel', 'sheet');
    };
})();
</script>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
