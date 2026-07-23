<?php
/**
 * Reporte imprimible de cartera: cuentas por cobrar por antigüedad.
 * Restringido por permiso nominal (contabilidad), no por rol.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('facturas.view');
require_cartera();
if (db(false)) { ensure_invoice_schema(); }

use Dompdf\Dompdf;
use Dompdf\Options;

$report = receivables_aging();
$buckets = $report['buckets'];
$total = $report['total'];

$h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$m = fn ($v) => 'RD$ ' . number_format((float) $v, 2, '.', ',');
$pct = fn (float $v) => $total['amount'] > 0 ? number_format($v / $total['amount'] * 100, 1) . '%' : '0.0%';

$logoData = '';
$logoPath = __DIR__ . '/../' . APP_LOGO;
if (is_file($logoPath)) {
    $logoData = 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath));
}

/* Color por tramo, coherente con la pantalla. */
$tone = [
    'por_vencer' => ['#066128', '#f5faf6', '#c7d6c9'],
    '0-30'       => ['#92660a', '#fffaf0', '#f4d58a'],
    '31-60'      => ['#92660a', '#fffaf0', '#f4d58a'],
    '61-90'      => ['#b42318', '#fef2f2', '#f3c4c4'],
    '90+'        => ['#b42318', '#fef2f2', '#f3c4c4'],
];

ob_start();
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
    * { font-family: "DejaVu Sans", sans-serif; }
    @page { margin: 28px 30px 70px; }
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
    .kpis { width: 100%; border-collapse: separate; border-spacing: 5px; margin-top: 14px; }
    .kpi { border: 1px solid #e3eaf1; border-radius: 7px; padding: 7px 9px; width: 16.6%; }
    .kpi .k { color: #8696a6; font-size: 7.6px; text-transform: uppercase; letter-spacing: .5px; }
    .kpi .v { font-size: 12px; font-weight: bold; margin-top: 3px; }
    .kpi .n { color: #56697b; font-size: 8px; }
    .section { margin-top: 15px; page-break-inside: avoid; }
    .section h3 { font-size: 11px; padding: 5px 8px; border-radius: 6px 6px 0 0; margin: 0; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #f1f8f3; color: #41515f; font-size: 8.2px; text-transform: uppercase; letter-spacing: .4px; padding: 5px 6px; text-align: left; border-bottom: 1px solid #d8e6dd; }
    table.data th.r, table.data td.r { text-align: right; }
    table.data td { padding: 5px 6px; border-bottom: 1px solid #eef3f8; font-size: 9.2px; }
    table.data tr.sub td { background: #f7fafc; font-weight: bold; border-bottom: 1px solid #d8e6dd; }
    .grand { margin-top: 16px; border: 2px solid #0a7d36; border-radius: 7px; padding: 8px 10px; }
    .grand .k { color: #0a7d36; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; }
    .grand .v { font-size: 17px; font-weight: bold; }
    .note { margin-top: 12px; color: #56697b; font-size: 8.4px; }
    .foot { position: fixed; left: -30px; right: -30px; bottom: -50px; height: 42px; }
    .foot-inner { border-top: 2px solid #0a7d36; margin: 0 30px; padding-top: 6px; color: #56697b; font-size: 8.4px; }
    .foot-inner table { width: 100%; border-collapse: collapse; }
    .foot-inner b { color: #0a7d36; }
</style></head>
<body>
    <div class="foot"><div class="foot-inner"><table><tr>
        <td><b><?= $h(APP_LEGAL) ?></b> · Cartera por antigüedad · Generado <?= $h(date('d/m/Y H:i')) ?> por <?= $h(current_user()['name'] ?? '') ?></td>
        <td class="right">Uso interno — contabilidad</td>
    </tr></table></div></div>

    <div class="accent"></div>
    <table class="head"><tr>
        <td style="width:60%;">
            <table><tr>
                <?php if ($logoData): ?><td style="width:54px;vertical-align:top;"><img src="<?= $logoData ?>" style="width:46px;"></td><?php endif; ?>
                <td style="vertical-align:top;padding-top:2px;">
                    <div class="brand-name"><?= $h(APP_NAME) ?></div>
                    <div class="brand-sub"><?= $h(APP_LEGAL) ?><?php if (APP_RNC !== ''): ?> · RNC: <?= $h(APP_RNC) ?><?php endif; ?></div>
                </td>
            </tr></table>
        </td>
        <td style="width:40%;text-align:right;">
            <div class="doc-label">Cuentas por cobrar</div>
            <div class="doc-title">Antigüedad de saldos</div>
            <div class="doc-meta">Al <b><?= $h(date('d/m/Y')) ?></b> · <b><?= $h((string) $total['count']) ?></b> factura<?= $total['count'] === 1 ? '' : 's' ?> con saldo</div>
        </td>
    </tr></table>

    <table class="kpis"><tr>
        <?php foreach ($buckets as $bk => $b): [$fg, $bg, $bd] = $tone[$bk] ?? ['#0e1a28', '#fff', '#e3eaf1']; ?>
            <td class="kpi" style="background:<?= $bg ?>;border-color:<?= $bd ?>;">
                <div class="k"><?= $h($b['label']) ?></div>
                <div class="v" style="color:<?= $fg ?>;"><?= $m($b['amount']) ?></div>
                <div class="n"><?= $h((string) $b['count']) ?> fact. · <?= $h($pct((float) $b['amount'])) ?></div>
            </td>
        <?php endforeach; ?>
        <td class="kpi" style="border-color:#0a7d36;">
            <div class="k">Total por cobrar</div>
            <div class="v" style="color:#0a7d36;"><?= $m($total['amount']) ?></div>
            <div class="n"><?= $h((string) $total['count']) ?> facturas</div>
        </td>
    </tr></table>

    <?php foreach ($buckets as $bk => $b): ?>
        <?php if (!$b['rows']) { continue; } [$fg, $bg, $bd] = $tone[$bk] ?? ['#0e1a28', '#f7fafc', '#e3eaf1']; ?>
        <div class="section">
            <h3 style="background:<?= $bg ?>;color:<?= $fg ?>;border:1px solid <?= $bd ?>;">
                <?= $h($b['label']) ?> — <?= $h((string) $b['count']) ?> factura<?= $b['count'] === 1 ? '' : 's' ?> · <?= $m($b['amount']) ?>
            </h3>
            <table class="data">
                <tr>
                    <th style="width:23%">Cliente</th>
                    <th style="width:13%">Factura</th>
                    <th style="width:13%">NCF</th>
                    <th style="width:10%">Emitida</th>
                    <th style="width:10%">Vence</th>
                    <th class="r" style="width:7%">Días</th>
                    <th class="r" style="width:12%">Total</th>
                    <th class="r" style="width:12%">Saldo RD$</th>
                </tr>
                <?php foreach ($b['rows'] as $r): ?>
                    <tr>
                        <td><?= $h($r['client_name'] ?: ($r['c_name'] ?? 'Cliente')) ?><?php if (!empty($r['client_rnc'])): ?><br><span class="muted"><?= $h($r['client_rnc']) ?></span><?php endif; ?></td>
                        <td><?= $h($r['invoice_number']) ?></td>
                        <td><?= $h($r['ncf'] ?: '—') ?></td>
                        <td><?= $h(date_es($r['issue_date'] ?? null)) ?></td>
                        <td><?= $h(date_es($r['due_date'] ?? null)) ?></td>
                        <td class="r"><?= (int) $r['aging']['days'] < 0 ? 'en ' . $h((string) abs((int) $r['aging']['days'])) : $h((string) (int) $r['aging']['days']) ?></td>
                        <td class="r"><?= $h(money_cur($r['total'], (string) ($r['currency'] ?? 'DOP'))) ?></td>
                        <td class="r"><b><?= $m($r['balance_dop']) ?></b></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="sub">
                    <td colspan="7" class="r">Subtotal <?= $h($b['label']) ?></td>
                    <td class="r"><?= $m($b['amount']) ?></td>
                </tr>
            </table>
        </div>
    <?php endforeach; ?>

    <?php if ($total['count'] === 0): ?>
        <div class="section"><p class="muted">No hay facturas emitidas con saldo pendiente.</p></div>
    <?php endif; ?>

    <table class="grand"><tr>
        <td><div class="k">Total cuentas por cobrar</div><div class="muted"><?= $h((string) $total['count']) ?> factura<?= $total['count'] === 1 ? '' : 's' ?> emitida<?= $total['count'] === 1 ? '' : 's' ?> con saldo pendiente</div></td>
        <td class="right"><div class="v"><?= $m($total['amount']) ?></div></td>
    </tr></table>

    <p class="note">
        Los días se cuentan desde la fecha de vencimiento de cada factura. El saldo descuenta abonos y retenciones (ITBIS/ISR).
        Las facturas en USD se expresan en RD$ usando la tasa registrada en el comprobante. No incluye borradores, anuladas ni saldadas.
    </p>
</body></html>
<?php
$html = (string) ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('dpi', 96);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
if ($font) {
    $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 22, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, [0.34, 0.41, 0.49]);
}

$dompdf->stream('Cartera-antiguedad-' . date('Y-m-d') . '.pdf', ['Attachment' => isset($_GET['download']) ? 1 : 0]);
exit;
