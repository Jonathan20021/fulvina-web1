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
    return '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;color:#0f172a">'
        . '<h2 style="margin:0 0 4px;font-size:18px">' . $brand . '</h2>'
        . '<p style="margin:0 0 18px;color:#64748b;font-size:13px">Código de verificación</p>'
        . '<p style="margin:0 0 8px">' . $hi . '</p>'
        . '<p style="margin:0 0 16px;color:#475569">Usa este código para entrar al CRM. Vence en 10 minutos.</p>'
        . '<div style="font-size:30px;font-weight:700;letter-spacing:6px;background:#f1f5f9;border-radius:10px;padding:16px;text-align:center;margin:0 0 18px">' . $codeFmt . '</div>'
        . '<p style="margin:0;color:#94a3b8;font-size:12px">Si no intentaste iniciar sesión, ignora este correo y cambia tu contraseña.</p>'
        . '</div>';
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
