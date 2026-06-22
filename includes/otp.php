<?php

declare(strict_types=1);

/* ============================================================
   Email (Resend) + login OTP / two-factor.

   Settings (stored in `settings`, editable from Configuración):
     resend_api_key   – Resend API key (re_...)
     mail_from_email  – verified sender, e.g. no-reply@schmedicos.com
     mail_from_name   – display name, e.g. SCH MEDICOS
     otp_enabled      – '1' to require an email code at login

   The login OTP is "fail closed": when it is active and the code cannot
   be emailed, the user is NOT logged in. To avoid lockouts it only turns
   active when BOTH the switch is on AND an API key is present.
   ============================================================ */

function resend_api_key(): string
{
    return trim((string) setting_get('resend_api_key', ''));
}

function mail_from_email(): string
{
    $v = trim((string) setting_get('mail_from_email', ''));
    return $v !== '' ? $v : (defined('APP_EMAIL') ? (string) APP_EMAIL : 'no-reply@localhost');
}

function mail_from_name(): string
{
    $v = trim((string) setting_get('mail_from_name', ''));
    return $v !== '' ? $v : (defined('APP_NAME') ? (string) APP_NAME : 'CRM');
}

/**
 * Absolute public base URL for links/images in emails. Prefers an explicit
 * `public_url` setting, then the current (non-local) request host, and finally
 * the canonical production domain — never a localhost host, which would break
 * images in a recipient's inbox.
 */
function public_base_url(): string
{
    $configured = trim((string) setting_get('public_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '' && !preg_match('/^(localhost|127\.|\[?::1)/i', $host)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host;
    }
    return 'https://schmedicos.com';
}

/** Absolute URL of the logo used in emails (DB override → company logo). */
function mail_logo_url(): string
{
    $custom = trim((string) setting_get('mail_logo_url', ''));
    if ($custom !== '') {
        return $custom;
    }
    $logo = defined('APP_LOGO') ? (string) APP_LOGO : 'assets/media/logo_SCH_-removebg-preview.png';
    if (preg_match('#^https?://#i', $logo)) {
        return $logo;
    }
    return public_base_url() . '/' . ltrim($logo, '/');
}

/**
 * Branded, email-client-safe HTML shell (table layout + inline CSS). Wraps the
 * given content with the logo header and a company footer. Reused by every CRM
 * email so they all look consistent.
 */
function mail_layout(string $preheader, string $content): string
{
    $brand = e(defined('APP_NAME') ? (string) APP_NAME : 'CRM');
    $legal = e(defined('APP_LEGAL') && APP_LEGAL !== '' ? (string) APP_LEGAL : (defined('APP_NAME') ? (string) APP_NAME : ''));
    $addr = e(defined('APP_ADDRESS') ? (string) APP_ADDRESS : '');
    $logo = e(mail_logo_url());
    $pre = e($preheader);
    $year = date('Y');
    $sans = "'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    return '<!doctype html><html lang="es"><head>'
        . '<meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light only">'
        . '<meta name="x-apple-disable-message-reformatting">'
        . '<title>' . $brand . '</title></head>'
        . '<body style="margin:0;padding:0;background:#eef2f7;-webkit-font-smoothing:antialiased;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">' . $pre . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef2f7;">'
        . '<tr><td align="center" style="padding:32px 16px;">'
        . '<table role="presentation" width="480" cellpadding="0" cellspacing="0" border="0" style="width:480px;max-width:480px;background:#ffffff;border:1px solid #e4ebf3;border-radius:14px;overflow:hidden;">'
        . '<tr><td style="height:4px;line-height:4px;font-size:0;background:#0666b3;">&nbsp;</td></tr>'
        . '<tr><td align="center" style="padding:28px 28px 4px;">'
        . '<img src="' . $logo . '" alt="' . $brand . '" height="46" style="height:46px;width:auto;border:0;outline:none;text-decoration:none;display:block;">'
        . '</td></tr>'
        . '<tr><td align="center" style="padding:6px 28px 20px;font-family:' . $sans . ';">'
        . '<div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#8aa0b6;font-weight:600;">Acceso seguro</div>'
        . '</td></tr>'
        . '<tr><td style="padding:0 32px 8px;font-family:' . $sans . ';color:#0a1a2b;font-size:15px;line-height:1.55;">'
        . $content
        . '</td></tr>'
        . '<tr><td style="padding:22px 32px 28px;">'
        . '<div style="border-top:1px solid #eef2f7;padding-top:16px;font-family:' . $sans . ';color:#9bafc4;font-size:11.5px;line-height:1.55;">'
        . '<strong style="color:#56697e;">' . $legal . '</strong>'
        . ($addr !== '' ? '<br>' . $addr : '')
        . '<br>Este es un correo automático, por favor no respondas.'
        . '<br>&copy; ' . $year . ' ' . $brand . '. Todos los derechos reservados.'
        . '</div></td></tr>'
        . '</table>'
        . '</td></tr></table></body></html>';
}

/**
 * Send an email through the Resend API.
 * Returns ['ok' => bool, 'error' => string].
 */
function mailer_send(string $toEmail, string $toName, string $subject, string $html): array
{
    $key = resend_api_key();
    if ($key === '') {
        return ['ok' => false, 'error' => 'Falta la API key de Resend.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL no está disponible en el servidor.'];
    }
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Correo de destino inválido.'];
    }

    $fromName = mail_from_name();
    $from = $fromName !== '' ? sprintf('%s <%s>', $fromName, mail_from_email()) : mail_from_email();
    $to = $toName !== '' ? sprintf('%s <%s>', $toName, $toEmail) : $toEmail;

    $payload = json_encode([
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'html' => $html,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'No se pudo conectar con Resend: ' . $err];
    }
    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'error' => ''];
    }

    $msg = '';
    $data = json_decode((string) $resp, true);
    if (is_array($data)) {
        $msg = (string) ($data['message'] ?? ($data['name'] ?? ''));
    }
    return ['ok' => false, 'error' => 'Resend respondió ' . $code . ($msg !== '' ? ': ' . $msg : '')];
}

/* ---------------- Login OTP ---------------- */

/** OTP is active only when enabled AND configured (an API key exists). */
function otp_active(): bool
{
    return setting_get('otp_enabled', '0') === '1' && resend_api_key() !== '';
}

function otp_email_html(string $name, string $code): string
{
    $brand = e(defined('APP_NAME') ? (string) APP_NAME : 'CRM');
    $hi = $name !== '' ? 'Hola ' . e($name) . ',' : 'Hola,';
    $codeFmt = e(trim(chunk_split($code, 3, ' ')));

    $content =
          '<p style="margin:6px 0 8px;font-size:19px;font-weight:700;color:#06243f;">Tu código de verificación</p>'
        . '<p style="margin:0 0 4px;">' . $hi . '</p>'
        . '<p style="margin:0 0 20px;color:#56697e;">Ingresa este código para completar tu inicio de sesión en el panel de <strong>' . $brand . '</strong>.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;">'
        . '<tr><td align="center" style="background:#f1f7fd;border:1px solid #cfe0f0;border-radius:12px;padding:22px 12px;">'
        . '<div style="font-family:\'Courier New\',Consolas,monospace;font-size:36px;font-weight:700;letter-spacing:12px;color:#04365f;padding-left:12px;">' . $codeFmt . '</div>'
        . '</td></tr></table>'
        . '<p style="margin:0 0 20px;text-align:center;color:#7c8ea1;font-size:13px;">Este código vence en <strong style="color:#56697e;">10 minutos</strong>.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
        . '<tr><td style="background:#fbfcfe;border:1px solid #eef2f7;border-radius:10px;padding:13px 15px;color:#7c8ea1;font-size:12.5px;line-height:1.55;">'
        . '<strong style="color:#56697e;">¿No fuiste tú?</strong> Si no intentaste iniciar sesión, ignora este correo y considera cambiar tu contraseña.'
        . '</td></tr></table>';

    return mail_layout('Código de verificación para entrar al panel de ' . $brand . '.', $content);
}

/**
 * Generate a code, stash the already-authenticated user as a pending login in the
 * session and email the code. Returns ['ok' => bool, 'error' => string].
 */
function otp_start(array $user): array
{
    $email = (string) ($user['email'] ?? '');
    $name = (string) ($user['name'] ?? '');
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $send = mailer_send($email, $name, 'Tu código de acceso al CRM', otp_email_html($name, $code));
    if (!$send['ok']) {
        return $send;
    }

    $_SESSION['otp_pending'] = [
        'user' => $user,
        'hash' => hash('sha256', $code),
        'expires' => time() + 600,
        'attempts' => 0,
        'resends' => (int) ($_SESSION['otp_pending']['resends'] ?? 0),
        'last_sent' => time(),
        'email' => $email,
    ];
    return ['ok' => true, 'error' => ''];
}

function otp_pending(): ?array
{
    $p = $_SESSION['otp_pending'] ?? null;
    if (!is_array($p)) {
        return null;
    }
    if ((int) ($p['expires'] ?? 0) < time()) {
        otp_clear();
        return null;
    }
    return $p;
}

function otp_clear(): void
{
    unset($_SESSION['otp_pending']);
}

/** Mask an email for display: jo***@gmail.com */
function otp_mask_email(string $email): string
{
    $at = strpos($email, '@');
    if ($at === false || $at < 1) {
        return $email;
    }
    $user = substr($email, 0, $at);
    $domain = substr($email, $at);
    $keep = min(2, strlen($user));
    return substr($user, 0, $keep) . str_repeat('*', max(1, strlen($user) - $keep)) . $domain;
}

/**
 * Verify a submitted code against the pending login.
 * Returns ['ok' => bool, 'user' => array|null, 'error' => string].
 */
function otp_verify(string $code): array
{
    $p = otp_pending();
    if ($p === null) {
        return ['ok' => false, 'user' => null, 'error' => 'El código expiró. Inicia sesión de nuevo.'];
    }
    if ((int) ($p['attempts'] ?? 0) >= 5) {
        otp_clear();
        return ['ok' => false, 'user' => null, 'error' => 'Demasiados intentos. Inicia sesión de nuevo.'];
    }
    $_SESSION['otp_pending']['attempts'] = (int) $p['attempts'] + 1;

    $code = preg_replace('/\D/', '', $code);
    if ($code !== '' && hash_equals((string) $p['hash'], hash('sha256', $code))) {
        $user = $p['user'];
        otp_clear();
        return ['ok' => true, 'user' => $user, 'error' => ''];
    }
    return ['ok' => false, 'user' => null, 'error' => 'Código incorrecto. Verifica e intenta otra vez.'];
}

/** Re-send the code (rate-limited: 60s cooldown, max 3 resends). */
function otp_resend(): array
{
    $p = otp_pending();
    if ($p === null) {
        return ['ok' => false, 'error' => 'La sesión de verificación expiró. Inicia sesión de nuevo.'];
    }
    if ((int) ($p['resends'] ?? 0) >= 3) {
        return ['ok' => false, 'error' => 'Llegaste al límite de reenvíos. Inicia sesión de nuevo.'];
    }
    if (time() - (int) ($p['last_sent'] ?? 0) < 60) {
        return ['ok' => false, 'error' => 'Espera un momento antes de pedir otro código.'];
    }
    $_SESSION['otp_pending']['resends'] = (int) ($p['resends'] ?? 0) + 1;
    return otp_start($p['user']);
}
