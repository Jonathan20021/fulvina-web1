<?php
/**
 * Recibo de ingreso en PDF.
 *
 *   ?id=PAGO_ID          → recibo del cobro indicado.
 *   ?receipt=REC-2026-01 → recibo por su número.
 *   &download=1          → fuerza la descarga en vez de la vista previa.
 *
 * Un recibo puede cubrir VARIOS comprobantes: cuando el cliente manda una sola
 * transferencia por cinco facturas, eso es un solo cobro y un solo recibo, con
 * el detalle de cómo se repartió. Por eso se agrupa por número de recibo y no
 * por pago: pedir el recibo de un pago devuelve el documento completo.
 *
 * Es el documento que se le entrega al cliente: quién pagó, cuánto, por qué
 * comprobantes, en qué forma y con cuánto queda. El monto va también en letras,
 * que es lo que hace que un recibo valga como constancia.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('facturas.view');

use Dompdf\Dompdf;
use Dompdf\Options;

/*
 * Un fallo al componer el PDF devolvería un 500 en blanco dentro del iframe de
 * vista previa, sin pista de qué pasó. Esto lo convierte en un mensaje legible;
 * el detalle técnico solo para quien administra el sistema.
 */
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
    echo "No se pudo generar el recibo de ingreso.\n";
    if ($canSeeErrors) {
        echo "\n" . $err['message'] . "\n" . $err['file'] . ':' . $err['line'] . "\n";
    }
});

$paymentId = (int) ($_GET['id'] ?? 0);
$receiptAsked = trim((string) ($_GET['receipt'] ?? ''));
$hasReceiptCol = db(false) && table_exists('invoice_payments') && column_exists('invoice_payments', 'receipt_number');

/* ---- Resolver los pagos que componen este recibo ------------------------- */
$payments = [];
if ($receiptAsked !== '' && $hasReceiptCol) {
    $payments = fetch_all('SELECT * FROM invoice_payments WHERE receipt_number = ? ORDER BY id ASC', [$receiptAsked]);
} elseif ($paymentId > 0 && db(false) && table_exists('invoice_payments')) {
    $seed = fetch_one('SELECT * FROM invoice_payments WHERE id = ?', [$paymentId]);
    if ($seed) {
        $seedNo = trim((string) ($seed['receipt_number'] ?? ''));
        // Un recibo agrupado se imprime entero aunque se pida por uno de sus pagos.
        $payments = ($seedNo !== '' && $hasReceiptCol)
            ? fetch_all('SELECT * FROM invoice_payments WHERE receipt_number = ? ORDER BY id ASC', [$seedNo])
            : [$seed];
    }
}

if (!$payments) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("El cobro indicado no existe.\n");
}

/* ---- Comprobantes cubiertos, con su saldo AL DÍA DEL COBRO --------------- */
$lines = [];
$amount = 0.0;
$inv0 = null;
$currencies = [];

foreach ($payments as $p) {
    $inv = fetch_one(
        'SELECT invoices.*, clients.name AS c_name, clients.rnc AS c_rnc
           FROM invoices LEFT JOIN clients ON clients.id = invoices.client_id
          WHERE invoices.id = ?',
        [(int) $p['invoice_id']]
    );
    if (!$inv) {
        continue;
    }
    $inv0 ??= $inv;
    $lineCur = strtoupper((string) ($inv['currency'] ?? 'DOP')) === 'USD' ? 'USD' : 'DOP';
    $currencies[$lineCur] = true;

    /*
     * Saldos tal como eran EL DÍA DEL PAGO. Nada se lee de amount_paid ni de
     * credited_amount, que reflejan el estado de hoy: los abonos se suman solo
     * hasta este pago inclusive y el crédito por notas se calcula hasta esa
     * misma fecha. Así la copia que se llevó el cliente y una reimpresión de
     * hoy dicen lo mismo, aunque después hayan entrado más cobros o notas.
     */
    $net = round(
        (float) $inv['total']
        - (float) $inv['itbis_retained']
        - (float) $inv['isr_retained']
        - invoice_credited_as_of((int) $inv['id'], (string) $p['paid_at'])
        - (float) ($inv['balance_adjustment'] ?? 0),
        2
    );
    $paidUpTo = 0.0;
    foreach (fetch_all('SELECT id, amount FROM invoice_payments WHERE invoice_id = ? ORDER BY paid_at ASC, id ASC', [(int) $inv['id']]) as $row) {
        $paidUpTo += (float) $row['amount'];
        if ((int) $row['id'] === (int) $p['id']) {
            break;
        }
    }
    $applied = (float) $p['amount'];
    $amount += $applied;

    $lines[] = [
        'inv' => $inv,
        'currency' => $lineCur,
        'ref' => trim((string) ($inv['ncf'] ?: $inv['invoice_number'] ?: '')),
        'number' => (string) ($inv['invoice_number'] ?? ''),
        'issue' => (string) ($inv['issue_date'] ?? ''),
        'title' => (string) ($inv['title'] ?? ''),
        'net' => $net,
        'before' => round($net - ($paidUpTo - $applied), 2),
        'applied' => $applied,
        'after' => round($net - $paidUpTo, 2),
    ];
}

if (!$lines || !$inv0) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("El cobro no tiene comprobantes asociados.\n");
}

$pay = $payments[0];
$multi = count($lines) > 1;
$cur = count($currencies) === 1 ? array_key_first($currencies) : 'DOP';
$amount = round($amount, 2);

$receiptNo = $hasReceiptCol ? trim((string) ($pay['receipt_number'] ?? '')) : '';
if ($receiptNo === '') {
    // Cobros registrados antes de que existiera la numeración: se identifica el
    // recibo por el id del pago, sin inventar un correlativo que no se emitió.
    $receiptNo = 'PAGO-' . str_pad((string) ($pay['id'] ?? 0), 4, '0', STR_PAD_LEFT);
}

$clientName = (string) ($inv0['client_name'] ?: $inv0['c_name'] ?: 'Cliente');
$clientRnc = (string) ($inv0['client_rnc'] ?: $inv0['c_rnc'] ?: '');

$h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$mc = fn ($v, $c = null) => money_cur($v, $c ?? $cur);

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
    h1, h2, h3 { margin: 0; }
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
    .block { margin-top: 15px; }
    .block h3 { font-size: 9px; color: #027F31; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1.5px solid #d8e6dd; padding-bottom: 3px; margin-bottom: 6px; }
    table.kv { width: 100%; border-collapse: collapse; }
    table.kv td { padding: 4px 0; vertical-align: top; font-size: 10px; }
    table.kv td.k { color: #56697b; width: 130px; }
    table.kv td.v { font-weight: bold; }
    table.bal { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.bal th { background: #f1f8f3; color: #41515f; font-size: 8.5px; text-transform: uppercase; letter-spacing: .4px; padding: 6px 8px; border-bottom: 1px solid #d8e6dd; text-align: right; }
    table.bal th:first-child, table.bal td:first-child { text-align: left; }
    table.bal td { padding: 6px 8px; border-bottom: 1px solid #eef3f8; font-size: 10px; text-align: right; }
    table.bal td.total, table.bal td.applied { font-weight: bold; color: #027F31; }
    table.bal tr.sum td { border-top: 1.5px solid #d8e6dd; border-bottom: none; font-weight: bold; }
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
            <div class="doc-label">Recibo de ingreso</div>
            <div class="doc-no"><?= $h($receiptNo) ?></div>
            <div class="doc-date">Fecha del cobro: <b><?= $h(date_es((string) $pay['paid_at'])) ?></b></div>
        </td>
    </tr></table>

    <div class="amount-box">
        <div class="k">Recibimos de <?= $h($clientName) ?><?= $clientRnc !== '' ? ' · RNC/Cédula ' . $h($clientRnc) : '' ?></div>
        <div class="v"><?= $h($mc($amount)) ?></div>
        <div class="w"><?= $h(money_in_words($amount, $cur)) ?></div>
    </div>

    <div class="block">
        <h3>Forma de pago</h3>
        <table class="kv">
            <tr><td class="k">Medio</td><td class="v"><?= $h((string) ($pay['method'] ?: 'No indicado')) ?><?= !empty($pay['reference']) ? ' · Ref. ' . $h((string) $pay['reference']) : '' ?></td></tr>
            <?php if (!empty($pay['note'])): ?><tr><td class="k">Observación</td><td class="v" style="font-weight:normal"><?= $h((string) $pay['note']) ?></td></tr><?php endif; ?>
        </table>
    </div>

    <div class="block">
        <h3><?= $multi ? 'Comprobantes saldados con este cobro' : 'Concepto' ?></h3>
        <?php if ($multi): ?>
            <table class="bal">
                <tr><th>Comprobante</th><th>Saldo anterior</th><th>Aplicado</th><th>Saldo pendiente</th></tr>
                <?php foreach ($lines as $l): ?>
                    <tr>
                        <td>
                            <b><?= $h($l['ref']) ?></b>
                            <?php if ($l['title'] !== ''): ?><br><span class="muted"><?= $h(mb_strimwidth($l['title'], 0, 52, '…')) ?></span><?php endif; ?>
                        </td>
                        <td><?= $h($mc($l['before'], $l['currency'])) ?></td>
                        <td class="applied">− <?= $h($mc($l['applied'], $l['currency'])) ?></td>
                        <td<?= $l['after'] <= 0.009 ? ' class="total"' : '' ?>><?= $h($mc(max(0.0, $l['after']), $l['currency'])) ?><?= $l['after'] <= 0.009 ? ' ✓' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="sum">
                    <td>Total recibido · <?= $h((string) count($lines)) ?> comprobantes</td>
                    <td></td>
                    <td class="applied"><?= $h($mc($amount)) ?></td>
                    <td></td>
                </tr>
            </table>
        <?php else: $l = $lines[0]; ?>
            <table class="kv">
                <tr><td class="k">Comprobante</td><td class="v"><?= $h($l['ref']) ?><?= $l['number'] !== '' && $l['ref'] !== $l['number'] ? ' <span class="muted" style="font-weight:normal">(' . $h($l['number']) . ')</span>' : '' ?></td></tr>
                <?php if ($l['title'] !== ''): ?><tr><td class="k">Detalle</td><td class="v"><?= $h($l['title']) ?></td></tr><?php endif; ?>
                <tr><td class="k">Fecha del comprobante</td><td class="v"><?= $h(date_es($l['issue'])) ?></td></tr>
            </table>
            <table class="bal" style="margin-top:8px">
                <tr><th>Concepto</th><th>Importe</th></tr>
                <tr><td>Neto del comprobante<?= $l['net'] < (float) $l['inv']['total'] - 0.009 ? ' <span class="muted">(ya descontadas retenciones, ajustes y notas de crédito)</span>' : '' ?></td><td><?= $h($mc($l['net'], $l['currency'])) ?></td></tr>
                <tr><td>Saldo antes de este pago</td><td><?= $h($mc($l['before'], $l['currency'])) ?></td></tr>
                <tr><td>Este pago</td><td class="applied">− <?= $h($mc($l['applied'], $l['currency'])) ?></td></tr>
                <tr><td><b>Saldo pendiente</b></td><td class="total"><?= $h($mc(max(0.0, $l['after']), $l['currency'])) ?></td></tr>
            </table>
            <?php if ($l['after'] <= 0.009): ?>
                <div class="note" style="border-left-color:#027F31;color:#1d6b3f"><b>Comprobante saldado.</b> Con este pago no queda balance pendiente.</div>
            <?php endif; ?>
        <?php endif; ?>
        <div class="muted" style="margin-top:4px;font-size:8.2px;">Importes al <?= $h(date_es((string) $pay['paid_at'])) ?>, fecha de este cobro.</div>
    </div>

    <?php if ($cur === 'USD'): ?>
        <div class="note">Importes expresados en dólares estadounidenses (USD), moneda de los comprobantes.</div>
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

$dompdf->stream('Recibo-' . $receiptNo . '.pdf', ['Attachment' => isset($_GET['download']) ? 1 : 0]);
exit;
