<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float|int|string|null $value): string
{
    // Espacio duro: «RD$» y su cifra no se separan al final de una línea.
    return "RD\$ " . number_format((float) $value, 2, '.', ',');
}

/**
 * Evita que una palabra de una sola letra quede sola al final de una línea.
 *
 * En español «y», «o», «a», «e» y «u» son palabras completas, y partir la
 * línea justo después deja una letra huérfana que se lee como un error de
 * maquetación. Se atan a la siguiente con espacio duro.
 */
function sin_viudas(string $texto): string
{
    return (string) preg_replace('/(^|\s)([yoaeuYOAEU])\s+/u', '$1$2' . "\u{00A0}", $texto);
}

/** Currency-aware money formatter (DOP -> RD$, USD -> US$). */
function money_cur(float|int|string|null $value, string $currency = 'DOP'): string
{
    $sym = strtoupper($currency) === 'USD' ? 'US$' : 'RD$';
    return $sym . " " . number_format((float) $value, 2, '.', ',');
}

/**
 * Lee un monto tecleado a mano tolerando el formato con el que la app lo imprime
 * ("1,601.70") y los descuidos habituales al escribirlo. `(float) "1,601.70"` en
 * PHP devuelve 1 —de ahí que los precios grandes quedaran truncados—, así que
 * todo campo de dinero/cantidad del CRM pasa por aquí en vez de por un cast.
 *
 * Reglas, iguales a las de crmParseAmount() en assets/js/app.js:
 *  - Si vienen punto y coma, manda el que esté más a la derecha como decimal y el
 *    otro es separador de miles ("1.601,70" y "1,601.70" dan lo mismo).
 *  - Un separador repetido siempre es de miles ("1.601.700" → 1601700).
 *  - Una coma sola seguida de exactamente 3 dígitos es de miles ("1,500" → 1500);
 *    con cualquier otra cantidad de dígitos es decimal ("1,5" → 1.5).
 *  - Un punto solo siempre es decimal, que es como la app muestra los importes.
 */
function amount_parse(mixed $value, float $default = 0.0): float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }
    $raw = (string) $value;
    // Espacios de agrupación (incluye NBSP y el fino), apóstrofo suizo y símbolos.
    $raw = str_replace(["Â ", "â¯", ' ', "'"], '', $raw);
    $negative = str_contains($raw, '-');
    $s = preg_replace('/[^0-9.,]/', '', $raw) ?? '';
    if ($s === '' || !preg_match('/[0-9]/', $s)) {
        return $default;
    }

    $dot = strrpos($s, '.');
    $comma = strrpos($s, ',');
    if ($dot !== false && $comma !== false) {
        $decimal = $dot > $comma ? '.' : ',';
        $s = str_replace($decimal === '.' ? ',' : '.', '', $s);
        $s = str_replace($decimal, '.', $s);
    } elseif ($dot !== false || $comma !== false) {
        $sep = $dot !== false ? '.' : ',';
        $tail = strlen($s) - strrpos($s, $sep) - 1;
        $isThousands = substr_count($s, $sep) > 1
            || ($sep === ',' && $tail === 3 && strrpos($s, $sep) > 0);
        $s = $isThousands ? str_replace($sep, '', $s) : str_replace($sep, '.', $s);
    }

    $n = (float) $s;
    return $negative ? -$n : $n;
}

/**
 * Reparte el descuento único del documento entre sus partidas, en proporción al
 * importe bruto de cada una. Se captura una sola vez (en % o en monto) pero se
 * guarda prorrateado por línea para que las bases gravada y exenta, el ITBIS y
 * los reportes sigan cuadrando sin tratar el descuento como un caso aparte.
 *
 * Cada fila entra con 'total' = importe bruto y sale con 'discount' asignado y
 * 'total' ya neto. El céntimo de redondeo cae en la última línea con importe.
 *
 * @param array<int, array<string, mixed>> $items
 * @return array{items: array<int, array<string, mixed>>, amount: float, pct: float}
 */
function distribute_discount(array $items, float $value, string $mode): array
{
    $gross = 0.0;
    foreach ($items as $item) {
        $gross += (float) ($item['total'] ?? 0);
    }

    $pct = 0.0;
    $amount = 0.0;
    if ($gross > 0 && $value > 0) {
        if ($mode === 'pct') {
            $pct = min(100.0, $value);
            $amount = round($gross * $pct / 100, 2);
        } else {
            $amount = round(min($gross, $value), 2);
        }
    }

    if ($amount <= 0) {
        foreach ($items as $i => $item) {
            $items[$i]['discount'] = 0.0;
            $items[$i]['discount_pct'] = 0.0;
            $items[$i]['total'] = round((float) ($item['total'] ?? 0), 2);
        }
        return ['items' => $items, 'amount' => 0.0, 'pct' => 0.0];
    }

    $lastIndex = null;
    foreach ($items as $i => $item) {
        if ((float) ($item['total'] ?? 0) > 0) { $lastIndex = $i; }
    }

    $assigned = 0.0;
    foreach ($items as $i => $item) {
        $lineGross = round((float) ($item['total'] ?? 0), 2);
        $lineDisc = $i === $lastIndex
            ? round($amount - $assigned, 2)
            : round($amount * $lineGross / $gross, 2);
        $lineDisc = max(0.0, min($lineGross, $lineDisc));
        $assigned += $lineDisc;
        $items[$i]['discount'] = $lineDisc;
        $items[$i]['discount_pct'] = $pct;
        $items[$i]['total'] = round($lineGross - $lineDisc, 2);
    }

    return ['items' => $items, 'amount' => round($assigned, 2), 'pct' => round($pct, 3)];
}

/** Etiqueta corta del descuento del documento: "− 10%" o "− RD$ 1,000.00". */
function discount_label(float $amount, float $pct, string $currency = 'DOP'): string
{
    if ($pct > 0) {
        return '− ' . rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '%';
    }
    return '− ' . money_cur($amount, $currency);
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $token = $_POST['csrf'] ?? '';
    if (hash_equals(csrf_token(), (string) $token)) {
        return;
    }

    /*
     * Esto le pasa a gente normal: dejas la pestaña abierta durante el
     * almuerzo, vuelves y pulsas «Guardar». Antes salían ocho palabras en
     * texto plano sobre fondo blanco, sin marca, sin explicación y sin salida.
     * El token expirado no es un ataque en el 99% de los casos, es una sesión
     * vieja, y la pantalla tiene que decir qué pasó y cómo seguir.
     */
    /* 419 no es un código estándar y Apache lo convierte en 500, que le diría
       al navegador y a los registros que el servidor falló. No falló: la
       petición no está autorizada. */
    http_response_code(403);
    $volver = e((string) ($_SERVER['HTTP_REFERER'] ?? url('crm/index.php')));
    $entrar = e(url('crm/login.php'));
    echo <<<HTML
<!doctype html>
<html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sesión vencida | CRM SCH MEDICOS</title>
<style>
  :root { color-scheme: light dark; }
  body { margin:0; min-height:100vh; display:grid; place-items:center; padding:1.5rem;
         background:#F6F8F7; color:#0F1B14;
         font-family:Aptos,"Segoe UI",system-ui,Arial,sans-serif; }
  .caja { max-width:30rem; padding:2rem 1.9rem; border:1px solid #E1E6E9; border-radius:18px;
          background:#fff; box-shadow:0 1px 2px rgba(15,27,20,.04),0 10px 28px -18px rgba(15,27,20,.22); }
  h1 { margin:.6rem 0 .5rem; font-size:1.3rem; font-weight:650; letter-spacing:-.028em; }
  p { margin:0 0 1.2rem; color:#5B6B62; font-size:.92rem; line-height:1.6; }
  .ic { display:grid; place-items:center; width:42px; height:42px; border-radius:12px;
        background:#F8F3E7; color:#7A6320; font-size:1.3rem; }
  .acc { display:flex; gap:.6rem; flex-wrap:wrap; }
  a { display:inline-flex; align-items:center; min-height:40px; padding:0 1.05rem; border-radius:11px;
      font-size:.9rem; font-weight:600; text-decoration:none; }
  .p { background:#027F31; color:#fff; border:1px solid #027F31; }
  .s { background:#fff; color:#0F1B14; border:1px solid #E1E6E9; }
  a:focus-visible { outline:2px solid #027F31; outline-offset:2px; }
  @media (prefers-color-scheme: dark) {
    body { background:#0F1712; color:#E9F0EB; }
    .caja { background:#141D18; border-color:#2F3E36; }
    p { color:#B9C7BE; }
    .ic { background:#2E2716; color:#D4B872; }
    .s { background:#1B2620; color:#E9F0EB; border-color:#2F3E36; }
  }
</style></head>
<body>
  <main class="caja">
    <span class="ic" aria-hidden="true">!</span>
    <h1>Tu sesión venció</h1>
    <p>Por seguridad el CRM caduca las sesiones inactivas, así que no se guardó
       lo que acabas de enviar. Vuelve a entrar y repítelo: los datos anteriores
       siguen intactos.</p>
    <div class="acc">
      <a class="p" href="{$entrar}">Volver a entrar</a>
      <a class="s" href="{$volver}">Regresar</a>
    </div>
  </main>
</body></html>
HTML;
    exit;
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

/**
 * ¿Existe la tabla? ¿Y la columna?
 *
 * Se pregunta muchísimo: solo en includes/ hay más de ochenta llamadas, y
 * varias dentro de bucles. Cada una iba a information_schema, que en MySQL 8
 * es de las consultas más caras que hay porque abre las definiciones de tabla.
 * El panel hacía 192 consultas y buena parte eran la misma pregunta repetida.
 *
 * Se recuerda SOLO la respuesta afirmativa, igual que index_exists(): una
 * tabla que existe no desaparece a mitad de la petición, pero una que no
 * existe SÍ puede crearla un ensure_*_schema() un momento después, y cachear
 * ese «no» dejaría al CRM creyendo que le falta media base.
 */
function table_exists(string $table): bool
{
    static $si = [];
    if (isset($si[$table])) {
        return true;
    }
    $pdo = db(false);
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $hay = (int) $stmt->fetchColumn() > 0;
        if ($hay) { $si[$table] = true; }
        return $hay;
    } catch (Throwable) {
        return false;
    }
}

function column_exists(string $table, string $column): bool
{
    static $si = [];
    $k = $table . '.' . $column;
    if (isset($si[$k])) {
        return true;
    }
    $pdo = db(false);
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        $hay = (int) $stmt->fetchColumn() > 0;
        if ($hay) { $si[$k] = true; }
        return $hay;
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
        'discount_amount' => "ALTER TABLE quotes ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER valid_until",
        // El descuento es uno solo para todo el documento y puede teclearse en %:
        // se guarda el porcentaje además del monto para reabrir la cotización en
        // el mismo modo e imprimir "Descuento − 10%" en vez del monto resuelto.
        'discount_pct' => "ALTER TABLE quotes ADD COLUMN discount_pct DECIMAL(6,3) NOT NULL DEFAULT 0 AFTER discount_amount",
    ];

    foreach ($columns as $column => $statement) {
        if (!column_exists('quotes', $column)) {
            try { $pdo->exec($statement); } catch (Throwable) { /* ignore */ }
        }
    }

    // Descuento por partida (el encabezado sólo guarda la suma, informativa).
    if (table_exists('quote_items') && !column_exists('quote_items', 'discount')) {
        try { $pdo->exec("ALTER TABLE quote_items ADD COLUMN discount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit_price"); } catch (Throwable) { /* ignore */ }
    }

    // `discount` guarda la parte del descuento del documento que le tocó a esta
    // partida al prorratearlo, y `discount_pct` el porcentaje del documento cuando
    // se capturó así. Ninguna de las dos se teclea por línea desde v3.25.
    if (table_exists('quote_items') && !column_exists('quote_items', 'discount_pct')) {
        try { $pdo->exec("ALTER TABLE quote_items ADD COLUMN discount_pct DECIMAL(6,3) NOT NULL DEFAULT 0 AFTER discount"); } catch (Throwable) { /* ignore */ }
    }

    // Anexo fotográfico de la cotización.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS quote_attachments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            quote_id INT UNSIGNED NOT NULL,
            file VARCHAR(190) NOT NULL,
            original_name VARCHAR(190) NULL,
            mime VARCHAR(60) NULL,
            size INT UNSIGNED NOT NULL DEFAULT 0,
            width SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            height SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            caption VARCHAR(190) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NULL,
            FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
            INDEX idx_quote_attachments (quote_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable) { /* ignore */ }

    ensure_settings_schema();
}

/* =========================================================================
   ANEXO FOTOGRÁFICO DE COTIZACIONES
   Las fotos se normalizan a JPEG al subirlas: se corrige la orientación EXIF,
   se limita el lado mayor y se re-codifica. Re-codificar descarta metadatos y
   cualquier carga incrustada en el archivo original, y deja un formato que
   dompdf siempre sabe incrustar (WEBP no lo soporta de forma fiable).
   ========================================================================= */

const QUOTE_PHOTO_MAX_BYTES = 12582912;   // 12 MB por archivo
const QUOTE_PHOTO_MAX_SIDE = 1600;        // lado mayor almacenado
const QUOTE_PHOTO_MAX_COUNT = 40;         // tope por cotización

/** Convierte valores de php.ini tipo "40M" / "512K" a bytes. */
function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') { return 0; }
    $unit = strtolower($value[strlen($value) - 1]);
    $n = (int) $value;
    return match ($unit) {
        'g' => $n * 1024 * 1024 * 1024,
        'm' => $n * 1024 * 1024,
        'k' => $n * 1024,
        default => $n,
    };
}

/**
 * Cuánto acepta este servidor en una sola carga. Manda el menor entre
 * post_max_size y upload_max_filesize; superar post_max_size vacía $_POST.
 */
function upload_limit_bytes(): int
{
    $post = ini_bytes((string) ini_get('post_max_size'));
    $file = ini_bytes((string) ini_get('upload_max_filesize'));
    $limits = array_filter([$post, $file]);
    return $limits ? (int) min($limits) : 8 * 1024 * 1024;
}

/** El mismo límite, ya redactado para mostrarlo ("40 MB"). */
function upload_limit_label(): string
{
    return number_format(upload_limit_bytes() / (1024 * 1024), 0) . ' MB';
}

/** Carpeta física de las fotos de una cotización (se crea si falta). */
function quote_photos_dir(int $quoteId, bool $create = false): string
{
    $dir = dirname(__DIR__) . '/uploads/cotizaciones/' . $quoteId;
    if ($create && !is_dir($dir)) {
        @mkdir($dir, 0775, true);
        // Defensa en profundidad: aunque la carpeta cuelgue del webroot, las
        // fotos sólo se sirven por crm/adjunto.php, que exige sesión.
        $root = dirname(__DIR__) . '/uploads';
        if (!is_file($root . '/.htaccess')) {
            @file_put_contents($root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (!is_file($root . '/index.php')) {
            @file_put_contents($root . '/index.php', "<?php http_response_code(404); exit;\n");
        }
    }
    return $dir;
}

/** Ruta absoluta de un adjunto, o null si el registro apunta a un archivo ausente. */
function quote_photo_path(array $attachment): ?string
{
    $file = basename((string) ($attachment['file'] ?? ''));
    if ($file === '') { return null; }
    $path = quote_photos_dir((int) ($attachment['quote_id'] ?? 0)) . '/' . $file;
    return is_file($path) ? $path : null;
}

/** Rota según la orientación EXIF; devuelve la imagen (posiblemente nueva). */
function image_apply_exif_orientation(GdImage $img, string $path): GdImage
{
    if (!function_exists('exif_read_data')) { return $img; }
    $exif = @exif_read_data($path);
    $orientation = (int) ($exif['Orientation'] ?? 0);
    $angle = match ($orientation) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
    if ($angle === 0) { return $img; }
    $rotated = @imagerotate($img, $angle, 0);
    if ($rotated instanceof GdImage) {
        imagedestroy($img);
        return $rotated;
    }
    return $img;
}

/** Reescala manteniendo proporción si excede $maxSide. Devuelve la imagen a usar. */
function image_scale_to_max(GdImage $img, int $maxSide): GdImage
{
    $w = imagesx($img);
    $h = imagesy($img);
    $long = max($w, $h);
    if ($long <= $maxSide) { return $img; }
    $ratio = $maxSide / $long;
    $nw = max(1, (int) round($w * $ratio));
    $nh = max(1, (int) round($h * $ratio));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);
    return $dst;
}

/** Carga un archivo de imagen con GD según su mime real. */
function image_load(string $path, string $mime): ?GdImage
{
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false,
    };
    return $img instanceof GdImage ? $img : null;
}

/**
 * Valida y guarda una foto subida. Devuelve los datos del archivo escrito o un
 * mensaje de error listo para mostrar al usuario.
 *
 * @return array{file:string,mime:string,size:int,width:int,height:int}|string
 */
function quote_store_photo(array $upload, int $quoteId): array|string
{
    $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return 'supera el tamaño máximo que permite el servidor';
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        return 'no se pudo recibir el archivo';
    }
    if ((int) $upload['size'] > QUOTE_PHOTO_MAX_BYTES) {
        return 'pesa más de 12 MB';
    }

    $tmp = (string) $upload['tmp_name'];
    $info = @getimagesize($tmp);
    $mime = (string) ($info['mime'] ?? '');
    if (!$info || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return 'no es una imagen JPG, PNG o WEBP válida';
    }

    $dir = quote_photos_dir($quoteId, true);
    if (!is_dir($dir) || !is_writable($dir)) {
        return 'no se pudo escribir en la carpeta de subidas';
    }

    try { $name = bin2hex(random_bytes(8)); } catch (Throwable) { $name = uniqid('f', true); }

    // Camino normal: re-codificar a JPEG con GD.
    if (extension_loaded('gd') && ($img = image_load($tmp, $mime)) !== null) {
        if ($mime === 'image/jpeg') { $img = image_apply_exif_orientation($img, $tmp); }
        $img = image_scale_to_max($img, QUOTE_PHOTO_MAX_SIDE);
        // Fondo blanco: el JPEG no tiene canal alfa y un PNG transparente
        // saldría con el fondo en negro.
        $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
        imagedestroy($img);
        $file = $name . '.jpg';
        $ok = @imagejpeg($flat, $dir . '/' . $file, 82);
        $w = imagesx($flat);
        $h = imagesy($flat);
        imagedestroy($flat);
        if (!$ok) { return 'no se pudo procesar la imagen'; }
        return ['file' => $file, 'mime' => 'image/jpeg', 'size' => (int) @filesize($dir . '/' . $file), 'width' => $w, 'height' => $h];
    }

    // Reserva sin GD: guardar el original ya validado por getimagesize().
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
    $file = $name . '.' . $ext;
    if (!@move_uploaded_file($tmp, $dir . '/' . $file)) {
        return 'no se pudo guardar el archivo';
    }
    return ['file' => $file, 'mime' => $mime, 'size' => (int) $upload['size'], 'width' => (int) $info[0], 'height' => (int) $info[1]];
}

/** Borra una foto del disco (la fila la limpia quien llama). */
function quote_delete_photo_file(array $attachment): void
{
    $path = quote_photo_path($attachment);
    if ($path !== null) { @unlink($path); }
}

/**
 * data: URI de la foto reducida al tamaño que realmente ocupa en el PDF. Sin
 * esto un anexo de 10 fotos produciría un PDF de decenas de MB.
 */
function quote_photo_data_uri(array $attachment, int $maxSide = 900): ?string
{
    $path = quote_photo_path($attachment);
    if ($path === null) { return null; }

    if (extension_loaded('gd')) {
        $img = image_load($path, (string) ($attachment['mime'] ?? 'image/jpeg'));
        if ($img !== null) {
            $img = image_scale_to_max($img, $maxSide);
            ob_start();
            $ok = @imagejpeg($img, null, 78);
            $bytes = (string) ob_get_clean();
            imagedestroy($img);
            if ($ok && $bytes !== '') {
                return 'data:image/jpeg;base64,' . base64_encode($bytes);
            }
        }
    }

    $raw = @file_get_contents($path);
    if ($raw === false) { return null; }
    return 'data:' . ($attachment['mime'] ?? 'image/jpeg') . ';base64,' . base64_encode($raw);
}

/**
 * Medidas de una foto ajustada dentro de un marco, conservando la proporción.
 * dompdf no tiene object-fit: si sólo se fija el ancho, las fotos verticales se
 * desbordan, y si se fijan ambos lados se deforman. Se calcula aquí.
 *
 * @return array{w:int,h:int}
 */
function quote_photo_fit(array $attachment, int $boxW, int $boxH): array
{
    $w = (int) ($attachment['width'] ?? 0);
    $h = (int) ($attachment['height'] ?? 0);
    if ($w <= 0 || $h <= 0) { return ['w' => $boxW, 'h' => $boxH]; }
    $scale = min($boxW / $w, $boxH / $h);
    if ($scale > 1) { $scale = 1; }   // no ampliamos fotos pequeñas
    return ['w' => max(1, (int) round($w * $scale)), 'h' => max(1, (int) round($h * $scale))];
}

/**
 * Moneda y tasa efectivas de una cotización. Devuelve ['DOP'|'USD', tasa], donde
 * la tasa es 1 para pesos y nunca menor que 1 para dólares (una tasa 0 guardada
 * por error no debe borrar el monto al convertir).
 */
function quote_currency(array $quote): array
{
    $cur = strtoupper((string) ($quote['currency'] ?? 'DOP')) === 'USD' ? 'USD' : 'DOP';
    return [$cur, $cur === 'USD' ? max(1.0, (float) ($quote['exchange_rate'] ?? 1)) : 1.0];
}

/** Total de una cotización llevado a RD$ (las de USD, con la tasa del documento). */
function quote_total_dop(array $quote): float
{
    [, $rate] = quote_currency($quote);
    return round((float) ($quote['total'] ?? 0) * $rate, 2);
}

/**
 * La misma conversión en SQL, para que los agregados (pipeline, KPIs, reportes)
 * sumen una sola moneda en carteras mixtas DOP/USD. Sin la columna `currency`
 * todos los totales ya están en pesos y la expresión se reduce a la columna.
 */
function quote_total_dop_sql(string $table = 'quotes'): string
{
    if (!column_exists('quotes', 'currency') || !column_exists('quotes', 'exchange_rate')) {
        return "{$table}.total";
    }
    return "({$table}.total * IF(UPPER({$table}.currency) = 'USD', GREATEST({$table}.exchange_rate, 1), 1))";
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
    // Force-password-change flag (set when an admin assigns a temporary password).
    if (!column_exists('users', 'must_change_password')) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Throwable) {
            /* ignore */
        }
    }
    // Lista blanca inicial de cartera (contabilidad); una sola vez.
    if (function_exists('cartera_seed_defaults')) {
        cartera_seed_defaults();
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
        . "2. Precios sujetos a cambio por variación del proveedor.\n"
        . "3. Tiempo de entrega: según disponibilidad, confirmado al recibir la orden de compra.\n"
        . "4. Forma de pago: 50% de anticipo, 50% contra entrega.\n"
        . "5. Garantía según fabricante. No incluye trabajos civiles ni eléctricos salvo indicación expresa.\n"
        . "6. Instalación y puesta en marcha por personal certificado de SERVICIOS PARA CLINICAS Y HOSPITALES.";
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

/**
 * Series ofrecidas al crear una factura. Además de las dos series fiscales,
 * «P» es la FACTURA PROFORMA: una cotización formal con formato de factura que
 * NO consume NCF, no se transmite a la DGII y no puede emitirse. No es una
 * serie de la DGII, por eso no aparece en «Secuencias NCF» (ncf_prefixes()).
 */
function invoice_series_options(): array
{
    return ncf_prefixes() + ['P' => 'P — Factura Proforma (sin valor fiscal)'];
}

/** ¿La fila de factura es una proforma (documento sin valor fiscal)? */
function invoice_is_proforma(?array $inv): bool
{
    return !empty($inv['is_proforma']);
}

/** Encabezado del documento: la proforma nunca se rotula como comprobante fiscal. */
function invoice_doc_heading(?array $inv): string
{
    return invoice_is_proforma($inv) ? 'FACTURA PROFORMA' : ncf_doc_heading((string) ($inv['ncf_type'] ?? '02'));
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

/**
 * Prioridades y estados válidos de un ticket.
 *
 * Van aquí y no dentro de la pantalla porque el orden importa fuera de ella:
 * el listado ordena con FIELD(priority,'Critica','Alta','Media','Baja') y los
 * colores se asignan por nombre. Una prioridad inventada no rompe nada de
 * golpe —simplemente el ticket se cae de todos los filtros y ordena al final,
 * que es peor: nadie lo ve.
 */
function ticket_priorities(): array
{
    return ['Critica', 'Alta', 'Media', 'Baja'];
}

function ticket_statuses(): array
{
    return ['Abierto', 'En proceso', 'Cotizado', 'Resuelto', 'Cerrado'];
}

function ticket_priority_or_default(?string $p): string
{
    $p = trim((string) $p);
    return in_array($p, ticket_priorities(), true) ? $p : 'Media';
}

function ticket_status_or_default(?string $s): string
{
    $s = trim((string) $s);
    return in_array($s, ticket_statuses(), true) ? $s : 'Abierto';
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
        . "5. Garantía de equipos según el fabricante. Instalación y certificación por personal de SERVICIOS PARA CLINICAS Y HOSPITALES.\n"
        . "6. La retención de ITBIS/ISR, cuando aplique, debe acreditarse con el comprobante de retención correspondiente.";
}

/**
 * Tramos de antigüedad de cobro ("periodo de vencimiento") que usa contabilidad.
 * Se cuentan los días transcurridos desde la fecha de vencimiento de la factura.
 */
function invoice_aging_buckets(): array
{
    return [
        'por_vencer' => 'Por vencer',
        '0-30'       => '0-30 días',
        '31-60'      => '31-60 días',
        '61-90'      => '61-90 días',
        '90+'        => '+90 días',
    ];
}

/**
 * La fecha desde la que se cuenta el atraso de una factura: con plan de cuotas,
 * la cuota más antigua sin cubrir; si no, su vencimiento, y si no tiene, la
 * emisión. Es la regla de invoice_due_sql() en PHP.
 *
 * Toda pantalla que muestre «vence el…» junto a un atraso debe usar esta fecha
 * y no due_date a secas: con un plan pactado, due_date es la fecha de la
 * factura entera y el atraso se cuenta desde la cuota, así que la fila decía
 * «vence el 15/08» y «faltan 10 días» a la vez.
 */
function invoice_effective_due(array $inv): ?string
{
    if (function_exists('installments_effective_due')) {
        $cuota = installments_effective_due($inv);
        if ($cuota !== null) {
            return $cuota;
        }
    }
    return valid_date((string) ($inv['due_date'] ?? ''))
        ?? valid_date((string) ($inv['issue_date'] ?? ''));
}

/**
 * Periodo de vencimiento de una factura: tramo, días vencidos y saldo pendiente.
 * Solo aplica a comprobantes emitidos con saldo; borradores, anuladas y saldadas
 * devuelven su propio estado para que la UI no invente antigüedad.
 * Devuelve ['key','label','days','balance','tone'].
 */
function invoice_aging(array $inv): array
{
    $status = (string) ($inv['status'] ?? '');
    $balance = invoice_balance($inv);

    if ($status === 'Borrador' || $status === '') {
        return ['key' => 'borrador', 'label' => 'Sin emitir', 'days' => null, 'balance' => $balance, 'tone' => 'muted'];
    }
    if ($status === 'Anulada') {
        return ['key' => 'anulada', 'label' => 'Anulada', 'days' => null, 'balance' => 0.0, 'tone' => 'muted'];
    }
    // Saldo negativo: el cliente pagó más de lo que terminó debiendo — pasa
    // cuando se acredita una factura ya cobrada. No es una cuenta por cobrar,
    // es una por devolver, y llamarla «saldada» escondería ese dinero.
    if ($balance <= -0.009) {
        return ['key' => 'a_favor', 'label' => 'Saldo a favor del cliente', 'days' => null, 'balance' => $balance, 'tone' => 'warn'];
    }
    if ($balance <= 0.009) {
        return ['key' => 'saldada', 'label' => 'Saldada', 'days' => null, 'balance' => 0.0, 'tone' => 'ok'];
    }

    $ref = invoice_effective_due($inv);
    if ($ref === null) {
        return ['key' => 'na', 'label' => 'Sin fecha', 'days' => null, 'balance' => $balance, 'tone' => 'muted'];
    }

    $days = (int) floor((strtotime(date('Y-m-d')) - strtotime($ref)) / 86400);
    if ($days < 0)      { $key = 'por_vencer'; $tone = 'ok'; }
    elseif ($days <= 30) { $key = '0-30';  $tone = 'warn'; }
    elseif ($days <= 60) { $key = '31-60'; $tone = 'warn'; }
    elseif ($days <= 90) { $key = '61-90'; $tone = 'bad'; }
    else                 { $key = '90+';   $tone = 'bad'; }

    return [
        'key' => $key,
        'label' => invoice_aging_buckets()[$key],
        'days' => $days,
        'balance' => $balance,
        'tone' => $tone,
    ];
}

/** Texto largo del periodo de vencimiento (documento y detalle). */
function invoice_aging_text(array $inv): string
{
    $a = invoice_aging($inv);
    if ($a['days'] === null) {
        return $a['label'];
    }
    $d = (int) $a['days'];
    if ($a['key'] === 'por_vencer') {
        return $a['label'] . ' (faltan ' . abs($d) . ' día' . (abs($d) === 1 ? '' : 's') . ')';
    }
    if ($d === 0) {
        return $a['label'] . ' (vence hoy)';
    }
    return $a['label'] . ' (' . $d . ' día' . ($d === 1 ? '' : 's') . ' de vencida)';
}

/**
 * Expresión SQL de la fecha que manda para el cobro: el vencimiento real, o la
 * emisión cuando no hay vencimiento. Se evalúa con YEAR() en lugar de comparar
 * contra el literal '0000-00-00' porque en MySQL estricto (NO_ZERO_DATE, el
 * modo de producción) ese literal hace fallar la consulta al prepararla.
 */
function invoice_due_sql(string $table = 'invoices'): string
{
    $base = "IF({$table}.due_date IS NULL OR YEAR({$table}.due_date) = 0, {$table}.issue_date, {$table}.due_date)";
    /* Con plan de cuotas manda la cuota más antigua sin cubrir, no el
       vencimiento de la factura entera. Sin esto, pactar tres cuotas sobre una
       factura vencida la dejaba «60 días vencida» en la cartera y en el
       recordatorio, reclamándole al cliente dinero que aún no toca pagar.
       Es la única fuente del vencimiento, así que cartera, vencidas,
       recordatorios y analítica lo heredan sin tocarlos. */
    if (!function_exists('installments_available') || !installments_available()) {
        return $base;
    }
    return "COALESCE(CASE WHEN {$table}.installment_base IS NOT NULL THEN "
        . "(SELECT MIN(ii.due_date) FROM invoice_installments ii WHERE ii.invoice_id = {$table}.id "
        . "AND ii.cumulative > {$table}.amount_paid - {$table}.installment_base + 0.009) END, {$base})";
}

/**
 * Importe NETO exigible de un comprobante, en su propia moneda: el total menos
 * lo que el cliente nunca va a desembolsar — las retenciones que practica y las
 * notas de crédito ya emitidas contra él.
 *
 * Fuente única de la fórmula. Antes vivía copiada en seis consultas distintas,
 * que es exactamente como una de ellas se queda atrás cuando la regla cambia.
 */
function invoice_net_sql(string $table = 'invoices'): string
{
    $credited = column_exists('invoices', 'credited_amount') ? " - {$table}.credited_amount" : '';
    $adjusted = column_exists('invoices', 'balance_adjustment') ? " - {$table}.balance_adjustment" : '';
    return "({$table}.total - {$table}.itbis_retained - {$table}.isr_retained{$credited}{$adjusted})";
}

/** Saldo vivo: lo exigible menos lo ya abonado. */
function invoice_balance_sql(string $table = 'invoices'): string
{
    return '(' . invoice_net_sql($table) . " - {$table}.amount_paid)";
}

/** Equivalente en PHP de invoice_net_sql(), sobre una fila ya cargada. */
function invoice_net(array $inv): float
{
    return round(
        (float) ($inv['total'] ?? 0)
        - (float) ($inv['itbis_retained'] ?? 0)
        - (float) ($inv['isr_retained'] ?? 0)
        - (float) ($inv['credited_amount'] ?? 0)
        - (float) ($inv['balance_adjustment'] ?? 0),
        2
    );
}

/** Equivalente en PHP de invoice_balance_sql(). */
function invoice_balance(array $inv): float
{
    return round(invoice_net($inv) - (float) ($inv['amount_paid'] ?? 0), 2);
}

/** Tipos de comprobante que son nota de crédito (serie vigente y electrónica). */
function ncf_credit_note_types(): array
{
    return ['04', '34'];
}

/**
 * ¿Es este comprobante una nota de crédito?
 *
 * Importa para la cartera: una nota de crédito nace con "saldo" porque nadie la
 * paga nunca — es un crédito a favor del cliente, no algo que se le cobre.
 * Contarla como cuenta por cobrar infla la cartera y ensucia la antigüedad, así
 * que queda fuera de todo cálculo de cobranza. (Aplicarla contra la factura que
 * modifica es otra función que el CRM todavía no tiene.)
 */
function invoice_is_credit_note(array $inv): bool
{
    return in_array((string) ($inv['ncf_type'] ?? ''), ncf_credit_note_types(), true);
}

/** Fragmento SQL equivalente a invoice_is_credit_note(), negado. */
function invoice_not_credit_note_sql(string $table = 'invoices'): string
{
    return "{$table}.ncf_type NOT IN ('04','34')";
}

/**
 * Condición SQL de «esto es cobrable». Fuente ÚNICA de la cartera, para que el
 * resumen por antigüedad, los recordatorios de pago, el estado de cuenta y los
 * reportes no puedan dar cifras distintas del mismo saldo.
 *
 * Un comprobante forma cartera si está emitido, tiene saldo vivo, y además:
 *   · no es proforma  — no tiene valor fiscal ni NCF, es solo una oferta;
 *   · no es nota de crédito — es un crédito A FAVOR del cliente, no una deuda.
 */
function invoice_receivable_sql(string $table = 'invoices'): string
{
    $cond = "{$table}.status = 'Emitida' AND " . invoice_not_credit_note_sql($table);
    if (column_exists('invoices', 'is_proforma')) {
        $cond .= " AND {$table}.is_proforma = 0";
    }
    return $cond . ' AND ' . invoice_balance_sql($table) . ' > 0.009';
}

/**
 * Tasa SQL del comprobante para llevar cualquier importe suyo a RD$ (1 en pesos).
 * Es el equivalente en consulta de la conversión que hace receivables_aging(),
 * para que los KPIs no sumen dólares y pesos como si fueran la misma moneda.
 */
function invoice_rate_sql(string $table = 'invoices'): string
{
    if (!column_exists('invoices', 'currency') || !column_exists('invoices', 'exchange_rate')) {
        return '1';
    }
    return "IF(UPPER({$table}.currency) = 'USD', GREATEST({$table}.exchange_rate, 1), 1)";
}

/**
 * Cartera por antigüedad: facturas emitidas con saldo, clasificadas en tramos.
 * Fuente única del resumen en pantalla, del PDF y del export; los importes se
 * expresan en RD$ (las facturas en USD se convierten con la tasa del comprobante).
 * Devuelve ['buckets' => [key => ['label','count','amount','rows']], 'total' => [...]].
 */
function receivables_aging(): array
{
    $buckets = [];
    foreach (invoice_aging_buckets() as $k => $label) {
        $buckets[$k] = ['label' => $label, 'count' => 0, 'amount' => 0.0, 'rows' => []];
    }
    $total = ['count' => 0, 'amount' => 0.0];
    if (!db(false) || !table_exists('invoices')) {
        return ['buckets' => $buckets, 'total' => $total];
    }

    $rows = fetch_all(
        'SELECT invoices.*, clients.name AS c_name
         FROM invoices LEFT JOIN clients ON clients.id = invoices.client_id
         WHERE ' . invoice_receivable_sql() . '
         ORDER BY ' . invoice_due_sql() . ' ASC, invoices.id ASC'
    );
    foreach ($rows as $r) {
        $a = invoice_aging($r);
        if (!isset($buckets[$a['key']])) {
            continue; // saldadas/anuladas no forman cartera
        }
        $rate = strtoupper((string) ($r['currency'] ?? 'DOP')) === 'USD' ? max(1.0, (float) ($r['exchange_rate'] ?? 1)) : 1.0;
        $dop = round((float) $a['balance'] * $rate, 2);
        $r['aging'] = $a;
        $r['balance_dop'] = $dop;
        $r['due_effective'] = invoice_effective_due($r);
        $buckets[$a['key']]['rows'][] = $r;
        $buckets[$a['key']]['count']++;
        $buckets[$a['key']]['amount'] += $dop;
        $total['count']++;
        $total['amount'] += $dop;
    }
    return ['buckets' => $buckets, 'total' => $total];
}

/** Condición SQL del tramo, para filtrar y totalizar cuentas por cobrar. */
function invoice_aging_condition(string $bucket): string
{
    $pending = invoice_receivable_sql();
    $d = 'DATEDIFF(CURDATE(), ' . invoice_due_sql() . ')';
    return match ($bucket) {
        'por_vencer' => "{$pending} AND {$d} < 0",
        '0-30'       => "{$pending} AND {$d} BETWEEN 0 AND 30",
        '31-60'      => "{$pending} AND {$d} BETWEEN 31 AND 60",
        '61-90'      => "{$pending} AND {$d} BETWEEN 61 AND 90",
        '90+'        => "{$pending} AND {$d} > 90",
        default      => '1=1',
    };
}

/* ===================== Recordatorios de pago (cobranza) ===================== */

/**
 * Cartera de un cliente: comprobantes emitidos con saldo pendiente.
 * Es la fuente del recordatorio de pago / estado de cuenta que se entrega al
 * cliente. Con $onlyIds el recordatorio se limita a esas facturas (aviso de un
 * solo comprobante). Los importes se acompañan de su equivalente en RD$ para
 * poder totalizar carteras mixtas DOP/USD.
 *
 * Devuelve ['client', 'rows', 'count', 'total_dop', 'by_currency',
 *           'overdue_count', 'overdue_dop', 'upcoming_dop', 'max_days', 'next_due'].
 */
function client_receivables(int $clientId, array $onlyIds = []): array
{
    $empty = [
        'client' => null, 'rows' => [], 'count' => 0, 'total_dop' => 0.0, 'by_currency' => [],
        'overdue_count' => 0, 'overdue_dop' => 0.0, 'upcoming_dop' => 0.0, 'max_days' => 0, 'next_due' => null,
    ];
    if ($clientId <= 0 || !db(false) || !table_exists('invoices')) {
        return $empty;
    }

    $out = $empty;
    $out['client'] = fetch_one('SELECT * FROM clients WHERE id = ?', [$clientId]);

    $sql = 'SELECT invoices.* FROM invoices
            WHERE invoices.client_id = ?
              AND ' . invoice_receivable_sql();
    $params = [$clientId];
    $ids = array_values(array_filter(array_map('intval', $onlyIds), fn ($i) => $i > 0));
    if ($ids) {
        $sql .= ' AND invoices.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = array_merge($params, $ids);
    }
    $sql .= ' ORDER BY ' . invoice_due_sql() . ' ASC, invoices.id ASC';

    foreach (fetch_all($sql, $params) as $r) {
        $fig = receivable_figures($r);
        $cur = $fig['currency'];

        $r['aging'] = $fig['aging'];
        $r['currency'] = $cur;
        $r['balance'] = $fig['balance'];
        $r['balance_dop'] = $fig['balance_dop'];
        $r['overdue'] = $fig['overdue'];
        $r['overdue_dop'] = $fig['overdue_dop'];
        $r['due_effective'] = $fig['due'];
        $r['plan'] = $fig['plan'];
        $out['rows'][] = $r;

        $out['count']++;
        $out['total_dop'] += $fig['balance_dop'];
        $out['by_currency'][$cur] = ($out['by_currency'][$cur] ?? 0.0) + $fig['balance'];
        if ($fig['overdue'] > 0.009) {
            $out['overdue_count']++;
            $out['overdue_dop'] += $fig['overdue_dop'];
            $out['max_days'] = max($out['max_days'], $fig['days']);
        }
        $out['upcoming_dop'] += $fig['balance_dop'] - $fig['overdue_dop'];
        if ($fig['next_due'] !== null && ($out['next_due'] === null || $fig['next_due'] < $out['next_due'])) {
            $out['next_due'] = $fig['next_due'];
        }
    }
    foreach (['total_dop', 'overdue_dop', 'upcoming_dop'] as $k) {
        $out[$k] = round($out[$k], 2);
    }

    return $out;
}

/**
 * Cifras de cobro de un comprobante con saldo: cuánto se debe, cuánto de eso ya
 * venció y desde cuándo, en su moneda y en RD$.
 *
 * Lo VENCIDO no siempre es el saldo entero. Con plan de cuotas solo vence lo que
 * falta de las cuotas cuya fecha ya pasó: pactar tres cuotas sobre una factura
 * atrasada y seguir reclamándola completa como «vencida» en el estado de cuenta
 * desdice el acuerdo que se le hizo al cliente.
 */
function receivable_figures(array $r): array
{
    $age = invoice_aging($r);
    $cur = strtoupper((string) ($r['currency'] ?? 'DOP')) === 'USD' ? 'USD' : 'DOP';
    $rate = $cur === 'USD' ? max(1.0, (float) ($r['exchange_rate'] ?? 1)) : 1.0;
    $balance = (float) $age['balance'];
    $days = $age['days'] === null ? 0 : (int) $age['days'];
    $plan = function_exists('installments_summary') ? installments_summary($r) : null;

    $overdue = 0.0;
    if ($days > 0) {
        $overdue = $plan !== null ? min($balance, (float) $plan['vencido']) : $balance;
    }
    $dop = round($balance * $rate, 2);
    $overdueDop = min($dop, round($overdue * $rate, 2));

    // Lo siguiente que toca pagar de lo que todavía no vence.
    $next = null;
    if ($plan !== null) {
        $next = $plan['proxima']['due_date'] ?? null;
    } elseif ($days <= 0) {
        $next = invoice_effective_due($r);
    }

    return [
        'aging' => $age,
        'currency' => $cur,
        'rate' => $rate,
        'days' => $days,
        'balance' => $balance,
        'balance_dop' => $dop,
        'overdue' => round($overdue, 2),
        'overdue_dop' => $overdueDop,
        'due' => invoice_effective_due($r),
        'next_due' => $next,
        'plan' => $plan,
    ];
}

/**
 * Clientes con saldo pendiente, agregados para la bandeja de recordatorios.
 * Con $onlyOverdue solo entran los que ya tienen comprobantes vencidos.
 * Orden: primero la deuda más antigua, luego el mayor importe.
 */
function receivables_clients(bool $onlyOverdue = false): array
{
    if (!db(false) || !table_exists('invoices')) {
        return [];
    }
    $rows = fetch_all(
        'SELECT invoices.*, clients.name AS c_name, clients.rnc AS c_rnc, clients.email AS c_email, clients.phone AS c_phone
         FROM invoices LEFT JOIN clients ON clients.id = invoices.client_id
         WHERE ' . invoice_receivable_sql()
    );

    $byClient = [];
    foreach ($rows as $r) {
        $cid = (int) $r['client_id'];
        $fig = receivable_figures($r);

        if (!isset($byClient[$cid])) {
            $byClient[$cid] = [
                'client_id' => $cid,
                'name' => (string) ($r['c_name'] ?: $r['client_name'] ?: 'Cliente'),
                'rnc' => (string) ($r['c_rnc'] ?: $r['client_rnc'] ?: ''),
                'email' => (string) ($r['c_email'] ?? ''),
                'phone' => (string) ($r['c_phone'] ?? ''),
                'count' => 0, 'total_dop' => 0.0, 'overdue_count' => 0, 'overdue_dop' => 0.0, 'max_days' => 0,
            ];
        }
        $byClient[$cid]['count']++;
        $byClient[$cid]['total_dop'] += $fig['balance_dop'];
        if ($fig['overdue'] > 0.009) {
            $byClient[$cid]['overdue_count']++;
            $byClient[$cid]['overdue_dop'] += $fig['overdue_dop'];
            $byClient[$cid]['max_days'] = max($byClient[$cid]['max_days'], $fig['days']);
        }
    }
    foreach ($byClient as &$c) {
        $c['total_dop'] = round($c['total_dop'], 2);
        $c['overdue_dop'] = round($c['overdue_dop'], 2);
    }
    unset($c);

    if ($onlyOverdue) {
        $byClient = array_filter($byClient, fn ($c) => $c['overdue_count'] > 0);
    }
    $list = array_values($byClient);
    usort($list, fn ($a, $b) => [$b['max_days'], $b['total_dop']] <=> [$a['max_days'], $a['total_dop']]);
    return $list;
}

/**
 * Tonos del recordatorio de pago. Cada tono cambia el encabezado, el color y la
 * redacción del aviso: cordial (preventivo), firme (saldo vencido) y final
 * (última gestión antes de suspender crédito). Los textos aceptan las marcas
 * {empresa}, {cliente}, {fecha}, {total}, {vencido}, {dias} y {contacto}.
 *
 * {total} es todo lo que se debe y {vencido} solo lo que ya pasó su fecha. Los
 * tonos que hablan de «saldo vencido» usan {vencido}: con {total} el aviso le
 * reclamaba como vencido al cliente lo que todavía no le tocaba pagar.
 */
function reminder_tones(): array
{
    return [
        'cordial' => [
            'label' => 'Cordial',
            'kicker' => 'Aviso preventivo',
            'title' => 'Recordatorio de pago',
            'color' => '#0a7d36',
            'soft' => '#f5faf6',
            'line' => '#c7d6c9',
            'subject' => 'Estado de su cuenta al {fecha} — saldo pendiente de {total}',
            'intro' => 'Reciba un cordial saludo de parte de {empresa}. A modo de recordatorio preventivo, le compartimos el detalle de los comprobantes fiscales que figuran pendientes de pago en nuestros registros al {fecha}.',
            'close' => 'Si el pago ya fue realizado, le agradecemos hacer caso omiso de este aviso y remitirnos el comprobante de la transferencia o el número de cheque para aplicarlo de inmediato a su cuenta. Quedamos atentos a cualquier aclaración.',
        ],
        'firme' => [
            'label' => 'Firme',
            'kicker' => 'Saldo vencido',
            'title' => 'Recordatorio de pago',
            'color' => '#92660a',
            'soft' => '#fffaf0',
            'line' => '#f4d58a',
            'subject' => 'Saldo vencido de {vencido} — {dias} día(s) de atraso',
            'intro' => 'Reciba un cordial saludo de parte de {empresa}. Al {fecha} nuestros registros muestran comprobantes fiscales vencidos por {vencido}, con hasta {dias} día(s) de atraso, dentro de un saldo total de {total}. Le solicitamos gestionar el pago a la mayor brevedad para mantener su cuenta al día.',
            'close' => 'Le agradecemos confirmar la fecha estimada de pago o, si ya fue realizado, remitirnos el comprobante para aplicarlo a su cuenta. Si existe alguna diferencia en el detalle, con gusto la revisamos con usted.',
        ],
        'final' => [
            'label' => 'Último aviso',
            'kicker' => 'Gestión final de cobro',
            'title' => 'Último aviso de cobro',
            'color' => '#b42318',
            'soft' => '#fef2f2',
            'line' => '#f3c4c4',
            'subject' => 'Último aviso — saldo vencido de {vencido} con {dias} día(s) de atraso',
            'intro' => 'Reciba un cordial saludo de parte de {empresa}. Pese a nuestras gestiones anteriores, al {fecha} continúa pendiente un saldo vencido de {vencido}, con hasta {dias} día(s) de atraso. Este documento constituye nuestra gestión final de cobro por la vía administrativa.',
            'close' => 'Le solicitamos regularizar el saldo o comunicarse con nosotros dentro de los próximos cinco (5) días laborables para acordar un plan de pago. De no recibir respuesta, la cuenta será remitida al departamento legal y el crédito quedará suspendido, conforme a los términos aceptados en cada comprobante.',
        ],
    ];
}

/** Hasta cuántos días de atraso rige cada tono; pasado el de «firme», «final». */
function reminder_tone_thresholds(): array
{
    return ['cordial' => 15, 'firme' => 60];
}

/** Tono sugerido según los días de atraso del comprobante más vencido. */
function reminder_tone_for(int $maxOverdueDays): string
{
    $u = reminder_tone_thresholds();
    if ($maxOverdueDays <= $u['cordial']) {
        return 'cordial';
    }
    return $maxOverdueDays <= $u['firme'] ? 'firme' : 'final';
}

/** Instrucciones de pago del recordatorio (editables en Configuración). */
function reminder_payment_info(): string
{
    $saved = trim((string) setting_get('invoice_payment_info', ''));
    if ($saved !== '') {
        return $saved;
    }
    $legal = defined('APP_LEGAL') ? APP_LEGAL : '';
    $mail = defined('APP_EMAIL') ? APP_EMAIL : '';
    return "Transferencia o depósito bancario a nombre de {$legal}.\n"
        . "Cheque a nombre de {$legal}, cruzado y no negociable.\n"
        . "Al pagar, indique el número de factura o el NCF en la descripción de la transacción.\n"
        . ($mail !== '' ? "Remita el comprobante de pago a {$mail} para aplicarlo el mismo día." : '');
}

/** Datos de contacto de cobros que cierran el recordatorio. */
function reminder_contact(): string
{
    $saved = trim((string) setting_get('reminder_contact', ''));
    if ($saved !== '') {
        return $saved;
    }
    $parts = array_filter([
        'Departamento de Cobros',
        defined('APP_PHONE') && APP_PHONE !== '' ? 'Tel. ' . APP_PHONE : '',
        defined('APP_EMAIL') && APP_EMAIL !== '' ? APP_EMAIL : '',
    ]);
    return implode(' · ', $parts);
}

/** Sustituye las marcas {empresa}, {cliente}, {fecha}, {total}, {vencido}, {dias}, {contacto}. */
function reminder_fill(string $text, array $vars): string
{
    $map = [];
    foreach ($vars as $k => $v) {
        $map['{' . $k . '}'] = (string) $v;
    }
    return strtr($text, $map);
}

/** Normaliza una fecha 'YYYY-MM-DD' real; null si viene vacía, cero o inválida. */
/**
 * Una fecha AAAA-MM-DD real, o null si no lo es.
 *
 * Este MySQL no corre en modo estricto: una fecha inválida no da error, se
 * guarda como 0000-00-00 y desde ahí ensucia todo lo que la lea —el equipo
 * aparece vencido desde siempre en la agenda y en los avisos—. Como el fallo
 * es silencioso, la fecha se valida ANTES de llegar a la columna, en todas las
 * pantallas y no solo en la de cobro.
 *
 * Rechaza también el 31 de febrero: createFromFormat lo aceptaría corriéndolo
 * al 3 de marzo, así que se compara la fecha reconstruida con la original.
 */
function valid_date(?string $date): ?string
{
    $date = trim((string) $date);
    if ($date === '' || str_starts_with($date, '0000-00-00')) {
        return null;
    }
    $solo = substr($date, 0, 10);
    $d = DateTime::createFromFormat('Y-m-d', $solo);
    return ($d && $d->format('Y-m-d') === $solo) ? $d->format('Y-m-d') : null;
}

/** Nombre histórico de valid_date(); lo usan las pantallas de facturación. */
function invoice_valid_date(string $date): ?string
{
    return valid_date($date);
}

/**
 * Whether a draft has an active, in-range, unexpired NCF sequence available.
 * Same filter the emission uses, so the UI never offers what emit would reject.
 */
function invoice_has_sequence(string $prefix, string $type): bool
{
    if (!db(false) || !table_exists('ncf_sequences')) {
        return false;
    }
    $row = fetch_one(
        'SELECT COUNT(*) c FROM ncf_sequences WHERE prefix=? AND ncf_type=? AND active=1 AND seq_next<=seq_to AND (expiration IS NULL OR expiration>=CURDATE())',
        [$prefix !== '' ? $prefix : 'B', $type !== '' ? $type : '02']
    );
    return (int) ($row['c'] ?? 0) > 0;
}

/**
 * Devuelve el NCF de una factura anulada al pool para reutilizarlo, SOLO si fue
 * el último número emitido de su rango (seq_next == este_seq + 1). En ese caso
 * decrementa seq_next: la próxima emisión volverá a tomar exactamente ese NCF,
 * sin abrir huecos en la secuencia. Si el número no era el último (hay emitidos
 * posteriores), no se puede liberar sin dejar hueco y se conserva como anulado.
 * Debe llamarse dentro de la transacción de anulación. Devuelve true si liberó.
 */
function invoice_release_ncf(int $invoiceId): bool
{
    $inv = fetch_one('SELECT ncf, ncf_prefix, ncf_type FROM invoices WHERE id=?', [$invoiceId]);
    if (!$inv || (string) ($inv['ncf'] ?? '') === '') {
        return false;
    }
    // Número secuencial embebido en el NCF (los dígitos tras la serie+tipo).
    $digits = substr((string) $inv['ncf'], 3);
    if (!ctype_digit($digits)) {
        return false;
    }
    $seq = (int) $digits;
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM ncf_sequences WHERE prefix=? AND ncf_type=? AND seq_next=? LIMIT 1 FOR UPDATE');
    $stmt->execute([(string) $inv['ncf_prefix'], (string) $inv['ncf_type'], $seq + 1]);
    $pool = $stmt->fetch();
    if (!$pool) {
        return false; // no era el último número consumido de ningún rango
    }
    $pdo->prepare('UPDATE ncf_sequences SET seq_next=seq_next-1, updated_at=NOW() WHERE id=?')->execute([(int) $pool['id']]);
    // La factura anulada suelta el NCF (queda registrada como anulada sin número
    // fiscal ocupado), de modo que ese comprobante quede libre para reasignarse.
    $pdo->prepare('UPDATE invoices SET ncf=NULL, updated_at=NOW() WHERE id=?')->execute([$invoiceId]);
    return true;
}

/* =========================== Notas de crédito =========================== */

/**
 * Recalcula cuánto se le ha acreditado a una factura mediante notas de crédito
 * emitidas contra ella, y lo guarda en credited_amount.
 *
 * Se recalcula desde cero en lugar de sumar o restar incrementos: así una nota
 * anulada, borrada o re-emitida siempre deja la factura en el estado correcto,
 * sin depender de que cada camino acuerde de ajustar el contador.
 *
 * El crédito se topa al importe exigible pendiente: acreditar más de lo que se
 * debe dejaría un saldo negativo, que no significa nada en una cuenta por
 * cobrar. Si eso pasa, el exceso se ignora aquí y se avisa en pantalla.
 */
function invoice_recalc_credited(int $invoiceId): float
{
    if ($invoiceId <= 0 || !db(false) || !table_exists('invoices')
        || !column_exists('invoices', 'credited_amount') || !column_exists('invoices', 'modifies_invoice_id')) {
        return 0.0;
    }
    $inv = fetch_one('SELECT * FROM invoices WHERE id=?', [$invoiceId]);
    if (!$inv) {
        return 0.0;
    }

    $types = "'" . implode("','", ncf_credit_note_types()) . "'";
    $sum = (float) (fetch_one(
        "SELECT COALESCE(SUM(total),0) v FROM invoices
          WHERE modifies_invoice_id = ? AND ncf_type IN ({$types}) AND status IN ('Emitida','Pagada')",
        [$invoiceId]
    )['v'] ?? 0);

    // El ajuste de cartera también descuenta: acreditar encima de él dejaría la
    // factura debiendo menos que cero.
    $ceiling = round((float) $inv['total'] - (float) $inv['itbis_retained'] - (float) $inv['isr_retained'] - (float) ($inv['balance_adjustment'] ?? 0), 2);
    $credited = round(max(0.0, min($sum, $ceiling)), 2);

    db()->prepare('UPDATE invoices SET credited_amount=?, updated_at=NOW() WHERE id=?')->execute([$credited, $invoiceId]);
    return $credited;
}

/**
 * Crédito acumulado contra una factura hasta una fecha, inclusive.
 *
 * Existe para los documentos históricos —el recibo de ingreso, sobre todo—:
 * un recibo dice cuánto se debía el día que se cobró, y una nota de crédito
 * emitida después no puede cambiar ese número. Si el recibo se reimprimiera con
 * el saldo de hoy, la copia del cliente y la nuestra dejarían de coincidir.
 */
function invoice_credited_as_of(int $invoiceId, string $date): float
{
    if ($invoiceId <= 0 || !db(false) || !table_exists('invoices')
        || !column_exists('invoices', 'modifies_invoice_id')) {
        return 0.0;
    }
    $date = trim($date);
    if ($date === '' || strtotime($date) === false) {
        return 0.0;
    }
    $types = "'" . implode("','", ncf_credit_note_types()) . "'";
    $sum = (float) (fetch_one(
        "SELECT COALESCE(SUM(total),0) v FROM invoices
          WHERE modifies_invoice_id = ? AND ncf_type IN ({$types}) AND status IN ('Emitida','Pagada')
            AND DATE(COALESCE(emitted_at, issue_date)) <= ?",
        [$invoiceId, date('Y-m-d', strtotime($date))]
    )['v'] ?? 0);
    return round(max(0.0, $sum), 2);
}

/** Notas de crédito emitidas contra una factura (para mostrarlas en su ficha). */
function invoice_credit_notes(int $invoiceId): array
{
    if ($invoiceId <= 0 || !db(false) || !table_exists('invoices') || !column_exists('invoices', 'modifies_invoice_id')) {
        return [];
    }
    $types = "'" . implode("','", ncf_credit_note_types()) . "'";
    return fetch_all(
        "SELECT id, invoice_number, ncf, status, total, currency, issue_date, emitted_at, notes
           FROM invoices WHERE modifies_invoice_id = ? AND ncf_type IN ({$types})
          ORDER BY id DESC",
        [$invoiceId]
    );
}

/** ¿Puede esta factura recibir una nota de crédito? Devuelve el motivo si no. */
function invoice_can_be_credited(array $inv): array
{
    if (!in_array((string) ($inv['status'] ?? ''), ['Emitida', 'Pagada'], true)) {
        return [false, 'Solo se acredita un comprobante ya emitido.'];
    }
    if (invoice_is_proforma($inv)) {
        return [false, 'Una proforma no es un comprobante fiscal: no se acredita, se edita.'];
    }
    if (invoice_is_credit_note($inv)) {
        return [false, 'Una nota de crédito no se acredita a sí misma.'];
    }
    if (trim((string) ($inv['ncf'] ?? '')) === '') {
        return [false, 'El comprobante no tiene NCF.'];
    }
    if (invoice_net($inv) <= 0.009) {
        return [false, 'El comprobante ya está acreditado por completo.'];
    }
    return [true, ''];
}

/* ============================ Recibo de ingreso ============================ */

/** Siguiente número de recibo de ingreso del año: REC-2026-0001. */
function next_receipt_number(): string
{
    $year = date('Y');
    $n = 1;
    if (db(false) && table_exists('invoice_payments') && column_exists('invoice_payments', 'receipt_number')) {
        $last = fetch_one(
            'SELECT receipt_number FROM invoice_payments WHERE receipt_number LIKE ? ORDER BY id DESC LIMIT 1',
            ["REC-{$year}-%"]
        );
        if ($last && preg_match('/-(\d+)$/', (string) $last['receipt_number'], $m)) {
            $n = ((int) $m[1]) + 1;
        }
    }
    return 'REC-' . $year . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/**
 * Reserva el siguiente número de recibo de forma atómica.
 *
 * Debe llamarse DENTRO de una transacción ya abierta. Bloquea la fila del
 * contador en `settings`, así que dos cobros simultáneos se serializan y cada
 * uno se lleva un número distinto — igual que hace invoice_emit() con el NCF.
 *
 * El contador se siembra a partir del último recibo existente, de modo que la
 * numeración continúa donde estaba sin necesidad de migrar datos.
 */
/**
 * Reserva atómica de un número correlativo por año.
 *
 * Una sola sentencia inserta o incrementa el contador y deja el valor nuevo en
 * LAST_INSERT_ID(): no hay ventana entre leer y escribir, así que dos procesos
 * simultáneos no pueden llevarse el mismo número ni bloquearse entre sí.
 *
 * $semillaSql debe devolver el último número ya usado, para que la numeración
 * continúe donde estaba sin migrar datos existentes.
 */
function reserve_serial(PDO $pdo, string $contador, string $prefijo, string $semillaSql, string $semillaLike): string
{
    $year = date('Y');
    $clave = $contador . '_' . $year;

    static $semilla = [];
    if (!isset($semilla[$clave])) {
        $semilla[$clave] = 0;
        try {
            $ultimo = fetch_one($semillaSql, [str_replace('{Y}', $year, $semillaLike)]);
            if ($ultimo && preg_match('/-(\d+)$/', (string) reset($ultimo), $m)) {
                $semilla[$clave] = (int) $m[1];
            }
        } catch (Throwable) { /* tabla o columna aún sin crear */ }
    }

    $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value, updated_at)
              VALUES (?, LAST_INSERT_ID(? + 1), NOW())
         ON DUPLICATE KEY UPDATE
              setting_value = LAST_INSERT_ID(setting_value + 1), updated_at = NOW()'
    )->execute([$clave, $semilla[$clave]]);

    return $prefijo . '-' . $year . '-' . str_pad((string) $pdo->lastInsertId(), 4, '0', STR_PAD_LEFT);
}

/** Número de factura, reservado sin carrera. Debe ir dentro de la transacción. */
function reserve_invoice_number(PDO $pdo): string
{
    return reserve_serial(
        $pdo, 'invoice_seq', 'FAC',
        'SELECT invoice_number FROM invoices WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT 1',
        'FAC-{Y}-%'
    );
}

/** Número de cotización, reservado sin carrera. */
function reserve_quote_number(PDO $pdo): string
{
    return reserve_serial(
        $pdo, 'quote_seq', 'SCH',
        'SELECT quote_number FROM quotes WHERE quote_number LIKE ? ORDER BY id DESC LIMIT 1',
        'SCH-{Y}-%'
    );
}

function reserve_receipt_number(PDO $pdo): string
{
    $year = date('Y');
    $clave = 'receipt_seq_' . $year;

    /*
     * Una sola sentencia: inserta el contador si no existe o lo incrementa si
     * ya está, y en ambos casos deja el valor NUEVO en LAST_INSERT_ID(). Al no
     * haber ventana entre leer y escribir, dos cobros simultáneos no pueden
     * llevarse el mismo número ni bloquearse entre sí.
     *
     * La versión anterior usaba SELECT ... FOR UPDATE y quitaba los duplicados,
     * pero con la fila aún inexistente cada proceso bloqueaba el hueco y MySQL
     * los mataba: siete de ocho cobros terminaban en interbloqueo.
     *
     * La semilla sale del último recibo ya emitido, así la numeración continúa
     * donde estaba sin migrar nada.
     */
    static $semilla = [];
    if (!isset($semilla[$year])) {
        $semilla[$year] = 0;
        $ultimo = fetch_one(
            'SELECT receipt_number FROM invoice_payments WHERE receipt_number LIKE ? ORDER BY id DESC LIMIT 1',
            ["REC-{$year}-%"]
        );
        if ($ultimo && preg_match('/-(\d+)$/', (string) $ultimo['receipt_number'], $m)) {
            $semilla[$year] = (int) $m[1];
        }
    }

    $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value, updated_at)
              VALUES (?, LAST_INSERT_ID(? + 1), NOW())
         ON DUPLICATE KEY UPDATE
              setting_value = LAST_INSERT_ID(setting_value + 1), updated_at = NOW()'
    )->execute([$clave, $semilla[$year]]);

    $n = (int) $pdo->lastInsertId();

    return 'REC-' . $year . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/**
 * Emit a draft: take the next number from its authorized NCF range, stamp it on
 * the invoice and lock the document. Single source of truth for every emission
 * path (invoice detail, list row action, "guardar y emitir").
 * Returns ['ok' => bool, 'message' => string, 'ncf' => string].
 */
function invoice_emit(int $invoiceId): array
{
    $inv = $invoiceId > 0 ? fetch_one('SELECT * FROM invoices WHERE id=?', [$invoiceId]) : null;
    if (!$inv) {
        return ['ok' => false, 'message' => 'La factura no existe.', 'ncf' => ''];
    }
    if (!invoice_is_editable($inv['status'])) {
        return ['ok' => false, 'message' => 'Esta factura ya fue emitida.', 'ncf' => ''];
    }
    // Una proforma no consume NCF: para convertirla en comprobante fiscal hay que
    // editarla y cambiar la serie a B (fiscal) o E (e-CF).
    if (invoice_is_proforma($inv)) {
        return ['ok' => false, 'message' => 'Una factura proforma no lleva NCF. Edítala y cambia la «Serie NCF» a B o E para poder emitirla como comprobante fiscal.', 'ncf' => ''];
    }
    if ((int) (fetch_one('SELECT COUNT(*) c FROM invoice_items WHERE invoice_id=?', [$invoiceId])['c'] ?? 0) === 0) {
        return ['ok' => false, 'message' => 'Agrega al menos una partida antes de emitir.', 'ncf' => ''];
    }
    $type = (string) $inv['ncf_type'];
    $prefix = (string) $inv['ncf_prefix'];
    if (ncf_requires_rnc($type) && trim((string) ($inv['client_rnc'] ?? '')) === '') {
        return ['ok' => false, 'message' => 'El tipo «' . ncf_type_label($type) . '» exige el RNC/Cédula del cliente. Complétalo en la ficha del cliente y vuelve a intentarlo.', 'ncf' => ''];
    }

    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $seq = $pdo->prepare('SELECT * FROM ncf_sequences WHERE prefix=? AND ncf_type=? AND active=1 AND seq_next<=seq_to AND (expiration IS NULL OR expiration>=CURDATE()) ORDER BY id ASC LIMIT 1 FOR UPDATE');
        $seq->execute([$prefix, $type]);
        $pool = $seq->fetch();
        if (!$pool) {
            if ($ownTransaction) { $pdo->rollBack(); }
            return [
                'ok' => false,
                'message' => 'No hay una secuencia NCF activa y vigente para ' . $prefix . $type . ' (' . ncf_type_label($type) . '). Revisa el rango en «Secuencias NCF»: debe estar activo, sin agotar y sin vencer.',
                'ncf' => '',
            ];
        }
        $ncf = ncf_format((string) $pool['prefix'], (string) $pool['ncf_type'], (int) $pool['seq_next']);
        $pdo->prepare('UPDATE ncf_sequences SET seq_next=seq_next+1, updated_at=NOW() WHERE id=?')->execute([(int) $pool['id']]);

        // Respeta la fecha de emisión que el usuario fijó en el borrador; solo usa
        // hoy si venía vacía. El vencimiento se conserva si lo capturó; si no, se
        // deriva de la condición de pago (Crédito: +N días; Contado: mismo día).
        $issue = invoice_valid_date((string) ($inv['issue_date'] ?? '')) ?? date('Y-m-d');
        $dueDays = max(0, (int) setting_get('invoice_due_days', '30'));
        $due = invoice_valid_date((string) ($inv['due_date'] ?? ''));
        if ($due === null) {
            $due = ((string) $inv['payment_condition'] === 'Crédito') ? date('Y-m-d', strtotime("{$issue} +{$dueDays} days")) : $issue;
        }
        $pdo->prepare('UPDATE invoices SET ncf=?, ncf_expiration=?, status=?, issue_date=?, due_date=?, emitted_at=NOW(), updated_at=NOW() WHERE id=?')
            ->execute([$ncf, $pool['expiration'], 'Emitida', $issue, $due, $invoiceId]);
        if ($ownTransaction) { $pdo->commit(); }
        log_activity('invoice', $invoiceId, 'factura_emitida', $ncf);

        // Una nota de crédito recién emitida reduce el saldo de la factura que
        // modifica. Se hace fuera de la transacción del NCF: si fallara, el
        // comprobante ya es válido y el saldo se rehace al volver a abrirlo.
        if (in_array($type, ncf_credit_note_types(), true) && (int) ($inv['modifies_invoice_id'] ?? 0) > 0) {
            invoice_recalc_credited((int) $inv['modifies_invoice_id']);
        }

        /* Si la factura salió de una cotización con anticipos, se le aplican al
           emitirla: el cliente ya pagó esa parte y la factura no puede nacer
           reclamándola. Fuera de la transacción del NCF, como la nota de crédito:
           el comprobante ya es válido aunque esto fallara, y el anticipo se
           puede aplicar a mano desde la factura. */
        $mensaje = 'Factura emitida con NCF ' . $ncf . '.';
        if ($ownTransaction && function_exists('anticipos_apply_for_invoice')) {
            $aplicados = anticipos_apply_for_invoice($invoiceId);
            if ($aplicados) {
                $mensaje .= ' Se le aplicaron los anticipos de la cotización: ' . implode(', ', array_map(
                    static fn ($x) => $x[0] . ' (' . money_cur($x[1], (string) ($inv['currency'] ?? 'DOP')) . ')',
                    $aplicados
                )) . '.';
            }
        }

        return ['ok' => true, 'message' => $mensaje, 'ncf' => $ncf];
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('invoice_emit: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'No se pudo emitir la factura. Inténtalo de nuevo.', 'ncf' => ''];
    }
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
            is_proforma TINYINT(1) NOT NULL DEFAULT 0,
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
            discount_pct DECIMAL(6,3) NOT NULL DEFAULT 0,
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
            // Proforma: documento con formato de factura pero sin NCF ni valor fiscal.
            'is_proforma' => "ALTER TABLE invoices ADD COLUMN is_proforma TINYINT(1) NOT NULL DEFAULT 0 AFTER ncf_prefix",
            'is_ecf' => "ALTER TABLE invoices ADD COLUMN is_ecf TINYINT(1) NOT NULL DEFAULT 0 AFTER ncf_prefix",
            'ecf_status' => "ALTER TABLE invoices ADD COLUMN ecf_status VARCHAR(30) NULL AFTER is_ecf",
            'ecf_track_id' => "ALTER TABLE invoices ADD COLUMN ecf_track_id VARCHAR(60) NULL AFTER ecf_status",
            'ecf_security_code' => "ALTER TABLE invoices ADD COLUMN ecf_security_code VARCHAR(20) NULL AFTER ecf_track_id",
            'ecf_sign_date' => "ALTER TABLE invoices ADD COLUMN ecf_sign_date DATETIME NULL AFTER ecf_security_code",
            'ecf_qr_url' => "ALTER TABLE invoices ADD COLUMN ecf_qr_url TEXT NULL AFTER ecf_sign_date",
            'ecf_xml' => "ALTER TABLE invoices ADD COLUMN ecf_xml MEDIUMTEXT NULL AFTER ecf_qr_url",
            'ecf_response' => "ALTER TABLE invoices ADD COLUMN ecf_response TEXT NULL AFTER ecf_xml",
            // Descuento único del documento: el monto ya existía; el porcentaje se
            // guarda aparte para reabrir la factura en el modo en que se capturó.
            'discount_pct' => "ALTER TABLE invoices ADD COLUMN discount_pct DECIMAL(6,3) NOT NULL DEFAULT 0 AFTER discount_amount",
            // Código de anulación de la DGII (01..11). El motivo en texto libre
            // sigue existiendo para el rastro interno; este es el que exige el 608.
            'void_code' => "ALTER TABLE invoices ADD COLUMN void_code VARCHAR(2) NULL AFTER void_reason",
            // Monto acreditado por notas de crédito emitidas CONTRA esta factura.
            // Reduce el saldo exigible sin tocar amount_paid: el cliente no pagó
            // ese dinero, se le perdonó, y confundir ambas cosas falsea la caja.
            'credited_amount' => 'ALTER TABLE invoices ADD COLUMN credited_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount_paid',
        ];
        foreach ($ecfColumns as $col => $sql) {
            if (!column_exists('invoices', $col)) {
                try { $pdo->exec($sql); } catch (Throwable) { /* ignore */ }
            }
        }

        // Numeración del recibo de ingreso que se entrega al cliente al cobrar.
        if (table_exists('invoice_payments') && !column_exists('invoice_payments', 'receipt_number')) {
            try {
                $pdo->exec('ALTER TABLE invoice_payments ADD COLUMN receipt_number VARCHAR(40) NULL AFTER id');
            } catch (Throwable) { /* ignore */ }
        }

        /*
         * Unicidad en la BASE, no solo en el código.
         *
         * El NCF ya se toma con SELECT ... FOR UPDATE y eso funciona. Pero si
         * algo llega por fuera de invoice_emit() —una importación, dos rangos
         * que solapan, un respaldo restaurado a medias— el duplicado entra sin
         * una queja y el 607 declara el mismo comprobante dos veces. El índice
         * lo vuelve imposible.
         *
         * El número de recibo no tenía ni índice normal útil: ocho cobros a la
         * vez produjeron ocho recibos con el MISMO número, todos correctos
         * según la base.
         *
         * Ambas columnas admiten NULL y los borradores lo usan, así que un
         * UNIQUE de MySQL los deja convivir: solo exige que los emitidos no se
         * repitan.
         */
        foreach ([
            ['invoices', 'ncf', 'uniq_invoices_ncf'],
        ] as [$tabla, $col, $idx]) {
            if (!table_exists($tabla) || !column_exists($tabla, $col)) {
                continue;
            }
            if (index_exists($tabla, $idx)) {
                continue;
            }
            try {
                // Una cadena vacía SÍ colisiona consigo misma; NULL no.
                $pdo->exec("UPDATE {$tabla} SET {$col}=NULL WHERE {$col}=''");
                $pdo->exec("ALTER TABLE {$tabla} ADD UNIQUE INDEX {$idx} ({$col})");
            } catch (Throwable) {
                /* Si ya hay duplicados el índice no entra: se avisa en pantalla
                   desde database/migrate.php en vez de romper el arranque. */
            }
        }

        /*
         * Recibos: lo que no se repite es la PAREJA (recibo, factura).
         *
         * Aquí hubo un UNIQUE sobre receipt_number a secas, y estaba mal: la
         * pantalla de Cobro reparte una transferencia entre varias facturas con
         * UN solo número, una fila por factura. Con ese índice, cualquier cobro
         * de dos o más facturas fallaba en la segunda línea. No llegó a romper
         * nada solo porque el servidor aún no tenía la pantalla de Cobro.
         *
         * Que dos cobros distintos no se lleven el mismo número lo garantiza
         * reserve_receipt_number(), que es atómico. Este índice impide lo otro:
         * que un mismo recibo aplique dos veces a la misma factura.
         */
        if (table_exists('invoice_payments') && column_exists('invoice_payments', 'receipt_number')) {
            if (index_exists('invoice_payments', 'uniq_payment_receipt')) {
                try {
                    $pdo->exec('ALTER TABLE invoice_payments DROP INDEX uniq_payment_receipt');
                } catch (Throwable) { /* ignore */ }
            }
            if (!index_exists('invoice_payments', 'uniq_receipt_line')) {
                try {
                    $pdo->exec("UPDATE invoice_payments SET receipt_number=NULL WHERE receipt_number=''");
                    $pdo->exec('ALTER TABLE invoice_payments ADD UNIQUE INDEX uniq_receipt_line (receipt_number, invoice_id)');
                } catch (Throwable) { /* ignore */ }
            }
        }
    } catch (Throwable) {
        /* ignore: best-effort provisioning */
    }

    // Historial de recibos corregidos/anulados y planes de cuotas.
    if (function_exists('ensure_cobros_schema')) {
        ensure_cobros_schema();
    }
}

/** ¿Existe ya ese índice? Evita intentar crearlo dos veces en cada arranque. */
function index_exists(string $table, string $index, bool $fresh = false): bool
{
    static $cache = [];
    $k = $table . '.' . $index;
    /* $fresh: leer de la base aunque haya respuesta guardada. Hace falta tras un
       DROP INDEX — el de recibos se quita en la migración, y la comprobación
       posterior no puede fiarse de un «sí» anterior al borrado. */
    if ($fresh) {
        unset($cache[$k]);
    }
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    try {
        $row = fetch_one(
            'SELECT COUNT(*) c FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        );
        $hay = ((int) ($row['c'] ?? 0)) > 0;
        // Solo se recuerda el SÍ: un índice que falta puede crearse en esta
        // misma petición, y cachear el NO haría que la comprobación mintiera
        // justo después de haberlo creado. Si se BORRA uno, quien comprueba
        // después pide $fresh.
        if ($hay) { $cache[$k] = true; }
        return $hay;
    } catch (Throwable) {
        return false;
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

/**
 * "(809) 905-4318" -> "tel:+18099054318". Los números se editan desde
 * Configuración, así que el href nunca debe quedar escrito a mano.
 */
function tel_href(string $phone, string $countryCode = '1'): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
        return '#';
    }
    if (strlen($digits) === 10) {
        $digits = $countryCode . $digits;
    }
    return 'tel:+' . $digits;
}

/** Root-relative path -> full https://host/... link, safe to send to a client. */
function absolute_url(string $relative): string
{
    $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $secure = $forwarded === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($secure ? 'https' : 'http') . '://' . $host . $relative;
}

/**
 * Public helpdesk link for a client. Always absolute: this URL is copied out of
 * the CRM into an email or WhatsApp, where a bare "/helpdesk?..." is not a link.
 */
function client_support_url(array $client): string
{
    $access = client_support_access($client);
    return absolute_url(url('helpdesk.php?cliente=' . rawurlencode($access['slug']) . '&key=' . rawurlencode($access['token'])));
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

/**
 * Estado en el vocabulario de la red: marca estampada + color de línea.
 * El tono nunca va solo — cada variante trae su glifo — porque un estado que
 * solo se distingue por color no se distingue en una impresión ni para quien
 * no separa el verde del rojo.
 */
function status_class(string $status): string
{
    return match (strtolower($status)) {
        'abierto', 'pendiente', 'borrador', 'nuevo', 'requiere revision', 'requiere revisión' => 'gas-estado--espera',
        'en proceso', 'enviado', 'cotizado', 'contactado', 'prospecto', 'negociacion', 'negociación' => 'gas-estado--curso',
        'aprobado', 'resuelto', 'activo', 'convertido' => 'gas-estado--ok',
        'cerrado', 'inactivo', 'rechazado', 'descartado', 'retirado', 'demo' => 'gas-estado--cerrado',
        'critico', 'crítico', 'alta', 'vencido', 'vencida', 'fuera de servicio' => 'gas-estado--alarma',
        default => 'gas-estado--cerrado',
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

/**
 * Renderiza un documento apretando la densidad hasta que quepa en una sola hoja.
 *
 * $build(int $level) devuelve el HTML completo, donde level 0 = densidad normal,
 * 1 = compacta y 2 = muy compacta. Evita que una factura de pocas partidas mande
 * las firmas solas a una segunda hoja. Si ni con la densidad máxima cabe, se
 * devuelve la versión normal: el documento es largo de verdad y apretarlo solo lo
 * haría ilegible.
 */
function pdf_render_fit(callable $build, \Dompdf\Options $options, int $maxLevel = 2): \Dompdf\Dompdf
{
    $render = static function (int $level) use ($build, $options): array {
        $pdf = new \Dompdf\Dompdf($options);
        $pdf->loadHtml($build($level), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        return [$pdf, (int) $pdf->getCanvas()->get_page_count()];
    };

    [$pdf, $pages] = $render(0);
    // Solo intentamos comprimir cuando falta poco: con 3+ hojas ya no se salva.
    if ($pages !== 2) { return $pdf; }

    for ($level = 1; $level <= $maxLevel; $level++) {
        [$try, $tryPages] = $render($level);
        if ($tryPages <= 1) { return $try; }
    }
    return $pdf;
}

function priority_class(string $priority): string
{
    return match (strtolower($priority)) {
        'critica', 'crítica', 'alta' => 'gas-estado--alarma',
        'media' => 'gas-estado--espera',
        default => 'gas-estado--cerrado',
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

/**
 * Encabezado de pantalla: qué estoy viendo, de qué se trata y el dato vivo que
 * la resume. Es la primera línea de cada módulo del CRM.
 */
function sch_encabezado(string $nombre, string $descripcion = '', string $dato = ''): string
{
    $out  = '<div class="sch-head">';
    $out .= '<div><h2 class="sch-head__t">' . e($nombre) . '</h2>';
    if ($descripcion !== '') { $out .= '<p class="sch-head__d">' . e($descripcion) . '</p>'; }
    $out .= '</div>';
    if ($dato !== '') { $out .= '<span class="sch-head__dato">' . e($dato) . '</span>'; }
    return $out . '</div>';
}

/** Nombre anterior de sch_encabezado(). */
function gas_banda(string $linea, string $nombre, string $descripcion = '', string $dato = ''): string
{
    unset($linea);   // el lenguaje anterior teñía la banda por línea de gas; hoy no.
    return sch_encabezado($nombre, $descripcion, $dato);
}
