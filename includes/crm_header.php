<?php

require_login();

$crmTitle = $crmTitle ?? 'Panel CRM';
$user = current_user();
$userName = $user['name'] ?? 'Usuario';
$userRole = $user['role'] ?? 'Administrador';
$parts = preg_split('/\s+/', trim($userName)) ?: [];
$initials = strtoupper(mb_substr($parts[0] ?? 'S', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));

// Notifications (recent tickets) for the top bell
$navHasTickets = db(false) && table_exists('tickets');
$navNotifs = $navHasTickets
    ? fetch_all("SELECT tickets.id, tickets.subject, tickets.status, tickets.priority, tickets.created_at, clients.name AS client_name FROM tickets LEFT JOIN clients ON clients.id = tickets.client_id ORDER BY tickets.created_at DESC LIMIT 5")
    : [
        ['id' => 267, 'subject' => 'Tomógrafo intermitente, error 8042', 'status' => 'Abierto', 'priority' => 'Alta', 'created_at' => date('Y-m-d H:i:s'), 'client_name' => 'Hospital Metropolitano'],
        ['id' => 258, 'subject' => 'Alarma de presión baja', 'status' => 'Abierto', 'priority' => 'Critica', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 hour')), 'client_name' => 'CAID'],
        ['id' => 263, 'subject' => 'Falla de encendido', 'status' => 'En proceso', 'priority' => 'Alta', 'created_at' => date('Y-m-d H:i:s', strtotime('-5 hour')), 'client_name' => 'Plaza de la Salud'],
    ];
$navOpenCount = $navHasTickets ? db_count('tickets', "status IN ('Abierto','En proceso')") : count($navNotifs);

// Sidebar live counts
$navHasDb = db(false) && table_exists('clients');
$navCounts = [
    'clientes'     => $navHasDb ? db_count('clients', "status = 'activo'") : 28,
    'equipos'      => $navHasDb ? db_count('equipment') : 152,
    'cotizaciones' => $navHasDb ? db_count('quotes', "status IN ('Borrador','Enviado','Cotizado','Negociacion','Aprobado')") : 18,
    'tickets'      => $navHasTickets ? db_count('tickets', "status IN ('Abierto','En proceso')") : 6,
];

// Grouped navigation: href => [label, icon, countKey|null, isAlert]
$crmNavGroups = [
    'General' => [
        'index.php' => ['Panel', 'layout-dashboard', null, false],
    ],
    'Comercial' => [
        'clientes.php'     => ['Clientes', 'building-2', 'clientes', false],
        'cotizaciones.php' => ['Cotizaciones', 'file-text', 'cotizaciones', false],
    ],
    'Soporte técnico' => [
        'equipos.php' => ['Equipos', 'monitor', 'equipos', false],
        'tickets.php' => ['Tickets', 'life-buoy', 'tickets', true],
        'agenda.php'  => ['Agenda', 'calendar-days', null, false],
    ],
    'Sistema' => [
        'reportes.php'      => ['Reportes', 'bar-chart-3', null, false],
        'configuracion.php' => ['Configuración', 'settings', null, false],
    ],
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($crmTitle) ?> | CRM SCH MEDICOS</title>
    <link rel="icon" href="<?= asset('assets/media/cropped-logo_SCH_-removebg-preview-32x32.png') ?>" sizes="32x32">
    <script>(function(){try{if(localStorage.getItem('crmNav')==='collapsed')document.documentElement.classList.add('crm-collapsed');}catch(e){}})();</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="<?= asset('assets/css/tailwind.css') ?>">
    <script defer src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
    <script defer src="<?= asset('assets/js/app.js') ?>"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-sch-page text-slate-900" x-data="{ nav: false }">
<a href="#contenido" class="skip-link">Saltar al contenido</a>
<div class="crm-shell">
    <header class="crm-topbar">
        <div class="crm-topbar__brand">
            <?= brand_lock('crm') ?>
            <button class="crm-icon-btn lg:hidden" type="button" @click.stop="nav = !nav" :aria-expanded="nav" aria-label="Abrir navegacion" aria-controls="crm-sidebar">
                <i data-lucide="menu" class="h-5 w-5"></i>
            </button>
        </div>

        <form action="<?= url('crm/buscar.php') ?>" method="get" class="crm-search" role="search">
            <i data-lucide="search" class="h-4 w-4"></i>
            <input name="q" placeholder="Buscar clientes, equipos, cotizaciones, tickets..." aria-label="Buscar en el CRM">
            <kbd>Ctrl K</kbd>
        </form>

        <div class="crm-topbar__actions" x-data="{ bell: false, user: false }">
            <a href="<?= url('crm/cotizaciones.php?new=1') ?>" class="crm-top-btn"><i data-lucide="plus" class="h-4 w-4"></i><span>Nueva cotizacion</span></a>
            <a href="<?= url('crm/tickets.php?new=1') ?>" class="crm-top-btn crm-top-btn--ghost"><i data-lucide="plus" class="h-4 w-4"></i><span>Nuevo ticket</span></a>

            <div class="dash-dd" @click.outside="bell = false">
                <button type="button" class="crm-bell" @click="bell = !bell; user = false" :aria-expanded="bell" aria-label="Notificaciones">
                    <i data-lucide="bell" class="h-5 w-5" aria-hidden="true"></i>
                    <?php if ($navOpenCount > 0): ?><b aria-hidden="true"><?= e((string) min(99, $navOpenCount)) ?></b><?php endif; ?>
                </button>
                <div class="dash-pop dash-pop--wide" x-show="bell" x-transition.origin.top.right x-cloak>
                    <div class="dash-pop__head"><b>Notificaciones</b><span><?= e((string) $navOpenCount) ?> activas</span></div>
                    <hr>
                    <?php foreach ($navNotifs as $n): ?>
                        <?php $pc = in_array($n['priority'] ?? '', ['Critica', 'Alta'], true) ? '#dc2626' : (($n['priority'] ?? '') === 'Media' ? '#d97706' : '#0a7d36'); ?>
                        <a class="dash-noti" href="<?= url('crm/tickets.php?id=' . (int) $n['id']) ?>">
                            <span class="dash-noti__dot" style="background:<?= e($pc) ?>"></span>
                            <span class="dash-noti__body"><b><?= e($n['subject']) ?></b><span><?= e($n['client_name'] ?? 'Cliente') ?> &middot; <?= e($n['status']) ?></span></span>
                            <time><?= e(date('d/m H:i', strtotime($n['created_at'] ?? 'now'))) ?></time>
                        </a>
                    <?php endforeach; ?>
                    <hr>
                    <a class="dash-pop__item" href="<?= url('crm/tickets.php') ?>"><i data-lucide="inbox"></i>Ver todos los tickets</a>
                </div>
            </div>

            <div class="dash-dd" @click.outside="user = false">
                <div class="crm-user-chip" role="button" tabindex="0" @click="user = !user; bell = false" @keydown.enter="user = !user" @keydown.space.prevent="user = !user" :aria-expanded="user">
                    <span class="crm-user-chip__avatar"><?= e($initials) ?></span>
                    <span class="crm-user-chip__meta">
                        <b><?= e($userName) ?></b>
                        <small><?= e(ucfirst($userRole)) ?></small>
                    </span>
                    <i data-lucide="chevron-down" class="h-4 w-4"></i>
                </div>
                <div class="dash-pop" x-show="user" x-transition.origin.top.right x-cloak style="min-width:220px">
                    <div class="dash-pop__label">Sesión &middot; <?= e(ucfirst($userRole)) ?></div>
                    <a class="dash-pop__item" href="<?= url('crm/configuracion.php') ?>"><i data-lucide="settings"></i>Configuración</a>
                    <a class="dash-pop__item" href="<?= url('crm/configuracion.php') ?>"><i data-lucide="users-round"></i>Usuarios y accesos</a>
                    <a class="dash-pop__item" href="<?= url('index.php') ?>"><i data-lucide="globe"></i>Ver sitio público</a>
                    <hr>
                    <a class="dash-pop__item dash-pop__item--danger" href="<?= url('crm/logout.php') ?>"><i data-lucide="log-out"></i>Cerrar sesión</a>
                </div>
            </div>
        </div>
    </header>

    <div class="crm-nav-backdrop" x-show="nav" @click="nav = false" x-transition.opacity x-cloak aria-hidden="true"></div>
    <div class="crm-body">
        <aside id="crm-sidebar" class="crm-nav" :class="nav ? 'is-open' : ''" @click.outside="nav = false">
            <div class="crm-nav__top">
                <button type="button" class="crm-nav-collapse" onclick="crmToggleNav()" title="Contraer / expandir menú" aria-label="Contraer o expandir menú">
                    <i data-lucide="panel-left-close" class="crm-nav-collapse__ic crm-nav-collapse__ic--close"></i>
                    <i data-lucide="panel-left-open" class="crm-nav-collapse__ic crm-nav-collapse__ic--open"></i>
                    <span>Contraer menú</span>
                </button>
            </div>
            <nav class="crm-nav__links" aria-label="Navegacion CRM">
                <?php foreach ($crmNavGroups as $groupLabel => $groupItems): ?>
                    <p class="crm-nav__label"><?= e($groupLabel) ?></p>
                    <?php foreach ($groupItems as $href => [$label, $icon, $countKey, $isAlert]): ?>
                        <a href="<?= url('crm/' . $href) ?>" class="<?= e(is_active($href)) ?>" <?= is_active($href) ? 'aria-current="page"' : '' ?> title="<?= e($label) ?>">
                            <i data-lucide="<?= e($icon) ?>" class="h-[18px] w-[18px]"></i>
                            <span><?= e($label) ?></span>
                            <?php if ($countKey && (int) ($navCounts[$countKey] ?? 0) > 0): ?>
                                <span class="crm-nav__count <?= $isAlert ? 'crm-nav__count--alert' : '' ?>"><?= e((string) $navCounts[$countKey]) ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>
            <div class="crm-nav__footer">
                <div class="crm-local-card">
                    <p>SCH MEDICOS, SRL</p>
                    <span>Santo Domingo, Rep. Dom.</span>
                    <hr>
                    <small>Hora local</small>
                    <strong><?= e(date('H:i')) ?></strong>
                    <span><?= e(date('d/m/Y')) ?></span>
                    <?php if (!empty($user['demo'])): ?>
                        <em><i data-lucide="database" class="h-3 w-3"></i> Modo demo sin MySQL</em>
                    <?php endif; ?>
                </div>
                <a href="<?= url('crm/logout.php') ?>" class="crm-logout" title="Cerrar sesión">
                    <i data-lucide="log-out" class="h-4 w-4"></i><span>Cerrar sesion</span>
                </a>
            </div>
        </aside>

        <div class="crm-content">
            <div class="crm-page-title">
                <p>SCH MEDICOS &middot; CRM</p>
                <h1><?= e($crmTitle) ?></h1>
            </div>

            <?php foreach (flashes() as $i => $item): ?>
                <div id="flash-crm-<?= $i ?>" class="mx-4 mt-4 rounded-xl border <?= $item['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800' ?> px-4 py-3 text-sm font-semibold lg:mx-0">
                    <div class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-2"><i data-lucide="<?= $item['type'] === 'success' ? 'check-circle-2' : 'alert-triangle' ?>" class="h-4 w-4"></i><?= e($item['message']) ?></span>
                        <button type="button" data-dismiss="#flash-crm-<?= $i ?>" class="rounded p-1 hover:bg-black/5" aria-label="Cerrar mensaje"><i data-lucide="x" class="h-4 w-4"></i></button>
                    </div>
                </div>
            <?php endforeach; ?>

            <main id="contenido" class="crm-main">
