<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!headers_sent()) {
    header('X-Frame-Options: DENY'); // the login screen is never framed
}

if (is_logged_in()) {
    redirect('crm/index.php');
}

verify_csrf();

$isLocal = is_local_env();
$ipKey = login_ip_key();

$step = otp_pending() !== null ? 'otp' : 'credentials';
$action = (string) ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'otp_cancel') {
        otp_clear();
        redirect('crm/login.php');
    } elseif ($action === 'otp_resend' && otp_pending() !== null) {
        $r = otp_resend();
        flash($r['ok'] ? 'success' : 'warning', $r['ok'] ? 'Te enviamos un nuevo código.' : $r['error']);
        redirect('crm/login.php');
    } elseif ($action === 'otp_verify' && otp_pending() !== null) {
        $res = otp_verify((string) ($_POST['code'] ?? ''));
        if ($res['ok']) {
            establish_session($res['user']);
            login_clear_failures($ipKey, login_email_key((string) ($res['user']['email'] ?? '')));
            flash('success', 'Sesion iniciada.');
            redirect('crm/index.php');
        }
        flash('warning', $res['error']);
        redirect('crm/login.php');
    } else {
        // ---- Credentials step ----
        $emailRaw = strtolower(trim((string) ($_POST['email'] ?? '')));
        $emailHash = $emailRaw !== '' ? login_email_key($emailRaw) : null;
        $tooMany = login_recent_failures($ipKey) >= 8
            || ($emailHash !== null && login_recent_failures_for_email($emailHash) >= 10);

        if ($tooMany) {
            flash('warning', 'Demasiados intentos fallidos. Espera unos minutos antes de volver a intentar.');
        } else {
            usleep(300000); // constant ~0.3s cost: slows brute force and timing analysis
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $user = ($email !== '' && $password !== '') ? authenticate_user($email, $password) : null;

            if ($user !== null) {
                // OTP off, or local demo admin (no real inbox) → straight in.
                if (!otp_active() || !empty($user['demo'])) {
                    establish_session($user);
                    login_clear_failures($ipKey, $emailHash);
                    flash('success', 'Sesion iniciada.');
                    redirect('crm/index.php');
                }
                // OTP required: email the code, then move to the verify step (PRG).
                $start = otp_start($user);
                if ($start['ok']) {
                    login_clear_failures($ipKey, $emailHash); // password was correct
                    flash('success', 'Te enviamos un código de verificación a tu correo.');
                } else {
                    flash('warning', 'No se pudo enviar el código: ' . $start['error']);
                }
            } else {
                login_record_failure($ipKey, $emailHash);
                flash('warning', 'Credenciales invalidas.');
            }
        }
        redirect('crm/login.php');
    }
}

$pending = otp_pending();
if ($pending === null && $step === 'otp') {
    $step = 'credentials';
}
$pendingEmailMask = $pending !== null ? otp_mask_email((string) ($pending['email'] ?? '')) : '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Acceso CRM | SCH MEDICOS</title>
    <link rel="icon" href="<?= asset('assets/media/cropped-logo_SCH_-removebg-preview-32x32.png') ?>" sizes="32x32">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400..800&family=Geist+Mono:wght@500..600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset('assets/css/tailwind.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/site-v2.css') ?>">
    <script defer src="https://unpkg.com/lucide@latest"></script>
    <script defer src="<?= asset('assets/js/app.js') ?>"></script>
</head>
<body class="sx sxl">
    <main class="sxl-shell">
        <aside class="sxl-aside">
            <img class="sxl-aside__img" src="<?= asset('assets/media/5.png') ?>" alt="" aria-hidden="true">
            <a href="<?= url('index.php') ?>" class="sxl-brand">
                <img src="<?= asset(APP_LOGO) ?>" alt="SCH MEDICOS">
                <span><strong>SCH MEDICOS</strong><small><?= e(APP_TAGLINE) ?></small></span>
            </a>
            <div>
                <h2 class="sxl-aside__h">Operacion comercial, soporte y equipos bajo control.</h2>
                <p class="sxl-aside__p">Clientes, cotizaciones, tickets, garantias y agenda de mantenimiento conectados para el equipo SCH.</p>
                <div class="sxl-points">
                    <div class="sxl-point"><i data-lucide="line-chart"></i>Pipeline comercial y cotizaciones en un solo lugar.</div>
                    <div class="sxl-point"><i data-lucide="ticket"></i>Tickets de soporte con prioridad y vencimiento.</div>
                    <div class="sxl-point"><i data-lucide="package"></i>Inventario de equipos, garantias y mantenimiento.</div>
                </div>
            </div>
        </aside>

        <section class="sxl-main">
            <div class="sxl-card">
                <div class="sxl-cardbrand">
                    <img src="<?= asset(APP_LOGO) ?>" alt="SCH MEDICOS">
                    <strong>SCH MEDICOS</strong>
                </div>

                <a href="<?= url('index.php') ?>" class="sxl-back"><i data-lucide="arrow-left"></i>Volver al sitio</a>

                <?php if ($step === 'otp'): ?>
                    <span class="sx-kicker" style="margin-top:1.4rem">Verificacion en dos pasos</span>
                    <h1>Ingresa el codigo</h1>
                    <p class="sxl-sub">Enviamos un codigo de 6 digitos a <strong><?= e($pendingEmailMask) ?></strong>. Vence en 10 minutos.</p>
                <?php else: ?>
                    <span class="sx-kicker" style="margin-top:1.4rem">Panel interno</span>
                    <h1>Entrar al CRM</h1>
                    <p class="sxl-sub">Acceso para ventas, soporte e ingenieria de SCH MEDICOS.</p>
                <?php endif; ?>

                <?php foreach (flashes() as $item): ?>
                    <div class="sxl-alert <?= $item['type'] === 'success' ? 'is-ok' : 'is-warn' ?>">
                        <i data-lucide="<?= $item['type'] === 'success' ? 'check-circle-2' : 'alert-triangle' ?>"></i><?= e($item['message']) ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($step === 'otp'): ?>
                <form method="post" class="sxl-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="otp_verify">
                    <label class="sxl-field">
                        <span>Codigo de verificacion</span>
                        <div class="sxl-input">
                            <i data-lucide="shield-check"></i>
                            <input type="text" name="code" required autofocus inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" placeholder="123 456" style="letter-spacing:.3em;font-weight:600">
                        </div>
                    </label>
                    <button class="sxl-submit" type="submit">Verificar y entrar <i data-lucide="arrow-right"></i></button>
                </form>
                <div class="sxl-otp-actions" style="display:flex;gap:.75rem;justify-content:space-between;margin-top:.9rem;font-size:.85rem">
                    <form method="post" style="margin:0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="otp_resend">
                        <button type="submit" class="sxl-link" style="background:none;border:0;color:var(--accent,#2563eb);cursor:pointer;padding:0">Reenviar codigo</button>
                    </form>
                    <form method="post" style="margin:0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="otp_cancel">
                        <button type="submit" class="sxl-link" style="background:none;border:0;color:#64748b;cursor:pointer;padding:0">Cancelar</button>
                    </form>
                </div>
                <?php else: ?>
                <form method="post" class="sxl-form">
                    <?= csrf_field() ?>
                    <label class="sxl-field">
                        <span>Correo</span>
                        <div class="sxl-input">
                            <i data-lucide="mail"></i>
                            <input type="email" name="email" required autofocus autocomplete="username" value="<?= $isLocal ? 'admin@sch.local' : '' ?>" placeholder="correo@sch.com.do">
                        </div>
                    </label>
                    <label class="sxl-field">
                        <span>Contrasena</span>
                        <div class="sxl-input">
                            <i data-lucide="lock"></i>
                            <input type="password" name="password" id="login-pwd" required autocomplete="current-password" value="<?= $isLocal ? 'admin123' : '' ?>">
                            <button type="button" class="sxl-toggle" aria-label="Mostrar u ocultar contrasena" onclick="schTogglePwd(this)"><i data-lucide="eye"></i></button>
                        </div>
                    </label>
                    <button class="sxl-submit" type="submit">Iniciar sesion <i data-lucide="arrow-right"></i></button>
                </form>

                <?php if ($isLocal): ?>
                <div class="sxl-demo">
                    <i data-lucide="info"></i>
                    <span>Demo local: <code>admin@sch.local</code> / <code>admin123</code></span>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <p class="sxl-secure"><i data-lucide="shield-check"></i>Conexion segura &middot; SCH MEDICOS, SRL</p>
            </div>
        </section>
    </main>
    <script>
        function schTogglePwd(btn) {
            var i = document.getElementById('login-pwd');
            if (!i) return;
            i.type = i.type === 'password' ? 'text' : 'password';
            btn.innerHTML = '<i data-lucide="' + (i.type === 'password' ? 'eye' : 'eye-off') + '"></i>';
            if (window.lucide) window.lucide.createIcons();
            i.focus();
        }
    </script>
</body>
</html>
