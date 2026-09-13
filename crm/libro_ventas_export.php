<?php
/**
 * Export del libro de ventas del mes para Excel.
 *
 * CSV UTF-8 con BOM y separador ';' — Excel en español lo abre en columnas sin
 * pasar por el asistente de importación. Incluye el resumen de ITBIS del mismo
 * periodo, que es lo que contabilidad necesita junto al detalle.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('dgii.view');
if (db(false)) {
    ensure_invoice_schema();
}

$ym = dgii_period((string) ($_GET['ym'] ?? ''));
$book = dgii_sales_book($ym);
$itbis = dgii_itbis_summary($ym);
$t = $book['totals'];

$filename = 'Libro-ventas-' . dgii_period_header($ym) . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM: acentos correctos en Excel

$put = static function (array $row) use ($out): void {
    fputcsv($out, $row, ';', '"');
};
$num = static fn ($v) => number_format((float) $v, 2, '.', ''); // punto decimal, sin miles

$put([APP_LEGAL . ' — Libro de ventas']);
$put(['RNC', APP_RNC !== '' ? APP_RNC : '(sin registrar)', 'Periodo', dgii_period_label($ym)]);
$put(['Generado', date('d/m/Y H:i'), 'Por', (string) (current_user()['name'] ?? '')]);
$put(['Criterio', 'Comprobantes con NCF emitidos en el periodo, ordenados por número dentro de cada serie para que cualquier salto en la secuencia quede a la vista. Las anuladas figuran sin importe por esa misma razón; las notas de crédito se exportan en negativo y restan en los totales. Importes en RD$ (USD convertido con la tasa del comprobante).']);
$put([]);

$put(['DETALLE']);
$put(['Fecha', 'NCF', 'Tipo', 'Descripción del tipo', 'NCF modificado', 'Cliente', 'RNC/Cédula', 'Moneda', 'Estado', 'Gravado', 'Exento', 'ITBIS', 'ISC', 'Ret. ITBIS', 'Ret. ISR', 'Total']);
foreach ($book['rows'] as $r) {
    // Las notas de crédito se exportan en NEGATIVO: quien abra esto en Excel va
    // a sumar la columna, y debe darle el total del mes sin más ajustes.
    $s = (int) $r['sign'];
    $put([
        date_es($r['date']),
        $r['ncf'],
        $r['ncf_type'],
        $r['type_label'],
        $r['modifies'],
        $r['client'],
        $r['rnc'],
        $r['currency'],
        $r['is_void'] ? 'ANULADA' : $r['status'],
        $num($s * $r['taxed']),
        $num($s * $r['exempt']),
        $num($s * $r['itbis']),
        $num($s * $r['isc']),
        $num($s * $r['itbis_ret']),
        $num($s * $r['isr_ret']),
        $num($s * $r['total']),
    ]);
}
if (!$book['rows']) {
    $put(['(Sin comprobantes con NCF en este periodo)']);
}
$put(['TOTALES', '', '', '', '', '', '', '', '', $num($t['taxed']), $num($t['exempt']), $num($t['itbis']), $num($t['isc']), $num($t['itbis_ret']), $num($t['isr_ret']), $num($t['total'])]);
$put([]);

$put(['RESUMEN DE ITBIS · ' . dgii_period_label($ym)]);
$put(['Concepto', 'Importe RD$']);
$put(['Ventas gravadas', $num($itbis['taxed'])]);
$put(['Ventas exentas', $num($itbis['exempt'])]);
$put(['Débito fiscal (ITBIS facturado)', $num($itbis['debit'])]);
$put(['ITBIS retenido por terceros', $num($itbis['withheld'])]);
$put(['Crédito fiscal de compras', 'NO DISPONIBLE — ' . $itbis['credit_reason']]);
$put(['Saldo parcial de ventas (débito − retenciones, SIN restar compras)', $num($itbis['partial_due'])]);
$put([]);

$put(['DESGLOSE POR TIPO DE COMPROBANTE']);
$put(['Tipo', 'Descripción', 'Comprobantes', 'Gravado', 'Exento', 'ITBIS']);
foreach ($itbis['by_type'] as $bt) {
    $put([$bt['type'], $bt['label'], $bt['count'], $num($bt['taxed']), $num($bt['exempt']), $num($bt['itbis'])]);
}
if (!$itbis['by_type']) {
    $put(['(Sin comprobantes)']);
}

fclose($out);
exit;
