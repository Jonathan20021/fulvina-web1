<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// The installer is a powerful, unauthenticated provisioning endpoint. Restrict
// it to a genuine local host so it can never seed/leak on a public deployment.
if (!is_local_env()) {
    http_response_code(404);
    exit('No encontrado.');
}

$ran = isset($_POST['run']);
$result = null;
$error = null;

function install_seed(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, "activo", NOW(), NOW())');
        $stmt->execute(['Administrador SCH', 'admin@sch.local', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
        $stmt->execute(['Soporte Tecnico', 'soporte@sch.local', password_hash('soporte123', PASSWORD_DEFAULT), 'soporte']);
        $stmt->execute(['Ventas SCH', 'ventas@sch.local', password_hash('ventas123', PASSWORD_DEFAULT), 'ventas']);
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    if ($count === 0) {
        $clients = [
            ['Hospital Metropolitano de Santiago', '101-00000-1', 'compras@hms.local', '809-000-0000', 'Santiago de los Caballeros', 'Privado'],
            ['Plaza de la Salud', '101-00000-2', 'biomedica@plaza.local', '809-000-0001', 'Santo Domingo', 'Privado'],
            ['Hospital Jaime Mota', '101-00000-3', 'mantenimiento@jaimemota.local', '809-000-0002', 'Barahona', 'Publico'],
            ['CAID', '101-00000-4', 'operaciones@caid.local', '809-000-0003', 'Santo Domingo', 'ONG'],
            ['Cedimat', '101-00000-5', 'compras@cedimat.local', '809-000-0004', 'Santo Domingo', 'Privado'],
        ];
        $stmt = $pdo->prepare('INSERT INTO clients (name, rnc, email, phone, city, sector, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, "activo", NOW(), NOW())');
        foreach ($clients as $client) {
            $stmt->execute($client);
        }
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM equipment')->fetchColumn();
    if ($count === 0) {
        $equipment = [
            [1, 'Sistema central de gases', 'Precision Medical', 'Central O2', 'SCH-HMS-001', 'Emergencia', 'Cuarto tecnico', 'activo', '+10 days'],
            [2, 'Lampara quirurgica', 'Hill-Rom', 'OR Light', 'SCH-PDS-087', 'Quirofano 2', 'Sala quirurgica', 'requiere revision', '+3 days'],
            [3, 'Manifold Lifeline', 'Precision Medical', 'Lifeline', 'SCH-HJM-044', 'Gases', 'Central', 'activo', '+18 days'],
            [4, 'Ventilador pediatrico', 'Drive DeVilbiss', 'Pediatric', 'SCH-CAID-018', 'Terapia', 'Area pediatrica', 'activo', '+8 days'],
        ];
        $stmt = $pdo->prepare('INSERT INTO equipment (client_id, name, brand, model, serial, area, location, status, installation_date, warranty_until, next_service_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_SUB(CURDATE(), INTERVAL 220 DAY), DATE_ADD(CURDATE(), INTERVAL 180 DAY), ?, NOW(), NOW())');
        foreach ($equipment as $item) {
            $next = date('Y-m-d', strtotime($item[8]));
            $stmt->execute([$item[0], $item[1], $item[2], $item[3], $item[4], $item[5], $item[6], $item[7], $next]);
        }
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
    if ($count === 0) {
        $tickets = [
            [1, 1, 'Revision de presion en linea O2', 'Presion irregular reportada por emergencia. Revisar manifold y alarmas sectoriales.', 'Alta', 'Abierto', 'Biomedica HMS', 'biomedica@hms.local', 2, '+2 days'],
            [2, 2, 'Mantenimiento preventivo vencido', 'Programar visita para mantenimiento preventivo de lampara quirurgica.', 'Media', 'En proceso', 'Mantenimiento Plaza', 'biomedica@plaza.local', 2, '+5 days'],
            [3, 3, 'Cotizar repuestos para manifold', 'Cliente solicita repuestos y certificacion posterior.', 'Media', 'Cotizado', 'Jefe mantenimiento', 'mantenimiento@jaimemota.local', 3, '+7 days'],
        ];
        $stmt = $pdo->prepare('INSERT INTO tickets (client_id, equipment_id, subject, description, priority, status, reported_by, reported_email, assigned_to, due_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        foreach ($tickets as $ticket) {
            $ticket[9] = date('Y-m-d', strtotime($ticket[9]));
            $stmt->execute($ticket);
        }
        $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, author_name, body, is_internal, created_at) VALUES (1, 2, "Soporte Tecnico", "Ticket clasificado como alta prioridad. Coordinar visita hoy.", 1, NOW())')->execute();
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM quotes')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO quotes (client_id, quote_number, title, status, valid_until, subtotal, tax_rate, tax_amount, total, notes, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 18, ?, ?, ?, 3, NOW(), NOW())');
        $stmt->execute([5, 'SCH-' . date('Y') . '-0001', 'Camas UCI y accesorios', 'Enviado', date('Y-m-d', strtotime('+30 days')), 15635, 2814.30, 18449.30, 'Validez de 30 dias. Entrega segun disponibilidad.']);
        $quoteId = (int) $pdo->lastInsertId();
        $items = [
            ['Camas UCI electricas', 3, 4200, 12600],
            ['Accesorios y barandas', 3, 595, 1785],
            ['Instalacion y puesta en marcha', 1, 1250, 1250],
        ];
        $itemStmt = $pdo->prepare('INSERT INTO quote_items (quote_id, description, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)');
        foreach ($items as $item) {
            $itemStmt->execute([$quoteId, ...$item]);
        }
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO leads (name, email, phone, company, type, message, status, created_at) VALUES (?, ?, ?, ?, ?, ?, "nuevo", NOW())');
        $stmt->execute(['Direccion de compras', 'compras@clinica.local', '809-000-1100', 'Clinica Demo', 'Cotizacion', 'Solicitan cotizacion de camas y gases para ampliacion de emergencia.']);
    }
}

if ($ran) {
    try {
        $serverDsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET;
        $pdo = new PDO($serverDsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . DB_NAME . '`');

        $schema = file_get_contents(__DIR__ . '/database/schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            $pdo->exec($statement);
        }
        install_seed($pdo);
        $result = 'Base instalada correctamente. Usuario: admin@sch.local / admin123';
    } catch (Throwable $e) {
        error_log('install.php: ' . $e->getMessage());
        $error = 'No se pudo conectar o instalar. Revisa config/database.php y que MySQL este encendido.';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Instalador SCH CRM</title>
    <link rel="stylesheet" href="<?= asset('assets/css/tailwind.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body class="bg-[#f5f8fb] text-slate-950">
    <main class="install-shell">
        <section class="install-card">
            <span class="login-card__brand" style="margin-top:0">
                <img src="<?= asset(APP_LOGO) ?>" alt="SCH MEDICOS" width="200" height="182">
                <strong>SCH MEDICOS</strong>
            </span>
            <h1>Instalar CRM SCH</h1>
            <p>Este instalador crea la base <strong><?= e(DB_NAME) ?></strong>, tablas, relaciones y datos demo para XAMPP local. Verifica que MySQL este encendido en el panel de XAMPP.</p>
            <div class="crm-mini-grid mt-5">
                <div class="crm-mini-stat"><span>Host</span><strong><?= e(DB_HOST) ?>:<?= e(DB_PORT) ?></strong></div>
                <div class="crm-mini-stat"><span>Usuario</span><strong><?= e(DB_USER) ?></strong></div>
                <div class="crm-mini-stat"><span>Base</span><strong><?= e(DB_NAME) ?></strong></div>
                <div class="crm-mini-stat"><span>Servidor</span><strong>PHP <?= e(PHP_VERSION) ?></strong></div>
            </div>

            <?php if ($result): ?>
                <div class="mt-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800"><?= e($result) ?></div>
                <a href="<?= url('crm/login.php') ?>" class="crm-primary-btn mt-5">Entrar al CRM</a>
            <?php elseif ($error): ?>
                <div class="mt-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-800"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="mt-6">
                <input type="hidden" name="run" value="1">
                <button class="crm-primary-btn" type="submit">Crear / actualizar base de datos</button>
            </form>
            <p class="mt-5 text-xs leading-5 text-slate-500">En produccion cambia las credenciales por defecto y elimina o protege este archivo.</p>
        </section>
    </main>
</body>
</html>
