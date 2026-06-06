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
function brand_lock(string $variant = 'public'): string
{
    $logo = asset(APP_LOGO);
    $home = url('index.php');

    if ($variant === 'crm') {
        return '<a href="' . url('crm/index.php') . '" class="crm-wordmark" aria-label="' . e(APP_NAME) . ' CRM, inicio">'
            . '<span class="crm-wordmark__plaque"><img src="' . $logo . '" alt="" width="200" height="182"></span>'
            . '<b>SCH <span>MEDICOS</span></b></a>';
    }

    if ($variant === 'login') {
        return '<span class="login-card__brand">'
            . '<img src="' . $logo . '" alt="' . e(APP_NAME) . '" width="200" height="182">'
            . '<strong>SCH MEDICOS</strong></span>';
    }

    if ($variant === 'footer') {
        return '<a href="' . $home . '" class="sch-brand sch-brand--light" aria-label="' . e(APP_NAME) . ' inicio">'
            . '<span class="sch-brand__plaque"><img src="' . $logo . '" alt="" width="200" height="182"></span>'
            . '<span class="sch-brand__text"><strong>SCH MEDICOS</strong><small>' . e(APP_TAGLINE) . '</small></span></a>';
    }

    // public header
    return '<a href="' . $home . '" class="sch-brand" aria-label="' . e(APP_NAME) . ' inicio">'
        . '<img class="sch-brand__mark" src="' . $logo . '" alt="' . e(APP_NAME) . '" width="200" height="182">'
        . '<span class="sch-brand__text"><strong>SCH MEDICOS</strong><small>' . e(APP_TAGLINE) . '</small></span>'
        . '<span class="sch-brand__since"><b>DESDE</b>' . e(APP_FOUNDED) . '</span></a>';
}
