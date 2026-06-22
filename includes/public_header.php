<?php

$pageTitle = $pageTitle ?? APP_NAME . ' | Equipos médicos, gases medicinales y soporte hospitalario';
$pageDescription = $pageDescription ?? SEO_DEFAULT_DESCRIPTION;
$pageImage = $pageImage ?? asset('assets/media/og-cover.png');
$canonical = $canonical ?? current_url();
$ogScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$ogHost = $_SERVER['HTTP_HOST'] ?? '';
$pageImageAbs = (preg_match('~^https?://~', $pageImage) || $ogHost === '') ? $pageImage : ($ogScheme . '://' . $ogHost . $pageImage);
$bodyClass = trim('site-public ' . ($bodyClass ?? ''));
$navItems = [
    'index.php' => 'Inicio',
    'servicios.php' => 'Productos',
    'proyectos.php' => 'Proyectos',
    'sobre-nosotros.php' => 'Nosotros',
    'soporte.php' => 'Soporte',
];

$schema = $schema ?? [
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => 'SCH MEDICOS',
    'url' => current_url(),
    'logo' => asset(APP_LOGO),
    'email' => APP_EMAIL,
    'telephone' => APP_PHONE,
    'foundingDate' => APP_FOUNDED,
    'address' => [
        '@type' => 'PostalAddress',
        'addressLocality' => 'Santo Domingo',
        'addressCountry' => 'DO',
    ],
    'sameAs' => ['https://schmedicos.com/'],
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($pageDescription) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="<?= e($canonical) ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="<?= e($pageDescription) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta property="og:site_name" content="SCH MEDICOS">
    <meta property="og:image" content="<?= e($pageImageAbs) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="SCH MEDICOS">
    <meta property="og:locale" content="es_DO">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="<?= e($pageImageAbs) ?>">
    <meta name="theme-color" content="#0666b3">
    <link rel="icon" href="<?= asset('assets/media/cropped-logo_SCH_-removebg-preview-32x32.png') ?>" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= asset('assets/media/cropped-logo_SCH_-removebg-preview-180x180.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <?php if (!empty($pageFontsGeist)): ?>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400..800&family=Geist+Mono:wght@500..600&display=swap" rel="stylesheet">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= asset_v('assets/css/tailwind.css') ?>">
    <link rel="stylesheet" href="<?= asset_v('assets/css/app.css') ?>">
    <?php foreach (($pageStyles ?? []) as $pageStyle): ?>
    <link rel="stylesheet" href="<?= asset_v($pageStyle) ?>">
    <?php endforeach; ?>
    <script defer src="<?= asset_v('assets/js/app.js') ?>"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://unpkg.com/lucide@latest"></script>
    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</head>
<body class="<?= e($bodyClass) ?>" x-data="{ mobile: false }">
<div class="scroll-progress" aria-hidden="true"></div>
<a href="#contenido" class="skip-link">Saltar al contenido</a>

<header class="public-nav">
    <div class="public-topbar">
        <div class="public-topbar__inner">
            <div class="public-topbar__meta">
                <span><i data-lucide="map-pin" class="h-4 w-4"></i><?= e(APP_ADDRESS) ?></span>
                <span class="public-topbar__sep">/</span>
                <span><i data-lucide="map-pin" class="h-4 w-4"></i><?= e(APP_SECONDARY_ADDRESS) ?></span>
                <span class="public-topbar__sep">/</span>
                <span><i data-lucide="phone" class="h-4 w-4"></i><?= e(APP_PHONE) ?></span>
                <span class="public-topbar__sep">/</span>
                <span><i data-lucide="mail" class="h-4 w-4"></i><?= e(APP_EMAIL) ?></span>
                <span class="public-topbar__sep">/</span>
                <span><i data-lucide="mail" class="h-4 w-4"></i><?= e(APP_INFO_EMAIL) ?></span>
            </div>
            <a href="<?= url('soporte.php') ?>" class="public-topbar__cta">
                <i data-lucide="headphones" class="h-4 w-4"></i>Reportar soporte 24/7
            </a>
        </div>
    </div>

    <div class="public-nav__bar">
        <?= brand_lock('public') ?>

        <nav class="public-nav__links" aria-label="Navegacion principal">
            <?php foreach ($navItems as $href => $label): ?>
                <a class="public-nav-link <?= e(is_active($href)) ?>" href="<?= url($href) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="public-nav__actions">
            <a href="<?= url('contacto.php#cotizar') ?>" class="sch-btn-primary public-nav__quote">
                <i data-lucide="clipboard-pen-line" class="h-4 w-4"></i>Cotizar
            </a>
            <a href="<?= url('crm/login.php') ?>" class="public-nav__crm">
                <i data-lucide="lock-keyhole" class="h-4 w-4"></i>Acceso CRM
            </a>
        </div>

        <button class="nav-toggle" type="button" @click="mobile = !mobile" :aria-expanded="mobile" aria-controls="mobile-menu" :aria-label="mobile ? 'Cerrar menu' : 'Abrir menu'">
            <i x-show="!mobile" data-lucide="menu" class="h-6 w-6"></i>
            <i x-show="mobile" data-lucide="x" class="h-6 w-6" x-cloak></i>
        </button>
    </div>

    <div class="mobile-menu" id="mobile-menu" x-show="mobile" x-transition x-cloak>
        <nav class="mobile-menu__inner" aria-label="Navegacion movil">
            <?php foreach ($navItems as $href => $label): ?>
                <a class="<?= e(is_active($href)) ?>" href="<?= url($href) ?>">
                    <?= e($label) ?><i data-lucide="chevron-right" class="h-4 w-4"></i>
                </a>
            <?php endforeach; ?>
            <a href="<?= url('crm/login.php') ?>">Acceso CRM<i data-lucide="lock-keyhole" class="h-4 w-4"></i></a>
            <a href="<?= url('contacto.php#cotizar') ?>" class="is-primary">Solicitar cotización</a>
        </nav>
    </div>
</header>

<?php foreach (flashes() as $i => $item): ?>
    <div id="flash-<?= $i ?>" class="container-sch mt-4 rounded-xl border <?= $item['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800' ?> px-4 py-3 text-sm font-semibold">
        <div class="flex items-center justify-between gap-3">
            <span class="flex items-center gap-2"><i data-lucide="<?= $item['type'] === 'success' ? 'check-circle-2' : 'alert-triangle' ?>" class="h-4 w-4"></i><?= e($item['message']) ?></span>
            <button type="button" data-dismiss="#flash-<?= $i ?>" class="rounded p-1 hover:bg-black/5" aria-label="Cerrar mensaje"><i data-lucide="x" class="h-4 w-4"></i></button>
        </div>
    </div>
<?php endforeach; ?>

<main id="contenido">
