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

/* 3) Verify the key columns the v2.0 features depend on. */
$out(str_repeat('-', 48));
$checks = [
    ['quotes', 'category'],
    ['quotes', 'approved_at'],
    ['quotes', 'currency'],
    ['quotes', 'terms'],
    ['equipment', 'last_service_at'],
    ['clients', 'support_slug'],
];
$allOk = true;
foreach ($checks as [$table, $col]) {
    $ok = table_exists($table) && column_exists($table, $col);
    $allOk = $allOk && $ok;
    $out(sprintf('  [%s] %s.%s', $ok ? 'OK' : 'FALTA', $table, $col));
}
foreach (['activity_log', 'contacts', 'settings', 'login_attempts'] as $t) {
    // login_attempts is created lazily on first login attempt; it's fine if absent here.
    $exists = table_exists($t);
    $out(sprintf('  [%s] tabla %s', $exists ? 'OK' : ($t === 'login_attempts' ? 'pendiente' : 'FALTA'), $t));
}

$out(str_repeat('-', 48));
$out($allOk ? 'Migración completada. La base de datos está lista.' : 'Migración ejecutada con avisos: revisa los FALTA de arriba.');
exit($cli ? ($allOk ? 0 : 2) : 0);
