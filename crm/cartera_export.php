<?php
/**
 * Export de cartera (antigüedad de cuentas por cobrar) para Excel.
 * CSV UTF-8 con BOM y separador ';' — Excel en español lo abre en columnas
 * sin pasar por el asistente de importación. Mismo permiso nominal que el PDF.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('facturas.view');
require_cartera();
if (db(false)) { ensure_invoice_schema(); }

$report = receivables_aging();

$filename = 'Cartera-antiguedad-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM: acentos correctos en Excel

$put = static function (array $row) use ($out): void {
    fputcsv($out, $row, ';', '"');
};
$num = static fn ($v) => number_format((float) $v, 2, '.', ''); // punto decimal, sin separador de miles

$put([APP_LEGAL . ' — Cuentas por cobrar por antigüedad']);
$put(['Generado', date('d/m/Y H:i'), 'Por', (string) (current_user()['name'] ?? '')]);
$put(['Criterio', 'Días contados desde la fecha de vencimiento; saldo neto de abonos y retenciones; USD convertido a RD$ con la tasa del comprobante']);
$put([]);

$put(['RESUMEN POR TRAMO']);
$put(['Tramo', 'Facturas', 'Saldo RD$']);
foreach ($report['buckets'] as $b) {
    $put([$b['label'], $b['count'], $num($b['amount'])]);
}
$put(['TOTAL', $report['total']['count'], $num($report['total']['amount'])]);
$put([]);

$put(['DETALLE']);
$put(['Tramo', 'Días vencida', 'Cliente', 'RNC/Cédula', 'Factura', 'NCF', 'Tipo', 'Emitida', 'Vence', 'Condición', 'Moneda', 'Total', 'Abonado', 'Saldo RD$']);
foreach ($report['buckets'] as $b) {
    foreach ($b['rows'] as $r) {
        $put([
            $b['label'],
            (int) $r['aging']['days'],
            (string) ($r['client_name'] ?: ($r['c_name'] ?? '')),
            (string) ($r['client_rnc'] ?? ''),
            (string) $r['invoice_number'],
            (string) ($r['ncf'] ?? ''),
            (string) $r['ncf_type'] . ' - ' . ncf_type_label((string) $r['ncf_type']),
            date_es($r['issue_date'] ?? null),
            date_es($r['due_date'] ?? null),
            (string) ($r['payment_condition'] ?? ''),
            (string) ($r['currency'] ?? 'DOP'),
            $num($r['total']),
            $num($r['amount_paid'] ?? 0),
            $num($r['balance_dop']),
        ]);
    }
}
if ($report['total']['count'] === 0) {
    $put(['(Sin facturas emitidas con saldo pendiente)']);
}

fclose($out);
exit;
