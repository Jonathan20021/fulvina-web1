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
$emailRaw = strtolower(trim((string) ($_POST['email'] ?? '')));
$emailHash = $emailRaw !== '' ? login_email_key($emailRaw) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tooMany = login_recent_failures($ipKey) >= 8
        || ($emailHash !== null && login_recent_failures_for_email($emailHash) >= 10);

    if ($tooMany) {
        flash('warning', 'Demasiados intentos fallidos. Espera unos minutos antes de volver a intentar.');
    } else {
        usleep(300000); // constant ~0.3s cost: slows brute force and timing analysis
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email !== '' && $password !== '' && login_user($email, $password)) {
            login_clear_failures($ipKey, $emailHash);
            flash('success', 'Sesion iniciada.');
            redirect('crm/index.php');
        }

        login_record_failure($ipKey, $emailHash);
        flash('warning', 'Credenciales invalidas.');
    }
}
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

                <span class="sx-kicker" style="margin-top:1.4rem">Panel interno</span>
                <h1>Entrar al CRM</h1>
                <p class="sxl-sub">Acceso para ventas, soporte e ingenieria de SCH MEDICOS.</p>

                <?php foreach (flashes() as $item): ?>
                    <div class="sxl-alert <?= $item['type'] === 'success' ? 'is-ok' : 'is-warn' ?>">
                        <i data-lucide="<?= $item['type'] === 'success' ? 'check-circle-2' : 'alert-triangle' ?>"></i><?= e($item['message']) ?>
                    </div>
                <?php endforeach; ?>

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
