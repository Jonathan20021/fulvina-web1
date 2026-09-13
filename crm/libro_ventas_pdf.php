<?php
/**
 * Libro de ventas del mes en PDF, con el resumen de ITBIS al final.
 *
 *   ?ym=AAAA-MM   → periodo (por defecto, el mes en curso).
 *   &download=1   → fuerza la descarga en vez de la vista previa.
 *
 * Es el documento que se archiva y el que pide un auditor: la secuencia
 * completa de NCF consumidos en el mes, con las anuladas a la vista.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('dgii.view');
if (db(false)) {
    ensure_invoice_schema();
}

use Dompdf\Dompdf;
use Dompdf\Options;

$canSeeErrors = current_can('config.manage');
register_shutdown_function(static function () use ($canSeeErrors): void {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "No se pudo generar el libro de ventas.\n";
    if ($canSeeErrors) {
        echo "\n" . $err['message'] . "\n" . $err['file'] . ':' . $err['line'] . "\n";
    }
});

$ym = dgii_period((string) ($_GET['ym'] ?? ''));
$book = dgii_sales_book($ym);
$itbis = dgii_itbis_summary($ym);
$t = $book['totals'];

$h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$m0 = fn ($v) => number_format((float) $v, 2, '.', ',');

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
    @page { margin: 26px 26px 60px; }
    body { margin: 0; color: #0e1a28; font-size: 8.4px; line-height: 1.35; }
    .muted { color: #56697b; }
    .right { text-align: right; }
    h1, h2, h3 { margin: 0; }
    .accent { height: 4px; background: #027F31; }
    .head { width: 100%; border-collapse: collapse; margin-top: 12px; }
    .head td { vertical-align: top; }
    .brand-name { font-size: 14px; font-weight: bold; color: #027F31; letter-spacing: -.3px; }
    .brand-meta { color: #56697b; font-size: 8px; margin-top: 2px; }
    .doc-label { color: #8696a6; font-size: 8px; letter-spacing: 2px; text-transform: uppercase; }
    .doc-title { font-size: 15px; font-weight: bold; color: #0e1a28; }
    .doc-meta { margin-top: 3px; color: #56697b; font-size: 8px; }
    .note { margin-top: 9px; border-left: 3px solid #d8e6dd; padding: 4px 9px; color: #56697b; font-size: 8px; line-height: 1.45; }
    .section { margin-top: 13px; }
    .section h3 { font-size: 9.5px; color: #027F31; border-bottom: 1.5px solid #027F31; padding-bottom: 3px; margin-bottom: 5px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #f1f8f3; color: #41515f; font-size: 7.4px; text-transform: uppercase; letter-spacing: .3px; padding: 4px 5px; text-align: left; border-bottom: 1px solid #d8e6dd; }
    table.data th.r, table.data td.r { text-align: right; }
    table.data td { padding: 3.6px 5px; border-bottom: 1px solid #eef3f8; font-size: 8.1px; }
    table.data tr.void td { background: #fdf4f4; color: #9b6b6b; }
    table.data tr.note td { background: #fffaf0; }
    table.data tfoot td { font-weight: bold; border-top: 1.5px solid #027F31; border-bottom: none; padding-top: 5px; }
    table.kv { width: 100%; border-collapse: collapse; }
    table.kv td { padding: 4px 5px; border-bottom: 1px solid #eef3f8; font-size: 9px; }
    table.kv td.r { text-align: right; font-weight: bold; }
    table.kv tr.sum td { border-top: 1.5px solid #027F31; border-bottom: none; font-weight: bold; font-size: 10px; }
    .warn { margin-top: 9px; border: 1px solid #f0d9a8; background: #fdf8ec; border-radius: 5px; padding: 6px 9px; color: #8a6414; font-size: 8.2px; line-height: 1.5; }
    .foot { position: fixed; left: -26px; right: -26px; bottom: -44px; height: 38px; }
    .foot-inner { border-top: 2px solid #027F31; margin: 0 26px; padding-top: 5px; color: #56697b; font-size: 7.6px; }
    .foot-inner table { width: 100%; border-collapse: collapse; }
    .foot-inner b { color: #027F31; }
    .section h3 { page-break-after: avoid; }
    .section table tr { page-break-inside: avoid; }
  </style></head>
<body>
    <div class="foot"><div class="foot-inner"><table><tr>
        <td><b><?= $h(APP_LEGAL) ?></b><?= APP_RNC !== '' ? ' · RNC: ' . $h(APP_RNC) : '' ?> · Libro de ventas <?= $h(dgii_period_label($ym)) ?></td>
        <td class="right">Generado <?= $h(date('d/m/Y H:i')) ?></td>
    </tr></table></div></div>

    <div class="accent"></div>
    <table class="head"><tr>
        <td style="width:58%;">
            <table><tr>
                <?php if ($logoData): ?><td style="width:52px;vertical-align:top;"><img src="<?= $logoData ?>" style="width:44px;"></td><?php endif; ?>
                <td style="vertical-align:top;padding-top:2px;">
                    <div class="brand-name"><?= $h(sin_viudas(APP_LEGAL)) ?></div>
                    <div class="brand-meta"><?php if (APP_RNC !== ''): ?>RNC: <?= $h(APP_RNC) ?><br><?php endif; ?><?= $h(APP_ADDRESS) ?></div>
                </td>
            </tr></table>
        </td>
        <td style="width:42%;text-align:right;">
            <div class="doc-label">Libro de ventas</div>
            <div class="doc-title"><?= $h(dgii_period_label($ym)) ?></div>
            <div class="doc-meta"><?= $h((string) $t['count']) ?> comprobantes<?= $t['voided'] > 0 ? ' · ' . $h((string) $t['voided']) . ' anulados' : '' ?></div>
        </td>
    </tr></table>

    <div class="note">
        Comprobantes con NCF emitidos en el periodo, <b>ordenados por número</b> dentro de cada serie, para que cualquier salto en la secuencia quede a la vista. Las <b>anuladas</b> figuran sin importe por esa misma razón; las <b>notas de crédito</b> se muestran con el signo «−» y restan en los totales. Importes en RD$ (los comprobantes en USD se convierten con la tasa del propio documento).
    </div>

    <div class="section">
        <h3>Detalle</h3>
        <table class="data">
            <thead><tr>
                <th>Fecha</th><th>NCF</th><th>Tipo</th><th>Cliente</th><th>RNC</th>
                <th class="r">Gravado</th><th class="r">Exento</th><th class="r">ITBIS</th><th class="r">Ret.</th><th class="r">Total</th>
            </tr></thead>
            <tbody>
            <?php foreach ($book['rows'] as $r):
                $cls = $r['is_void'] ? 'void' : ($r['is_note'] ? 'note' : '');
                $sg = $r['is_note'] ? '−' : ''; ?>
                <tr class="<?= $cls ?>">
                    <td><?= $h(date_es($r['date'])) ?></td>
                    <td><?= $h($r['ncf']) ?><?= $r['modifies'] !== '' ? '<br><span class="muted">s/ ' . $h($r['modifies']) . '</span>' : '' ?></td>
                    <td><?= $h($r['ncf_type']) ?><?= $r['is_void'] ? '<br><span class="muted">ANULADA</span>' : '' ?></td>
                    <td><?= $h(mb_strimwidth((string) $r['client'], 0, 30, '…')) ?></td>
                    <td><?= $h($r['rnc'] ?: '—') ?></td>
                    <td class="r"><?= $r['is_void'] ? '—' : $sg . $m0($r['taxed']) ?></td>
                    <td class="r"><?= $r['is_void'] ? '—' : ($r['exempt'] > 0 ? $sg . $m0($r['exempt']) : '—') ?></td>
                    <td class="r"><?= $r['is_void'] ? '—' : $sg . $m0($r['itbis']) ?></td>
                    <td class="r"><?= $r['is_void'] || $r['itbis_ret'] <= 0 ? '—' : $m0($r['itbis_ret']) ?></td>
                    <td class="r"><?= $r['is_void'] ? '—' : $sg . $m0($r['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$book['rows']): ?><tr><td colspan="10" class="muted">Sin comprobantes con NCF en este periodo.</td></tr><?php endif; ?>
            </tbody>
            <tfoot><tr>
                <td colspan="5">TOTALES DEL MES</td>
                <td class="r"><?= $m0($t['taxed']) ?></td>
                <td class="r"><?= $m0($t['exempt']) ?></td>
                <td class="r"><?= $m0($t['itbis']) ?></td>
                <td class="r"><?= $m0($t['itbis_ret']) ?></td>
                <td class="r"><?= $m0($t['total']) ?></td>
            </tr></tfoot>
        </table>
    </div>

    <div class="section">
        <h3>Resumen de ITBIS</h3>
        <table class="kv">
            <tr><td>Ventas gravadas</td><td class="r"><?= $m0($itbis['taxed']) ?></td></tr>
            <tr><td>Ventas exentas</td><td class="r"><?= $m0($itbis['exempt']) ?></td></tr>
            <tr><td>Débito fiscal · ITBIS facturado a clientes</td><td class="r"><?= $m0($itbis['debit']) ?></td></tr>
            <tr><td>− ITBIS retenido por terceros</td><td class="r">− <?= $m0($itbis['withheld']) ?></td></tr>
            <tr><td>− Crédito fiscal de compras</td><td class="r">no disponible</td></tr>
            <tr class="sum"><td>Saldo parcial de ventas</td><td class="r"><?= $m0($itbis['partial_due']) ?></td></tr>
        </table>
        <div class="warn">
            <b>Este no es el ITBIS a pagar.</b> Falta restar el crédito fiscal de las compras del mes. <?= $h($itbis['credit_reason']) ?> Lleva esta cifra a tu contabilidad y réstale las compras antes de declarar el IT-1.
        </div>
    </div>

    <?php if ($itbis['by_type']): ?>
    <div class="section">
        <h3>Desglose por tipo de comprobante</h3>
        <table class="data">
            <thead><tr><th>Tipo</th><th>Descripción</th><th class="r">Comprobantes</th><th class="r">Gravado</th><th class="r">Exento</th><th class="r">ITBIS</th></tr></thead>
            <tbody>
            <?php foreach ($itbis['by_type'] as $bt): ?>
                <tr>
                    <td><?= $h($bt['type']) ?></td>
                    <td><?= $h($bt['label']) ?></td>
                    <td class="r"><?= $h((string) $bt['count']) ?></td>
                    <td class="r"><?= $m0($bt['taxed']) ?></td>
                    <td class="r"><?= $bt['exempt'] != 0.0 ? $m0($bt['exempt']) : '—' ?></td>
                    <td class="r"><?= $m0($bt['itbis']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <td colspan="2">TOTAL</td>
                <td class="r"><?= $h((string) ($itbis['count'] - $itbis['voided'])) ?></td>
                <td class="r"><?= $m0($itbis['taxed']) ?></td>
                <td class="r"><?= $m0($itbis['exempt']) ?></td>
                <td class="r"><?= $m0($itbis['debit']) ?></td>
            </tr></tfoot>
        </table>
    </div>
    <?php endif; ?>
</body></html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('dpi', 96);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
if ($font) {
    $canvas->page_text($canvas->get_width() - 96, $canvas->get_height() - 20, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7.5, [0.34, 0.41, 0.49]);
}

$dompdf->stream('Libro-ventas-' . dgii_period_header($ym) . '.pdf', ['Attachment' => isset($_GET['download']) ? 1 : 0]);
exit;
