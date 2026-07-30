<?php
/**
 * Recordatorio de pago / estado de cuenta en PDF.
 *
 * Modos:
 *   ?client=ID          → estado de cuenta con TODOS los comprobantes con saldo del cliente.
 *   ?id=FACTURA_ID      → recordatorio de una sola factura (resuelve su cliente).
 *   ?scope=all          → lote: un recordatorio por cliente, uno por página (solo cartera).
 *   &vencidas=1         → en el lote, únicamente clientes con comprobantes ya vencidos.
 *   &tono=cordial|firme|final  → fuerza el tono; por defecto se elige por días de atraso.
 *   &download=1         → fuerza la descarga en vez de la vista previa.
 *
 * El documento es para el cliente: lleva el logo, los datos fiscales de la
 * empresa, el detalle de cada comprobante con su antigüedad, las formas de pago
 * y el contacto de cobros.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('facturas.view');

use Dompdf\Dompdf;
use Dompdf\Options;

/*
 * Si la composición del PDF falla (dompdf, memoria, datos inesperados), el
 * servidor devolvería un 500 en blanco dentro del iframe de vista previa y no
 * habría forma de saber por qué. Este cierre convierte cualquier error fatal en
 * un mensaje legible: el detalle técnico solo para quien administra el sistema.
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
    echo "No se pudo generar el recordatorio de pago.\n";
    echo $canSeeErrors
        ? "\n" . $err['message'] . "\n@ " . $err['file'] . ':' . $err['line'] . "\n"
        : "Avisa al administrador: el detalle quedó en el log de errores del servidor.\n";
});

$hasDb = db(false) && table_exists('invoices');
if (db(false)) { ensure_invoice_schema(); }

$scope = (string) ($_GET['scope'] ?? '');
$clientId = (int) ($_GET['client'] ?? 0);
$invoiceId = (int) ($_GET['id'] ?? 0);
$onlyOverdue = isset($_GET['vencidas']);
$download = isset($_GET['download']);
$forcedTone = (string) ($_GET['tono'] ?? '');
$tones = reminder_tones();
if (!isset($tones[$forcedTone])) { $forcedTone = ''; }

/* El lote expone la cartera completa de la empresa → mismo gate que cartera. */
if ($scope === 'all') {
    require_cartera();
}

/* --------------------------- carga de datos --------------------------- */
/** @var array<int, array> $docs  Cada entrada = un recordatorio (un cliente). */
$docs = [];
$onlyInvoice = null;

if (!$hasDb) {
    // Modo demo (sin base de datos): recordatorio de ejemplo para ver el diseño.
    $demoRows = [
        ['invoice_number' => 'FAC-' . date('Y') . '-0007', 'ncf' => 'B0100000007', 'ncf_type' => '01', 'title' => 'Equipamiento biomédico',
         'issue_date' => date('Y-m-d', strtotime('-75 days')), 'due_date' => date('Y-m-d', strtotime('-45 days')), 'currency' => 'DOP',
         'total' => 17700, 'amount_paid' => 5000, 'itbis_retained' => 0, 'isr_retained' => 0, 'balance' => 12700, 'balance_dop' => 12700,
         'aging' => ['days' => 45, 'label' => '31-60 días', 'tone' => 'warn']],
        ['invoice_number' => 'FAC-' . date('Y') . '-0011', 'ncf' => 'B0100000011', 'ncf_type' => '01', 'title' => 'Mantenimiento preventivo',
         'issue_date' => date('Y-m-d', strtotime('-20 days')), 'due_date' => date('Y-m-d', strtotime('+10 days')), 'currency' => 'DOP',
         'total' => 8260, 'amount_paid' => 0, 'itbis_retained' => 0, 'isr_retained' => 0, 'balance' => 8260, 'balance_dop' => 8260,
         'aging' => ['days' => -10, 'label' => 'Por vencer', 'tone' => 'ok']],
    ];
    $docs[] = [
        'client' => ['id' => 0, 'name' => 'Hospital Metropolitano de Santiago', 'rnc' => '101-00000-1',
                     'address' => 'Av. Principal, Santiago de los Caballeros', 'email' => 'compras@hms.local', 'phone' => '809-000-0000'],
        'rows' => $demoRows, 'count' => 2, 'total_dop' => 20960.0, 'by_currency' => ['DOP' => 20960.0],
        'overdue_count' => 1, 'overdue_dop' => 12700.0, 'upcoming_dop' => 8260.0, 'max_days' => 45,
        'next_due' => date('Y-m-d', strtotime('+10 days')),
    ];
} elseif ($scope === 'all') {
    foreach (receivables_clients($onlyOverdue) as $c) {
        $data = client_receivables((int) $c['client_id']);
        if ($data['count'] > 0) {
            $docs[] = $data;
        }
    }
    if (!$docs) {
        http_response_code(404);
        exit('No hay clientes con saldo pendiente para generar recordatorios.');
    }
} else {
    if ($invoiceId > 0) {
        $onlyInvoice = fetch_one('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
        if (!$onlyInvoice) {
            http_response_code(404);
            exit('Factura no encontrada.');
        }
        $clientId = (int) $onlyInvoice['client_id'];
    }
    if ($clientId <= 0) {
        http_response_code(400);
        exit('Indica el cliente o la factura del recordatorio.');
    }
    $data = client_receivables($clientId, $onlyInvoice ? [$invoiceId] : []);
    if ($data['count'] === 0) {
        http_response_code(404);
        exit($onlyInvoice
            ? 'Esta factura no tiene saldo pendiente: no procede un recordatorio de pago.'
            : 'Este cliente no tiene comprobantes con saldo pendiente.');
    }
    $docs[] = $data;
}

/* ------------------------------ helpers ------------------------------- */
$h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$m = fn ($v) => 'RD$ ' . number_format((float) $v, 2, '.', ',');
$mc = fn ($v, $cur) => (strtoupper((string) $cur) === 'USD' ? 'US$ ' : 'RD$ ') . number_format((float) $v, 2, '.', ',');

/* El logo es configurable (puede ser PNG, JPG o WEBP subido desde el CRM), así
   que el tipo se toma del archivo real: un data URI mal etiquetado hace fallar
   al renderizador. Si no se puede leer, el documento sale sin logo. */
$logoData = '';
$logoPath = __DIR__ . '/../' . APP_LOGO;
if (is_file($logoPath) && is_readable($logoPath)) {
    $info = @getimagesize($logoPath);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
        $logoData = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($logoPath));
    }
}

$paymentInfo = reminder_payment_info();
$contactInfo = reminder_contact();
$today = date('Y-m-d');
$issuer = current_user()['name'] ?? '';

ob_start();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    * { font-family: "DejaVu Sans", sans-serif; }
    @page { margin: 30px 40px 76px; }
    body { margin: 0; color: #1a2734; font-size: 10.5px; line-height: 1.45; }
    .muted { color: #5b6b7b; }
    .right { text-align: right; }
    .center { text-align: center; }
    h1, h2, h3 { margin: 0; }
    /* dompdf aplica el margen por defecto de <p> (1em arriba y abajo); lo anulamos
       para que el espaciado del documento sea solo el que declaran las clases. */
    p { margin: 0; }

    .doc { page-break-after: always; }
    .doc:last-child { page-break-after: auto; }

    .topbar { height: 4px; }
    .head { width: 100%; border-collapse: collapse; margin-top: 12px; }
    .head td { vertical-align: top; }
    .brand-logo { width: 54px; height: auto; }
    .brand-name { font-size: 14.5px; font-weight: bold; letter-spacing: -.2px; line-height: 1.25; color: #0a7d36; }
    .brand-meta { color: #5b6b7b; font-size: 8.4px; margin-top: 5px; line-height: 1.45; }
    .doc-box { border: 1px solid #d8e2ec; border-radius: 8px; padding: 8px 11px; }
    .doc-label { color: #8696a6; font-size: 8.2px; letter-spacing: 1.4px; text-transform: uppercase; }
    .doc-title { font-size: 14.5px; font-weight: bold; color: #0e1a28; letter-spacing: -.4px; margin-top: 1px; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; color: #fff; font-size: 8.2px; font-weight: bold; margin-top: 4px; }
    .doc-meta { margin-top: 6px; color: #5b6b7b; font-size: 8.8px; line-height: 1.5; }
    .doc-meta b { color: #0e1a28; }

    .to { margin-top: 12px; border: 1px solid #e3eaf1; border-radius: 8px; padding: 9px 12px; }
    .to h3 { color: #8696a6; font-size: 8.2px; letter-spacing: 1.4px; text-transform: uppercase; margin-bottom: 4px; }
    .to .name { font-size: 12.5px; font-weight: bold; color: #0e1a28; }
    .to p { margin: 2px 0 0; color: #5b6b7b; font-size: 9.1px; line-height: 1.45; }
    .to .rnc { color: #41515f; font-weight: bold; font-size: 9.4px; }

    .subject { margin-top: 11px; padding: 2px 0 2px 10px; }
    .subject .k { color: #8696a6; font-size: 7.8px; letter-spacing: 1.4px; text-transform: uppercase; }
    .subject .v { font-size: 11.5px; font-weight: bold; color: #0e1a28; }

    .body-text { margin-top: 9px; color: #2b3b4b; font-size: 9.9px; line-height: 1.55; text-align: justify; }

    .kpis { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 11px -6px 0; }
    .kpi { border: 1px solid #e3eaf1; border-radius: 8px; padding: 7px 9px; width: 25%; }
    .kpi .k { color: #8696a6; font-size: 7.4px; text-transform: uppercase; letter-spacing: .6px; font-weight: bold; }
    .kpi .v { font-size: 12.5px; font-weight: bold; margin-top: 2px; color: #0e1a28; }
    .kpi .n { color: #5b6b7b; font-size: 7.8px; }

    table.items { width: 100%; border-collapse: collapse; margin-top: 11px; }
    table.items thead th { color: #fff; font-size: 8.2px; font-weight: bold; text-transform: uppercase; letter-spacing: .4px; padding: 6px 7px; text-align: left; }
    table.items thead th.r { text-align: right; }
    table.items tbody td { padding: 6px 7px; border-bottom: 1px solid #eef3f8; font-size: 9.2px; vertical-align: top; }
    table.items tbody tr:nth-child(even) td { background: #f8fafc; }
    table.items td.r { text-align: right; white-space: nowrap; }
    table.items td.num { color: #8696a6; width: 20px; text-align: center; }
    .doc-name { font-weight: bold; color: #0e1a28; }
    .ncf-mono { color: #0e1a28; font-weight: bold; letter-spacing: .3px; }
    .days-chip { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 8px; font-weight: bold; white-space: nowrap; }
    .days-ok { background: #f5faf6; color: #066128; border: 1px solid #c7d6c9; }
    .days-warn { background: #fffaf0; color: #92660a; border: 1px solid #f4d58a; }
    .days-bad { background: #fef2f2; color: #b42318; border: 1px solid #f3c4c4; }
    table.items tr.tot td { border-top: 2px solid #0e1a28; border-bottom: none; font-weight: bold; font-size: 10.4px; padding-top: 7px; }

    .lower { width: 100%; border-collapse: collapse; margin-top: 9px; }
    .lower td { vertical-align: top; }
    .lower .l { width: 55%; padding-right: 12px; }
    .lower .r { width: 45%; }
    .pay-box { border: 1px solid #e3eaf1; border-radius: 8px; padding: 8px 10px; }
    .pay-box h3 { color: #8696a6; font-size: 8.2px; letter-spacing: 1.4px; text-transform: uppercase; margin-bottom: 3px; }
    .pay-box p { margin: 0; color: #41515f; font-size: 8.6px; line-height: 1.45; }
    .due-box { border-radius: 8px; padding: 9px 11px; }
    .due-box .k { font-size: 7.8px; letter-spacing: 1.4px; text-transform: uppercase; font-weight: bold; }
    .due-box .v { font-size: 18px; font-weight: bold; letter-spacing: -.5px; margin-top: 2px; }
    .due-box .w { color: #41515f; font-size: 8.4px; margin-top: 3px; line-height: 1.4; }
    .due-box .split { color: #41515f; font-size: 8.4px; margin-top: 4px; }

    .close-text { margin-top: 8px; color: #2b3b4b; font-size: 9.9px; line-height: 1.55; text-align: justify; }
    .note { margin-top: 7px; color: #8696a6; font-size: 8px; line-height: 1.5; }

    .sign { width: 100%; border-collapse: collapse; margin-top: 2px; page-break-inside: avoid; }
    .sign-line { border-top: 1px solid #1a2734; padding-top: 5px; }
    .sign-line b { font-size: 9px; color: #0e1a28; }
    .sign-line span { display: block; font-size: 8.4px; color: #5b6b7b; margin-top: 1px; }

    table.items tbody tr, .lower, .kpis { page-break-inside: avoid; }

    .foot { position: fixed; left: -40px; right: -40px; bottom: -50px; height: 30px; }
    .foot-inner { border-top: 2px solid #0a7d36; margin: 0 40px; padding-top: 6px; color: #5b6b7b; font-size: 8.2px; line-height: 1.4; }
    .foot-inner table { width: 100%; border-collapse: collapse; }
    .foot-inner b { color: #0a7d36; }
</style>
</head>
<body>
    <div class="foot">
        <div class="foot-inner">
            <table>
                <tr>
                    <td><b><?= $h(APP_LEGAL) ?></b> &nbsp;·&nbsp; <?= $h(APP_ADDRESS) ?> &nbsp;·&nbsp; <?= $h(APP_SECONDARY_ADDRESS) ?></td>
                    <td class="right">Tel. <?= $h(APP_PHONE) ?> &nbsp;·&nbsp; <?= $h(APP_INFO_EMAIL) ?><?= APP_RNC !== '' ? ' &nbsp;·&nbsp; RNC: ' . $h(APP_RNC) : '' ?></td>
                </tr>
            </table>
        </div>
    </div>

<?php foreach ($docs as $doc):
    $client = $doc['client'] ?? [];
    $toneKey = $forcedTone !== '' ? $forcedTone : reminder_tone_for((int) $doc['max_days']);
    $t = $tones[$toneKey];
    $vars = [
        'empresa' => APP_LEGAL,
        'cliente' => (string) ($client['name'] ?? 'Cliente'),
        'fecha' => date_es($today),
        'total' => $m($doc['total_dop']),
        'dias' => (string) (int) $doc['max_days'],
        'contacto' => $contactInfo,
    ];
    $ref = 'REC-' . date('Ymd') . '-' . str_pad((string) (int) ($client['id'] ?? 0), 4, '0', STR_PAD_LEFT);
    $words = money_in_words((float) $doc['total_dop'], 'DOP');
    $mixed = count($doc['by_currency']) > 1 || !isset($doc['by_currency']['DOP']);
?>
    <div class="doc">
        <div class="topbar" style="background: <?= $t['color'] ?>;"></div>

        <table class="head">
            <tr>
                <td style="width: 56%;">
                    <table style="border-collapse: collapse;">
                        <tr>
                            <?php if ($logoData): ?><td style="width: 70px; vertical-align: top;"><img src="<?= $logoData ?>" class="brand-logo"></td><?php endif; ?>
                            <td style="vertical-align: top; padding-top: 2px;">
                                <div class="brand-name"><?= $h(APP_NAME) ?></div>
                                <div class="brand-meta">
                                    <b style="color:#0e1a28"><?= $h(APP_LEGAL) ?></b><?php if (APP_RNC !== ''): ?> · RNC: <?= $h(APP_RNC) ?><?php endif; ?><br>
                                    <?= $h(APP_ADDRESS) ?><br>
                                    Tel. <?= $h(APP_PHONE) ?> · <?= $h(APP_PHONE_US) ?> · <?= $h(APP_INFO_EMAIL) ?>
                                </div>
                            </td>
                        </tr>
                    </table>
                </td>
                <td style="width: 44%;">
                    <div class="doc-box">
                        <div class="doc-label"><?= $h($t['kicker']) ?></div>
                        <div class="doc-title"><?= $h($t['title']) ?></div>
                        <div><span class="badge" style="background: <?= $t['color'] ?>;"><?= $h($doc['overdue_count'] > 0 ? $doc['overdue_count'] . ' comprobante' . ($doc['overdue_count'] === 1 ? '' : 's') . ' vencido' . ($doc['overdue_count'] === 1 ? '' : 's') : 'Sin atrasos a la fecha') ?></span></div>
                        <div class="doc-meta">
                            Referencia: <b><?= $h($ref) ?></b><br>
                            Fecha de emisión: <b><?= $h(date_es($today)) ?></b><br>
                            Comprobantes con saldo: <b><?= $h((string) (int) $doc['count']) ?></b>
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        <div class="to">
            <h3>Dirigido a · Atención: Departamento de Cuentas por Pagar</h3>
            <div class="name"><?= $h($client['name'] ?? 'Cliente') ?><?php if (!empty($client['rnc'])): ?><span class="rnc"> · RNC/Cédula: <?= $h($client['rnc']) ?></span><?php endif; ?></div>
            <p>
                <?php
                $toLine = array_filter([
                    trim((string) ($client['address'] ?? '')) . (!empty($client['address']) && !empty($client['city']) ? ', ' : '') . trim((string) ($client['city'] ?? '')),
                    !empty($client['phone']) ? 'Tel. ' . $client['phone'] : '',
                    (string) ($client['email'] ?? ''),
                ]);
                echo $h(implode(' · ', $toLine));
                ?>
            </p>
        </div>

        <div class="subject" style="border-left: 3px solid <?= $t['color'] ?>;">
            <div class="k">Asunto</div>
            <div class="v"><?= $h(reminder_fill($t['subject'], $vars)) ?></div>
        </div>

        <p class="body-text"><?= $h(reminder_fill($t['intro'], $vars)) ?></p>

        <table class="kpis">
            <tr>
                <td class="kpi">
                    <div class="k">Saldo total</div>
                    <div class="v" style="color: <?= $t['color'] ?>;"><?= $m($doc['total_dop']) ?></div>
                    <div class="n"><?= $h((string) (int) $doc['count']) ?> comprobante<?= (int) $doc['count'] === 1 ? '' : 's' ?></div>
                </td>
                <td class="kpi" style="border-color: <?= $doc['overdue_dop'] > 0 ? '#f3c4c4' : '#e3eaf1' ?>; background: <?= $doc['overdue_dop'] > 0 ? '#fef2f2' : '#fff' ?>;">
                    <div class="k">Vencido</div>
                    <div class="v" style="color: <?= $doc['overdue_dop'] > 0 ? '#b42318' : '#0e1a28' ?>;"><?= $m($doc['overdue_dop']) ?></div>
                    <div class="n"><?= $h((string) (int) $doc['overdue_count']) ?> comprobante<?= (int) $doc['overdue_count'] === 1 ? '' : 's' ?></div>
                </td>
                <td class="kpi">
                    <div class="k">Por vencer</div>
                    <div class="v"><?= $m($doc['upcoming_dop']) ?></div>
                    <div class="n"><?= $doc['next_due'] ? 'Próximo: ' . $h(date_es($doc['next_due'])) : 'Sin documentos por vencer' ?></div>
                </td>
                <td class="kpi">
                    <div class="k">Mayor atraso</div>
                    <div class="v" style="color: <?= (int) $doc['max_days'] > 0 ? '#b42318' : '#066128' ?>;"><?= $h((string) (int) $doc['max_days']) ?> día<?= (int) $doc['max_days'] === 1 ? '' : 's' ?></div>
                    <div class="n"><?= (int) $doc['max_days'] > 0 ? 'del comprobante más antiguo' : 'cuenta al día' ?></div>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr style="background: <?= $t['color'] ?>;">
                    <th class="num" style="width:3%">#</th>
                    <th style="width:24%">Comprobante</th>
                    <th style="width:12%">NCF</th>
                    <th style="width:9%">Emitida</th>
                    <th style="width:9%">Vence</th>
                    <th class="r" style="width:8%">Atraso</th>
                    <th class="r" style="width:12%">Total</th>
                    <th class="r" style="width:11%">Abonado</th>
                    <th class="r" style="width:12%">Saldo</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($doc['rows'] as $r):
                    $rCur = strtoupper((string) ($r['currency'] ?? 'DOP')) === 'USD' ? 'USD' : 'DOP';
                    $rDays = (int) ($r['aging']['days'] ?? 0);
                    $chip = $rDays > 90 ? 'days-bad' : ($rDays > 0 ? 'days-warn' : 'days-ok');
                ?>
                    <tr>
                        <td class="num"><?= $i++ ?></td>
                        <td>
                            <span class="doc-name"><?= $h($r['invoice_number'] ?? '') ?></span>
                            <?php if (!empty($r['title'])): ?><br><span class="muted"><?= $h($r['title']) ?></span><?php endif; ?>
                        </td>
                        <td><span class="ncf-mono"><?= $h($r['ncf'] ?: '—') ?></span></td>
                        <td><?= $h(date_es($r['issue_date'] ?? null)) ?></td>
                        <td><?= $h(date_es($r['due_date'] ?? null)) ?></td>
                        <td class="r"><span class="days-chip <?= $chip ?>"><?= $rDays > 0 ? $h((string) $rDays) . ' d' : ($rDays === 0 ? 'hoy' : 'en ' . $h((string) abs($rDays)) . ' d') ?></span></td>
                        <td class="r"><?= $h($mc($r['total'] ?? 0, $rCur)) ?></td>
                        <td class="r"><?= (float) ($r['amount_paid'] ?? 0) > 0 ? $h($mc($r['amount_paid'], $rCur)) : '—' ?></td>
                        <td class="r"><b><?= $h($mc($r['balance'] ?? 0, $rCur)) ?></b><?php if ($rCur === 'USD'): ?><br><span class="muted" style="font-size:8px"><?= $m($r['balance_dop'] ?? 0) ?></span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="tot">
                    <td colspan="8" class="r">Total adeudado a la fecha</td>
                    <td class="r" style="color: <?= $t['color'] ?>;"><?= $m($doc['total_dop']) ?></td>
                </tr>
            </tbody>
        </table>

        <table class="lower">
            <tr>
                <td class="l">
                    <div class="pay-box">
                        <h3>Formas de pago</h3>
                        <p><?= nl2br($h(trim($paymentInfo))) ?></p>
                    </div>
                </td>
                <td class="r">
                    <div class="due-box" style="background: <?= $t['soft'] ?>; border: 1px solid <?= $t['line'] ?>;">
                        <div class="k" style="color: <?= $t['color'] ?>;">Total a pagar</div>
                        <div class="v" style="color: <?= $t['color'] ?>;"><?= $m($doc['total_dop']) ?></div>
                        <div class="w"><?= $h($words) ?></div>
                        <?php if ($mixed): ?>
                            <div class="split">
                                Detalle por moneda:
                                <?php $parts = []; foreach ($doc['by_currency'] as $cc => $amt) { $parts[] = $mc($amt, $cc); } echo $h(implode(' · ', $parts)); ?>
                                — convertido a RD$ con la tasa registrada en cada comprobante.
                            </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>

        <p class="close-text"><?= $h(reminder_fill($t['close'], $vars)) ?></p>

        <table class="sign">
            <tr>
                <td style="width:42%; vertical-align: bottom; padding-top: 6px;">
                    <div class="sign-line">
                        <b>Por <?= $h(APP_LEGAL) ?></b>
                        <span><?= $h($contactInfo) ?></span>
                    </div>
                </td>
                <td style="width:58%; vertical-align: bottom; padding-left: 18px;">
                    <p class="note">
                        Documento informativo de cobranza generado el <?= $h(date('d/m/Y')) ?><?= $issuer !== '' ? ' por ' . $h($issuer) : '' ?>; no es un comprobante fiscal.
                        Los saldos descuentan abonos y retenciones (ITBIS/ISR); notifique cualquier diferencia dentro de los 5 días siguientes.
                    </p>
                </td>
            </tr>
        </table>
    </div>
<?php endforeach; ?>
</body>
</html>
<?php
$html = (string) ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('dpi', 96);

try {
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $canvas = $dompdf->getCanvas();
    $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    if ($font) {
        // Justo encima de la línea del pie fijo, para que no se solape con sus datos.
        $canvas->page_text($canvas->get_width() - 128, $canvas->get_height() - 52, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, [0.36, 0.42, 0.48]);
    }
} catch (Throwable $ex) {
    error_log('[recordatorio_pdf] ' . $ex::class . ': ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "No se pudo componer el recordatorio de pago.\n";
    if ($canSeeErrors) {
        echo "\n" . $ex::class . ': ' . $ex->getMessage() . "\n@ " . $ex->getFile() . ':' . $ex->getLine() . "\n";
    }
    exit;
}

if ($scope === 'all') {
    $filename = 'Recordatorios-pago-' . date('Y-m-d') . '.pdf';
} else {
    $slug = preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($docs[0]['client']['name'] ?? 'Cliente'));
    $slug = trim((string) $slug, '-');
    $filename = 'Recordatorio-' . ($onlyInvoice ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($onlyInvoice['ncf'] ?: $onlyInvoice['invoice_number'])) . '-' : '') . ($slug !== '' ? $slug : 'cliente') . '.pdf';
}

if ($hasDb && $scope !== 'all' && isset($docs[0]['client']['id'])) {
    log_activity('client', (int) $docs[0]['client']['id'], 'recordatorio_pago_generado', 'Saldo ' . $m($docs[0]['total_dop']) . ' · ' . (int) $docs[0]['count'] . ' comprobante(s)');
} elseif ($hasDb && $scope === 'all') {
    log_activity('invoice', null, 'recordatorios_pago_lote', count($docs) . ' cliente(s) con saldo pendiente');
}

$dompdf->stream($filename, ['Attachment' => $download ? 1 : 0]);
exit;
