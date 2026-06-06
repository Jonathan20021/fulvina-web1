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
    ? fetch_one('SELECT quotes.*, clients.name AS client_name, clients.email, clients.phone, clients.address, clients.city, clients.rnc FROM quotes LEFT JOIN clients ON clients.id = quotes.client_id WHERE quotes.id = ?', [$id])
    : null;
$items = $hasDb && $quote ? fetch_all('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id ASC', [$id]) : [];

// On a live database, a missing/deleted id must NOT fabricate an official-looking
// quote — return an honest 404. The demo fallback is only for the no-DB preview.
if ($hasDb && !$quote) {
    http_response_code(404);
    exit('Cotización no encontrada.');
}
if (!$quote) {
    $quote = [
        'quote_number' => 'SCH-2026-0001', 'client_name' => 'Hospital Metropolitano de Santiago', 'rnc' => '101-00000-1',
        'email' => 'compras@hms.local', 'phone' => '809-000-0000', 'address' => 'Av. Principal, Santiago de los Caballeros', 'city' => 'Santiago',
        'title' => 'Sistema central de gases medicinales', 'category' => 'Gases medicinales', 'status' => 'Enviado', 'valid_until' => date('Y-m-d', strtotime('+30 days')),
        'created_at' => date('Y-m-d'), 'subtotal' => 15000, 'tax_rate' => 18, 'tax_amount' => 2700, 'total' => 17700,
        'currency' => 'DOP', 'exchange_rate' => 1, 'notes' => '', 'terms' => '',
    ];
    $items = [
        ['description' => 'Suministro e instalación de salidas de gases medicinales', 'quantity' => 12, 'unit_price' => 650, 'total' => 7800],
        ['description' => 'Alarmas sectoriales y caja de válvulas', 'quantity' => 1, 'unit_price' => 4200, 'total' => 4200],
        ['description' => 'Puesta en marcha y certificación', 'quantity' => 1, 'unit_price' => 3000, 'total' => 3000],
    ];
}

$cur = strtoupper((string) ($quote['currency'] ?? 'DOP')) === 'USD' ? 'USD' : 'DOP';
$rate = (float) ($quote['exchange_rate'] ?? 1);
if ($rate <= 0) { $rate = 1; }
$terms = trim((string) ($quote['terms'] ?? ''));
if ($terms === '') { $terms = setting_get('quote_terms', quote_default_terms()); }
$number = (string) ($quote['quote_number'] ?? 'COT');
$createdAt = $quote['created_at'] ?? date('Y-m-d');
$category = trim((string) ($quote['category'] ?? ''));

$st = strtolower((string) ($quote['status'] ?? 'borrador'));
$statusColor = match ($st) {
    'aprobado' => '#0a7d36',
    'enviado' => '#0666b3',
    'cotizado' => '#1fa6d8',
    'rechazado', 'cerrado' => '#64748b',
    'negociacion' => '#9c7d34',
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
$totalWords = money_in_words((float) ($quote['total'] ?? 0), $cur);

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

    /* Header */
    .topbar { height: 4px; background: #0a7d36; }
    .head { width: 100%; border-collapse: collapse; margin-top: 16px; }
    .head td { vertical-align: top; }
    .brand-logo { width: 60px; height: auto; }
    .brand-name { font-size: 20px; font-weight: bold; color: #0a7d36; letter-spacing: -.3px; }
    .brand-sub { color: #5b6b7b; font-size: 9px; margin-top: 1px; }
    .brand-meta { color: #5b6b7b; font-size: 8.6px; margin-top: 6px; line-height: 1.5; }
    .doc-box { border: 1px solid #d8e2ec; border-radius: 8px; padding: 9px 12px; }
    .doc-label { color: #8696a6; font-size: 8.5px; letter-spacing: 2px; text-transform: uppercase; }
    .doc-number { font-size: 20px; font-weight: bold; color: #0e1a28; letter-spacing: -.5px; margin-top: 1px; }
    .badge { display: inline-block; padding: 3px 11px; border-radius: 20px; color: #fff; font-size: 8.5px; font-weight: bold; margin-top: 4px; }
    .doc-meta { margin-top: 7px; color: #5b6b7b; font-size: 9px; line-height: 1.55; }
    .doc-meta b { color: #0e1a28; }

    /* Parties */
    .parties { width: 100%; border-collapse: collapse; margin-top: 18px; }
    .parties td { vertical-align: top; width: 50%; padding: 0; }
    .pcard { border: 1px solid #e3eaf1; border-radius: 8px; padding: 11px 13px; min-height: 78px; }
    .pcard-l { margin-right: 8px; }
    .pcard-r { margin-left: 8px; }
    .pcard h3 { color: #0a7d36; font-size: 8.5px; letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 5px; }
    .pcard .name { font-size: 12px; font-weight: bold; color: #0e1a28; }
    .pcard p { margin: 3px 0 0; color: #5b6b7b; font-size: 9.3px; line-height: 1.5; }

    .subject-row { margin-top: 14px; border-left: 3px solid #0a7d36; padding: 2px 0 2px 10px; }
    .subject-row .k { color: #8696a6; font-size: 8px; letter-spacing: 1.5px; text-transform: uppercase; }
    .subject-row .v { font-size: 12.5px; font-weight: bold; color: #0e1a28; }
    .cat-tag { display: inline-block; margin-left: 8px; padding: 2px 8px; border-radius: 12px; background: #e8f4ec; color: #066128; font-size: 8.5px; font-weight: bold; }

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
    table.items td.num { color: #8696a6; width: 24px; text-align: center; }
    .item-name { font-weight: bold; color: #0e1a28; }

    /* Lower section */
    .lower { width: 100%; border-collapse: collapse; margin-top: 14px; }
    .lower td { vertical-align: top; }
    .lower .l { width: 55%; padding-right: 16px; }
    .lower .r { width: 45%; }
    .tt { width: 100%; border-collapse: collapse; }
    .tt td { padding: 6px 12px; font-size: 10.5px; }
    .tt td.k { color: #5b6b7b; }
    .tt td.v { text-align: right; font-weight: bold; color: #0e1a28; white-space: nowrap; }
    .tt tr.sep td { border-top: 1px solid #e3eaf1; }
    .tt tr.total td { background: #0a7d36; color: #fff; font-size: 13.5px; font-weight: bold; }
    .tt tr.total td.k { color: #eafff2; }
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

    /* Signatures */
    .signs { width: 100%; border-collapse: collapse; margin-top: 34px; page-break-inside: avoid; }
    .signs td { width: 50%; vertical-align: bottom; padding: 0 18px; }
    .sign-line { border-top: 1px solid #1a2734; padding-top: 6px; text-align: center; }
    .sign-line b { font-size: 9.5px; color: #0e1a28; }
    .sign-line span { display: block; font-size: 8.4px; color: #5b6b7b; margin-top: 1px; }

    /* Fixed footer */
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
                    <td><b><?= $h(APP_NAME) ?>, SRL</b> &nbsp;·&nbsp; <?= $h(APP_TAGLINE) ?> &nbsp;·&nbsp; Desde <?= $h(APP_FOUNDED) ?><br><?= $h(APP_ADDRESS) ?> &nbsp;·&nbsp; <?= $h(APP_SECONDARY_ADDRESS) ?></td>
                    <td class="right">Tel. <?= $h(APP_PHONE) ?> &nbsp;·&nbsp; <?= $h(APP_PHONE_US) ?><br><?= $h(APP_INFO_EMAIL) ?> &nbsp;·&nbsp; RNC y datos fiscales en factura final</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="topbar"></div>

    <table class="head">
        <tr>
            <td style="width: 58%;">
                <table style="border-collapse: collapse;">
                    <tr>
                        <?php if ($logoData): ?><td style="width: 70px; vertical-align: top;"><img src="<?= $logoData ?>" class="brand-logo"></td><?php endif; ?>
                        <td style="vertical-align: top; padding-top: 2px;">
                            <div class="brand-name">SCH MEDICOS</div>
                            <div class="brand-sub"><?= $h(APP_TAGLINE) ?></div>
                            <div class="brand-meta">Equipos médicos · Gases medicinales · Diseño, instalación,<br>certificación y soporte técnico hospitalario.<br><?= $h(APP_ADDRESS) ?> · Tel. <?= $h(APP_PHONE) ?></div>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 42%;">
                <div class="doc-box">
                    <div class="doc-label">Cotización</div>
                    <div class="doc-number"><?= $h($number) ?></div>
                    <div><span class="badge" style="background: <?= $statusColor ?>;"><?= $h($quote['status'] ?? 'Borrador') ?></span></div>
                    <div class="doc-meta">
                        Emitida: <b><?= $h(date_es($createdAt)) ?></b><br>
                        Válida hasta: <b><?= $h(date_es($quote['valid_until'] ?? null)) ?></b><br>
                        Moneda: <b><?= $h($cur) ?></b><?php if ($cur === 'USD'): ?> (US$ 1 = RD$ <?= $h(number_format($rate, 2)) ?>)<?php endif; ?>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                <div class="pcard pcard-l">
                    <h3>De</h3>
                    <div class="name"><?= $h(APP_NAME) ?>, SRL</div>
                    <p>
                        <?= $h(APP_ADDRESS) ?><br>
                        Tel. <?= $h(APP_PHONE) ?> · <?= $h(APP_PHONE_US) ?><br>
                        <?= $h(APP_INFO_EMAIL) ?>
                    </p>
                </div>
            </td>
            <td>
                <div class="pcard pcard-r">
                    <h3>Para</h3>
                    <div class="name"><?= $h($quote['client_name'] ?? 'Cliente') ?></div>
                    <p>
                        <?php if (!empty($quote['rnc'])): ?>RNC: <?= $h($quote['rnc']) ?><br><?php endif; ?>
                        <?php if (!empty($quote['address'])): ?><?= $h($quote['address']) ?><br><?php endif; ?>
                        <?php if (!empty($quote['city'])): ?><?= $h($quote['city']) ?><br><?php endif; ?>
                        <?php if (!empty($quote['phone'])): ?>Tel. <?= $h($quote['phone']) ?><?php endif; ?>
                        <?php if (!empty($quote['email'])): ?><?= !empty($quote['phone']) ? ' · ' : '' ?><?= $h($quote['email']) ?><?php endif; ?>
                    </p>
                </div>
            </td>
        </tr>
    </table>

    <div class="subject-row">
        <div class="k">Asunto de la propuesta</div>
        <div class="v"><?= $h($quote['title'] ?? '') ?><?php if ($category !== ''): ?><span class="cat-tag"><?= $h($category) ?></span><?php endif; ?></div>
    </div>

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
                    <td class="r"><?= $h($qty($item['quantity'] ?? 0)) ?></td>
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
                <div class="words">
                    <div class="k">Son</div>
                    <div class="v"><?= $h($totalWords) ?></div>
                </div>
            </td>
            <td class="r">
                <table class="tt">
                    <tr><td class="k">Subtotal</td><td class="v"><?= $h($fmt($quote['subtotal'] ?? 0)) ?></td></tr>
                    <tr class="sep"><td class="k">ITBIS (<?= $h((string) ($quote['tax_rate'] ?? 18)) ?>%)</td><td class="v"><?= $h($fmt($quote['tax_amount'] ?? 0)) ?></td></tr>
                    <tr class="total"><td class="k">TOTAL</td><td class="v"><?= $h($fmt($quote['total'] ?? 0)) ?></td></tr>
                    <?php if ($cur === 'USD'): ?>
                        <tr class="equiv"><td class="k">Equivalente (RD$ a <?= $h(number_format($rate, 2)) ?>)</td><td class="v" style="color:#5b6b7b;">RD$ <?= $h(number_format((float) ($quote['total'] ?? 0) * $rate, 2, '.', ',')) ?></td></tr>
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
            <td><div class="sign-line"><b>Por <?= $h(APP_NAME) ?>, SRL</b><span>Firma autorizada y sello</span></div></td>
            <td><div class="sign-line"><b>Aceptado por el cliente</b><span>Nombre, firma y fecha</span></div></td>
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

// Page numbers on every page
$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
if ($font) {
    $canvas->page_text($canvas->get_width() - 130, $canvas->get_height() - 30, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, [0.36, 0.42, 0.48]);
}

$filename = 'Cotizacion-' . preg_replace('/[^A-Za-z0-9_-]/', '', $number) . '.pdf';
$dompdf->stream($filename, ['Attachment' => $download ? 1 : 0]);
exit;
