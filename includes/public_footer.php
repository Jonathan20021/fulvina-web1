</main>

<footer class="sch-footer">
    <div class="sch-footer__grid">
        <div class="sch-footer__brand">
            <?= brand_lock('footer') ?>
            <p>Equipos médicos, gases medicinales, diseño, instalación y soporte técnico para instituciones de salud públicas y privadas desde <?= e(APP_FOUNDED) ?>.</p>
            <div class="sch-footer__social">
                <a href="mailto:<?= e(APP_EMAIL) ?>" class="is-mail"><i data-lucide="mail" class="h-4 w-4"></i>Enviar correo</a>
                <a href="https://wa.me/<?= APP_WHATSAPP ?>" class="is-wa"><i data-lucide="message-circle" class="h-4 w-4"></i>WhatsApp</a>
            </div>
        </div>
        <div>
            <h2>Soluciones</h2>
            <ul>
                <li><a href="<?= url('servicios.php#gases') ?>">Gases medicinales</a></li>
                <li><a href="<?= url('servicios.php#equipos') ?>">Equipos médicos</a></li>
                <li><a href="<?= url('servicios.php#paredes') ?>">Paredes y cabeceros</a></li>
                <li><a href="<?= url('soporte.php') ?>">Soporte técnico</a></li>
            </ul>
        </div>
        <div>
            <h2>Empresa</h2>
            <ul>
                <li><a href="<?= url('sobre-nosotros.php') ?>">Sobre SCH</a></li>
                <li><a href="<?= url('proyectos.php') ?>">Proyectos</a></li>
                <li><a href="<?= url('contacto.php') ?>">Contacto</a></li>
                <li><a href="<?= url('crm/login.php') ?>">Acceso CRM</a></li>
            </ul>
        </div>
        <div>
            <h2>Contacto</h2>
            <address>
                <p><i data-lucide="map-pin" class="inline h-4 w-4 text-sch-cyan"></i> <?= e(APP_ADDRESS) ?></p>
                <p><i data-lucide="warehouse" class="inline h-4 w-4 text-sch-cyan"></i> <?= e(APP_SECONDARY_ADDRESS) ?></p>
                <p><a href="tel:+18095675559"><?= e(APP_PHONE) ?></a> &middot; <a href="tel:+13055974090"><?= e(APP_PHONE_US) ?></a></p>
                <p><a href="mailto:<?= e(APP_EMAIL) ?>"><?= e(APP_EMAIL) ?></a> &middot; <a href="mailto:<?= e(APP_INFO_EMAIL) ?>"><?= e(APP_INFO_EMAIL) ?></a></p>
            </address>
        </div>
    </div>
    <div class="sch-footer__bottom">
        <div class="sch-footer__bottom-inner">
            <p>&copy; <?= date('Y') ?> <?= e(APP_LEGAL) ?>. Todos los derechos reservados.</p>
            <p>Ingeniería hospitalaria, equipos médicos y soporte institucional.</p>
        </div>
        <div class="sch-footer__credit">
            <a href="https://kyrosrd.com/" target="_blank" rel="noopener" class="sch-credit">
                <i data-lucide="code"></i>
                <span>Desarrollado por <strong>KYROS SOLUTIONS</strong></span>
            </a>
        </div>
    </div>
</footer>
</body>
</html>
