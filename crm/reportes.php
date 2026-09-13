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
$billing       = analytics_billing($period);
$canCartera    = can_view_cartera();
$hasBilling    = analytics_has_billing();
$margin        = analytics_margin($period);
$marginTop     = analytics_margin_by_product($period, 8);
/* Cobranza: solo se consulta si el usuario puede ver cartera. */
$cashflow      = $canCartera ? analytics_cashflow_forecast() : null;
$collection    = $canCartera ? analytics_collection_metrics($period) : null;
$payers        = $canCartera ? analytics_collection_by_client(6) : [];

/* ---- Helpers ------------------------------------------------------------- */
$money0 = fn ($v) => 'RD$ ' . number_format((float) $v, 0, '.', ',');
$kfmt = fn ($v) => $v >= 1000000 ? 'RD$ ' . number_format($v / 1000000, 2) . 'M' : ($v >= 1000 ? 'RD$ ' . number_format($v / 1000, 0) . 'k' : 'RD$ ' . number_format((float) $v, 0));
$delta_html = function (?float $d): string {
    // NULL = el periodo anterior estaba en cero: no hay porcentaje que calcular.
    if ($d === null) return '<span class="dash-sub">Sin base de comparación</span>';
    if ($d == 0.0) return '<span class="dash-sub">Sin variación</span>';
    $up = $d > 0;
    $cls = $up ? 'dash-delta--up' : 'dash-delta--down';
    $ic = $up ? 'trending-up' : 'trending-down';
    return '<span class="dash-delta ' . $cls . '"><i data-lucide="' . $ic . '"></i>' . ($up ? '+' : '') . e((string) $d) . '%</span>';
};

/* Una sola familia: del neutro al verde del logo, con el bronce del caduceo
   para lo que espera. El rojo no entra aquí: está reservado. */
$statusColors = [
    'Abierto' => '#6C5E3D', 'En proceso' => '#66746D', 'Cotizado' => '#0BA344',
    'Resuelto' => '#027F31', 'Cerrado' => '#C3CCC7', 'Pendiente' => '#7A6320',
];
$priorityColors = ['Critica' => '#C2202C', 'Alta' => '#D4564F', 'Media' => '#6C5E3D', 'Baja' => '#66746D'];
$equipColors = ['activo' => '#027F31', 'requiere revision' => '#6C5E3D', 'fuera de servicio' => '#C2202C', 'retirado' => '#C3CCC7'];

/* ---- Presence flags (drive honest empty states) -------------------------- */
$trendHas = array_sum($trend['ingresos_raw']) > 0 || array_sum($trend['cotizaciones']) > 0 || array_sum($trend['tickets']) > 0
    || array_sum($trend['facturado_raw']) > 0 || array_sum($trend['cobrado_raw']) > 0;

/* Conversión del ciclo: de lo aprobado, ¿cuánto se facturó?; de lo facturado,
   ¿cuánto entró en caja? Son los dos puntos donde se pierde dinero en silencio. */
$flowPct = function (float $part, float $whole): ?float {
    return $whole > 0.009 ? round($part / $whole * 100) : null;
};
$billedPct    = $flowPct($billing['billed']['value'], $kpis['won']['value']);
$collectedPct = $flowPct($billing['collected']['value'], $billing['billed']['value']);
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
<?= sch_encabezado('Reportes', 'Indicadores comerciales, cobranza, margen y SLA') ?>


<?php if (!$live): ?>
    <div class="gas-aviso">Modo demostración (sin MySQL). Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para reportes con datos reales.</div>
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

    <!-- Ciclo comercial: aprobado -> facturado -> cobrado -> por cobrar -->
    <section class="rep-flow" aria-label="Ciclo comercial del periodo">
        <header class="rep-flow__head">
            <h3><i data-lucide="route"></i> Ciclo comercial · <?= e($period['label']) ?></h3>
            <p>Cada etapa mide una cosa distinta: lo <b>aprobado</b> son cotizaciones aceptadas, lo <b>facturado</b> son comprobantes fiscales emitidos y lo <b>cobrado</b> es dinero que entró. Las diferencias entre etapas son el trabajo pendiente, no un error.</p>
        </header>
        <div class="rep-flow__track">
            <article class="rep-flow__step rep-flow__step--quote">
                <span class="rep-flow__label"><i data-lucide="file-check-2"></i>Aprobado</span>
                <strong class="rep-flow__value"><?= e($kfmt($kpis['won']['value'])) ?></strong>
                <span class="rep-flow__foot"><?= $delta_html($kpis['won']['delta']) ?></span>
                <span class="rep-flow__sub">cotizaciones aceptadas</span>
            </article>

            <div class="rep-flow__link" aria-hidden="true">
                <i data-lucide="chevron-right"></i>
                <?php if ($billedPct !== null): ?><span class="rep-flow__pct <?= $billedPct < 60 ? 'is-warn' : '' ?>"><?= e((string) $billedPct) ?>%</span><?php endif; ?>
            </div>

            <article class="rep-flow__step rep-flow__step--billed">
                <span class="rep-flow__label"><i data-lucide="receipt"></i>Facturado</span>
                <strong class="rep-flow__value"><?= e($kfmt($billing['billed']['value'])) ?></strong>
                <span class="rep-flow__foot"><?= $delta_html($billing['billed']['delta']) ?></span>
                <span class="rep-flow__sub"><?= e((string) $billing['billed']['count']) ?> comprobante<?= $billing['billed']['count'] === 1 ? '' : 's' ?> emitido<?= $billing['billed']['count'] === 1 ? '' : 's' ?></span>
            </article>

            <div class="rep-flow__link" aria-hidden="true">
                <i data-lucide="chevron-right"></i>
                <?php if ($collectedPct !== null): ?><span class="rep-flow__pct <?= $collectedPct < 60 ? 'is-warn' : '' ?>"><?= e((string) $collectedPct) ?>%</span><?php endif; ?>
            </div>

            <article class="rep-flow__step rep-flow__step--paid">
                <span class="rep-flow__label"><i data-lucide="banknote"></i>Cobrado</span>
                <strong class="rep-flow__value"><?= e($kfmt($billing['collected']['value'])) ?></strong>
                <span class="rep-flow__foot"><?= $delta_html($billing['collected']['delta']) ?></span>
                <span class="rep-flow__sub"><?= e((string) $billing['collected']['count']) ?> pago<?= $billing['collected']['count'] === 1 ? '' : 's' ?> recibido<?= $billing['collected']['count'] === 1 ? '' : 's' ?></span>
            </article>

            <?php if ($canCartera): ?>
                <div class="rep-flow__link" aria-hidden="true"><i data-lucide="chevron-right"></i></div>
                <article class="rep-flow__step rep-flow__step--due">
                    <span class="rep-flow__label"><i data-lucide="calendar-clock"></i>Por cobrar</span>
                    <strong class="rep-flow__value"><?= e($kfmt($billing['outstanding']['value'])) ?></strong>
                    <span class="rep-flow__foot">
                        <?php if ($billing['outstanding']['overdue_value'] > 0): ?>
                            <span class="rep-flow__alert"><i data-lucide="alert-triangle"></i><?= e($kfmt($billing['outstanding']['overdue_value'])) ?> vencido</span>
                        <?php else: ?>
                            <span>Sin saldo vencido</span>
                        <?php endif; ?>
                    </span>
                    <span class="rep-flow__sub"><?= e((string) $billing['outstanding']['count']) ?> con saldo · saldo de hoy</span>
                </article>
            <?php endif; ?>
        </div>
        <?php if ($live && !$hasBilling): ?>
            <p class="rep-flow__note"><i data-lucide="info"></i> Aún no existe el módulo de facturación en esta base de datos, así que «facturado» y «cobrado» salen en cero. Ejecuta <code>php database/migrate.php</code> para crearlo.</p>
        <?php elseif ($live && $kpis['won']['value'] > 0 && $billing['billed']['value'] <= 0.009): ?>
            <p class="rep-flow__note rep-flow__note--warn"><i data-lucide="alert-triangle"></i> Hay cotizaciones aprobadas en el periodo pero ningún comprobante emitido. Revisa qué ventas cerradas siguen sin facturar.</p>
        <?php endif; ?>
    </section>

    <!-- KPI row -->
    <section class="rep-kpis">
        <article class="rep-kpi">
            <div class="rep-kpi__top"><span class="rep-kpi__label">Pipeline activo</span><span class="rep-kpi__ic"><i data-lucide="trending-up"></i></span></div>
            <div class="rep-kpi__value"><?= e($kfmt($kpis['pipeline']['value'])) ?></div>
            <div class="rep-kpi__foot"><span><?= e((string) (int) ($kpis['pipeline']['open_count'] ?? 0)) ?> cotizaciones abiertas · snapshot</span></div>
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

    <!-- Margen: lo que de verdad se ganó, con su cobertura -->
    <div class="rep-grid rep-grid--2">
        <article class="dash-card">
            <div class="dash-card__head">
                <h3><i data-lucide="scale"></i> Margen · <?= e($period['label']) ?></h3>
                <a class="dash-card__meta" href="<?= url('crm/productos.php') ?>">Catálogo <i data-lucide="arrow-up-right" class="h-3.5 w-3.5"></i></a>
            </div>
            <?php if ($margin['lines_costed'] === 0): ?>
                <div class="chart-empty">
                    <i data-lucide="scale"></i><strong>Todavía no se puede medir el margen</strong>
                    <p>Ninguna partida facturada en el periodo tiene costo. Registra el costo en el <a href="<?= url('crm/productos.php') ?>">catálogo</a> e insértalo desde ahí al cotizar o facturar: el costo viaja con la partida y queda congelado en el documento.</p>
                </div>
            <?php else: ?>
                <div class="rep-sla" style="margin-bottom:.3rem">
                    <div class="rep-sla__cell <?= $margin['margin'] < 0 ? 'is-bad' : 'is-good' ?>"><span>Margen</span><strong><?= e($kfmt($margin['margin'])) ?></strong></div>
                    <div class="rep-sla__cell <?= $margin['pct'] === null ? '' : ($margin['pct'] >= 25 ? 'is-good' : ($margin['pct'] >= 10 ? 'is-warn' : 'is-bad')) ?>"><span>% sobre venta</span><strong><?= $margin['pct'] === null ? '—' : e((string) $margin['pct']) . '%' ?></strong></div>
                    <div class="rep-sla__cell <?= $margin['coverage'] === null ? '' : ($margin['coverage'] >= 80 ? 'is-good' : ($margin['coverage'] >= 40 ? 'is-warn' : 'is-bad')) ?>"><span>Cobertura</span><strong><?= $margin['coverage'] === null ? '—' : e((string) $margin['coverage']) . '%' ?></strong></div>
                </div>
                <div class="crm-table-wrap" style="padding:0 .4rem .3rem">
                    <table class="crm-table">
                        <tbody>
                            <tr><td>Venta de las partidas con costo</td><td class="text-right"><strong><?= money($margin['revenue']) ?></strong></td></tr>
                            <tr><td>− Costo de esas partidas</td><td class="text-right"><strong>− <?= money($margin['cost']) ?></strong></td></tr>
                            <tr style="border-top:2px solid var(--line)"><td><b>Margen</b></td><td class="text-right"><strong class="<?= $margin['margin'] < 0 ? 'sch-monto--baja' : 'sch-monto--sube' ?>" style="font-size:1.05rem"><?= money($margin['margin']) ?></strong></td></tr>
                        </tbody>
                    </table>
                </div>
                <?php if (($margin['coverage'] ?? 100) < 99.5): ?>
                    <p class="rep-cash__note rep-cash__note--warn">
                        <i data-lucide="alert-triangle"></i>
                        <span>Este margen cubre <b><?= e((string) $margin['coverage']) ?>%</b> de lo facturado (<?= e((string) $margin['lines_costed']) ?> de <?= e((string) $margin['lines']) ?> partidas tienen costo). El resto queda fuera del cálculo: sin costo no se sabe si se ganó, y contarlo como cero daría un margen del 100% que no es real.</span>
                    </p>
                <?php else: ?>
                    <p class="rep-cash__note"><i data-lucide="check-circle-2"></i> <span>Todas las partidas facturadas del periodo tienen costo: el margen está completo.</span></p>
                <?php endif; ?>
            <?php endif; ?>
        </article>

        <article class="dash-card">
            <div class="dash-card__head">
                <h3><i data-lucide="package"></i> Margen por producto</h3>
                <span class="rep-card__sub">del catálogo</span>
            </div>
            <?php if (!$marginTop): ?>
                <div class="chart-empty">
                    <i data-lucide="package"></i><strong>Sin partidas del catálogo</strong>
                    <p>Aquí aparece qué se vendió y cuánto dejó cada ficha, en cuanto se facturen partidas insertadas desde el catálogo con su costo.</p>
                </div>
            <?php else: ?>
                <div class="crm-table-wrap" style="padding:0 .4rem .3rem">
                    <table class="crm-table">
                        <thead><tr><th>Producto</th><th class="text-right">Cant.</th><th class="text-right">Venta</th><th class="text-right">Margen</th></tr></thead>
                        <tbody>
                        <?php foreach ($marginTop as $mp):
                            $mpMargin = (float) $mp['revenue'] - (float) $mp['cost'];
                            $mpPct = (float) $mp['revenue'] > 0.009 ? round($mpMargin / (float) $mp['revenue'] * 100) : null; ?>
                            <tr>
                                <td><strong><?= e((string) $mp['name']) ?></strong><?php if (!empty($mp['category'])): ?><p class="text-xs text-slate-500"><?= e((string) $mp['category']) ?></p><?php endif; ?></td>
                                <td class="text-right"><?= e(rtrim(rtrim(number_format((float) $mp['qty'], 2), '0'), '.')) ?></td>
                                <td class="text-right"><?= money($mp['revenue']) ?></td>
                                <td class="text-right">
                                    <strong class="<?= $mpMargin < 0 ? 'sch-monto--baja' : 'sch-monto--sube' ?>"><?= money($mpMargin) ?></strong>
                                    <?php if ($mpPct !== null): ?><p class="text-xs text-slate-500"><?= e((string) $mpPct) ?>%</p><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="rep-cash__note">Solo partidas insertadas desde el catálogo y con costo. Las escritas a mano no se pueden atribuir a una ficha.</p>
            <?php endif; ?>
        </article>
    </div>

    <?php if ($canCartera): ?>
    <!-- Row A0: proyección de caja + comportamiento de cobro -->
    <div class="rep-grid rep-grid--2">
        <article class="dash-card">
            <div class="dash-card__head">
                <h3><i data-lucide="wallet-cards"></i> Flujo de caja proyectado</h3>
                <a class="dash-card__meta" href="<?= url('crm/facturas.php') ?>">Cartera <i data-lucide="arrow-up-right" class="h-3.5 w-3.5"></i></a>
            </div>
            <?php if ($cashflow['total']['amount'] <= 0.009): ?>
                <div class="chart-empty"><i data-lucide="wallet-cards"></i><strong>Sin saldo por cobrar</strong><p>Cuando haya comprobantes emitidos con saldo, aquí verás cuándo se espera cobrarlos.</p></div>
            <?php else:
                $cashMax = max(array_map(fn ($b) => (float) $b['amount'], $cashflow['buckets'])) ?: 1.0; ?>
                <div class="rep-cash">
                    <?php foreach ($cashflow['buckets'] as $key => $b):
                        if ($b['count'] === 0) { continue; }
                        $w = max(3, round($b['amount'] / $cashMax * 100)); ?>
                        <div class="rep-cash__row <?= $key === 'vencido' ? 'rep-cash__row--late' : '' ?>">
                            <div class="rep-cash__top">
                                <span class="rep-cash__label"><?= e($b['label']) ?></span>
                                <span class="rep-cash__amt"><?= money($b['amount']) ?></span>
                            </div>
                            <span class="rep-cash__track"><span class="rep-cash__fill" style="width:<?= e((string) $w) ?>%"></span></span>
                            <span class="rep-cash__sub"><?= e((string) $b['count']) ?> comprobante<?= $b['count'] === 1 ? '' : 's' ?><?= $key === 'vencido' ? ' · debió entrar ya' : '' ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="rep-cash__total"><span>Total por cobrar</span><b><?= money($cashflow['total']['amount']) ?></b></div>
                </div>
                <p class="rep-cash__note"><i data-lucide="info" style="width:12px;height:12px;vertical-align:-1px"></i> Reparte el saldo vivo por su fecha de vencimiento. Es un supuesto — que cada cliente pague el día que vence —, no una predicción de caja.</p>
            <?php endif; ?>
        </article>

        <article class="dash-card">
            <div class="dash-card__head">
                <h3><i data-lucide="hourglass"></i> Cobranza</h3>
                <span class="rep-card__sub"><?= e($period['label']) ?></span>
            </div>
            <div class="rep-sla" style="margin-bottom:.2rem">
                <div class="rep-sla__cell <?= $collection['dso'] === null ? '' : ($collection['dso'] <= 45 ? 'is-good' : ($collection['dso'] <= 75 ? 'is-warn' : 'is-bad')) ?>">
                    <span>DSO · <?= e((string) $collection['dso_days']) ?> d</span><strong><?= $collection['dso'] === null ? '—' : e((string) $collection['dso']) . ' d' ?></strong>
                </div>
                <div class="rep-sla__cell <?= $collection['avg_days'] === null ? '' : ($collection['avg_days'] <= 30 ? 'is-good' : ($collection['avg_days'] <= 60 ? 'is-warn' : 'is-bad')) ?>">
                    <span>Tardanza real</span><strong><?= $collection['avg_days'] === null ? '—' : e((string) $collection['avg_days']) . ' d' ?></strong>
                </div>
                <div class="rep-sla__cell <?= $collection['on_time_pct'] === null ? '' : ($collection['on_time_pct'] >= 80 ? 'is-good' : ($collection['on_time_pct'] >= 50 ? 'is-warn' : 'is-bad')) ?>">
                    <span>Cobrado a tiempo</span><strong><?= $collection['on_time_pct'] === null ? '—' : e((string) $collection['on_time_pct']) . '%' ?></strong>
                </div>
            </div>
            <p class="rep-cash__note" style="margin-top:.45rem">
                <b>DSO</b>: cuántos días de facturación tienes en la calle. Se mide siempre sobre los últimos <?= e((string) $collection['dso_days']) ?> días —no sobre el periodo elegido— para que sea comparable mes a mes. <b>Tardanza real</b> y <b>cobrado a tiempo</b> sí corresponden al periodo. Un guion significa que aún no hay base para calcularlo.
            </p>
            <?php if ($payers): ?>
                <div class="crm-table-wrap" style="padding:0 .4rem .3rem">
                    <table class="crm-table">
                        <thead><tr><th>Cliente</th><th class="text-right">Tarda</th><th class="text-right">A tiempo</th><th class="text-right">Saldo</th></tr></thead>
                        <tbody>
                        <?php foreach ($payers as $p): ?>
                            <tr>
                                <td>
                                    <?php if ($p['client_id'] > 0): ?><a href="<?= url('crm/cliente.php?id=' . (int) $p['client_id']) ?>"><strong><?= e($p['name']) ?></strong></a><?php else: ?><strong><?= e($p['name']) ?></strong><?php endif; ?>
                                    <?php if ($p['overdue'] > 0.009): ?><p class="text-xs sch-monto--baja" style="font-weight:600"><?= money($p['overdue']) ?> vencido</p><?php endif; ?>
                                </td>
                                <td class="text-right"><?= $p['avg_days'] === null ? '<span style="color:var(--muted)" title="Todavía no ha hecho ningún pago">sin historial</span>' : '<strong>' . e((string) round($p['avg_days'])) . ' d</strong>' ?></td>
                                <td class="text-right"><?= $p['on_time_pct'] === null ? '<span style="color:var(--muted)">—</span>' : e((string) round($p['on_time_pct'])) . '%' ?></td>
                                <td class="text-right"><?= $p['balance'] > 0.009 ? money($p['balance']) : '<span style="color:var(--muted)">—</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="rep-cash__note">Ordenado por quien más tarda en pagar: es la lista de llamadas del día. «Sin historial» es un cliente que debe y todavía no ha pagado nada — no hay hábito que medir, y por eso conviene mirarlo.</p>
            <?php else: ?>
                <div class="chart-empty" style="min-height:120px"><i data-lucide="hourglass"></i><strong>Sin pagos registrados</strong><p>El comportamiento de pago aparecerá al registrar cobros en las facturas.</p></div>
            <?php endif; ?>
        </article>
    </div>
    <?php endif; ?>

    <!-- Row A: revenue trend + conversion funnel -->
    <div class="rep-grid rep-grid--wide">
        <article class="dash-card">
            <div class="dash-card__head">
                <h3><i data-lucide="line-chart"></i> Tendencia mensual · 12 meses</h3>
                <div class="dash-seg" role="tablist" aria-label="Métrica del gráfico">
                    <button type="button" class="is-active" data-rep-metric="facturado">Facturado</button>
                    <button type="button" data-rep-metric="cobrado">Cobrado</button>
                    <button type="button" data-rep-metric="ingresos">Aprobado</button>
                    <button type="button" data-rep-metric="cotizaciones">Cotiz.</button>
                    <button type="button" data-rep-metric="tickets">Tickets</button>
                </div>
            </div>
            <div class="rep-chart rep-chart--lg">
                <?php if ($trendHas): ?>
                    <canvas id="repTrend"></canvas>
                <?php else: ?>
                    <div class="chart-empty"><i data-lucide="line-chart"></i><strong>Aún no hay actividad</strong><p>Cuando emitas comprobantes, registres cobros o abras tickets, la tendencia mensual aparecerá aquí.</p></div>
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
                            <div class="rep-funnel__track"><div class="rep-funnel__fill" style="width:<?= e((string) max(4, $pct)) ?>%;background:<?= e($pipeline[$f['stage']]['color'] ?? '#027F31') ?>"></div></div>
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
        'billing' => [
            'aprobado'  => (float) $kpis['won']['value'],
            'facturado' => (float) $billing['billed']['value'],
            'facturas'  => (int) $billing['billed']['count'],
            'cobrado'   => (float) $billing['collected']['value'],
            'pagos'     => (int) $billing['collected']['count'],
            // La cartera es un dato restringido: solo viaja al export si el
            // usuario tiene el permiso nominal para verla.
            'porCobrar' => $canCartera ? (float) $billing['outstanding']['value'] : null,
            'vencido'   => $canCartera ? (float) $billing['outstanding']['overdue_value'] : null,
        ],
        'margen' => [
            'margen' => $margin['margin'], 'pct' => $margin['pct'], 'cobertura' => $margin['coverage'],
            'venta' => $margin['revenue'], 'costo' => $margin['cost'],
            'lineas' => $margin['lines'], 'lineasConCosto' => $margin['lines_costed'],
            'productos' => array_map(fn ($p) => [
                'producto' => $p['name'], 'cantidad' => (float) $p['qty'],
                'venta' => (float) $p['revenue'], 'costo' => (float) $p['cost'],
                'margen' => round((float) $p['revenue'] - (float) $p['cost'], 2),
            ], $marginTop),
        ],
        'cobranza' => $canCartera ? [
            'dso' => $collection['dso'],
            'dsoDias' => $collection['dso_days'],
            'tardanza' => $collection['avg_days'],
            'aTiempo' => $collection['on_time_pct'],
            'tramos' => array_values(array_map(
                fn ($k, $b) => ['tramo' => $b['label'], 'monto' => (float) $b['amount'], 'documentos' => (int) $b['count']],
                array_keys($cashflow['buckets']),
                $cashflow['buckets']
            )),
            'clientes' => array_map(fn ($p) => [
                'cliente' => $p['name'], 'tardaDias' => $p['avg_days'], 'aTiempoPct' => $p['on_time_pct'],
                'saldo' => $p['balance'], 'vencido' => $p['overdue'],
            ], $payers),
        ] : null,
        'trend' => $trend,
        'ticketStatus' => ['labels' => array_column($ticketStatus, 'status'), 'data' => array_map('intval', array_column($ticketStatus, 'total')), 'colors' => array_map(fn ($s) => $statusColors[$s] ?? '#C3CCC7', array_column($ticketStatus, 'status'))],
        'ticketPriority' => ['labels' => array_column($ticketPriority, 'priority'), 'data' => array_map('intval', array_column($ticketPriority, 'total')), 'colors' => array_map(fn ($s) => $priorityColors[$s] ?? '#C3CCC7', array_column($ticketPriority, 'priority'))],
        'equipStatus' => ['labels' => array_column($equipStatus, 'status'), 'data' => array_map('intval', array_column($equipStatus, 'total')), 'colors' => array_map(fn ($s) => $equipColors[strtolower($s)] ?? '#C3CCC7', array_column($equipStatus, 'status'))],
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

    var moneyK = function (v) { return 'RD$ ' + v + 'k'; };
    var trendMeta = {
        facturado: { label: 'Facturado', color: '#027F31', data: REP.trend.facturado, fmt: moneyK },
        cobrado: { label: 'Cobrado', color: '#0BA344', data: REP.trend.cobrado, fmt: moneyK },
        ingresos: { label: 'Aprobado (cotizaciones)', color: '#6C5E3D', data: REP.trend.ingresos, fmt: moneyK },
        cotizaciones: { label: 'Cotizaciones', color: '#8A9A92', data: REP.trend.cotizaciones, fmt: function (v) { return v + ' cotiz.'; } },
        tickets: { label: 'Tickets', color: '#66746D', data: REP.trend.tickets, fmt: function (v) { return v + ' tickets'; } }
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
        <?php if ($trendHas): ?>buildTrend('facturado');<?php endif; ?>
        <?php if ($ticketStatusHas): ?>donut('repTicketStatus', REP.ticketStatus);<?php endif; ?>
        <?php if ($ticketPriorityHas): ?>donut('repTicketPriority', { labels: REP.ticketPriority.labels, data: REP.ticketPriority.data, colors: REP.ticketPriority.colors });<?php endif; ?>
        <?php if ($equipStatusHas): ?>donut('repEquipStatus', REP.equipStatus);<?php endif; ?>
        <?php if ($equipBrandHas): ?>barH('repEquipBrand', REP.equipBrand.labels, REP.equipBrand.data, '#027F31');<?php endif; ?>
        <?php if ($leadHas): ?>donut('repLeads', { labels: REP.leads.labels, data: REP.leads.data, colors: ['#027F31', '#0BA344', '#6C5E3D', '#66746D', '#C3CCC7'] });<?php endif; ?>

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
        rows.push(['Ciclo comercial', 'Monto (RD$)', 'Documentos']);
        rows.push(['Aprobado (cotizaciones)', REP.billing.aprobado, '']);
        rows.push(['Facturado (comprobantes emitidos)', REP.billing.facturado, REP.billing.facturas]);
        rows.push(['Cobrado (pagos recibidos)', REP.billing.cobrado, REP.billing.pagos]);
        if (REP.billing.porCobrar !== null) {
            rows.push(['Por cobrar (saldo de hoy)', REP.billing.porCobrar, '']);
            rows.push(['  de lo cual vencido', REP.billing.vencido, '']);
        }
        rows.push([]);
        rows.push(['Margen', 'Valor']);
        rows.push(['Venta de partidas con costo (RD$)', REP.margen.venta]);
        rows.push(['Costo de esas partidas (RD$)', REP.margen.costo]);
        rows.push(['Margen (RD$)', REP.margen.margen]);
        rows.push(['Margen %', REP.margen.pct === null ? 'sin base' : REP.margen.pct]);
        rows.push(['Cobertura % (venta con costo / venta total)', REP.margen.cobertura === null ? 'sin base' : REP.margen.cobertura]);
        rows.push(['Partidas con costo / totales', REP.margen.lineasConCosto + ' de ' + REP.margen.lineas]);
        if (REP.margen.productos.length) {
            rows.push([]);
            rows.push(['Margen por producto', 'Cantidad', 'Venta (RD$)', 'Costo (RD$)', 'Margen (RD$)']);
            REP.margen.productos.forEach(function (p) { rows.push([p.producto, p.cantidad, p.venta, p.costo, p.margen]); });
        }
        if (REP.cobranza) {
            rows.push([]);
            rows.push(['Cobranza', 'Valor']);
            rows.push(['DSO (días de venta por cobrar, últimos ' + REP.cobranza.dsoDias + ' días)', REP.cobranza.dso === null ? 'sin base' : REP.cobranza.dso]);
            rows.push(['Tardanza real en cobrar (días)', REP.cobranza.tardanza === null ? 'sin base' : REP.cobranza.tardanza]);
            rows.push(['Cobrado a tiempo (%)', REP.cobranza.aTiempo === null ? 'sin base' : REP.cobranza.aTiempo]);
            rows.push([]);
            rows.push(['Flujo de caja proyectado', 'Monto (RD$)', 'Comprobantes']);
            REP.cobranza.tramos.forEach(function (t) { rows.push([t.tramo, t.monto, t.documentos]); });
            rows.push([]);
            rows.push(['Comportamiento de pago', 'Tarda (días)', 'A tiempo (%)', 'Saldo (RD$)', 'Vencido (RD$)']);
            REP.cobranza.clientes.forEach(function (c) {
                rows.push([c.cliente, c.tardaDias === null ? 'sin base' : c.tardaDias, c.aTiempoPct, c.saldo, c.vencido]);
            });
        }
        rows.push([]);
        rows.push(['Tendencia mensual', 'Facturado (RD$)', 'Cobrado (RD$)', 'Aprobado (RD$)', 'Cotizaciones', 'Tickets']);
        REP.trend.labels.forEach(function (l, i) {
            rows.push([l, REP.trend.facturado_raw[i], REP.trend.cobrado_raw[i], REP.trend.ingresos_raw[i], REP.trend.cotizaciones[i], REP.trend.tickets[i]]);
        });
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
