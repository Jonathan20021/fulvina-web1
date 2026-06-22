<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('facturas.view');

use Dompdf\Dompdf;
use Dompdf\Options;

$hasDb = db(false) && table_exists('invoices');
if (db(false)) { ensure_invoice_schema(); }

$id = (int) ($_GET['id'] ?? 0);
$download = isset($_GET['download']);

$inv = $hasDb
    ? fetch_one('SELECT invoices.*, clients.email AS c_email, clients.phone AS c_phone FROM invoices LEFT JOIN clients ON clients.id = invoices.client_id WHERE invoices.id = ?', [$id])
    : null;
$items = $hasDb && $inv ? fetch_all('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC', [$id]) : [];

if ($hasDb && !$inv) {
    http_response_code(404);
    exit('Factura no encontrada.');
}
if (!$inv) {
    // No-DB preview only.
    $inv = [
        'invoice_number' => 'FAC-' . date('Y') . '-0001', 'ncf' => 'B0100000001', 'ncf_type' => '01', 'ncf_prefix' => 'B',
        'ncf_expiration' => date('Y-m-d', strtotime('+1 year')), 'modifies_ncf' => '',
        'client_name' => 'Hospital Metropolitano de Santiago', 'client_rnc' => '101-00000-1', 'client_address' => 'Av. Principal, Santiago de los Caballeros',
        'c_email' => 'compras@hms.local', 'c_phone' => '809-000-0000', 'title' => 'Equipamiento biomédico',
        'status' => 'Emitida', 'payment_condition' => 'Crédito', 'payment_method' => 'Transferencia',
        'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+30 days')),
        'taxed_base' => 15000, 'exempt_base' => 0, 'discount_amount' => 0, 'subtotal' => 15000, 'tax_rate' => 18, 'tax_amount' => 2700,
        'isc_amount' => 0, 'itbis_retained' => 0, 'isr_retained' => 0, 'total' => 17700, 'amount_paid' => 0,
        'currency' => 'DOP', 'exchange_rate' => 1, 'notes' => '', 'terms' => '',
    ];
    $items = [
        ['description' => 'Camas UCI eléctricas', 'quantity' => 3, 'unit_price' => 4200, 'discount' => 0, 'is_exempt' => 0, 'total' => 12600],
        ['description' => 'Instalación, puesta en marcha y certificación', 'quantity' => 1, 'unit_price' => 2400, 'discount' => 0, 'is_exempt' => 0, 'total' => 2400],
    ];
}

$cur = strtoupper((string) ($inv['currency'] ?? 'DOP')) === 'USD' ? 'USD' : 'DOP';
$rate = (float) ($inv['exchange_rate'] ?? 1);
if ($rate <= 0) { $rate = 1; }
$terms = trim((string) ($inv['terms'] ?? ''));
if ($terms === '') { $terms = setting_get('invoice_terms', invoice_default_terms()); }
$type = (string) ($inv['ncf_type'] ?? '02');
$ncf = trim((string) ($inv['ncf'] ?? ''));
$status = (string) ($inv['status'] ?? 'Borrador');
$number = (string) ($inv['invoice_number'] ?? 'FAC');
$heading = ncf_doc_heading($type);
$net = round((float) $inv['total'] - (float) $inv['itbis_retained'] - (float) $inv['isr_retained'], 2);
$hasRet = (float) $inv['itbis_retained'] > 0 || (float) $inv['isr_retained'] > 0;

$statusColor = match (strtolower($status)) {
    'pagada' => '#0a7d36',
    'emitida' => '#0666b3',
    'anulada' => '#64748b',
    default => '#d97706',
};

$logoData = '';
$logoPath = __DIR__ . '/../' . APP_LOGO;
if (is_file($logoPath)) {
    $logoData = 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath));
}

$sym = $cur === 'USD' ? 'US$' : 'RD$';
$fmt = fn ($v) => $sym . ' ' . number_format((float) $v, 2, '.', ',');
$qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.');
$h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$totalWords = money_in_words((float) ($inv['total'] ?? 0), $cur);

ob_start();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    * { font-family: "DejaVu Sans", sans-serif; }
    @page { margin: 32px 40px 96px; }
    body { margin: 0; color: #1a2734; font-size: 10.5px; line-height: 1.45; }
    .muted { color: #5b6b7b; }
    .right { text-align: right; }
    .center { text-align: center; }
    h1, h2, h3 { margin: 0; }

    .topbar { height: 4px; background: #0a7d36; }
    .head { width: 100%; border-collapse: collapse; margin-top: 16px; }
    .head td { vertical-align: top; }
    .brand-logo { width: 60px; height: auto; }
    .brand-name { font-size: 20px; font-weight: bold; color: #0a7d36; letter-spacing: -.3px; }
    .brand-sub { color: #5b6b7b; font-size: 9px; margin-top: 1px; }
    .brand-meta { color: #5b6b7b; font-size: 8.6px; margin-top: 6px; line-height: 1.5; }
    .doc-box { border: 1px solid #d8e2ec; border-radius: 8px; padding: 9px 12px; }
    .doc-label { color: #8696a6; font-size: 8.5px; letter-spacing: 1.5px; text-transform: uppercase; }
    .doc-number { font-size: 17px; font-weight: bold; color: #0e1a28; letter-spacing: -.5px; margin-top: 1px; }
    .badge { display: inline-block; padding: 3px 11px; border-radius: 20px; color: #fff; font-size: 8.5px; font-weight: bold; margin-top: 4px; }
    .ncf-box { margin-top: 7px; border: 1px solid #c7d6c9; border-radius: 6px; background: #f5faf6; padding: 5px 9px; }
    .ncf-box .k { color: #066128; font-size: 7.6px; letter-spacing: 1.2px; text-transform: uppercase; font-weight: bold; }
    .ncf-box .v { font-size: 15px; font-weight: bold; color: #0a7d36; letter-spacing: .5px; }
    .ncf-box .t { color: #5b6b7b; font-size: 8px; }
    .doc-meta { margin-top: 7px; color: #5b6b7b; font-size: 9px; line-height: 1.55; }
    .doc-meta b { color: #0e1a28; }

    .proforma { margin-top: 12px; border: 1px dashed #d3a017; border-radius: 7px; background: #fffaf0; padding: 7px 12px; color: #92660a; font-size: 9px; font-weight: bold; }
    .voided { margin-top: 12px; border: 1px solid #e7b3b3; border-radius: 7px; background: #fdf2f2; padding: 7px 12px; color: #b3261e; font-size: 9px; font-weight: bold; }
    .ecf-note { margin-top: 12px; border: 1px solid #9bbbd9; border-radius: 7px; background: #f1f7fd; padding: 7px 12px; color: #1d4e74; font-size: 9px; font-weight: bold; }

    .parties { width: 100%; border-collapse: collapse; margin-top: 16px; }
    .parties td { vertical-align: top; width: 50%; padding: 0; }
    .pcard { border: 1px solid #e3eaf1; border-radius: 8px; padding: 11px 13px; min-height: 78px; }
    .pcard-l { margin-right: 8px; }
    .pcard-r { margin-left: 8px; }
    .pcard h3 { color: #0a7d36; font-size: 8.5px; letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 5px; }
    .pcard .name { font-size: 12px; font-weight: bold; color: #0e1a28; }
    .pcard p { margin: 3px 0 0; color: #5b6b7b; font-size: 9.3px; line-height: 1.5; }
    .pcard .rnc { color: #0e1a28; font-weight: bold; }

    .subject-row { margin-top: 14px; border-left: 3px solid #0a7d36; padding: 2px 0 2px 10px; }
    .subject-row .k { color: #8696a6; font-size: 8px; letter-spacing: 1.5px; text-transform: uppercase; }
    .subject-row .v { font-size: 12.5px; font-weight: bold; color: #0e1a28; }
    .cond-tag { display: inline-block; margin-left: 8px; padding: 2px 8px; border-radius: 12px; background: #e8f4ec; color: #066128; font-size: 8.5px; font-weight: bold; }

    table.items { width: 100%; border-collapse: collapse; margin-top: 16px; }
    table.items thead th { background: #0a7d36; color: #fff; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; padding: 8px 10px; text-align: left; }
    table.items thead th.r { text-align: right; }
    table.items tbody td { padding: 8px 10px; border-bottom: 1px solid #eef3f8; font-size: 10px; vertical-align: top; }
    table.items tbody tr:nth-child(even) td { background: #f7faf8; }
    table.items td.r { text-align: right; white-space: nowrap; }
    table.items td.num { color: #8696a6; width: 24px; text-align: center; }
    .item-name { font-weight: bold; color: #0e1a28; }
    .ex-tag { display: inline-block; margin-left: 5px; padding: 1px 6px; border-radius: 10px; background: #eef2f7; color: #5b6b7b; font-size: 7.5px; font-weight: bold; }

    .lower { width: 100%; border-collapse: collapse; margin-top: 14px; }
    .lower td { vertical-align: top; }
    .lower .l { width: 52%; padding-right: 16px; }
    .lower .r { width: 48%; }
    .tt { width: 100%; border-collapse: collapse; }
    .tt td { padding: 5px 12px; font-size: 10.5px; }
    .tt td.k { color: #5b6b7b; }
    .tt td.v { text-align: right; font-weight: bold; color: #0e1a28; white-space: nowrap; }
    .tt tr.sep td { border-top: 1px solid #e3eaf1; }
    .tt tr.total td { background: #0a7d36; color: #fff; font-size: 13.5px; font-weight: bold; }
    .tt tr.total td.k { color: #eafff2; }
    .tt tr.minor td { color: #5b6b7b; font-size: 9.4px; padding-top: 4px; padding-bottom: 4px; }
    .tt tr.net td { font-size: 11.5px; font-weight: bold; color: #0e1a28; border-top: 1px solid #e3eaf1; }
    .tt tr.equiv td { color: #5b6b7b; font-size: 9.2px; padding-top: 7px; }
    .words { border: 1px dashed #c7d6c9; border-radius: 7px; background: #f5faf6; padding: 8px 11px; margin-top: 6px; }
    .words .k { color: #8696a6; font-size: 7.6px; letter-spacing: 1.4px; text-transform: uppercase; }
    .words .v { color: #0e1a28; font-size: 9.6px; font-weight: bold; margin-top: 2px; }
    .notes-box { border: 1px solid #e3eaf1; border-radius: 8px; padding: 9px 11px; }
    .notes-box h3 { color: #8696a6; font-size: 8.5px; letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 4px; }
    .notes-box p { margin: 0; color: #41515f; font-size: 9.3px; line-height: 1.5; }

    .lower, table.items tbody tr { page-break-inside: avoid; }
    .terms { margin-top: 16px; border-top: 1px solid #e3eaf1; padding-top: 10px; page-break-inside: avoid; }
    .terms h3 { color: #0e1a28; font-size: 10px; font-weight: bold; margin-bottom: 5px; }
    .terms p { margin: 0; color: #5b6b7b; font-size: 8.7px; line-height: 1.7; }

    .signs { width: 100%; border-collapse: collapse; margin-top: 30px; page-break-inside: avoid; }
    .signs td { width: 50%; vertical-align: bottom; padding: 0 18px; }
    .sign-line { border-top: 1px solid #1a2734; padding-top: 6px; text-align: center; }
    .sign-line b { font-size: 9.5px; color: #0e1a28; }
    .sign-line span { display: block; font-size: 8.4px; color: #5b6b7b; margin-top: 1px; }

    .foot { position: fixed; left: -40px; right: -40px; bottom: -72px; height: 60px; }
    .foot-inner { border-top: 2px solid #0a7d36; margin: 0 40px; padding-top: 7px; color: #5b6b7b; font-size: 8.4px; line-height: 1.5; }
    .foot-inner table { width: 100%; border-collapse: collapse; }
    .foot-inner b { color: #0a7d36; }
</style>
</head>
<body>
    <div class="foot">
        <div class="foot-inner">
            <table>
                <tr>
                    <td><b><?= $h(APP_LEGAL) ?></b> &nbsp;·&nbsp; <?= $h(APP_TAGLINE) ?> &nbsp;·&nbsp; Desde <?= $h(APP_FOUNDED) ?><br><?= $h(APP_ADDRESS) ?> &nbsp;·&nbsp; <?= $h(APP_SECONDARY_ADDRESS) ?></td>
                    <td class="right">Tel. <?= $h(APP_PHONE) ?> &nbsp;·&nbsp; <?= $h(APP_PHONE_US) ?><br><?= $h(APP_INFO_EMAIL) ?> &nbsp;·&nbsp; <?= APP_RNC !== '' ? 'RNC: ' . $h(APP_RNC) : 'RNC en configuración' ?></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="topbar"></div>

    <table class="head">
        <tr>
            <td style="width: 56%;">
                <table style="border-collapse: collapse;">
                    <tr>
                        <?php if ($logoData): ?><td style="width: 70px; vertical-align: top;"><img src="<?= $logoData ?>" class="brand-logo"></td><?php endif; ?>
                        <td style="vertical-align: top; padding-top: 2px;">
                            <div class="brand-name">SCH MEDICOS</div>
                            <div class="brand-sub"><?= $h(APP_TAGLINE) ?></div>
                            <div class="brand-meta"><?= $h(APP_LEGAL) ?><?php if (APP_RNC !== ''): ?> · RNC: <?= $h(APP_RNC) ?><?php endif; ?><br><?= $h(APP_ADDRESS) ?><br>Tel. <?= $h(APP_PHONE) ?> · <?= $h(APP_INFO_EMAIL) ?></div>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 44%;">
                <div class="doc-box">
                    <div class="doc-label"><?= $h($heading) ?></div>
                    <div class="doc-number"><?= $h($number) ?></div>
                    <div><span class="badge" style="background: <?= $statusColor ?>;"><?= $h($status) ?></span></div>
                    <div class="ncf-box">
                        <div class="k">NCF — Comprobante fiscal</div>
                        <div class="v"><?= $ncf !== '' ? $h($ncf) : 'PENDIENTE' ?></div>
                        <div class="t"><?= $h($type) ?> · <?= $h(ncf_type_label($type)) ?></div>
                    </div>
                    <div class="doc-meta">
                        Emitida: <b><?= $h(date_es($inv['issue_date'] ?? null)) ?></b><br>
                        Vencimiento: <b><?= $h(date_es($inv['due_date'] ?? null)) ?></b> · <?= $h($inv['payment_condition'] ?? 'Contado') ?><br>
                        <?php if (!empty($inv['ncf_expiration'])): ?>Vence NCF: <b><?= $h(date_es($inv['ncf_expiration'])) ?></b><br><?php endif; ?>
                        Moneda: <b><?= $h($cur) ?></b><?php if ($cur === 'USD'): ?> (US$ 1 = RD$ <?= $h(number_format($rate, 2)) ?>)<?php endif; ?>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <?php if ($ncf === '' && strtolower($status) === 'borrador'): ?>
        <div class="proforma">PROFORMA — DOCUMENTO SIN VALIDEZ FISCAL. El NCF se asigna al emitir la factura.</div>
    <?php elseif (strtolower($status) === 'anulada'): ?>
        <div class="voided">COMPROBANTE ANULADO<?php if (!empty($inv['void_reason'])): ?> — <?= $h($inv['void_reason']) ?><?php endif; ?></div>
    <?php elseif (!empty($inv['is_ecf']) && empty($inv['ecf_track_id'])): ?>
        <div class="ecf-note">REPRESENTACIÓN IMPRESA DE e-CF — registro manual del comprobante fiscal electrónico, pendiente de transmisión y validación ante la DGII.</div>
    <?php endif; ?>

    <table class="parties">
        <tr>
            <td>
                <div class="pcard pcard-l">
                    <h3>Emisor</h3>
                    <div class="name"><?= $h(APP_LEGAL) ?></div>
                    <?php if (APP_RNC !== ''): ?><p class="rnc">RNC: <?= $h(APP_RNC) ?></p><?php endif; ?>
                    <p><?= $h(APP_ADDRESS) ?><br>Tel. <?= $h(APP_PHONE) ?> · <?= $h(APP_PHONE_US) ?><br><?= $h(APP_INFO_EMAIL) ?></p>
                </div>
            </td>
            <td>
                <div class="pcard pcard-r">
                    <h3>Cliente</h3>
                    <div class="name"><?= $h($inv['client_name'] ?? 'Cliente') ?></div>
                    <?php if (!empty($inv['client_rnc'])): ?><p class="rnc">RNC/Cédula: <?= $h($inv['client_rnc']) ?></p><?php endif; ?>
                    <p>
                        <?php if (!empty($inv['client_address'])): ?><?= $h($inv['client_address']) ?><br><?php endif; ?>
                        <?php if (!empty($inv['c_phone'])): ?>Tel. <?= $h($inv['c_phone']) ?><?php endif; ?>
                        <?php if (!empty($inv['c_email'])): ?><?= !empty($inv['c_phone']) ? ' · ' : '' ?><?= $h($inv['c_email']) ?><?php endif; ?>
                    </p>
                </div>
            </td>
        </tr>
    </table>

    <div class="subject-row">
        <div class="k">Concepto</div>
        <div class="v"><?= $h($inv['title'] ?? 'Venta de bienes y servicios') ?><?php if (!empty($inv['modifies_ncf'])): ?><span class="cond-tag">Modifica NCF <?= $h($inv['modifies_ncf']) ?></span><?php endif; ?></div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th class="num">#</th>
                <th>Descripción</th>
                <th class="r">Cant.</th>
                <th class="r">Precio unit.</th>
                <th class="r">Desc.</th>
                <th class="r">Importe</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach ($items as $item): ?>
                <tr>
                    <td class="num"><?= $i++ ?></td>
                    <td><span class="item-name"><?= $h($item['description'] ?? '') ?></span><?php if (!empty($item['is_exempt'])): ?><span class="ex-tag">EXENTO</span><?php endif; ?></td>
                    <td class="r"><?= $h($qty($item['quantity'] ?? 0)) ?></td>
                    <td class="r"><?= $h($fmt($item['unit_price'] ?? 0)) ?></td>
                    <td class="r"><?= (float) ($item['discount'] ?? 0) > 0 ? $h($fmt($item['discount'])) : '—' ?></td>
                    <td class="r"><?= $h($fmt($item['total'] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?>
                <tr><td colspan="6" class="center muted" style="padding: 16px;">Esta factura no tiene partidas registradas.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <table class="lower">
        <tr>
            <td class="l">
                <?php if (!empty($inv['notes'])): ?>
                    <div class="notes-box"><h3>Notas</h3><p><?= nl2br($h($inv['notes'])) ?></p></div>
                <?php endif; ?>
                <div class="words">
                    <div class="k">Son</div>
                    <div class="v"><?= $h($totalWords) ?></div>
                </div>
            </td>
            <td class="r">
                <table class="tt">
                    <?php if ((float) $inv['exempt_base'] > 0): ?>
                        <tr><td class="k">Subtotal gravado</td><td class="v"><?= $h($fmt($inv['taxed_base'])) ?></td></tr>
                        <tr><td class="k">Subtotal exento</td><td class="v"><?= $h($fmt($inv['exempt_base'])) ?></td></tr>
                    <?php else: ?>
                        <tr><td class="k">Subtotal</td><td class="v"><?= $h($fmt($inv['subtotal'])) ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float) $inv['discount_amount'] > 0): ?>
                        <tr><td class="k">Descuento</td><td class="v">− <?= $h($fmt($inv['discount_amount'])) ?></td></tr>
                    <?php endif; ?>
                    <tr class="sep"><td class="k">ITBIS (<?= $h($qty($inv['tax_rate'] ?? 18)) ?>%)</td><td class="v"><?= $h($fmt($inv['tax_amount'] ?? 0)) ?></td></tr>
                    <?php if ((float) $inv['isc_amount'] > 0): ?>
                        <tr><td class="k">ISC</td><td class="v"><?= $h($fmt($inv['isc_amount'])) ?></td></tr>
                    <?php endif; ?>
                    <tr class="total"><td class="k">TOTAL</td><td class="v"><?= $h($fmt($inv['total'] ?? 0)) ?></td></tr>
                    <?php if ((float) $inv['itbis_retained'] > 0): ?>
                        <tr class="minor"><td class="k">Retención ITBIS</td><td class="v">− <?= $h($fmt($inv['itbis_retained'])) ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float) $inv['isr_retained'] > 0): ?>
                        <tr class="minor"><td class="k">Retención ISR</td><td class="v">− <?= $h($fmt($inv['isr_retained'])) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($hasRet): ?>
                        <tr class="net"><td class="k">Neto a pagar</td><td class="v"><?= $h($fmt($net)) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($cur === 'USD'): ?>
                        <tr class="equiv"><td class="k">Equivalente (RD$ a <?= $h(number_format($rate, 2)) ?>)</td><td class="v" style="color:#5b6b7b;">RD$ <?= $h(number_format($net * $rate, 2, '.', ',')) ?></td></tr>
                    <?php endif; ?>
                </table>
            </td>
        </tr>
    </table>

    <div class="terms">
        <h3>Términos y condiciones</h3>
        <p><?= nl2br($h($terms)) ?></p>
    </div>

    <table class="signs">
        <tr>
            <td><div class="sign-line"><b>Por <?= $h(APP_LEGAL) ?></b><span>Firma autorizada y sello</span></div></td>
            <td><div class="sign-line"><b>Recibido conforme</b><span>Nombre, firma y fecha</span></div></td>
        </tr>
    </table>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('dpi', 96);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
if ($font) {
    $canvas->page_text($canvas->get_width() - 130, $canvas->get_height() - 30, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, [0.36, 0.42, 0.48]);
}

$base = $ncf !== '' ? $ncf : $number;
$filename = 'Factura-' . preg_replace('/[^A-Za-z0-9_-]/', '', $base) . '.pdf';
$dompdf->stream($filename, ['Attachment' => $download ? 1 : 0]);
exit;
