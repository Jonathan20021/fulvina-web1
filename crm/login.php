<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) {
    redirect('crm/index.php');
}

verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (login_user($email, $password)) {
        flash('success', 'Sesion iniciada.');
        redirect('crm/index.php');
    }

    flash('warning', 'Credenciales invalidas.');
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
    <link rel="stylesheet" href="<?= asset('assets/css/tailwind.css') ?>">
    <script defer src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
    <script defer src="<?= asset('assets/js/app.js') ?>"></script>
</head>
<body class="bg-sch-graphite text-white login-page">
    <main class="login-shell">
        <section class="login-media">
            <img src="<?= asset('assets/media/5.png') ?>" alt="Pasillo hospitalario intervenido por SCH MEDICOS">
            <div class="login-media__content">
                <a href="<?= url('index.php') ?>" class="sch-brand sch-brand--light">
                    <span class="sch-brand__plaque"><img src="<?= asset(APP_LOGO) ?>" alt="SCH MEDICOS"></span>
                    <span class="sch-brand__text"><strong>SCH MEDICOS</strong><small><?= e(APP_TAGLINE) ?></small></span>
                </a>
                <div>
                    <h1>Operacion comercial, soporte tecnico y equipos bajo control.</h1>
                    <p>Clientes, cotizaciones, tickets, garantias y agenda de mantenimiento conectados para el equipo SCH.</p>
                    <ul class="login-media__points">
                        <li><i data-lucide="check-circle-2" class="h-5 w-5"></i>Pipeline comercial y cotizaciones en un solo lugar.</li>
                        <li><i data-lucide="check-circle-2" class="h-5 w-5"></i>Tickets de soporte con prioridad y vencimiento.</li>
                        <li><i data-lucide="check-circle-2" class="h-5 w-5"></i>Inventario de equipos, garantias y mantenimiento.</li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="login-panel">
            <div class="login-card">
                <a href="<?= url('index.php') ?>" class="login-back"><i data-lucide="arrow-left" class="h-4 w-4"></i>Volver al sitio</a>

                <div class="login-brand">
                    <span class="login-brand__logo"><img src="<?= asset(APP_LOGO) ?>" alt="SCH MEDICOS"></span>
                    <div class="login-brand__text">
                        <strong>SCH MEDICOS</strong>
                        <small><?= e(APP_TAGLINE) ?></small>
                    </div>
                </div>

                <div class="login-head">
                    <span class="login-eyebrow"><i data-lucide="lock-keyhole" class="h-3.5 w-3.5"></i> Panel interno</span>
                    <h2>Entrar al CRM</h2>
                    <p>Acceso para ventas, soporte e ingenieria de SCH MEDICOS.</p>
                </div>

                <?php foreach (flashes() as $i => $item): ?>
                    <div class="login-alert <?= $item['type'] === 'success' ? 'is-ok' : 'is-warn' ?>">
                        <i data-lucide="<?= $item['type'] === 'success' ? 'check-circle-2' : 'alert-triangle' ?>" class="h-4 w-4"></i><?= e($item['message']) ?>
                    </div>
                <?php endforeach; ?>

                <form method="post" class="login-form">
                    <?= csrf_field() ?>
                    <label class="login-field">
                        <span>Correo</span>
                        <div class="login-input">
                            <i data-lucide="mail"></i>
                            <input type="email" name="email" required autofocus autocomplete="username" value="admin@sch.local" placeholder="correo@sch.com.do">
                        </div>
                    </label>
                    <label class="login-field">
                        <span>Contrasena</span>
                        <div class="login-input">
                            <i data-lucide="lock"></i>
                            <input type="password" name="password" id="login-pwd" required autocomplete="current-password" value="admin123">
                            <button type="button" class="login-toggle" aria-label="Mostrar u ocultar contrasena" onclick="schTogglePwd(this)"><i data-lucide="eye"></i></button>
                        </div>
                    </label>
                    <button class="login-submit" type="submit">Iniciar sesion <i data-lucide="arrow-right"></i></button>
                </form>

                <div class="login-demo">
                    <i data-lucide="info" class="h-4 w-4"></i>
                    <span>Demo local: <code>admin@sch.local</code> / <code>admin123</code></span>
                </div>

                <p class="login-secure"><i data-lucide="shield-check"></i> Conexion segura &middot; SCH MEDICOS, SRL</p>
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
