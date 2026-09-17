<?php

/**
 * SCH MEDICOS CRM — idempotent schema migrator.
 *
 * Brings any environment's database up to date using that environment's own
 * config/database.php. Safe to run repeatedly: it only creates missing tables
 * (CREATE TABLE IF NOT EXISTS) and adds missing columns (column_exists guards
 * inside the ensure_*_schema helpers). No data is ever modified or dropped.
 *
 * Usage on the production server (recommended):
 *     php database/migrate.php
 *
 * It can also be opened in a browser, but ONLY from a genuine local host; on a
 * public server the web entry point is refused (use the CLI instead).
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$cli = PHP_SAPI === 'cli';
if (!$cli && !is_local_env()) {
    http_response_code(403);
    exit('Ejecuta este migrador por consola: php database/migrate.php');
}

$nl = $cli ? "\n" : '<br>';
$out = function (string $msg) use ($nl) { echo $msg . $nl; };

$pdo = db(false);
if (!$pdo) {
    $out('ERROR: no se pudo conectar a MySQL. Revisa config/database.php de este servidor.');
    exit($cli ? 1 : 0);
}

$out('SCH MEDICOS — migración de base de datos');
$out('Base de datos: ' . (defined('DB_NAME') ? DB_NAME : '?') . ' @ ' . (defined('DB_HOST') ? DB_HOST : '?'));
$out(str_repeat('-', 48));

/* 1) Base schema — create any missing tables (idempotent). */
$schema = @file_get_contents(__DIR__ . '/schema.sql');
if ($schema !== false) {
    $created = 0;
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
        if ($stmt === '') {
            continue;
        }
        try { $pdo->exec($stmt); $created++; } catch (Throwable $e) { /* ignore individual errors */ }
    }
    $out("schema.sql aplicado ({$created} sentencias CREATE TABLE IF NOT EXISTS)");
} else {
    $out('AVISO: no se pudo leer database/schema.sql; continuo con migraciones de columnas.');
}

/* 2) Runtime column/table migrations (additive, guarded by column_exists). */
ensure_settings_schema();
$out('settings: OK');

ensure_quote_schema();
$out('quotes: category, currency, exchange_rate, terms, approved_at: OK');

ensure_helpdesk_schema();
$out('clients.support_* y tickets.source/public_reference: OK');

ensure_invoice_schema();
$out('invoices/invoice_items/ncf_sequences + discount_pct: OK');

ensure_products_schema();
$out('products + product_id/unit_cost en partidas: OK');
ensure_cobros_schema();
$out('historial de recibos + plan de cuotas + ajuste de cartera: OK');
ensure_statement_schema();
$out('estados de cuenta editables: OK');

/*
 * 3) Enlazar notas de crédito históricas.
 *
 * Antes, el NCF que modificaba una nota de crédito se escribía a mano en
 * modifies_ncf y nadie llenaba modifies_invoice_id, así que esas notas no
 * descontaban saldo: la factura seguía figurando por su importe completo en la
 * cartera. Esto busca a qué comprobante apuntaba cada nota y las enlaza.
 *
 * Solo actúa cuando el NCF identifica a UN único comprobante. Si hubiera
 * ambigüedad se deja como está y se informa: enlazar a la factura equivocada
 * sería peor que no enlazar.
 */
if (table_exists('invoices') && column_exists('invoices', 'modifies_invoice_id') && column_exists('invoices', 'credited_amount')) {
    $pending = fetch_all(
        "SELECT id, modifies_ncf FROM invoices
          WHERE ncf_type IN ('04','34')
            AND modifies_ncf IS NOT NULL AND modifies_ncf <> ''
            AND (modifies_invoice_id IS NULL OR modifies_invoice_id = 0)"
    );
    $linked = 0;
    $ambiguous = 0;
    $orphan = 0;
    $touched = [];
    foreach ($pending as $note) {
        $matches = fetch_all('SELECT id FROM invoices WHERE ncf = ? AND id <> ?', [(string) $note['modifies_ncf'], (int) $note['id']]);
        if (count($matches) === 1) {
            $srcId = (int) $matches[0]['id'];
            $pdo->prepare('UPDATE invoices SET modifies_invoice_id = ? WHERE id = ?')->execute([$srcId, (int) $note['id']]);
            $touched[$srcId] = true;
            $linked++;
        } elseif (count($matches) > 1) {
            $ambiguous++;
        } else {
            $orphan++;
        }
    }
    foreach (array_keys($touched) as $srcId) {
        invoice_recalc_credited((int) $srcId);
    }
    if ($pending === []) {
        $out('notas de crédito: no hay ninguna pendiente de enlazar');
    } else {
        $out(sprintf(
            'notas de crédito: %d enlazadas y aplicadas a %d comprobante(s)%s%s',
            $linked,
            count($touched),
            $ambiguous > 0 ? "; {$ambiguous} con NCF ambiguo (revisar a mano)" : '',
            $orphan > 0 ? "; {$orphan} apuntan a un NCF inexistente" : ''
        ));
    }
}

/* 4) Verify the key columns the v2.0 features depend on. */
$out(str_repeat('-', 48));
$checks = [
    ['quotes', 'category'],
    ['quotes', 'approved_at'],
    ['quotes', 'currency'],
    ['quotes', 'terms'],
    ['quotes', 'discount_amount'],
    ['quotes', 'discount_pct'],
    ['invoices', 'discount_pct'],
    ['invoices', 'void_code'],
    ['invoices', 'credited_amount'],
    ['invoice_payments', 'receipt_number'],
    ['invoice_items', 'unit_cost'],
    ['quote_items', 'unit_cost'],
    ['equipment', 'last_service_at'],
    ['clients', 'support_slug'],
    ['invoices', 'installment_base'],
    ['invoices', 'balance_adjustment'],
    ['invoice_installments', 'cumulative'],
    ['invoice_payment_log', 'before_json'],
    ['client_statements', 'excluded_ids'],
];
$allOk = true;
foreach ($checks as [$table, $col]) {
    $ok = table_exists($table) && column_exists($table, $col);
    $allOk = $allOk && $ok;
    $out(sprintf('  [%s] %s.%s', $ok ? 'OK' : 'FALTA', $table, $col));
}
/* El índice de recibos: tiene que ser por (recibo, factura). Uno UNIQUE sobre el
   número a secas rompe cualquier cobro repartido entre varias facturas. */
$lineaOk = table_exists('invoice_payments') && index_exists('invoice_payments', 'uniq_receipt_line', true);
$viejo = table_exists('invoice_payments') && index_exists('invoice_payments', 'uniq_payment_receipt', true);
$allOk = $allOk && $lineaOk && !$viejo;
$out(sprintf('  [%s] invoice_payments: índice único por (recibo, factura)', $lineaOk ? 'OK' : 'FALTA'));
$out(sprintf('  [%s] invoice_payments: sin el índice único viejo sobre el número', $viejo ? 'FALTA quitarlo' : 'OK'));
foreach (['activity_log', 'contacts', 'settings', 'login_attempts'] as $t) {
    // login_attempts is created lazily on first login attempt; it's fine if absent here.
    $exists = table_exists($t);
    $out(sprintf('  [%s] tabla %s', $exists ? 'OK' : ($t === 'login_attempts' ? 'pendiente' : 'FALTA'), $t));
}

$out(str_repeat('-', 48));
$out($allOk ? 'Migración completada. La base de datos está lista.' : 'Migración ejecutada con avisos: revisa los FALTA de arriba.');
exit($cli ? ($allOk ? 0 : 2) : 0);
