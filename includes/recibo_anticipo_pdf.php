<?php
/**
 * Recibo de ingreso de un ANTICIPO (cobrado sobre una cotización).
 *
 * Lo incluye crm/recibo_pdf.php cuando el número pedido pertenece a un
 * anticipo; espera $ant (anticipo_by_receipt) y Dompdf ya cargado. Mismo diseño
 * que el recibo de un cobro de factura: para el cliente es un recibo más.
 *
 * Los importes son los del DÍA del anticipo: los anticipos anteriores se cuentan
 * por fecha. Si ya se aplicó, se dice a qué factura; si se anuló, lo dice arriba.
 */

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($ant) || !is_array($ant)) {
    http_response_code(404);
    exit("El anticipo indicado no existe.\n");
}

$quote = fetch_one('SELECT quotes.*, clients.name AS c_name, clients.rnc AS c_rnc FROM quotes LEFT JOIN clients ON clients.id = quotes.client_id WHERE quotes.id = ?', [(int) $ant['quote_id']]) ?? [];
$cur = (string) $ant['currency'];
$monto = round((float) $ant['amount'], 2);
$qTotal = round((float) ($quote['total'] ?? 0), 2);
$previos = round((float) (fetch_one(
    "SELECT COALESCE(SUM(amount), 0) v FROM quote_payments
      WHERE quote_id = ? AND status = 'vigente' AND id <> ? AND (paid_at < ? OR (paid_at = ? AND id < ?))",
    [(int) $ant['quote_id'], (int) $ant['id'], (string) $ant['paid_at'], (string) $ant['paid_at'], (int) $ant['id']]
)['v'] ?? 0), 2);
$antes = max(0.0, round($qTotal - $previos, 2));
$despues = round($antes - $monto, 2);
$anulado = (string) $ant['status'] !== 'vigente';

$clientName = (string) (($quote['c_name'] ?? '') ?: 'Cliente');
$clientRnc = (string) ($quote['c_rnc'] ?? '');
$h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$mc = fn ($v) => money_cur($v, $cur);

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
    @page { margin: 30px 34px 64px; }
    body { margin: 0; color: #0e1a28; font-size: 10.5px; line-height: 1.45; }
    .muted { color: #56697b; }
    .right { text-align: right; }
    .accent { height: 5px; background: #027F31; }
    .head { width: 100%; border-collapse: collapse; margin-top: 16px; }
    .head td { vertical-align: top; }
    .brand-name { font-size: 16px; font-weight: bold; color: #027F31; letter-spacing: -.3px; }
    .brand-meta { color: #56697b; font-size: 8.8px; margin-top: 2px; }
    .doc-label { color: #8696a6; font-size: 9px; letter-spacing: 2.2px; text-transform: uppercase; }
    .doc-no { font-size: 19px; font-weight: bold; color: #027F31; letter-spacing: -.3px; }
    .doc-date { color: #56697b; font-size: 9px; margin-top: 3px; }
    .amount-box { margin-top: 18px; border: 1.5px solid #027F31; border-radius: 9px; background: #f4faf6; padding: 11px 14px; }
    .amount-box .k { color: #41727f; font-size: 8.5px; text-transform: uppercase; letter-spacing: 1px; }
    .amount-box .v { font-size: 23px; font-weight: bold; color: #027F31; letter-spacing: -.6px; margin-top: 1px; }
    .amount-box .w { color: #2c4a38; font-size: 9.2px; margin-top: 4px; font-style: italic; }
    .void { margin-top: 14px; border: 1.5px solid #b42318; border-radius: 9px; background: #fef2f2; padding: 9px 14px; color: #b42318; }
    .void b { font-size: 13px; letter-spacing: 1px; }
    .block { margin-top: 15px; }
    .block h3 { margin: 0 0 6px; font-size: 9px; color: #027F31; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1.5px solid #d8e6dd; padding-bottom: 3px; }
    table.kv { width: 100%; border-collapse: collapse; }
    table.kv td { padding: 4px 0; vertical-align: top; font-size: 10px; }
    table.kv td.k { color: #56697b; width: 130px; }
    table.kv td.v { font-weight: bold; }
    table.bal { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.bal th { background: #f1f8f3; color: #41515f; font-size: 8.5px; text-transform: uppercase; letter-spacing: .4px; padding: 6px 8px; border-bottom: 1px solid #d8e6dd; text-align: right; }
    table.bal th:first-child, table.bal td:first-child { text-align: left; }
    table.bal td { padding: 6px 8px; border-bottom: 1px solid #eef3f8; font-size: 10px; text-align: right; }
    table.bal td.total, table.bal td.applied { font-weight: bold; color: #027F31; }
    .sign { width: 100%; border-collapse: collapse; margin-top: 40px; }
    .sign td { width: 50%; padding: 0 18px; font-size: 8.8px; color: #56697b; text-align: center; }
    .sign .line { border-top: 1px solid #9aa9b7; padding-top: 4px; }
    .foot { position: fixed; left: -34px; right: -34px; bottom: -46px; height: 40px; }
    .foot-inner { border-top: 2px solid #027F31; margin: 0 34px; padding-top: 6px; color: #56697b; font-size: 8.2px; }
    .foot-inner table { width: 100%; border-collapse: collapse; }
    .foot-inner b { color: #027F31; }
    .note { margin-top: 12px; border-left: 3px solid #d8e6dd; padding: 5px 10px; color: #56697b; font-size: 9px; }
</style></head>
<body>
    <div class="foot"><div class="foot-inner"><table><tr>
        <td><b><?= $h(APP_LEGAL) ?></b><?= APP_RNC !== '' ? ' · RNC: ' . $h(APP_RNC) : '' ?> · <?= $h(APP_ADDRESS) ?></td>
        <td class="right">Tel. <?= $h(APP_PHONE) ?> · <?= $h(APP_INFO_EMAIL) ?></td>
    </tr></table></div></div>

    <div class="accent"></div>
    <table class="head"><tr>
        <td style="width:60%;">
            <table><tr>
                <?php if ($logoData): ?><td style="width:60px;vertical-align:top;"><img src="<?= $logoData ?>" style="width:52px;"></td><?php endif; ?>
                <td style="vertical-align:top;padding-top:2px;">
                    <div class="brand-name"><?= $h(sin_viudas(APP_LEGAL)) ?></div>
                    <div class="brand-meta"><?php if (APP_RNC !== ''): ?>RNC: <?= $h(APP_RNC) ?><br><?php endif; ?><?= $h(APP_ADDRESS) ?><br>Tel. <?= $h(APP_PHONE) ?> · <?= $h(APP_INFO_EMAIL) ?></div>
                </td>
            </tr></table>
        </td>
        <td style="width:40%;text-align:right;">
            <div class="doc-label">Recibo de ingreso · Anticipo</div>
            <div class="doc-no"><?= $h((string) $ant['receipt_number']) ?></div>
            <div class="doc-date">Fecha del cobro: <b><?= $h(date_es((string) $ant['paid_at'])) ?></b></div>
        </td>
    </tr></table>

    <?php if ($anulado): ?>
        <div class="void"><b>ANULADO</b><?php if (!empty($ant['void_reason'])): ?> · <?= $h((string) $ant['void_reason']) ?><?php endif; ?><?php if (!empty($ant['voided_at'])): ?> · <?= $h(date_es((string) $ant['voided_at'])) ?><?php endif; ?></div>
    <?php endif; ?>

    <div class="amount-box">
        <div class="k">Recibimos de <?= $h($clientName) ?><?= $clientRnc !== '' ? ' · RNC/Cédula ' . $h($clientRnc) : '' ?></div>
        <div class="v"><?= $h($mc($monto)) ?></div>
        <div class="w"><?= $h(money_in_words($monto, $cur)) ?></div>
    </div>

    <div class="block">
        <h3>Forma de pago</h3>
        <table class="kv">
            <tr><td class="k">Medio</td><td class="v"><?= $h((string) ($ant['method'] ?: 'No indicado')) ?><?= !empty($ant['reference']) ? ' · Ref. ' . $h((string) $ant['reference']) : '' ?></td></tr>
            <?php if (!empty($ant['note'])): ?><tr><td class="k">Observación</td><td class="v" style="font-weight:normal"><?= $h((string) $ant['note']) ?></td></tr><?php endif; ?>
        </table>
    </div>

    <div class="block">
        <h3>Concepto</h3>
        <table class="kv">
            <tr><td class="k">Anticipo a la cotización</td><td class="v"><?= $h((string) ($quote['quote_number'] ?? '')) ?></td></tr>
            <?php if (!empty($quote['title'])): ?><tr><td class="k">Detalle</td><td class="v"><?= $h((string) $quote['title']) ?></td></tr><?php endif; ?>
        </table>
        <table class="bal">
            <tr><th>Concepto</th><th>Importe</th></tr>
            <tr><td>Total de la cotización</td><td><?= $h($mc($qTotal)) ?></td></tr>
            <?php if ($previos > 0.009): ?><tr><td>Anticipos anteriores</td><td>− <?= $h($mc($previos)) ?></td></tr><?php endif; ?>
            <tr><td>Por cubrir antes de este anticipo</td><td><?= $h($mc($antes)) ?></td></tr>
            <tr><td>Este anticipo</td><td class="applied">− <?= $h($mc($monto)) ?></td></tr>
            <tr><td><b>Por cubrir</b></td><td class="total"><?= $h($mc(max(0.0, $despues))) ?></td></tr>
        </table>
        <div class="muted" style="margin-top:4px;font-size:8.2px;">Importes al <?= $h(date_es((string) $ant['paid_at'])) ?>, fecha de este anticipo.</div>
    </div>

    <?php if (!$anulado && $ant['applications']): ?>
        <div class="note" style="border-left-color:#027F31;color:#1d6b3f">
            <b>Aplicado a:</b>
            <?= $h(implode(' · ', array_map(fn ($x) => (string) ($x['ncf'] ?: $x['invoice_number']) . ' (' . $mc($x['amount']) . ')', $ant['applications']))) ?>
            <?php if ($ant['pending'] > 0.009): ?> · quedan <?= $h($mc($ant['pending'])) ?> por aplicar<?php endif; ?>
        </div>
    <?php elseif (!$anulado): ?>
        <div class="note">Este anticipo se descontará de la factura que se emita por esta cotización.</div>
    <?php endif; ?>

    <?php if ($cur === 'USD'): ?>
        <div class="note">Importes expresados en dólares estadounidenses (USD), moneda de la cotización.</div>
    <?php endif; ?>

    <table class="sign"><tr>
        <td><div class="line">Recibido por · <?= $h(APP_NAME) ?></div></td>
        <td><div class="line">Conforme · <?= $h($clientName) ?></div></td>
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
$dompdf->stream('Recibo-' . $ant['receipt_number'] . '.pdf', ['Attachment' => isset($_GET['download']) ? 1 : 0]);
exit;
