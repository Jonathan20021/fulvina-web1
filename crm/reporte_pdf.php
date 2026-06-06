<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('reportes.view');
if (db(false)) { ensure_quote_schema(); }

use Dompdf\Dompdf;
use Dompdf\Options;

$period = analytics_period((string) ($_GET['period'] ?? 'month'));
$kpis        = analytics_kpis($period);
$funnel      = analytics_quote_funnel();
$pipeline    = analytics_pipeline_by_stage();
$byLine      = analytics_revenue_by_line();
$topClients  = analytics_top_clients(8);
$ticketStatus = analytics_tickets_by_status();
$equipStatus = analytics_equipment_by_status();
$resolution  = analytics_resolution($period);

$h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$m0 = fn ($v) => 'RD$ ' . number_format((float) $v, 0, '.', ',');

$logoData = '';
$logoPath = __DIR__ . '/../' . APP_LOGO;
if (is_file($logoPath)) {
    $logoData = 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath));
}

ob_start();
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
    * { font-family: "DejaVu Sans", sans-serif; }
    @page { margin: 28px 34px 70px; }
    body { margin: 0; color: #0e1a28; font-size: 10px; line-height: 1.4; }
    .muted { color: #56697b; }
    .right { text-align: right; }
    h1,h2,h3 { margin: 0; }
    .accent { height: 5px; background: #0a7d36; }
    .head { width: 100%; border-collapse: collapse; margin-top: 14px; }
    .head td { vertical-align: top; }
    .brand-name { font-size: 18px; font-weight: bold; color: #0a7d36; letter-spacing: -.3px; }
    .brand-sub { color: #56697b; font-size: 9px; }
    .doc-label { color: #8696a6; font-size: 9px; letter-spacing: 2px; text-transform: uppercase; }
    .doc-title { font-size: 17px; font-weight: bold; color: #0e1a28; }
    .doc-meta { margin-top: 5px; color: #56697b; font-size: 9px; }
    .doc-meta b { color: #0e1a28; }
    .kpis { width: 100%; border-collapse: separate; border-spacing: 6px; margin-top: 14px; }
    .kpi { border: 1px solid #e3eaf1; border-radius: 7px; padding: 8px 10px; width: 25%; }
    .kpi .k { color: #8696a6; font-size: 8px; text-transform: uppercase; letter-spacing: .5px; }
    .kpi .v { font-size: 15px; font-weight: bold; color: #0e1a28; margin-top: 3px; }
    .section { margin-top: 16px; page-break-inside: avoid; }
    .section h3 { font-size: 11px; color: #0a7d36; border-bottom: 2px solid #0a7d36; padding-bottom: 4px; margin-bottom: 7px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #f1f8f3; color: #41515f; font-size: 8.5px; text-transform: uppercase; letter-spacing: .4px; padding: 6px 8px; text-align: left; border-bottom: 1px solid #d8e6dd; }
    table.data th.r, table.data td.r { text-align: right; }
    table.data td { padding: 6px 8px; border-bottom: 1px solid #eef3f8; font-size: 9.5px; }
    .cols { width: 100%; border-collapse: collapse; }
    .cols td { vertical-align: top; width: 50%; }
    .cols .l { padding-right: 8px; }
    .cols .r2 { padding-left: 8px; }
    .foot { position: fixed; left: -34px; right: -34px; bottom: -50px; height: 42px; }
    .foot-inner { border-top: 2px solid #0a7d36; margin: 0 34px; padding-top: 6px; color: #56697b; font-size: 8.4px; }
    .foot-inner table { width: 100%; border-collapse: collapse; }
    .foot-inner b { color: #0a7d36; }
</style></head>
<body>
    <div class="foot"><div class="foot-inner"><table><tr>
        <td><b><?= $h(APP_NAME) ?></b> · Centro de reportes · Generado <?= $h(date('d/m/Y H:i')) ?></td>
        <td class="right">Tel. <?= $h(APP_PHONE) ?> · <?= $h(APP_INFO_EMAIL) ?></td>
    </tr></table></div></div>

    <div class="accent"></div>
    <table class="head"><tr>
        <td style="width:62%;">
            <table><tr>
                <?php if ($logoData): ?><td style="width:54px;vertical-align:top;"><img src="<?= $logoData ?>" style="width:46px;"></td><?php endif; ?>
                <td style="vertical-align:top;padding-top:2px;">
                    <div class="brand-name">SCH MEDICOS</div>
                    <div class="brand-sub"><?= $h(APP_TAGLINE) ?></div>
                </td>
            </tr></table>
        </td>
        <td style="width:38%;text-align:right;">
            <div class="doc-label">Reporte de operaciones</div>
            <div class="doc-title"><?= $h($period['label']) ?></div>
            <div class="doc-meta">Modo: <b><?= analytics_mode() === 'live' ? 'Datos reales' : 'Muestra' ?></b></div>
        </td>
    </tr></table>

    <table class="kpis"><tr>
        <td class="kpi"><div class="k">Pipeline activo</div><div class="v"><?= $m0($kpis['pipeline']['value']) ?></div></td>
        <td class="kpi"><div class="k">Ganado (periodo)</div><div class="v"><?= $m0($kpis['won']['value']) ?></div></td>
        <td class="kpi"><div class="k">Tasa de cierre</div><div class="v"><?= $h((string) $kpis['win_rate']['value']) ?>%</div></td>
        <td class="kpi"><div class="k">Cotizaciones</div><div class="v"><?= $h((string) (int) $kpis['quotes']['value']) ?></div></td>
    </tr><tr>
        <td class="kpi"><div class="k">Tickets abiertos</div><div class="v"><?= $h((string) (int) $kpis['open_tickets']['value']) ?></div></td>
        <td class="kpi"><div class="k">Resolución prom.</div><div class="v"><?= $h((string) $resolution['avg_hours']) ?> h</div></td>
        <td class="kpi"><div class="k">Dentro de SLA</div><div class="v"><?= $h((string) $resolution['sla_pct']) ?>%</div></td>
        <td class="kpi"><div class="k">Clientes nuevos</div><div class="v"><?= $h((string) (int) $kpis['clients']['value']) ?></div></td>
    </tr></table>

    <table class="cols"><tr>
        <td class="l">
            <div class="section"><h3>Embudo de conversión</h3>
                <table class="data"><tr><th>Etapa</th><th class="r">Cotiz.</th><th class="r">Monto</th></tr>
                <?php foreach ($funnel as $f): ?>
                    <tr><td><?= $h($f['stage']) ?></td><td class="r"><?= $h((string) $f['count']) ?></td><td class="r"><?= $m0($pipeline[$f['stage']]['amount'] ?? 0) ?></td></tr>
                <?php endforeach; ?>
                </table>
            </div>
            <div class="section"><h3>Ingresos por línea de negocio</h3>
                <table class="data"><tr><th>Línea</th><th class="r">Cotiz.</th><th class="r">Monto</th></tr>
                <?php foreach ($byLine as $l): ?>
                    <tr><td><?= $h($l['line']) ?></td><td class="r"><?= $h((string) $l['count']) ?></td><td class="r"><?= $m0($l['amount']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$byLine): ?><tr><td colspan="3" class="muted">Sin datos por línea.</td></tr><?php endif; ?>
                </table>
            </div>
        </td>
        <td class="r2">
            <div class="section"><h3>Clientes con mayor valor</h3>
                <table class="data"><tr><th>Cliente</th><th class="r">Pipeline</th><th class="r">Tk</th></tr>
                <?php foreach ($topClients as $c): ?>
                    <tr><td><?= $h($c['name']) ?></td><td class="r"><?= $m0($c['quote_value']) ?></td><td class="r"><?= $h((string) (int) $c['ticket_count']) ?></td></tr>
                <?php endforeach; ?>
                </table>
            </div>
            <div class="section"><h3>Tickets por estado</h3>
                <table class="data"><tr><th>Estado</th><th class="r">Total</th></tr>
                <?php foreach ($ticketStatus as $t): ?>
                    <tr><td><?= $h($t['status']) ?></td><td class="r"><?= $h((string) $t['total']) ?></td></tr>
                <?php endforeach; ?>
                </table>
            </div>
            <div class="section"><h3>Equipos por estado</h3>
                <table class="data"><tr><th>Estado</th><th class="r">Total</th></tr>
                <?php foreach ($equipStatus as $eq): ?>
                    <tr><td><?= $h($eq['status']) ?></td><td class="r"><?= $h((string) $eq['total']) ?></td></tr>
                <?php endforeach; ?>
                </table>
            </div>
        </td>
    </tr></table>
</body></html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('dpi', 96);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
if ($font) {
    $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 22, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, [0.34, 0.41, 0.49]);
}

$dompdf->stream('Reporte-SCH-' . date('Y-m-d') . '.pdf', ['Attachment' => isset($_GET['download']) ? 1 : 0]);
exit;
