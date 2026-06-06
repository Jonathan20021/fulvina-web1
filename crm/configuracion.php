<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('config.manage');
verify_csrf();

$hasDb = db(false) && table_exists('settings');
if (db(false)) { ensure_settings_schema(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && db(false) && ($_POST['form'] ?? '') === 'settings') {
    setting_set('quote_terms', trim((string) ($_POST['quote_terms'] ?? '')));
    $rate = (float) ($_POST['quote_exchange_rate'] ?? 0);
    setting_set('quote_exchange_rate', (string) ($rate > 0 ? $rate : 1));
    $tax = (float) ($_POST['quote_tax_rate'] ?? 18);
    setting_set('quote_tax_rate', (string) ($tax >= 0 ? $tax : 18));
    $svc = (int) ($_POST['service_interval_days'] ?? 0);
    setting_set('service_interval_days', (string) ($svc > 0 ? $svc : 180));
    log_activity('config', null, 'preferencias_actualizadas', null);
    flash('success', 'Preferencias guardadas.');
    redirect('crm/configuracion.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !db(false)) {
    flash('warning', 'Ejecuta install.php para guardar preferencias en MySQL.');
}

$quoteTermsSetting = setting_get('quote_terms', quote_default_terms());
$quoteRateSetting = setting_get('quote_exchange_rate', '60');
$quoteTaxSetting = setting_get('quote_tax_rate', '18');
$serviceIntervalSetting = setting_get('service_interval_days', '180');

$crmTitle = 'Configuración';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para guardar preferencias.</div>
<?php endif; ?>

<section class="crm-cockpit">
    <div class="crm-cockpit__top">
        <div class="crm-cockpit__hero">
            <span class="crm-kicker"><i data-lucide="settings"></i>Sistema</span>
            <h2>Configuración del CRM.</h2>
            <p>Valores por defecto que se aplican al crear cotizaciones y al programar mantenimientos. Los accesos y permisos se administran por separado.</p>
            <div class="crm-cockpit__actions">
                <?php if (current_can('usuarios.manage')): ?><a href="<?= url('crm/usuarios.php') ?>" class="crm-secondary-btn"><i data-lucide="users-round" class="h-4 w-4"></i>Usuarios</a><?php endif; ?>
                <a href="<?= url('crm/roles.php') ?>" class="crm-secondary-btn"><i data-lucide="shield-check" class="h-4 w-4"></i>Roles y permisos</a>
            </div>
        </div>
        <div class="crm-cockpit__metrics" aria-label="Resumen de configuración">
            <article><span>ITBIS</span><strong><?= e(rtrim(rtrim(number_format((float) $quoteTaxSetting, 2, '.', ''), '0'), '.')) ?>%</strong><small>por defecto</small></article>
            <article><span>Tasa US$</span><strong>RD$ <?= e(number_format((float) $quoteRateSetting, 2)) ?></strong><small>1 dólar</small></article>
            <article><span>Servicio</span><strong><?= e($serviceIntervalSetting) ?> d</strong><small>intervalo</small></article>
            <article><span>Moneda</span><strong>DOP</strong><small>base</small></article>
        </div>
    </div>

    <div class="cfg-grid">
        <!-- Preferencias comerciales -->
        <form method="post" class="crm-card cfg-card">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="settings">
            <div class="crm-card__head">
                <div><h2><i data-lucide="file-text" class="cfg-ic"></i> Preferencias de cotización</h2><p>Se precargan al crear una cotización (editables en cada una).</p></div>
            </div>
            <div class="crm-card__body" style="display:grid;gap:1rem">
                <div class="crm-form-grid">
                    <label class="crm-field">
                        <span>ITBIS por defecto (%)</span>
                        <input type="number" step="0.01" min="0" name="quote_tax_rate" value="<?= e($quoteTaxSetting) ?>" class="crm-input" <?= db(false) ? '' : 'disabled' ?>>
                    </label>
                    <label class="crm-field">
                        <span>Tasa de cambio (US$ 1 = RD$)</span>
                        <input type="number" step="0.01" min="0" name="quote_exchange_rate" value="<?= e($quoteRateSetting) ?>" class="crm-input" <?= db(false) ? '' : 'disabled' ?>>
                    </label>
                </div>
                <label class="crm-field">
                    <span>Términos y condiciones por defecto</span>
                    <textarea name="quote_terms" rows="9" class="crm-textarea" <?= db(false) ? '' : 'disabled' ?>><?= e($quoteTermsSetting) ?></textarea>
                    <small class="cfg-hint">Aparecen al final del PDF de cada cotización.</small>
                </label>
                <div class="crm-card__head" style="padding:.4rem 0 0;border-top:1px solid var(--line)">
                    <div><h2 style="font-size:.95rem"><i data-lucide="wrench" class="cfg-ic"></i> Mantenimiento</h2></div>
                </div>
                <div class="crm-form-grid">
                    <label class="crm-field">
                        <span>Intervalo de servicio (días)</span>
                        <input type="number" step="1" min="1" name="service_interval_days" value="<?= e($serviceIntervalSetting) ?>" class="crm-input" <?= db(false) ? '' : 'disabled' ?>>
                        <small class="cfg-hint">Al resolver un ticket con equipo, el próximo servicio se agenda con este intervalo.</small>
                    </label>
                </div>
                <div class="crm-toolbar" style="justify-content:flex-end">
                    <button class="crm-primary-btn" type="submit" <?= db(false) ? '' : 'disabled' ?>><i data-lucide="save" class="h-4 w-4"></i>Guardar preferencias</button>
                </div>
            </div>
        </form>

        <div class="cfg-side">
            <!-- Datos de la empresa -->
            <article class="crm-card cfg-card">
                <div class="crm-card__head"><div><h2><i data-lucide="building-2" class="cfg-ic"></i> Datos de la empresa</h2><p>Se usan en encabezados, pie del PDF y SEO.</p></div></div>
                <div class="crm-card__body cfg-info">
                    <div><span>Razón social</span><strong><?= e(APP_NAME) ?>, SRL</strong></div>
                    <div><span>Eslogan</span><strong><?= e(APP_TAGLINE) ?></strong></div>
                    <div><span>Teléfono</span><strong><?= e(APP_PHONE) ?> · <?= e(APP_PHONE_US) ?></strong></div>
                    <div><span>Correo</span><strong><?= e(APP_INFO_EMAIL) ?></strong></div>
                    <div><span>Direcciones</span><strong><?= e(APP_ADDRESS) ?> · <?= e(APP_SECONDARY_ADDRESS) ?></strong></div>
                    <div><span>Desde</span><strong><?= e(APP_FOUNDED) ?></strong></div>
                    <p class="cfg-hint" style="margin-top:.2rem">Para cambiarlos, edita <code>config/app.php</code> en el servidor.</p>
                </div>
            </article>

            <!-- Accesos -->
            <article class="crm-card cfg-card">
                <div class="crm-card__head"><div><h2><i data-lucide="key-round" class="cfg-ic"></i> Accesos y seguridad</h2></div></div>
                <div class="crm-card__body" style="display:grid;gap:.55rem">
                    <?php if (current_can('usuarios.manage')): ?>
                        <a href="<?= url('crm/usuarios.php') ?>" class="cfg-link"><span class="cfg-link__ic"><i data-lucide="users-round"></i></span><span class="cfg-link__tx"><b>Usuarios</b><small>Alta, edición, rol y estado del equipo</small></span><i data-lucide="chevron-right" class="cfg-link__go"></i></a>
                    <?php endif; ?>
                    <a href="<?= url('crm/roles.php') ?>" class="cfg-link"><span class="cfg-link__ic"><i data-lucide="shield-check"></i></span><span class="cfg-link__tx"><b>Roles y permisos</b><small>Qué puede ver y hacer cada rol</small></span><i data-lucide="chevron-right" class="cfg-link__go"></i></a>
                    <a href="<?= url('crm/perfil.php') ?>" class="cfg-link"><span class="cfg-link__ic"><i data-lucide="user-round"></i></span><span class="cfg-link__tx"><b>Mi perfil</b><small>Cambia tu nombre y contraseña</small></span><i data-lucide="chevron-right" class="cfg-link__go"></i></a>
                </div>
            </article>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
