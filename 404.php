<?php
require_once __DIR__ . '/includes/bootstrap.php';

http_response_code(404);
$pageTitle = 'Pagina no encontrada | SCH MEDICOS';
$pageDescription = 'La pagina solicitada no existe. Vuelve al inicio de SCH MEDICOS o explora productos, proyectos y soporte.';
$canonical = current_url();
require_once __DIR__ . '/includes/public_header.php';
?>

<section class="sch-section sch-section--white">
    <div class="container-sch">
        <div class="error-shell" data-reveal="scale">
            <div class="error-shell__code">404</div>
            <span class="sch-eyebrow" style="justify-content:center">Pagina no encontrada</span>
            <h1>No pudimos encontrar esa pagina</h1>
            <p>Es posible que el enlace haya cambiado o que la pagina ya no exista. Te dejamos algunas rutas utiles para continuar.</p>
            <div class="error-shell__actions">
                <a href="<?= url('index.php') ?>" class="sch-btn-primary"><i data-lucide="home" class="h-5 w-5"></i>Volver al inicio</a>
                <a href="<?= url('servicios.php') ?>" class="sch-btn-ghost"><i data-lucide="list-checks" class="h-5 w-5"></i>Productos</a>
                <a href="<?= url('soporte.php') ?>" class="sch-btn-ghost"><i data-lucide="headphones" class="h-5 w-5"></i>Soporte</a>
                <a href="<?= url('contacto.php') ?>" class="sch-btn-ghost"><i data-lucide="mail" class="h-5 w-5"></i>Contacto</a>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
