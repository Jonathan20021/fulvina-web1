<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float|int|string|null $value): string
{
    return 'RD$ ' . number_format((float) $value, 2, '.', ',');
}

/** Currency-aware money formatter (DOP -> RD$, USD -> US$). */
function money_cur(float|int|string|null $value, string $currency = 'DOP'): string
{
    $sym = strtoupper($currency) === 'USD' ? 'US$' : 'RD$';
    return $sym . ' ' . number_format((float) $value, 2, '.', ',');
}

/** Spanish words for a non-negative integer (supports up to 999,999,999,999). */
function int_to_words_es(int $n): string
{
    if ($n < 0) { $n = -$n; }
    if ($n === 0) return 'cero';
    if ($n > 999999999999) { return (string) $n; } // beyond supported range: numeric fallback (never fatals)

    $unidades = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciseis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte'];
    $decenas = ['', '', 'veinti', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
    $centenas = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

    // 0..999
    $under1000 = function (int $x) use ($unidades, $decenas, $centenas): string {
        if ($x === 0) return '';
        if ($x === 100) return 'cien';
        $c = intdiv($x, 100);
        $r = $x % 100;
        $out = $centenas[$c];
        if ($r > 0) {
            if ($r <= 20) {
                $part = $unidades[$r];
            } else {
                $d = intdiv($r, 10);
                $u = $r % 10;
                if ($d === 2) {
                    $part = $u === 0 ? 'veinte' : 'veinti' . $unidades[$u];
                } else {
                    $part = $decenas[$d] . ($u > 0 ? ' y ' . $unidades[$u] : '');
                }
            }
            $out = trim($out . ' ' . $part);
        }
        return $out;
    };

    // 0..999,999 (thousands + hundreds)
    $underMillion = function (int $x) use ($under1000): string {
        $miles = intdiv($x, 1000);
        $resto = $x % 1000;
        $parts = [];
        if ($miles === 1) {
            $parts[] = 'mil';
        } elseif ($miles > 0) {
            $parts[] = $under1000($miles) . ' mil';
        }
        if ($resto > 0) {
            $parts[] = $under1000($resto);
        }
        return trim(implode(' ', $parts));
    };

    $millones = intdiv($n, 1000000);   // 0..999,999
    $resto = $n % 1000000;             // 0..999,999
    $parts = [];
    if ($millones === 1) {
        $parts[] = 'un millon';
    } elseif ($millones > 0) {
        $parts[] = $underMillion($millones) . ' millones';
    }
    if ($resto > 0) {
        $parts[] = $underMillion($resto);
    }
    return trim(implode(' ', $parts));
}

/** Amount in words, accounting style: "DIECISIETE MIL SETECIENTOS PESOS CON 00/100". */
function money_in_words(float $value, string $currency = 'DOP'): string
{
    // Round to total cents once so a fractional carry rolls into whole units
    // (e.g. 1.999 -> "DOS ... CON 00/100", never "UNO ... CON 100/100").
    $tc = (int) round(abs($value) * 100);
    $entero = intdiv($tc, 100);
    $cents = $tc % 100;
    $unit = strtoupper($currency) === 'USD' ? 'DOLARES ESTADOUNIDENSES' : 'PESOS DOMINICANOS';
    $words = int_to_words_es($entero);
    return mb_strtoupper($words, 'UTF-8') . ' ' . $unit . ' CON ' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT) . '/100';
}

function date_es(?string $date): string
{
    if (!$date) {
        return 'Sin fecha';
    }

    return date('d/m/Y', strtotime($date));
}

function date_long_es(?string $date): string
{
    if (!$date) {
        return 'Sin fecha';
    }

    $months = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ];

    $timestamp = strtotime($date);
    return date('j', $timestamp) . ' de ' . $months[(int) date('n', $timestamp)] . ' de ' . date('Y', $timestamp);
}

/**
 * Versioned asset URL — appends ?v=<filemtime> so browsers fetch the new file
 * automatically after every deploy (no more stale-CSS/JS from cache).
 */
function asset_v(string $path): string
{
    $abs = dirname(__DIR__) . '/' . ltrim($path, '/');
    $v = is_file($abs) ? (string) filemtime($abs) : '0';
    return asset($path) . '?v=' . $v;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ?? '';
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf'] ?? '';
        if (!hash_equals(csrf_token(), (string) $token)) {
            http_response_code(419);
            exit('Token de seguridad invalido.');
        }
    }
}

/* ============================================================
   Public form anti-spam: content heuristics, time-trap, CAPTCHA.
   ============================================================ */

/**
 * High-precision spam filter for public form submissions, tuned for a
 * Spanish-speaking (Dominican Republic) audience. $identity = fields where a
 * URL never legitimately belongs (name, company, phone…); $body = the message.
 */
function looks_like_spam(array $identity, string $body): bool
{
    $all = $body . ' ' . implode(' ', array_map('strval', $identity));

    // Non-Latin scripts (Cyrillic, Greek, Hebrew, Arabic, CJK, Thai): not our
    // audience — virtually always bot spam.
    if (preg_match('/[\x{0370}-\x{03FF}\x{0400}-\x{052F}\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}\x{0E00}-\x{0E7F}]/u', $all)) {
        return true;
    }

    // A URL in a name/company/phone field is never legitimate.
    foreach ($identity as $f) {
        if (preg_match('#https?://|www\.#i', (string) $f)) {
            return true;
        }
    }

    // Known URL shorteners anywhere (classic spam vector).
    if (preg_match('#\b(goo\.su|bit\.ly|tinyurl\.com|t\.me|cutt\.ly|is\.gd|ow\.ly|rb\.gy|clck\.ru|vk\.cc|tiny\.cc|shorturl|surl\.li)\b#i', $all)) {
        return true;
    }

    // Two or more links in the body reads as link spam.
    if (preg_match_all('#https?://#i', $body) >= 2) {
        return true;
    }

    // BBCode / anchor injection.
    if (preg_match('#\[/?url[=\]]|<a\s+href#i', $all)) {
        return true;
    }

    return false;
}

/** Hidden, signed timestamp so we can reject implausibly fast (bot) submits. */
function form_time_field(): string
{
    $ts = time();
    $sig = hash_hmac('sha256', (string) $ts, (string) ($_SESSION['csrf'] ?? 'k'));
    return '<input type="hidden" name="fts" value="' . $ts . '"><input type="hidden" name="ftok" value="' . e($sig) . '">';
}

/** True when the form was on screen at least $minSeconds and the token is valid. */
function form_time_ok(int $minSeconds = 2): bool
{
    $ts = (int) ($_POST['fts'] ?? 0);
    $sig = (string) ($_POST['ftok'] ?? '');
    if ($ts <= 0 || $sig === '') {
        return false;
    }
    if (!hash_equals(hash_hmac('sha256', (string) $ts, (string) ($_SESSION['csrf'] ?? 'k')), $sig)) {
        return false;
    }
    $elapsed = time() - $ts;
    return $elapsed >= $minSeconds && $elapsed <= 86400;
}

/* ---- Cloudflare Turnstile (CAPTCHA) — keys editable in Configuración ---- */

function turnstile_site_key(): string
{
    return trim((string) setting_get('turnstile_site_key', ''));
}

function turnstile_secret_key(): string
{
    return trim((string) setting_get('turnstile_secret_key', ''));
}

function turnstile_enabled(): bool
{
    return turnstile_site_key() !== '' && turnstile_secret_key() !== '';
}

/** Turnstile widget + loader (empty string when not configured). */
function turnstile_widget(): string
{
    if (!turnstile_enabled()) {
        return '';
    }
    return '<div class="cf-turnstile" data-sitekey="' . e(turnstile_site_key()) . '" data-theme="light"></div>'
        . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
}

/**
 * Server-side verification of the Turnstile token.
 * Returns true when it passes OR Turnstile is not configured. Fails open on a
 * network/cURL error (so a Cloudflare outage never loses real leads); fails
 * closed only on a missing/invalid token.
 */
function turnstile_verify(): bool
{
    if (!turnstile_enabled()) {
        return true;
    }
    $token = (string) ($_POST['cf-turnstile-response'] ?? '');
    if ($token === '') {
        return false;
    }
    if (!function_exists('curl_init')) {
        return true;
    }
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret' => turnstile_secret_key(),
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) {
        return true; // can't reach Cloudflare → don't block a possibly-real lead
    }
    $data = json_decode((string) $resp, true);
    return is_array($data) && !empty($data['success']);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

/**
 * True only on a genuine local development host. Requires BOTH a loopback peer
 * (REMOTE_ADDR, which cannot be spoofed on a direct TCP connection) AND a
 * loopback Host, so a public deployment can never be coaxed into "dev mode"
 * via a forged Host header. Behind a reverse proxy REMOTE_ADDR is the proxy
 * IP, which correctly disables dev mode in production.
 */
function is_local_env(): bool
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
        return false;
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\]|::1)(:\d+)?$/', $host);
}

function table_exists(string $table): bool
{
    $pdo = db(false);
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function column_exists(string $table, string $column): bool
{
    $pdo = db(false);
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function slugify(string $value): string
{
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim((string) $value, '-');
    return $value !== '' ? $value : 'cliente';
}

function ensure_helpdesk_schema(): void
{
    $pdo = db(false);
    if (!$pdo || !table_exists('clients')) {
        return;
    }

    $clientColumns = [
        'support_slug' => "ALTER TABLE clients ADD COLUMN support_slug VARCHAR(220) NULL AFTER status",
        'support_token' => "ALTER TABLE clients ADD COLUMN support_token VARCHAR(64) NULL AFTER support_slug",
        'support_enabled' => "ALTER TABLE clients ADD COLUMN support_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER support_token",
    ];

    foreach ($clientColumns as $column => $statement) {
        if (!column_exists('clients', $column)) {
            $pdo->exec($statement);
        }
    }

    if (table_exists('tickets')) {
        $ticketColumns = [
            'source' => "ALTER TABLE tickets ADD COLUMN source VARCHAR(40) NOT NULL DEFAULT 'interno' AFTER status",
            'public_reference' => "ALTER TABLE tickets ADD COLUMN public_reference VARCHAR(80) NULL AFTER source",
        ];

        foreach ($ticketColumns as $column => $statement) {
            if (!column_exists('tickets', $column)) {
                $pdo->exec($statement);
            }
        }
    }
}

function ensure_quote_schema(): void
{
    $pdo = db(false);
    if (!$pdo || !table_exists('quotes')) {
        return;
    }

    $columns = [
        'currency' => "ALTER TABLE quotes ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'DOP' AFTER total",
        'exchange_rate' => "ALTER TABLE quotes ADD COLUMN exchange_rate DECIMAL(12,4) NOT NULL DEFAULT 1 AFTER currency",
        'terms' => "ALTER TABLE quotes ADD COLUMN terms TEXT NULL AFTER notes",
        'category' => "ALTER TABLE quotes ADD COLUMN category VARCHAR(80) NULL AFTER title",
        'approved_at' => "ALTER TABLE quotes ADD COLUMN approved_at DATETIME NULL AFTER status",
    ];

    foreach ($columns as $column => $statement) {
        if (!column_exists('quotes', $column)) {
            try { $pdo->exec($statement); } catch (Throwable) { /* ignore */ }
        }
    }

    ensure_settings_schema();
}

/**
 * RBAC schema: widen users.role from ENUM to VARCHAR so custom roles can be
 * assigned. Idempotent — only alters when the column is still an ENUM.
 */
function ensure_rbac_schema(): void
{
    $pdo = db(false);
    if (!$pdo || !table_exists('users')) {
        return;
    }
    ensure_settings_schema();
    try {
        $row = fetch_one("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'");
        if ($row && strtolower((string) $row['DATA_TYPE']) === 'enum') {
            $pdo->exec("ALTER TABLE users MODIFY role VARCHAR(40) NOT NULL DEFAULT 'soporte'");
        }
    } catch (Throwable) {
        /* ignore */
    }
}

/** Simple key/value settings store for global CRM preferences. */
function ensure_settings_schema(): void
{
    $pdo = db(false);
    if (!$pdo) {
        return;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(120) PRIMARY KEY, setting_value TEXT NULL, updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable) { /* ignore */ }
}

function setting_get(string $key, ?string $default = null): ?string
{
    if (!db(false) || !table_exists('settings')) {
        return $default;
    }
    $row = fetch_one('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
    return $row !== null ? (string) $row['setting_value'] : $default;
}

function setting_set(string $key, string $value): void
{
    if (!db(false) || !table_exists('settings')) {
        return;
    }
    db()->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()')
        ->execute([$key, $value]);
}

/** Default, editable terms & conditions for quotes. */
function quote_default_terms(): string
{
    return "1. Validez de la oferta: 30 días a partir de la fecha de emisión.\n"
        . "2. Precios sujetos a cambio sin previo aviso por variación del proveedor o de la tasa de cambio.\n"
        . "3. Tiempo de entrega: según disponibilidad, confirmado al recibir la orden de compra.\n"
        . "4. Forma de pago: 50% de anticipo, 50% contra entrega.\n"
        . "5. Garantía según fabricante. No incluye trabajos civiles ni eléctricos salvo indicación expresa.\n"
        . "6. Instalación y puesta en marcha por personal certificado de SCH MEDICOS.";
}

/* =========================================================================
   FACTURACIÓN — Comprobantes Fiscales (DGII República Dominicana)
   Numeración NCF, tipos de comprobante, retenciones e ITBIS.
   ========================================================================= */

/**
 * Catálogo oficial de Tipos de Comprobante Fiscal de la DGII.
 * code(2) => [etiqueta, encabezado del documento, exige RNC del cliente, serie].
 * Serie B = comprobante fiscal vigente (códigos 01–17).
 * Serie E = comprobante fiscal electrónico / e-CF (códigos 31–47), disponibles
 *           para registro MANUAL hasta que se conecte la transmisión a la DGII.
 */
function ncf_types(): array
{
    return [
        // ---- Serie B — Comprobante Fiscal (vigente) ----
        '01' => ['Factura de Crédito Fiscal',            'FACTURA DE CRÉDITO FISCAL', true,  'B'],
        '02' => ['Factura de Consumo',                   'FACTURA DE CONSUMO',        false, 'B'],
        '03' => ['Nota de Débito',                       'NOTA DE DÉBITO',            true,  'B'],
        '04' => ['Nota de Crédito',                      'NOTA DE CRÉDITO',           true,  'B'],
        '11' => ['Comprobante de Compras',               'COMPROBANTE DE COMPRAS',    true,  'B'],
        '12' => ['Registro Único de Ingresos',           'REGISTRO ÚNICO DE INGRESOS', false, 'B'],
        '13' => ['Comprobante para Gastos Menores',      'COMPROBANTE PARA GASTOS MENORES', false, 'B'],
        '14' => ['Comprobante de Regímenes Especiales',  'FACTURA — RÉGIMEN ESPECIAL', true,  'B'],
        '15' => ['Comprobante Gubernamental',            'FACTURA GUBERNAMENTAL',     true,  'B'],
        '16' => ['Comprobante para Exportaciones',       'FACTURA DE EXPORTACIÓN',    true,  'B'],
        '17' => ['Comprobante para Pagos al Exterior',   'COMPROBANTE PAGOS AL EXTERIOR', true, 'B'],
        // ---- Serie E — Comprobante Fiscal Electrónico (e-CF) ----
        '31' => ['Factura de Crédito Fiscal Electrónica', 'FACTURA DE CRÉDITO FISCAL ELECTRÓNICA', true,  'E'],
        '32' => ['Factura de Consumo Electrónica',        'FACTURA DE CONSUMO ELECTRÓNICA',        false, 'E'],
        '33' => ['Nota de Débito Electrónica',            'NOTA DE DÉBITO ELECTRÓNICA',            true,  'E'],
        '34' => ['Nota de Crédito Electrónica',           'NOTA DE CRÉDITO ELECTRÓNICA',           true,  'E'],
        '41' => ['Compras Electrónico',                   'COMPROBANTE DE COMPRAS ELECTRÓNICO',    true,  'E'],
        '43' => ['Gastos Menores Electrónico',            'COMPROBANTE GASTOS MENORES ELECTRÓNICO', false, 'E'],
        '44' => ['Regímenes Especiales Electrónico',      'FACTURA RÉGIMEN ESPECIAL ELECTRÓNICA',  true,  'E'],
        '45' => ['Gubernamental Electrónico',             'FACTURA GUBERNAMENTAL ELECTRÓNICA',     true,  'E'],
        '46' => ['Exportaciones Electrónico',             'FACTURA DE EXPORTACIÓN ELECTRÓNICA',    true,  'E'],
        '47' => ['Pagos al Exterior Electrónico',         'COMPROBANTE PAGOS AL EXTERIOR ELECTRÓNICO', true, 'E'],
    ];
}

/** Subset of the catalog for one series ('B' fiscal vigente, 'E' electrónico). */
function ncf_types_for(string $prefix): array
{
    $p = strtoupper($prefix) === 'E' ? 'E' : 'B';
    return array_filter(ncf_types(), fn ($t) => ($t[3] ?? 'B') === $p);
}

/** Series ('B' | 'E') a given comprobante code belongs to. */
function ncf_series(string $type): string
{
    return ncf_types()[$type][3] ?? 'B';
}

/** Equivalence between the vigente (B) code and its e-CF (E) counterpart. */
function ncf_pair_map(): array
{
    return ['01' => '31', '02' => '32', '03' => '33', '04' => '34', '11' => '41', '13' => '43', '14' => '44', '15' => '45', '16' => '46', '17' => '47'];
}

/** Coerce a comprobante code so it always matches the chosen series (server-side guard). */
function ncf_normalize_type(string $type, string $prefix): string
{
    $prefix = strtoupper($prefix) === 'E' ? 'E' : 'B';
    $types = ncf_types();
    if (isset($types[$type]) && ($types[$type][3] ?? 'B') === $prefix) {
        return $type;
    }
    $map = ncf_pair_map();
    if ($prefix === 'E' && isset($map[$type])) {
        return $map[$type];
    }
    if ($prefix === 'B') {
        $flip = array_flip($map);
        if (isset($flip[$type])) { return $flip[$type]; }
    }
    return $prefix === 'E' ? '31' : '01';
}

function ncf_type_label(string $type): string
{
    return ncf_types()[$type][0] ?? ('Tipo ' . $type);
}

/** Document heading shown on the PDF for a comprobante type. */
function ncf_doc_heading(string $type): string
{
    return ncf_types()[$type][1] ?? 'COMPROBANTE FISCAL';
}

/** Whether the DGII type requires the customer's RNC/Cédula. */
function ncf_requires_rnc(string $type): bool
{
    return (bool) (ncf_types()[$type][2] ?? false);
}

/** Allowed NCF prefixes: B = comprobante fiscal vigente, E = comprobante fiscal electrónico (e-CF). */
function ncf_prefixes(): array
{
    return ['B' => 'B — Comprobante Fiscal', 'E' => 'E — Comprobante Fiscal Electrónico (e-CF)'];
}

/** Sequence width: e-CF (E) uses 10 digits, the vigente (B) uses 8. */
function ncf_seq_width(string $prefix): int
{
    return strtoupper($prefix) === 'E' ? 10 : 8;
}

/** Build a full NCF string, e.g. ncf_format('B','01',123) => "B0100000123". */
function ncf_format(string $prefix, string $type, int $seq): string
{
    $prefix = strtoupper($prefix) === 'E' ? 'E' : 'B';
    $type = substr(preg_replace('/\D/', '', $type) ?: '02', 0, 2);
    $type = str_pad($type, 2, '0', STR_PAD_LEFT);
    return $prefix . $type . str_pad((string) max(0, $seq), ncf_seq_width($prefix), '0', STR_PAD_LEFT);
}

/** Stored lifecycle states of an invoice. "Vencida" is derived, never stored. */
function invoice_status_list(): array
{
    return ['Borrador', 'Emitida', 'Pagada', 'Anulada'];
}

function invoice_payment_conditions(): array
{
    return ['Contado', 'Crédito'];
}

function invoice_payment_methods(): array
{
    return ['Efectivo', 'Transferencia', 'Cheque', 'Tarjeta de crédito', 'Tarjeta de débito', 'Crédito', 'Otro'];
}

/** An invoice is only editable while it is a draft (fiscal docs lock on emission). */
function invoice_is_editable(?string $status): bool
{
    return strtolower((string) $status) === 'borrador' || (string) $status === '';
}

/** Default, editable invoice terms (note: comprobante fiscal). */
function invoice_default_terms(): string
{
    return "1. Comprobante fiscal válido para fines del ITBIS según las normas de la DGII.\n"
        . "2. Las mercancías viajan por cuenta y riesgo del comprador una vez despachadas.\n"
        . "3. Reclamaciones sobre esta factura dentro de los 5 días posteriores a su recepción.\n"
        . "4. Facturas a crédito: el incumplimiento del plazo genera intereses por mora.\n"
        . "5. Garantía de equipos según el fabricante. Instalación y certificación por personal de SCH MEDICOS.\n"
        . "6. La retención de ITBIS/ISR, cuando aplique, debe acreditarse con el comprobante de retención correspondiente.";
}

/**
 * Provision the invoicing schema at runtime (mirrors ensure_quote_schema):
 * invoices, invoice_items, invoice_payments and ncf_sequences. Idempotent.
 */
function ensure_invoice_schema(): void
{
    $pdo = db(false);
    if (!$pdo) {
        return;
    }
    ensure_settings_schema();

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ncf_sequences (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prefix VARCHAR(2) NOT NULL DEFAULT 'B',
            ncf_type VARCHAR(2) NOT NULL,
            seq_from BIGINT UNSIGNED NOT NULL DEFAULT 1,
            seq_to BIGINT UNSIGNED NOT NULL DEFAULT 0,
            seq_next BIGINT UNSIGNED NOT NULL DEFAULT 1,
            expiration DATE NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            note VARCHAR(190) NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            INDEX idx_ncf_type (prefix, ncf_type, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            quote_id INT UNSIGNED NULL,
            invoice_number VARCHAR(40) NOT NULL UNIQUE,
            ncf VARCHAR(19) NULL,
            ncf_type VARCHAR(2) NOT NULL DEFAULT '02',
            ncf_prefix VARCHAR(2) NOT NULL DEFAULT 'B',
            is_ecf TINYINT(1) NOT NULL DEFAULT 0,
            ecf_status VARCHAR(30) NULL,
            ecf_track_id VARCHAR(60) NULL,
            ecf_security_code VARCHAR(20) NULL,
            ecf_sign_date DATETIME NULL,
            ecf_qr_url TEXT NULL,
            ecf_xml MEDIUMTEXT NULL,
            ecf_response TEXT NULL,
            ncf_expiration DATE NULL,
            modifies_ncf VARCHAR(19) NULL,
            modifies_invoice_id INT UNSIGNED NULL,
            title VARCHAR(190) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Borrador',
            payment_condition VARCHAR(20) NOT NULL DEFAULT 'Contado',
            payment_method VARCHAR(40) NULL,
            issue_date DATE NULL,
            due_date DATE NULL,
            taxed_base DECIMAL(12,2) NOT NULL DEFAULT 0,
            exempt_base DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            tax_rate DECIMAL(5,2) NOT NULL DEFAULT 18,
            tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            isc_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            itbis_retained DECIMAL(12,2) NOT NULL DEFAULT 0,
            isr_retained DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(3) NOT NULL DEFAULT 'DOP',
            exchange_rate DECIMAL(12,4) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            terms TEXT NULL,
            client_name VARCHAR(190) NULL,
            client_rnc VARCHAR(40) NULL,
            client_address TEXT NULL,
            created_by INT UNSIGNED NULL,
            emitted_at DATETIME NULL,
            paid_at DATETIME NULL,
            voided_at DATETIME NULL,
            void_reason VARCHAR(255) NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            INDEX idx_invoices_status (status),
            INDEX idx_invoices_client (client_id),
            INDEX idx_invoices_due (due_date),
            INDEX idx_invoices_ncf (ncf)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT UNSIGNED NOT NULL,
            description TEXT NOT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            is_exempt TINYINT(1) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            method VARCHAR(40) NULL,
            reference VARCHAR(120) NULL,
            paid_at DATE NULL,
            note VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NULL,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // e-CF columns — added idempotently to databases provisioned before the
        // electronic comprobantes existed. They sit ready for manual capture now
        // and for the DGII e-CF transmission integration later.
        $ecfColumns = [
            'is_ecf' => "ALTER TABLE invoices ADD COLUMN is_ecf TINYINT(1) NOT NULL DEFAULT 0 AFTER ncf_prefix",
            'ecf_status' => "ALTER TABLE invoices ADD COLUMN ecf_status VARCHAR(30) NULL AFTER is_ecf",
            'ecf_track_id' => "ALTER TABLE invoices ADD COLUMN ecf_track_id VARCHAR(60) NULL AFTER ecf_status",
            'ecf_security_code' => "ALTER TABLE invoices ADD COLUMN ecf_security_code VARCHAR(20) NULL AFTER ecf_track_id",
            'ecf_sign_date' => "ALTER TABLE invoices ADD COLUMN ecf_sign_date DATETIME NULL AFTER ecf_security_code",
            'ecf_qr_url' => "ALTER TABLE invoices ADD COLUMN ecf_qr_url TEXT NULL AFTER ecf_sign_date",
            'ecf_xml' => "ALTER TABLE invoices ADD COLUMN ecf_xml MEDIUMTEXT NULL AFTER ecf_qr_url",
            'ecf_response' => "ALTER TABLE invoices ADD COLUMN ecf_response TEXT NULL AFTER ecf_xml",
        ];
        foreach ($ecfColumns as $col => $sql) {
            if (!column_exists('invoices', $col)) {
                try { $pdo->exec($sql); } catch (Throwable) { /* ignore */ }
            }
        }
    } catch (Throwable) {
        /* ignore: best-effort provisioning */
    }
}

function client_support_access(array $client, bool $persist = true): array
{
    $slug = trim((string) ($client['support_slug'] ?? ''));
    $token = trim((string) ($client['support_token'] ?? ''));

    if ($slug === '') {
        $slug = slugify((string) ($client['name'] ?? 'cliente')) . '-' . (int) ($client['id'] ?? 0);
    }

    if ($token === '') {
        $token = bin2hex(random_bytes(16));
    }

    if ($persist && (empty($client['support_slug']) || empty($client['support_token'])) && db(false) && table_exists('clients') && column_exists('clients', 'support_slug')) {
        db()->prepare('UPDATE clients SET support_slug=?, support_token=?, support_enabled=1, updated_at=NOW() WHERE id=?')
            ->execute([$slug, $token, (int) $client['id']]);
    }

    return ['slug' => $slug, 'token' => $token];
}

function client_support_url(array $client): string
{
    $access = client_support_access($client);
    return url('helpdesk.php?cliente=' . rawurlencode($access['slug']) . '&key=' . rawurlencode($access['token']));
}

function fetch_all(string $sql, array $params = []): array
{
    $pdo = db(false);
    if (!$pdo) {
        return [];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetch_one(string $sql, array $params = []): ?array
{
    $pdo = db(false);
    if (!$pdo) {
        return null;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function db_count(string $table, string $where = '1=1', array $params = []): int
{
    $pdo = db(false);
    if (!$pdo) {
        return 0;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function status_class(string $status): string
{
    return match (strtolower($status)) {
        'abierto', 'pendiente', 'borrador', 'nuevo', 'requiere revision', 'requiere revisión' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
        'en proceso', 'enviado', 'cotizado', 'contactado', 'prospecto' => 'bg-blue-50 text-blue-700 ring-1 ring-blue-200',
        'aprobado', 'resuelto', 'activo', 'convertido' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
        'negociacion', 'negociación' => 'bg-amber-100 text-amber-800 ring-1 ring-amber-300',
        'cerrado', 'inactivo', 'rechazado', 'descartado', 'retirado', 'demo' => 'bg-slate-100 text-slate-600 ring-1 ring-slate-200',
        'critico', 'crítico', 'alta', 'vencido', 'vencida', 'fuera de servicio' => 'bg-red-50 text-red-700 ring-1 ring-red-200',
        default => 'bg-slate-100 text-slate-700 ring-1 ring-slate-200',
    };
}

/** Sentence-case label for a status value (storage stays lowercase). */
function status_label(?string $status): string
{
    $s = trim((string) $status);
    return $s === '' ? '—' : mb_strtoupper(mb_substr($s, 0, 1), 'UTF-8') . mb_substr($s, 1);
}

/**
 * Append a row to the activity_log audit trail. Never throws — logging must
 * not break the mutation it records. user_id is null for public/portal events.
 */
function log_activity(string $entityType, ?int $entityId, string $action, ?string $details = null): void
{
    if (!db(false) || !table_exists('activity_log')) {
        return;
    }
    try {
        db()->prepare('INSERT INTO activity_log (user_id, entity_type, entity_id, action, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([current_user()['id'] ?? null, $entityType, $entityId, $action, $details]);
    } catch (Throwable) {
        /* swallow: auditing is best-effort */
    }
}

/** Recent activity-log rows joined to the actor name (optionally scoped to an entity). */
function activity_recent(int $limit = 10, ?string $entityType = null, ?int $entityId = null): array
{
    if (!db(false) || !table_exists('activity_log')) {
        return [];
    }
    $where = '1=1';
    $params = [];
    if ($entityType !== null) { $where .= ' AND a.entity_type = ?'; $params[] = $entityType; }
    if ($entityId !== null) { $where .= ' AND a.entity_id = ?'; $params[] = $entityId; }
    $limit = max(1, min(50, $limit));
    $userJoin = table_exists('users') ? 'LEFT JOIN users u ON u.id = a.user_id' : '';
    $userCol = table_exists('users') ? 'u.name AS actor' : 'NULL AS actor';
    return fetch_all("SELECT a.*, {$userCol} FROM activity_log a {$userJoin} WHERE {$where} ORDER BY a.created_at DESC, a.id DESC LIMIT {$limit}", $params);
}

function priority_class(string $priority): string
{
    return match (strtolower($priority)) {
        'critica', 'alta' => 'bg-red-50 text-red-700 ring-1 ring-red-200',
        'media' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
        default => 'bg-slate-100 text-slate-700 ring-1 ring-slate-200',
    };
}

function image_alt(string $file, string $fallback): string
{
    $name = pathinfo($file, PATHINFO_FILENAME);
    $name = str_replace(['-', '_'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name) !== '' ? 'SCH MEDICOS: ' . trim($name) : $fallback;
}

/**
 * Official SCH brand lockup. Single source of truth for the logo.
 * Variants: 'public' (header), 'footer', 'crm', 'login'.
 */
/** The brand wordmark text shown in headers, footer, login and the CRM. */
function brand_wordmark(): string
{
    return 'Servicios para Clínicas y Hospitales';
}

function brand_lock(string $variant = 'public'): string
{
    $logo = asset(APP_LOGO);
    $home = url('index.php');
    $word = brand_wordmark();

    if ($variant === 'crm') {
        return '<a href="' . url('crm/index.php') . '" class="crm-wordmark" aria-label="' . e(APP_NAME) . ' CRM, inicio">'
            . '<span class="crm-wordmark__plaque"><img src="' . $logo . '" alt="" width="200" height="182"></span>'
            . '<b>' . e($word) . '</b></a>';
    }

    if ($variant === 'login') {
        return '<span class="login-card__brand">'
            . '<img src="' . $logo . '" alt="' . e(APP_NAME) . '" width="200" height="182">'
            . '<strong>' . e($word) . '</strong></span>';
    }

    if ($variant === 'footer') {
        return '<a href="' . $home . '" class="sch-brand sch-brand--light" aria-label="' . e(APP_NAME) . ' inicio">'
            . '<span class="sch-brand__plaque"><img src="' . $logo . '" alt="" width="200" height="182"></span>'
            . '<span class="sch-brand__text"><strong>' . e($word) . '</strong></span></a>';
    }

    // public header
    return '<a href="' . $home . '" class="sch-brand" aria-label="' . e(APP_NAME) . ' inicio">'
        . '<img class="sch-brand__mark" src="' . $logo . '" alt="' . e(APP_NAME) . '" width="200" height="182">'
        . '<span class="sch-brand__text"><strong>' . e($word) . '</strong></span>'
        . '<span class="sch-brand__since"><b>DESDE</b>' . e(APP_FOUNDED) . '</span></a>';
}
