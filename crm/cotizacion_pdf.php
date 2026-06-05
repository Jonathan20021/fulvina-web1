<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

use Dompdf\Dompdf;
use Dompdf\Options;

$hasDb = db(false) && table_exists('quotes');
if ($hasDb) {
    ensure_quote_schema();
}

$id = (int) ($_GET['id'] ?? 0);
$download = isset($_GET['download']);

$quote = $hasDb
    ? fetch_one('SELECT quotes.*, clients.name AS client_name, clients.email, clients.phone, clients.address, clients.rnc FROM quotes LEFT JOIN clients ON clients.id = quotes.client_id WHERE quotes.id = ?', [$id])
    : null;
$items = $hasDb && $quote ? fetch_all('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id ASC', [$id]) : [];

if (!$quote) {
    $quote = [
        'quote_number' => 'SCH-2026-0001', 'client_name' => 'Hospital Metropolitano de Santiago', 'rnc' => '101-00000-1',
        'email' => 'compras@hms.local', 'phone' => '809-000-0000', 'address' => 'Santiago de los Caballeros',
        'title' => 'Sistema central de gases medicinales', 'status' => 'Enviado', 'valid_until' => date('Y-m-d', strtotime('+30 days')),
        'created_at' => date('Y-m-d'), 'subtotal' => 15000, 'tax_rate' => 18, 'tax_amount' => 2700, 'total' => 17700,
        'currency' => 'DOP', 'exchange_rate' => 1, 'notes' => '', 'terms' => '',
    ];
    $items = [
        ['description' => 'Suministro e instalacion de salidas de gases medicinales', 'quantity' => 12, 'unit_price' => 650, 'total' => 7800],
        ['description' => 'Alarmas sectoriales y caja de valvulas', 'quantity' => 1, 'unit_price' => 4200, 'total' => 4200],
        ['description' => 'Puesta en marcha y certificacion', 'quantity' => 1, 'unit_price' => 3000, 'total' => 3000],
    ];
}

$cur = strtoupper((string) ($quote['currency'] ?? 'DOP')) === 'USD' ? 'USD' : 'DOP';
$rate = (float) ($quote['exchange_rate'] ?? 1);
if ($rate <= 0) { $rate = 1; }
$terms = trim((string) ($quote['terms'] ?? ''));
if ($terms === '') { $terms = setting_get('quote_terms', quote_default_terms()); }
$number = (string) ($quote['quote_number'] ?? 'COT');
$createdAt = $quote['created_at'] ?? date('Y-m-d');

// Status colour
$st = strtolower((string) ($quote['status'] ?? 'borrador'));
$statusColor = match ($st) {
    'aprobado' => '#0a7d36',
    'enviado', 'cotizado' => '#0666b3',
    'rechazado', 'cerrado' => '#64748b',
    'negociacion' => '#9c7d34',
    default => '#d97706',
};

// Logo as data URI (reliable embedding in dompdf)
$logoData = '';
$logoPath = __DIR__ . '/../' . APP_LOGO;
if (is_file($logoPath)) {
    $logoData = 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath));
}

$sym = $cur === 'USD' ? 'US$' : 'RD$';
$fmt = fn ($v) => $sym . ' ' . number_format((float) $v, 2, '.', ',');
$h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    * { font-family: "DejaVu Sans", sans-serif; }
    @page { margin: 26px 34px 86px; }
    body { margin: 0; color: #0e1a28; font-size: 10.5px; line-height: 1.45; }
    .muted { color: #56697b; }
    .right { text-align: right; }
    .center { text-align: center; }
    h1, h2, h3 { margin: 0; }

    /* Header */
    .accent { height: 5px; background: #0a7d36; }
    .head { width: 100%; border-collapse: collapse; margin-top: 14px; }
    .head td { vertical-align: top; }
    .brand-logo { width: 58px; height: auto; }
    .brand-name { font-size: 19px; font-weight: bold; color: #0a7d36; letter-spacing: -.3px; }
    .brand-sub { color: #56697b; font-size: 9px; }
    .brand-meta { color: #56697b; font-size: 9px; margin-top: 4px; }
    .doc-label { color: #8696a6; font-size: 9px; letter-spacing: 2px; text-transform: uppercase; }
    .doc-number { font-size: 22px; font-weight: bold; color: #0e1a28; letter-spacing: -.5px; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; color: #fff; font-size: 9px; font-weight: bold; }
    .doc-meta { margin-top: 7px; color: #56697b; font-size: 9.5px; }
    .doc-meta b { color: #0e1a28; }

    /* Parties */
    .parties { width: 100%; border-collapse: collapse; margin-top: 16px; }
    .parties td { vertical-align: top; width: 50%; padding: 0; }
    .pcard { border: 1px solid #e3eaf1; border-radius: 8px; padding: 10px 12px; }
    .pcard-l { margin-right: 7px; }
    .pcard-r { margin-left: 7px; }
    .pcard h3 { color: #8696a6; font-size: 8.5px; letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 4px; }
    .pcard .name { font-size: 12.5px; font-weight: bold; color: #0e1a28; }
    .pcard p { margin: 3px 0 0; color: #56697b; font-size: 9.5px; }

    /* Items */
    table.items { width: 100%; border-collapse: collapse; margin-top: 16px; }
    table.items thead th {
        background: #0a7d36; color: #fff; font-size: 9px; font-weight: bold;
        text-transform: uppercase; letter-spacing: .5px; padding: 8px 10px; text-align: left;
    }
    table.items thead th.r { text-align: right; }
    table.items tbody td { padding: 8px 10px; border-bottom: 1px solid #eef3f8; font-size: 10px; vertical-align: top; }
    table.items tbody tr:nth-child(even) td { background: #f7faf8; }
    table.items td.r { text-align: right; white-space: nowrap; }
    table.items td.num { color: #8696a6; width: 26px; }
    .item-name { font-weight: bold; color: #0e1a28; }

    /* Totals + terms */
    .lower { width: 100%; border-collapse: collapse; margin-top: 14px; }
    .lower td { vertical-align: top; }
    .lower .l { width: 58%; padding-right: 16px; }
    .lower .r { width: 42%; }
    .tt { width: 100%; border-collapse: collapse; }
    .tt td { padding: 6px 12px; font-size: 10.5px; }
    .tt td.k { color: #56697b; }
    .tt td.v { text-align: right; font-weight: bold; color: #0e1a28; white-space: nowrap; }
    .tt tr.sep td { border-top: 1px solid #e3eaf1; }
    .tt tr.total td { background: #0a7d36; color: #fff; font-size: 13px; font-weight: bold; }
    .tt tr.total td.k { color: #eafff2; }
    .tt tr.equiv td { color: #56697b; font-size: 9.5px; padding-top: 7px; }
    .notes-box { border: 1px solid #e3eaf1; border-radius: 8px; padding: 9px 11px; }
    .notes-box h3 { color: #8696a6; font-size: 8.5px; letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 4px; }
    .notes-box p { margin: 0; color: #41515f; font-size: 9.5px; }

    .lower { page-break-inside: avoid; }
    table.items tbody tr { page-break-inside: avoid; }
    .terms { margin-top: 14px; border-top: 1px solid #e3eaf1; padding-top: 10px; page-break-inside: avoid; }
    .terms h3 { color: #0e1a28; font-size: 10.5px; font-weight: bold; margin-bottom: 5px; }
    .terms p { margin: 0; color: #56697b; font-size: 8.8px; line-height: 1.65; }

    /* Fixed footer (repeats each page) */
    .foot { position: fixed; left: -34px; right: -34px; bottom: -64px; height: 56px; }
    .foot-inner { border-top: 2px solid #0a7d36; margin: 0 34px; padding-top: 7px; color: #56697b; font-size: 8.6px; }
    .foot-inner table { width: 100%; border-collapse: collapse; }
    .foot-inner b { color: #0a7d36; }
</style>
</head>
<body>
    <div class="foot">
        <div class="foot-inner">
            <table>
                <tr>
                    <td><b><?= $h(APP_NAME) ?></b> &nbsp;·&nbsp; <?= $h(APP_TAGLINE) ?> &nbsp;·&nbsp; Desde <?= $h(APP_FOUNDED) ?></td>
                    <td class="right">Tel. <?= $h(APP_PHONE) ?> &nbsp;·&nbsp; <?= $h(APP_INFO_EMAIL) ?> &nbsp;·&nbsp; <?= $h(APP_ADDRESS) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="accent"></div>

    <table class="head">
        <tr>
            <td style="width: 60%;">
                <table style="border-collapse: collapse;">
                    <tr>
                        <?php if ($logoData): ?><td style="width: 66px; vertical-align: top;"><img src="<?= $logoData ?>" class="brand-logo"></td><?php endif; ?>
                        <td style="vertical-align: top; padding-top: 2px;">
                            <div class="brand-name">SCH MEDICOS</div>
                            <div class="brand-sub"><?= $h(APP_TAGLINE) ?></div>
                            <div class="brand-meta">Equipos médicos · Gases medicinales · Diseño, instalación, certificación y soporte técnico.<br>Datos fiscales y RNC en la factura final.</div>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 40%; text-align: right;">
                <div class="doc-label">Cotización</div>
                <div class="doc-number"><?= $h($number) ?></div>
                <div style="margin-top: 5px;"><span class="badge" style="background: <?= $statusColor ?>;"><?= $h($quote['status'] ?? 'Borrador') ?></span></div>
                <div class="doc-meta">
                    Emitida: <b><?= $h(date_es($createdAt)) ?></b><br>
                    Válida hasta: <b><?= $h(date_es($quote['valid_until'] ?? null)) ?></b><br>
                    Moneda: <b><?= $h($cur) ?></b><?php if ($cur === 'USD'): ?> &nbsp;(US$ 1 = RD$ <?= $h(number_format($rate, 2)) ?>)<?php endif; ?>
                </div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                <div class="pcard pcard-l">
                    <h3>Cliente</h3>
                    <div class="name"><?= $h($quote['client_name'] ?? 'Cliente') ?></div>
                    <p>
                        <?php if (!empty($quote['rnc'])): ?>RNC: <?= $h($quote['rnc']) ?><br><?php endif; ?>
                        <?php if (!empty($quote['email'])): ?><?= $h($quote['email']) ?><br><?php endif; ?>
                        <?php if (!empty($quote['phone'])): ?><?= $h($quote['phone']) ?><br><?php endif; ?>
                        <?php if (!empty($quote['address'])): ?><?= $h($quote['address']) ?><?php endif; ?>
                    </p>
                </div>
            </td>
            <td>
                <div class="pcard pcard-r">
                    <h3>Detalle de la cotización</h3>
                    <div class="name"><?= $h($quote['title'] ?? '') ?></div>
                    <p>Propuesta comercial preparada por el equipo técnico-comercial de SCH MEDICOS para la institución indicada.</p>
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th class="num">#</th>
                <th>Descripción</th>
                <th class="r">Cant.</th>
                <th class="r">Precio unit.</th>
                <th class="r">Importe</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach ($items as $item): ?>
                <tr>
                    <td class="num"><?= $i++ ?></td>
                    <td><span class="item-name"><?= $h($item['description'] ?? '') ?></span></td>
                    <td class="r"><?= $h(rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2, '.', ','), '0'), '.')) ?></td>
                    <td class="r"><?= $h($fmt($item['unit_price'] ?? 0)) ?></td>
                    <td class="r"><?= $h($fmt($item['total'] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?>
                <tr><td colspan="5" class="center muted" style="padding: 16px;">Esta cotización no tiene partidas registradas.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <table class="lower">
        <tr>
            <td class="l">
                <?php if (!empty($quote['notes'])): ?>
                    <div class="notes-box"><h3>Notas comerciales</h3><p><?= nl2br($h($quote['notes'])) ?></p></div>
                <?php endif; ?>
            </td>
            <td class="r">
                <table class="tt">
                    <tr><td class="k">Subtotal</td><td class="v"><?= $h($fmt($quote['subtotal'] ?? 0)) ?></td></tr>
                    <tr class="sep"><td class="k">ITBIS (<?= $h((string) ($quote['tax_rate'] ?? 18)) ?>%)</td><td class="v"><?= $h($fmt($quote['tax_amount'] ?? 0)) ?></td></tr>
                    <tr class="total"><td class="k">TOTAL</td><td class="v"><?= $h($fmt($quote['total'] ?? 0)) ?></td></tr>
                    <?php if ($cur === 'USD'): ?>
                        <tr class="equiv"><td class="k">Equivalente (RD$ a <?= $h(number_format($rate, 2)) ?>)</td><td class="v" style="color:#56697b;">RD$ <?= $h(number_format((float) ($quote['total'] ?? 0) * $rate, 2, '.', ',')) ?></td></tr>
                    <?php endif; ?>
                </table>
            </td>
        </tr>
    </table>

    <div class="terms">
        <h3>Términos y condiciones</h3>
        <p><?= nl2br($h($terms)) ?></p>
    </div>
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
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// Page numbers on every page
$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
if ($font) {
    $canvas->page_text($canvas->get_width() - 120, $canvas->get_height() - 24, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, [0.34, 0.41, 0.49]);
}

$filename = 'Cotizacion-' . preg_replace('/[^A-Za-z0-9_-]/', '', $number) . '.pdf';
$dompdf->stream($filename, ['Attachment' => $download ? 1 : 0]);
exit;
