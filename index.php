<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/data/sch.php';

$pageTitle = 'SCH MEDICOS | Ingenieria hospitalaria, gases medicinales y equipos medicos';
$pageDescription = 'SCH MEDICOS: diseno, instalacion, certificacion y soporte tecnico de gases medicinales, equipos medicos e ingenieria hospitalaria para clinicas y hospitales desde 1995.';
$pageImage = asset('assets/media/2-1.png');
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'MedicalBusiness',
    'name' => 'SCH MEDICOS',
    'description' => $pageDescription,
    'url' => current_url(),
    'logo' => asset(APP_LOGO),
    'email' => APP_EMAIL,
    'telephone' => APP_PHONE,
    'foundingDate' => '1995-01',
    'address' => [
        '@type' => 'PostalAddress',
        'streetAddress' => 'Calle Ortega y Gasset #24, Ensanche La Fe',
        'addressLocality' => 'Santo Domingo',
        'addressCountry' => 'DO',
    ],
];
require_once __DIR__ . '/includes/public_header.php';
?>

<section class="sch-home-hero">
    <div class="sch-home-hero__grid">
        <div class="sch-home-hero__copy" data-reveal="left">
            <span class="sch-eyebrow">Soluciones hospitalarias integrales &middot; Desde 1995</span>
            <h1>Ingenieria hospitalaria, gases medicinales y <em>equipos medicos</em> para instituciones de salud</h1>
            <p>Diseno, instalacion, certificacion y soporte tecnico para clinicas y hospitales del sector publico y privado en Republica Dominicana y el Caribe.</p>
            <div class="sch-home-actions">
                <a href="<?= url('contacto.php#cotizar') ?>" class="sch-btn-primary">
                    <i data-lucide="clipboard-pen-line" class="h-5 w-5"></i>Solicitar cotizacion
                </a>
                <a href="<?= url('soporte.php') ?>" class="sch-btn-outline-green">
                    <i data-lucide="headphones" class="h-5 w-5"></i>Reportar soporte
                </a>
            </div>
            <div class="sch-home-trust">
                <div><strong>30+</strong><span>Anos de experiencia</span></div>
                <div><strong>+500</strong><span>Proyectos ejecutados</span></div>
                <div><strong>2</strong><span>Sedes: Sto. Dgo. y Miami</span></div>
                <div><strong>24/7</strong><span>Soporte tecnico</span></div>
            </div>
        </div>
        <div class="sch-home-hero__media" data-reveal="right" data-reveal-delay="120">
            <img src="<?= asset('assets/media/2-1.png') ?>" alt="Area hospitalaria equipada por SCH MEDICOS" class="sch-hero-main-img">
            <img src="<?= asset('assets/media/Gases-2.png') ?>" alt="Salidas de gases medicinales instaladas por SCH MEDICOS" class="sch-hero-floating sch-hero-floating--one">
            <div class="sch-hero-badge">
                <i data-lucide="shield-check"></i>
                <div><strong>Marcas autorizadas</strong><span>Representacion y certificacion</span></div>
            </div>
            <div class="sch-hero-stat">
                <span class="sch-hero-stat__icon"><i data-lucide="building-2" class="h-5 w-5"></i></span>
                <div><strong>+500</strong><span>Proyectos ejecutados</span></div>
            </div>
        </div>
    </div>
</section>

<section class="sch-proof-strip" aria-label="Datos de SCH MEDICOS" data-reveal>
    <article>
        <span class="sch-mini-flag sch-mini-flag--do" aria-hidden="true"></span>
        <div>
            <h2>Santo Domingo</h2>
            <p>Calle Ortega y Gasset #24, Ens. La Fe.</p>
        </div>
    </article>
    <article>
        <span class="sch-mini-flag sch-mini-flag--us" aria-hidden="true"></span>
        <div>
            <h2>Miami</h2>
            <p>11119 NW 122 ST, Medley, FL 33178.</p>
        </div>
    </article>
    <article>
        <i data-lucide="shield-check"></i>
        <div>
            <h2>Desde 1995</h2>
            <p>Diseno e instalacion hospitalaria.</p>
        </div>
    </article>
    <article>
        <i data-lucide="building-2"></i>
        <div>
            <h2>Sector salud</h2>
            <p>Clinicas y hospitales publicos y privados.</p>
        </div>
    </article>
    <article>
        <i data-lucide="award"></i>
        <div>
            <h2>Marcas autorizadas</h2>
            <p>Representacion y entrenamientos.</p>
        </div>
    </article>
</section>

<section class="sch-section sch-section--white">
    <div class="sch-solutions">
        <div class="sch-solutions__intro" data-reveal>
            <span class="sch-eyebrow">Que hacemos</span>
            <h2>Nuestras soluciones integrales</h2>
            <p>Integramos ingenieria, tecnologia y servicio para garantizar infraestructura hospitalaria segura, eficiente y certificada.</p>
            <a href="<?= url('servicios.php') ?>" class="sch-btn-ghost mt-6"><i data-lucide="list-checks" class="h-4 w-4"></i>Ver todos los productos</a>
        </div>
        <div class="sch-solutions__grid">
            <?php
            $homeSolutions = [
                ['gauge', 'Gases medicinales', 'Diseno, instalacion y certificacion de sistemas centrales cumpliendo NFPA y normativas locales.', 'servicios.php#gases'],
                ['monitor', 'Equipos medicos', 'Suministro e instalacion de equipos para diagnostico, terapia y soporte clinico.', 'servicios.php#equipos'],
                ['layout-panel-top', 'Ingenieria hospitalaria', 'Diseno y construccion de areas criticas: quirofanos, UCI, laboratorios y emergencias.', 'servicios.php#paredes'],
                ['wrench', 'Soporte tecnico 24/7', 'Mantenimiento preventivo y correctivo con respuesta rapida y tecnicos certificados.', 'soporte.php'],
            ];
            foreach ($homeSolutions as $i => [$icon, $title, $desc, $link]): ?>
                <a href="<?= url($link) ?>" class="sch-solution-item" data-reveal="scale" data-reveal-delay="<?= $i * 90 ?>">
                    <span class="sch-solution-item__num">0<?= $i + 1 ?></span>
                    <span class="sch-solution-item__icon"><i data-lucide="<?= e($icon) ?>"></i></span>
                    <h3><?= e($title) ?></h3>
                    <p><?= e($desc) ?></p>
                    <span class="sch-solution-item__link">Conocer mas <i data-lucide="arrow-right" class="h-4 w-4"></i></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="sch-section sch-section--page">
    <div class="sch-project-showcase">
        <div class="sch-project-showcase__intro" data-reveal>
            <span class="sch-eyebrow">Evidencia real</span>
            <h2>Proyectos destacados</h2>
            <p>Instalaciones, suministros y sistemas ejecutados para instituciones de salud en todo el pais.</p>
            <a href="<?= url('proyectos.php') ?>">Ver todos los proyectos <i data-lucide="arrow-right" class="h-5 w-5"></i></a>
        </div>
        <div class="sch-project-showcase__grid">
            <?php foreach (array_slice($projects, 0, 4) as $i => $project): ?>
                <a href="<?= url('proyectos.php') ?>" class="sch-showcase-card" data-reveal="scale" data-reveal-delay="<?= $i * 90 ?>">
                    <div class="sch-showcase-card__media">
                        <img src="<?= asset('assets/media/' . $project['image']) ?>" alt="<?= e($project['name']) ?>" loading="lazy">
                    </div>
                    <div class="sch-showcase-card__body">
                        <h3><?= e($project['name']) ?></h3>
                        <p><i data-lucide="map-pin" class="h-4 w-4"></i><?= e($project['location']) ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section id="marca" class="sch-section sch-section--white">
    <div class="sch-about-band">
        <div data-reveal="left">
            <span class="sch-eyebrow">Sobre SCH</span>
            <h2>Servicios para clinicas y hospitales desde Santo Domingo</h2>
            <p>Servicios para Clinicas y Hospitales (SCH) fue fundada en enero de 1995 por Fulvio Montisano para ofrecer soluciones integrales en el equipamiento del area hospitalaria, mediante conceptualizacion, diseno e implementacion de sistemas centrales de gases medicinales y equipos hospitalarios.</p>
            <ul class="sch-about-points">
                <li><i data-lucide="check-circle-2" class="h-5 w-5"></i>Mas de 30 anos equipando el sector salud publico y privado.</li>
                <li><i data-lucide="check-circle-2" class="h-5 w-5"></i>Representacion oficial de marcas internacionales certificadas.</li>
                <li><i data-lucide="check-circle-2" class="h-5 w-5"></i>Equipo tecnico propio para instalacion, certificacion y soporte.</li>
            </ul>
        </div>
        <div class="sch-about-gallery" data-reveal="right" data-reveal-delay="120">
            <img src="<?= asset('assets/media/Gases-5.png') ?>" alt="Sistema de gases medicinales SCH" loading="lazy">
            <img src="<?= asset('assets/media/Paredes-1.png') ?>" alt="Paredes hospitalarias y proteccion SCH" loading="lazy">
            <img src="<?= asset('assets/media/Equipo-medico-3.png') ?>" alt="Equipo medico suministrado por SCH" loading="lazy">
        </div>
    </div>
</section>

<section class="sch-section sch-section--page sch-brands">
    <div class="sch-brand-strip" data-reveal>
        <span class="sch-brand-strip__eyebrow"><i data-lucide="badge-check"></i> Fabricantes representados</span>
        <h2>Marcas autorizadas y entrenamientos</h2>
        <p class="sch-brand-strip__hint">Representamos y damos soporte a fabricantes lideres en equipamiento medico y hospitalario, con personal certificado y repuestos originales.</p>
    </div>
    <div class="sch-marquee" data-reveal data-reveal-delay="100" aria-label="Marcas representadas por SCH MEDICOS">
        <div class="sch-marquee__track">
            <?php for ($rep = 0; $rep < 2; $rep++): ?>
                <?php foreach ($brands as $brand): ?>
                    <span class="sch-marquee__item"<?= $rep === 1 ? ' aria-hidden="true"' : '' ?>>
                        <img src="<?= asset('assets/media/' . $brand['logo']) ?>" alt="<?= e($brand['name']) ?>" loading="lazy">
                    </span>
                <?php endforeach; ?>
            <?php endfor; ?>
        </div>
    </div>
</section>

<section class="sch-section sch-section--white">
    <div class="container-sch">
        <div class="sch-cta-band" data-reveal>
            <div>
                <span class="sch-eyebrow sch-eyebrow--light">Hablemos de tu proyecto</span>
                <h2>Necesitas dimensionar un proyecto o resolver una falla?</h2>
                <p>Ventas y soporte trabajan sobre el mismo CRM para que la informacion no se pierda entre cotizacion, instalacion y mantenimiento.</p>
            </div>
            <a href="<?= url('contacto.php#cotizar') ?>" class="sch-btn-primary"><i data-lucide="send" class="h-5 w-5"></i>Enviar solicitud</a>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
