<?php

declare(strict_types=1);

/**
 * SCH MEDICOS CRM — Formatos de envío de datos de la DGII.
 *
 * Genera los archivos de texto que se cargan en la Oficina Virtual cada mes:
 *
 *   607  Ventas de bienes y servicios  — se arma con los comprobantes emitidos.
 *   608  Comprobantes anulados          — se arma con los comprobantes anulados.
 *   606  Compras de bienes y servicios  — NO disponible: el CRM todavía no
 *        registra compras ni gastos con NCF de suplidor. Ver dgii_606_status().
 *
 * Todo importe se expresa en RD$: un comprobante en USD se convierte con la
 * tasa guardada en el propio documento, igual que hace la cartera. Ningún
 * cálculo se rehace aquí — se leen los totales que la factura ya tiene fijados,
 * porque un comprobante emitido es inmutable.
 *
 * IMPORTANTE: el diseño de registro (orden y cantidad de campos) sigue la
 * especificación publicada por la DGII. Antes del primer envío real, valida un
 * archivo de prueba en la Oficina Virtual: si la DGII cambia el layout, se
 * ajusta aquí, en dgii_607_line() / dgii_608_line(), sin tocar nada más.
 */

/** Periodo válido: AAAA-MM entre 2000-01 y un año hacia adelante. */
function dgii_period_valid(string $ym): bool
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
        return false;
    }
    $year = (int) substr($ym, 0, 4);
    return $year >= 2000 && $year <= ((int) date('Y') + 1);
}

/** Normaliza un periodo de entrada al mes actual si viene inválido. */
function dgii_period(string $ym): string
{
    return dgii_period_valid($ym) ? $ym : date('Y-m');
}

/** Primer y último día del periodo: ['2026-09-01', '2026-09-30']. */
function dgii_period_range(string $ym): array
{
    $from = $ym . '-01';
    return [$from, date('Y-m-t', strtotime($from))];
}

/** Etiqueta legible del periodo: "Septiembre 2026". */
function dgii_period_label(string $ym): string
{
    $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    $ts = strtotime($ym . '-01');
    return $meses[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Fecha límite de envío: día 20 del mes siguiente al periodo declarado. */
function dgii_deadline(string $ym): string
{
    return date('Y-m-20', strtotime($ym . '-01 +1 month'));
}

/**
 * Tipo de ingreso (campo 5 del 607). El valor por defecto se configura una vez
 * y aplica a todo comprobante: SCH factura operaciones, no rentas financieras.
 */
function dgii_income_types(): array
{
    return [
        '01' => 'Ingresos por operaciones (no financieros)',
        '02' => 'Ingresos financieros',
        '03' => 'Ingresos extraordinarios',
        '04' => 'Ingresos por arrendamientos',
        '05' => 'Ingresos por venta de activo depreciable',
        '06' => 'Otros ingresos',
    ];
}

function dgii_income_type(): string
{
    $v = (string) setting_get('dgii_income_type', '01');
    return isset(dgii_income_types()[$v]) ? $v : '01';
}

/** Tipos de anulación del 608 (campo 3). */
function dgii_void_reasons(): array
{
    return [
        '01' => 'Deterioro de factura pre-impresa',
        '02' => 'Errores de impresión',
        '03' => 'Impresión defectuosa',
        '04' => 'Duplicidad de factura',
        '05' => 'Corrección de la información',
        '06' => 'Cambio de productos',
        '07' => 'Devolución de productos',
        '08' => 'Omisión de productos',
        '09' => 'Errores en secuencia de NCF',
        '10' => 'Cese de operaciones',
        '11' => 'Pérdida o hurto de talonarios',
    ];
}

function dgii_void_reason_label(?string $code): string
{
    $code = trim((string) $code);
    return dgii_void_reasons()[$code] ?? '';
}

/**
 * Tipo de identificación del comprador a partir del documento capturado.
 * 1 = RNC (9 dígitos) · 2 = Cédula (11 dígitos) · 3 = Pasaporte / otro.
 * Devuelve ['digits' => solo números, 'type' => '1'|'2'|'3'|''].
 */
function dgii_id_kind(?string $raw): array
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return ['digits' => '', 'type' => ''];
    }
    // Se quitan solo los separadores (guiones, puntos, espacios). Nunca las
    // letras: un pasaporte las lleva y arrancárselas lo convertiría en un
    // documento que no existe.
    $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    if ($clean === '') {
        return ['digits' => '', 'type' => ''];
    }
    if (!ctype_digit($clean)) {
        return ['digits' => $clean, 'type' => '3'];
    }
    return match (strlen($clean)) {
        9 => ['digits' => $clean, 'type' => '1'],   // RNC
        11 => ['digits' => $clean, 'type' => '2'],  // Cédula
        default => ['digits' => $clean, 'type' => '3'],
    };
}

/** Importe con el formato que espera la DGII: punto decimal, sin separador de miles. */
function dgii_amount(float $v): string
{
    return number_format(round($v, 2), 2, '.', '');
}

/** Fecha AAAAMMDD, o cadena vacía si no hay fecha. */
function dgii_date(?string $date): string
{
    $date = trim((string) $date);
    if ($date === '' || str_starts_with($date, '0000')) {
        return '';
    }
    $ts = strtotime($date);
    return $ts === false ? '' : date('Ymd', $ts);
}

/** Bucket de forma de pago del 607 al que pertenece un método de cobro nuestro. */
function dgii_payment_bucket(?string $method): string
{
    return match (trim((string) $method)) {
        'Efectivo' => 'efectivo',
        'Transferencia', 'Cheque', 'Depósito' => 'cheque',
        'Tarjeta de crédito', 'Tarjeta de débito' => 'tarjeta',
        'Crédito' => 'credito',
        default => 'otras',
    };
}

/**
 * Bucket para dinero YA COBRADO. Nunca devuelve 'credito': si el dinero entró,
 * no es una venta a crédito, aunque el método quedara etiquetado así o vacío.
 */
function dgii_collected_bucket(?string $method): string
{
    $bucket = dgii_payment_bucket($method);
    return $bucket === 'credito' ? 'otras' : $bucket;
}

/** Comprobantes que son nota de crédito o débito (no llevan formas de pago). */
function dgii_is_note(string $ncfType): bool
{
    return in_array($ncfType, ['03', '04', '33', '34'], true);
}

/**
 * Filas del 607 del periodo: comprobantes fiscales emitidos y todavía vigentes.
 *
 * Se excluyen borradores, proformas (no son fiscales) y los que ya fueron
 * anulados — esos se informan en el 608. Cada fila trae además 'warnings' con
 * lo que la DGII rechazaría, para poder corregirlo ANTES de enviar.
 */
function dgii_607_rows(string $ym): array
{
    if (!db(false) || !table_exists('invoices')) {
        return [];
    }
    [$from, $to] = dgii_period_range($ym);
    $rate = invoice_rate_sql();
    $date = 'DATE(COALESCE(invoices.emitted_at, invoices.issue_date))';
    $proforma = column_exists('invoices', 'is_proforma') ? ' AND invoices.is_proforma = 0' : '';

    $rows = fetch_all(
        "SELECT invoices.*, {$rate} AS fx, {$date} AS fiscal_date
           FROM invoices
          WHERE invoices.status IN ('Emitida','Pagada'){$proforma}
            AND invoices.ncf IS NOT NULL AND invoices.ncf <> ''
            AND {$date} BETWEEN ? AND ?
          ORDER BY invoices.ncf ASC",
        [$from, $to]
    );

    $incomeType = dgii_income_type();
    $hasPayments = table_exists('invoice_payments');
    $out = [];

    foreach ($rows as $inv) {
        $fx = max(1.0, (float) ($inv['fx'] ?? 1));
        $conv = fn ($v) => (float) $v * $fx;
        $type = (string) $inv['ncf_type'];

        $taxedBase = $conv($inv['taxed_base'] ?? 0);
        $exemptBase = $conv($inv['exempt_base'] ?? 0);
        $billed = $taxedBase + $exemptBase;   // monto facturado SIN ITBIS
        $itbis = $conv($inv['tax_amount'] ?? 0);
        $isc = $conv($inv['isc_amount'] ?? 0);
        $itbisRet = $conv($inv['itbis_retained'] ?? 0);
        $isrRet = $conv($inv['isr_retained'] ?? 0);
        $total = $conv($inv['total'] ?? 0);

        // Formas de pago (campos 17 a 23). La regla es la realidad del cobro, no
        // la etiqueta del documento:
        //   · cada abono registrado va al bucket de SU método;
        //   · lo que la factura da por cobrado sin abono que lo detalle se
        //     atribuye al método declarado del comprobante;
        //   · lo que todavía no entró es venta a crédito, siempre — da igual si
        //     la factura decía «contado»: si el dinero no llegó, es crédito.
        // Las notas de crédito y débito van sin formas de pago.
        $pay = ['efectivo' => 0.0, 'cheque' => 0.0, 'tarjeta' => 0.0, 'credito' => 0.0, 'otras' => 0.0];
        if (!dgii_is_note($type)) {
            $detailed = 0.0;
            if ($hasPayments) {
                foreach (fetch_all('SELECT amount, method FROM invoice_payments WHERE invoice_id=? AND paid_at IS NOT NULL AND paid_at <= ?', [(int) $inv['id'], $to]) as $p) {
                    $amt = $conv($p['amount'] ?? 0);
                    $pay[dgii_collected_bucket($p['method'] ?? '')] += $amt;
                    $detailed += $amt;
                }
            }
            $declared = $conv($inv['amount_paid'] ?? 0);
            $undetailed = round($declared - $detailed, 2);
            if ($undetailed > 0.009) {
                $pay[dgii_collected_bucket($inv['payment_method'] ?? '')] += $undetailed;
            }
            $rest = round($total - max($declared, $detailed), 2);
            if ($rest > 0.009) {
                $pay['credito'] += $rest;
            }
        }

        $id = dgii_id_kind($inv['client_rnc'] ?? '');
        $warnings = [];
        if ($id['digits'] === '' && ncf_requires_rnc($type)) {
            $warnings[] = 'El tipo «' . ncf_type_label($type) . '» exige RNC/Cédula del cliente y el comprobante no lo tiene.';
        }
        if (dgii_is_note($type) && trim((string) ($inv['modifies_ncf'] ?? '')) === '') {
            $warnings[] = 'Nota de crédito/débito sin el NCF que modifica.';
        }
        if ($billed <= 0.009 && $itbis <= 0.009) {
            $warnings[] = 'Comprobante sin monto.';
        }
        if (dgii_date((string) ($inv['fiscal_date'] ?? '')) === '') {
            $warnings[] = 'Comprobante sin fecha de emisión.';
        }

        $out[] = [
            'invoice_id' => (int) $inv['id'],
            'ncf' => (string) $inv['ncf'],
            'ncf_type' => $type,
            'type_label' => ncf_type_label($type),
            'modifies' => trim((string) ($inv['modifies_ncf'] ?? '')),
            'client' => (string) ($inv['client_name'] ?? ''),
            'rnc' => $id['digits'],
            'id_type' => $id['type'],
            'income_type' => $incomeType,
            'date' => (string) $inv['fiscal_date'],
            'retention_date' => ($itbisRet > 0.009 || $isrRet > 0.009) ? (string) $inv['fiscal_date'] : '',
            'billed' => $billed,
            'itbis' => $itbis,
            'itbis_ret' => $itbisRet,
            'isr_ret' => $isrRet,
            'isc' => $isc,
            'total' => $total,
            'currency' => (string) ($inv['currency'] ?? 'DOP'),
            'pay' => $pay,
            'warnings' => $warnings,
        ];
    }

    return $out;
}

/**
 * Filas del 608 del periodo: comprobantes anulados durante el mes.
 *
 * Solo entran los que conservan NCF. Cuando una anulación libera el número
 * (invoice_release_ncf), la factura se queda sin NCF y ese número vuelve al
 * rango para reutilizarse: no se anuló nada ante la DGII y no debe informarse.
 */
function dgii_608_rows(string $ym): array
{
    if (!db(false) || !table_exists('invoices') || !column_exists('invoices', 'voided_at')) {
        return [];
    }
    [$from, $to] = dgii_period_range($ym);
    $codeCol = column_exists('invoices', 'void_code') ? 'invoices.void_code' : "''";

    $rows = fetch_all(
        "SELECT invoices.id, invoices.ncf, invoices.ncf_type, invoices.issue_date, invoices.emitted_at,
                invoices.client_name, invoices.void_reason, invoices.voided_at, {$codeCol} AS void_code
           FROM invoices
          WHERE invoices.status = 'Anulada'
            AND invoices.ncf IS NOT NULL AND invoices.ncf <> ''
            AND DATE(invoices.voided_at) BETWEEN ? AND ?
          ORDER BY invoices.ncf ASC",
        [$from, $to]
    );

    $out = [];
    foreach ($rows as $r) {
        $code = trim((string) ($r['void_code'] ?? ''));
        $warnings = [];
        if ($code === '' || !isset(dgii_void_reasons()[$code])) {
            $warnings[] = 'Falta el código de anulación de la DGII.';
            $code = '';
        }
        $out[] = [
            'invoice_id' => (int) $r['id'],
            'ncf' => (string) $r['ncf'],
            'type_label' => ncf_type_label((string) $r['ncf_type']),
            'client' => (string) ($r['client_name'] ?? ''),
            'date' => (string) (($r['issue_date'] ?? '') ?: substr((string) ($r['emitted_at'] ?? ''), 0, 10)),
            'voided_at' => (string) ($r['voided_at'] ?? ''),
            'code' => $code,
            'code_label' => dgii_void_reason_label($code),
            'reason' => (string) ($r['void_reason'] ?? ''),
            'warnings' => $warnings,
        ];
    }
    return $out;
}

/* ========================= Libro de ventas e ITBIS ========================= */

/**
 * Libro de ventas del periodo: TODOS los comprobantes con NCF emitidos en el
 * mes, en orden, incluidas las anuladas.
 *
 * Las anuladas se muestran con importes en cero y su marca: el libro tiene que
 * enseñar la secuencia completa de NCF consumidos, porque un salto sin explicar
 * es lo primero que salta en una auditoría. Las notas de crédito se listan con
 * sus importes en positivo y restan en los totales (llevan 'sign' = -1).
 *
 * Todo en RD$: los comprobantes en USD se convierten con la tasa del documento.
 */
function dgii_sales_book(string $ym): array
{
    if (!db(false) || !table_exists('invoices')) {
        return ['rows' => [], 'totals' => dgii_sales_book_totals([]), 'period' => $ym];
    }
    [$from, $to] = dgii_period_range($ym);
    $rate = invoice_rate_sql();
    $date = 'DATE(COALESCE(invoices.emitted_at, invoices.issue_date))';
    $proforma = column_exists('invoices', 'is_proforma') ? ' AND invoices.is_proforma = 0' : '';

    $rows = fetch_all(
        "SELECT invoices.*, {$rate} AS fx, {$date} AS fiscal_date
           FROM invoices
          WHERE invoices.status IN ('Emitida','Pagada','Anulada'){$proforma}
            AND invoices.ncf IS NOT NULL AND invoices.ncf <> ''
            AND {$date} BETWEEN ? AND ?
          ORDER BY invoices.ncf ASC, invoices.id ASC",
        [$from, $to]
    );

    $out = [];
    foreach ($rows as $inv) {
        $fx = max(1.0, (float) ($inv['fx'] ?? 1));
        $type = (string) $inv['ncf_type'];
        $void = (string) $inv['status'] === 'Anulada';
        $note = dgii_is_note($type);
        // Una anulada no aporta importe alguno: su NCF figura solo para que la
        // secuencia quede completa.
        $conv = fn ($v) => $void ? 0.0 : (float) $v * $fx;

        $out[] = [
            'invoice_id' => (int) $inv['id'],
            'date' => (string) $inv['fiscal_date'],
            'ncf' => (string) $inv['ncf'],
            'ncf_type' => $type,
            'type_label' => ncf_type_label($type),
            'modifies' => trim((string) ($inv['modifies_ncf'] ?? '')),
            'client' => (string) ($inv['client_name'] ?? ''),
            'rnc' => dgii_id_kind($inv['client_rnc'] ?? '')['digits'],
            'currency' => (string) ($inv['currency'] ?? 'DOP'),
            'status' => (string) $inv['status'],
            'is_void' => $void,
            'is_note' => $note,
            'sign' => $note ? -1 : 1,
            'taxed' => $conv($inv['taxed_base'] ?? 0),
            'exempt' => $conv($inv['exempt_base'] ?? 0),
            'itbis' => $conv($inv['tax_amount'] ?? 0),
            'isc' => $conv($inv['isc_amount'] ?? 0),
            'itbis_ret' => $conv($inv['itbis_retained'] ?? 0),
            'isr_ret' => $conv($inv['isr_retained'] ?? 0),
            'total' => $conv($inv['total'] ?? 0),
        ];
    }

    return ['rows' => $out, 'totals' => dgii_sales_book_totals($out), 'period' => $ym];
}

/** Totales del libro, con las notas de crédito restando. */
function dgii_sales_book_totals(array $rows): array
{
    $t = [
        'count' => count($rows), 'voided' => 0, 'notes' => 0,
        'taxed' => 0.0, 'exempt' => 0.0, 'itbis' => 0.0, 'isc' => 0.0,
        'itbis_ret' => 0.0, 'isr_ret' => 0.0, 'total' => 0.0,
    ];
    foreach ($rows as $r) {
        if ($r['is_void']) { $t['voided']++; continue; }
        if ($r['is_note']) { $t['notes']++; }
        $s = (int) $r['sign'];
        foreach (['taxed', 'exempt', 'itbis', 'isc', 'itbis_ret', 'isr_ret', 'total'] as $k) {
            $t[$k] += $s * (float) $r[$k];
        }
    }
    foreach (['taxed', 'exempt', 'itbis', 'isc', 'itbis_ret', 'isr_ret', 'total'] as $k) {
        $t[$k] = round($t[$k], 2);
    }
    return $t;
}

/**
 * Resumen de ITBIS del mes, base de la declaración IT-1.
 *
 * Solo cubre la mitad de ventas, que es la que el CRM conoce. El crédito fiscal
 * (el ITBIS de las compras) no se puede calcular porque no hay módulo de
 * compras, así que se devuelve null y la pantalla lo dice: dar un «ITBIS a
 * pagar» sin restar compras sería un número que nadie debería usar.
 */
function dgii_itbis_summary(string $ym): array
{
    $book = dgii_sales_book($ym);
    $t = $book['totals'];

    // Desglose por tipo de comprobante, para cuadrar contra el 607.
    $byType = [];
    foreach ($book['rows'] as $r) {
        if ($r['is_void']) {
            continue;
        }
        $k = $r['ncf_type'];
        $byType[$k] ??= ['type' => $k, 'label' => $r['type_label'], 'count' => 0, 'taxed' => 0.0, 'exempt' => 0.0, 'itbis' => 0.0];
        $s = (int) $r['sign'];
        $byType[$k]['count']++;
        $byType[$k]['taxed'] += $s * $r['taxed'];
        $byType[$k]['exempt'] += $s * $r['exempt'];
        $byType[$k]['itbis'] += $s * $r['itbis'];
    }
    foreach ($byType as $k => $v) {
        foreach (['taxed', 'exempt', 'itbis'] as $f) {
            $byType[$k][$f] = round($v[$f], 2);
        }
    }
    ksort($byType);

    return [
        'period' => $ym,
        'taxed' => $t['taxed'],
        'exempt' => $t['exempt'],
        'sales' => round($t['taxed'] + $t['exempt'], 2),
        // Débito fiscal: el ITBIS que SCH le cobró a sus clientes este mes.
        'debit' => $t['itbis'],
        // Retenido por terceros: ya lo enteró el cliente, se descuenta de lo que
        // toca pagar.
        'withheld' => $t['itbis_ret'],
        'isc' => $t['isc'],
        // Crédito fiscal de compras: no hay de dónde sacarlo todavía.
        'credit' => null,
        'credit_reason' => 'Requiere el módulo de compras con NCF de suplidor, que el CRM aún no tiene.',
        // Parcial a propósito: débito − retenciones, SIN restar compras.
        'partial_due' => round($t['itbis'] - $t['itbis_ret'], 2),
        'by_type' => array_values($byType),
        'voided' => $t['voided'],
        'notes' => $t['notes'],
        'count' => $t['count'],
    ];
}

/** Totales del 607 para conciliar contra la contabilidad antes de enviar. */
function dgii_607_totals(array $rows): array
{
    $sum = fn (string $k) => array_sum(array_map(fn ($r) => (float) $r[$k], $rows));
    return [
        'count' => count($rows),
        'billed' => $sum('billed'),
        'itbis' => $sum('itbis'),
        'itbis_ret' => $sum('itbis_ret'),
        'isr_ret' => $sum('isr_ret'),
        'isc' => $sum('isc'),
        'total' => $sum('total'),
        'warnings' => array_sum(array_map(fn ($r) => count($r['warnings']), $rows)),
    ];
}

/** RNC de la empresa informante, sin separadores. */
function dgii_company_rnc(): string
{
    return preg_replace('/\D/', '', (string) (defined('APP_RNC') ? APP_RNC : '')) ?? '';
}

/** Periodo en el formato AAAAMM que lleva la cabecera. */
function dgii_period_header(string $ym): string
{
    return str_replace('-', '', $ym);
}

/** Una línea de detalle del 607 (23 campos separados por "|"). */
function dgii_607_line(array $r): string
{
    return implode('|', [
        $r['rnc'],                              //  1 RNC o cédula del comprador
        $r['id_type'],                          //  2 Tipo de identificación
        $r['ncf'],                              //  3 NCF
        $r['modifies'],                         //  4 NCF modificado
        $r['income_type'],                      //  5 Tipo de ingreso
        dgii_date($r['date']),                  //  6 Fecha del comprobante
        dgii_date($r['retention_date']),        //  7 Fecha de retención
        dgii_amount($r['billed']),              //  8 Monto facturado (sin ITBIS)
        dgii_amount($r['itbis']),               //  9 ITBIS facturado
        dgii_amount($r['itbis_ret']),           // 10 ITBIS retenido por terceros
        dgii_amount(0),                         // 11 ITBIS percibido
        dgii_amount($r['isr_ret']),             // 12 Retención de renta por terceros
        dgii_amount(0),                         // 13 ISR percibido
        dgii_amount($r['isc']),                 // 14 Impuesto selectivo al consumo
        dgii_amount(0),                         // 15 Otros impuestos / tasas
        dgii_amount(0),                         // 16 Monto propina legal
        dgii_amount($r['pay']['efectivo']),     // 17 Efectivo
        dgii_amount($r['pay']['cheque']),       // 18 Cheque / transferencia / depósito
        dgii_amount($r['pay']['tarjeta']),      // 19 Tarjeta de débito o crédito
        dgii_amount($r['pay']['credito']),      // 20 Venta a crédito
        dgii_amount(0),                         // 21 Bonos o certificados de regalo
        dgii_amount(0),                         // 22 Permuta
        dgii_amount($r['pay']['otras']),        // 23 Otras formas de venta
    ]);
}

/** Una línea de detalle del 608 (3 campos separados por "|"). */
function dgii_608_line(array $r): string
{
    return implode('|', [
        $r['ncf'],                 // 1 NCF anulado
        dgii_date($r['date']),     // 2 Fecha del comprobante
        $r['code'],                // 3 Tipo de anulación
    ]);
}

/**
 * Archivo completo de un formato. Los saltos de línea van en CRLF, que es lo
 * que espera la carga de la Oficina Virtual.
 */
function dgii_build_txt(string $report, string $ym, array $rows): string
{
    $line = $report === '608' ? 'dgii_608_line' : 'dgii_607_line';
    $out = [implode('|', [$report, dgii_company_rnc(), dgii_period_header($ym), (string) count($rows)])];
    foreach ($rows as $r) {
        $out[] = $line($r);
    }
    return implode("\r\n", $out) . "\r\n";
}

/** Nombre de archivo sugerido: DGII_607_130123456_202609.TXT */
function dgii_filename(string $report, string $ym): string
{
    $rnc = dgii_company_rnc();
    return 'DGII_' . $report . '_' . ($rnc !== '' ? $rnc . '_' : '') . dgii_period_header($ym) . '.TXT';
}

/**
 * Números que faltan en la secuencia del periodo y que NADIE explica.
 *
 * Se cruza lo declarado en el 607 con lo anulado en el 608: si entre dos
 * comprobantes consecutivos falta un número y ese número tampoco aparece
 * anulado, hay un hueco sin justificar. Puede ser legítimo —un rango que se
 * empezó en otro mes, un número liberado que se reutilizará— pero es lo
 * primero que la DGII pregunta, así que se avisa antes de enviar.
 *
 * Devuelve [['serie' => 'B01', 'desde' => 2, 'hasta' => 4, 'faltan' => [3]], …]
 */
function dgii_sequence_gaps(string $ym): array
{
    $emitidos = [];
    foreach (dgii_607_rows($ym) as $r) {
        if (preg_match('/^([BE]\d{2})(\d+)$/', $r['ncf'], $m)) {
            $emitidos[$m[1]][] = (int) $m[2];
        }
    }
    $anulados = [];
    foreach (dgii_608_rows($ym) as $r) {
        if (preg_match('/^([BE]\d{2})(\d+)$/', $r['ncf'], $m)) {
            $anulados[$m[1]][(int) $m[2]] = true;
        }
    }

    $huecos = [];
    foreach ($emitidos as $serie => $nums) {
        sort($nums);
        for ($i = 1; $i < count($nums); $i++) {
            $faltan = [];
            for ($n = $nums[$i - 1] + 1; $n < $nums[$i]; $n++) {
                if (!isset($anulados[$serie][$n])) {
                    $faltan[] = $n;
                }
                // Más de 25 seguidos: casi seguro un rango nuevo, no un hueco.
                if (count($faltan) > 25) { $faltan = []; break; }
            }
            if ($faltan) {
                $huecos[] = [
                    'serie'  => $serie,
                    'desde'  => $nums[$i - 1],
                    'hasta'  => $nums[$i],
                    'faltan' => $faltan,
                ];
            }
        }
    }
    return $huecos;
}

/**
 * Estado del 606. No se genera porque el CRM no registra compras ni gastos:
 * no hay de dónde sacar el NCF del suplidor, el tipo de bien o servicio ni las
 * retenciones practicadas. Se informa con honestidad en pantalla en vez de
 * producir un archivo vacío que la DGII aceptaría como "sin compras".
 */
function dgii_606_status(): array
{
    return [
        'available' => false,
        'reason' => 'El CRM aún no registra compras ni gastos con NCF de suplidor, que es la fuente del 606.',
        'needs' => [
            'Módulo de compras y gastos con NCF del suplidor y su tipo de identificación.',
            'Clasificación por tipo de bien o servicio comprado (01 a 11).',
            'Retenciones de ITBIS e ISR practicadas al suplidor.',
            'Forma de pago de cada compra.',
        ],
    ];
}
