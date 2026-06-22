<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('config.manage');
verify_csrf();

$hasDb = db(false) && table_exists('settings');
if (db(false)) { ensure_settings_schema(); }

/* ---- Save quote / maintenance preferences ------------------------------- */
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

/* ---- Save invoicing (facturación) preferences --------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && db(false) && ($_POST['form'] ?? '') === 'invoice_settings') {
    setting_set('invoice_terms', trim((string) ($_POST['invoice_terms'] ?? '')));
    $itax = (float) ($_POST['invoice_tax_rate'] ?? 18);
    setting_set('invoice_tax_rate', (string) ($itax >= 0 ? $itax : 18));
    $itype = substr(preg_replace('/\D/', '', (string) ($_POST['invoice_default_type'] ?? '01')) ?: '01', 0, 2);
    if (!isset(ncf_types()[$itype])) { $itype = '01'; }
    setting_set('invoice_default_type', $itype);
    $icond = in_array((string) ($_POST['invoice_default_condition'] ?? ''), invoice_payment_conditions(), true) ? (string) $_POST['invoice_default_condition'] : 'Contado';
    setting_set('invoice_default_condition', $icond);
    $idue = (int) ($_POST['invoice_due_days'] ?? 30);
    setting_set('invoice_due_days', (string) ($idue >= 0 ? $idue : 30));
    log_activity('config', null, 'facturacion_actualizada', null);
    flash('success', 'Preferencias de facturación guardadas.');
    redirect('crm/configuracion.php');
}

/* ---- Save company profile (all fields + logo upload) -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && db(false) && ($_POST['form'] ?? '') === 'company') {
    foreach (company_field_defs() as $key => [$label, $default, $const]) {
        if ($key === 'company_logo') { continue; } // handled below
        setting_set($key, trim((string) ($_POST[$key] ?? '')));
    }

    // Logo: typed path and/or uploaded image (upload wins).
    $logoPath = trim((string) ($_POST['company_logo'] ?? ''));
    $up = $_FILES['company_logo_file'] ?? null;
    if ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($up['tmp_name'])) {
        if ((int) $up['size'] > 2 * 1024 * 1024) {
            flash('warning', 'El logo debe pesar menos de 2 MB.');
        } else {
            $info = @getimagesize($up['tmp_name']);
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
            $mime = $info['mime'] ?? '';
            if ($info && isset($allowed[$mime])) {
                $fname = 'company-logo-' . date('YmdHis') . '.' . $allowed[$mime];
                $dest = __DIR__ . '/../assets/media/' . $fname;
                if (@move_uploaded_file($up['tmp_name'], $dest)) {
                    $logoPath = 'assets/media/' . $fname;
                } else {
                    flash('warning', 'No se pudo guardar el logo (revisa permisos de assets/media).');
                }
            } else {
                flash('warning', 'Formato de logo no válido. Usa PNG, JPG o WEBP.');
            }
        }
    }
    if ($logoPath !== '') { setting_set('company_logo', $logoPath); }
    log_activity('config', null, 'empresa_actualizada', null);
    flash('success', 'Datos de la empresa guardados.');
    redirect('crm/configuracion.php');
}

/* ---- Save email (Resend) + login OTP preferences ------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && db(false) && ($_POST['form'] ?? '') === 'mail_settings') {
    // Only overwrite the API key when a new value is typed (blank = keep current).
    $postedKey = trim((string) ($_POST['resend_api_key'] ?? ''));
    if ($postedKey !== '') { setting_set('resend_api_key', $postedKey); }
    setting_set('mail_from_email', trim((string) ($_POST['mail_from_email'] ?? '')));
    setting_set('mail_from_name', trim((string) ($_POST['mail_from_name'] ?? '')));
    setting_set('mail_logo_url', trim((string) ($_POST['mail_logo_url'] ?? '')));
    $otpOn = ($_POST['otp_enabled'] ?? '') === '1';
    if ($otpOn && resend_api_key() === '') {
        setting_set('otp_enabled', '0');
        flash('warning', 'Para activar el código OTP primero guarda una API key de Resend.');
    } else {
        setting_set('otp_enabled', $otpOn ? '1' : '0');
        flash('success', 'Configuración de correo guardada.');
    }
    log_activity('config', null, 'correo_otp_actualizado', null);
    redirect('crm/configuracion.php');
}

/* ---- Send a test email to the current user ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && db(false) && ($_POST['form'] ?? '') === 'mail_test') {
    $me = current_user();
    $to = (string) ($me['email'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        flash('warning', 'Tu usuario no tiene un correo válido para la prueba.');
    } else {
        $testBody = mail_layout('Correo de prueba de ' . APP_NAME . '.',
            '<p style="margin:6px 0 8px;font-size:19px;font-weight:700;color:#06243f;">Prueba de correo ✅</p>'
            . '<p style="margin:0 0 4px;">Hola ' . e((string) ($me['name'] ?? '')) . ',</p>'
            . '<p style="margin:0 0 8px;color:#56697e;">Este es un correo de prueba enviado desde la configuración del CRM de <strong>' . e(APP_NAME) . '</strong>. Si lo recibiste con su logo y formato, la integración con Resend quedó funcionando correctamente.</p>');
        $testAttach = array_values(array_filter([mail_logo_attachment()]));
        $r = mailer_send($to, (string) ($me['name'] ?? ''), 'Prueba de correo · ' . APP_NAME, $testBody, $testAttach);
        flash($r['ok'] ? 'success' : 'warning', $r['ok'] ? ('Correo de prueba enviado a ' . $to . '.') : ('No se pudo enviar: ' . $r['error']));
    }
    redirect('crm/configuracion.php');
}

/* ---- Wipe demo / operational data (production cleanup) ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && db(false) && ($_POST['form'] ?? '') === 'wipe_demo') {
    if (strtoupper(trim((string) ($_POST['confirm'] ?? ''))) !== 'BORRAR') {
        flash('warning', 'Escribe BORRAR (en mayúsculas) para confirmar la limpieza.');
        redirect('crm/configuracion.php');
    }
    // Child → parent order; FK checks are also disabled as a safety net.
    $wipeTables = [
        'invoice_payments', 'invoice_items', 'invoices',
        'quote_items', 'quotes',
        'ticket_comments', 'tickets',
        'equipment', 'contacts', 'leads', 'clients',
        'activity_log', 'login_attempts',
    ];
    $pdo = db();
    $deleted = 0;
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($wipeTables as $t) {
            if (!table_exists($t)) { continue; }
            $deleted += (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $pdo->exec("TRUNCATE TABLE `$t`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        log_activity('config', null, 'datos_demo_limpiados', null);
        flash('success', 'Datos demo eliminados (' . $deleted . ' registros). El CRM quedó limpio. Se conservaron usuarios, configuración y roles.');
    } catch (Throwable $e) {
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable) { /* ignore */ }
        error_log('wipe_demo: ' . $e->getMessage());
        flash('warning', 'No se pudo completar la limpieza. Revisa los permisos de la base de datos.');
    }
    redirect('crm/configuracion.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !db(false)) {
    flash('warning', 'Ejecuta install.php para guardar la configuración en MySQL.');
}

$quoteTermsSetting = setting_get('quote_terms', quote_default_terms());
$quoteRateSetting = setting_get('quote_exchange_rate', '60');
$quoteTaxSetting = setting_get('quote_tax_rate', '18');
$serviceIntervalSetting = setting_get('service_interval_days', '180');
$invoiceTermsSetting = setting_get('invoice_terms', invoice_default_terms());
$invoiceTaxSetting = setting_get('invoice_tax_rate', $quoteTaxSetting);
$invoiceTypeSetting = (string) setting_get('invoice_default_type', '01');
$invoiceConditionSetting = (string) setting_get('invoice_default_condition', 'Contado');
$invoiceDueSetting = setting_get('invoice_due_days', '30');
$companyDefs = company_field_defs();
$cv = fn (string $k) => company_value($k);
$dis = db(false) ? '' : 'disabled';

$resendKeySet = setting_get('resend_api_key', '') !== '';
$mailFromEmailSetting = (string) setting_get('mail_from_email', '');
$mailFromNameSetting = (string) setting_get('mail_from_name', (string) (defined('APP_NAME') ? APP_NAME : ''));
$mailLogoSetting = (string) setting_get('mail_logo_url', '');
$otpEnabledSetting = setting_get('otp_enabled', '0') === '1';

$crmTitle = 'Configuración';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para guardar la configuración.</div>
<?php endif; ?>

<section class="crm-cockpit">
    <div class="crm-cockpit__top">
        <div class="crm-cockpit__hero">
            <span class="crm-kicker"><i data-lucide="settings"></i>Sistema</span>
            <h2>Configuración del CRM.</h2>
            <p>Personaliza los datos de tu empresa y los valores por defecto del CRM. Todo se aplica al instante en encabezados, pie de página, PDFs y SEO.</p>
            <div class="crm-cockpit__actions">
                <?php if (current_can('usuarios.manage')): ?><a href="<?= url('crm/usuarios.php') ?>" class="crm-secondary-btn"><i data-lucide="users-round" class="h-4 w-4"></i>Usuarios</a><?php endif; ?>
                <a href="<?= url('crm/roles.php') ?>" class="crm-secondary-btn"><i data-lucide="shield-check" class="h-4 w-4"></i>Roles y permisos</a>
            </div>
        </div>
        <div class="crm-cockpit__metrics" aria-label="Resumen de configuración">
            <article><span>Empresa</span><strong style="font-size:1.05rem"><?= e($cv('company_name')) ?></strong><small>identidad</small></article>
            <article><span>ITBIS</span><strong><?= e(rtrim(rtrim(number_format((float) $quoteTaxSetting, 2, '.', ''), '0'), '.')) ?>%</strong><small>por defecto</small></article>
            <article><span>Tasa US$</span><strong>RD$ <?= e(number_format((float) $quoteRateSetting, 2)) ?></strong><small>1 dólar</small></article>
            <article><span>Servicio</span><strong><?= e($serviceIntervalSetting) ?> d</strong><small>intervalo</small></article>
        </div>
    </div>

    <!-- Datos de la empresa (editable) -->
    <form method="post" enctype="multipart/form-data" class="crm-card cfg-card" style="margin-bottom:1rem">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="company">
        <div class="crm-card__head">
            <div><h2><i data-lucide="building-2" class="cfg-ic"></i> Datos de la empresa</h2><p>Aparecen en la web pública, los encabezados, el pie del PDF y el SEO.</p></div>
        </div>
        <div class="crm-card__body" style="display:grid;gap:1rem">
            <div class="cfg-logo-row">
                <div class="cfg-logo-prev"><img src="<?= asset($cv('company_logo')) ?>" alt="Logo actual"></div>
                <div style="flex:1;display:grid;gap:.6rem">
                    <label class="crm-field"><span>Logo (subir PNG, JPG o WEBP · máx. 2 MB)</span><input type="file" name="company_logo_file" accept="image/png,image/jpeg,image/webp" class="crm-input" <?= $dis ?>></label>
                    <label class="crm-field"><span>O ruta del logo</span><input name="company_logo" value="<?= e($cv('company_logo')) ?>" class="crm-input" <?= $dis ?>></label>
                </div>
            </div>

            <p class="dash-section-label" style="margin:.2rem 0 0">Identidad</p>
            <div class="crm-form-grid">
                <label class="crm-field"><span>Nombre comercial</span><input name="company_name" value="<?= e($cv('company_name')) ?>" class="crm-input" <?= $dis ?>></label>
                <label class="crm-field"><span>Razón social</span><input name="company_legal" value="<?= e($cv('company_legal')) ?>" class="crm-input" <?= $dis ?>></label>
                <label class="crm-field"><span>RNC</span><input name="company_rnc" value="<?= e($cv('company_rnc')) ?>" class="crm-input" placeholder="1-30-XXXXX-X" <?= $dis ?>></label>
                <label class="crm-field"><span>Año de fundación</span><input name="company_founded" value="<?= e($cv('company_founded')) ?>" class="crm-input" <?= $dis ?>></label>
            </div>
            <label class="crm-field"><span>Eslogan</span><input name="company_tagline" value="<?= e($cv('company_tagline')) ?>" class="crm-input" <?= $dis ?>></label>

            <p class="dash-section-label" style="margin:.2rem 0 0">Contacto</p>
            <div class="crm-form-grid">
                <label class="crm-field"><span>Correo principal</span><input type="email" name="company_email" value="<?= e($cv('company_email')) ?>" class="crm-input" <?= $dis ?>></label>
                <label class="crm-field"><span>Correo de información</span><input type="email" name="company_info_email" value="<?= e($cv('company_info_email')) ?>" class="crm-input" <?= $dis ?>></label>
                <label class="crm-field"><span>Teléfono (RD)</span><input name="company_phone" value="<?= e($cv('company_phone')) ?>" class="crm-input" <?= $dis ?>></label>
                <label class="crm-field"><span>Teléfono (US)</span><input name="company_phone_us" value="<?= e($cv('company_phone_us')) ?>" class="crm-input" <?= $dis ?>></label>
                <label class="crm-field"><span>WhatsApp (solo números)</span><input name="company_whatsapp" value="<?= e($cv('company_whatsapp')) ?>" class="crm-input" placeholder="18095675559" <?= $dis ?>></label>
            </div>

            <p class="dash-section-label" style="margin:.2rem 0 0">Ubicación y SEO</p>
            <div class="crm-form-grid">
                <label class="crm-field"><span>Dirección (RD)</span><input name="company_address" value="<?= e($cv('company_address')) ?>" class="crm-input" <?= $dis ?>></label>
                <label class="crm-field"><span>Dirección secundaria (US)</span><input name="company_address_2" value="<?= e($cv('company_address_2')) ?>" class="crm-input" <?= $dis ?>></label>
            </div>
            <label class="crm-field"><span>Descripción para buscadores (SEO)</span><textarea name="company_seo_desc" rows="3" class="crm-textarea" <?= $dis ?>><?= e($cv('company_seo_desc')) ?></textarea></label>

            <div class="crm-toolbar" style="justify-content:flex-end">
                <button class="crm-primary-btn" type="submit" <?= $dis ?>><i data-lucide="save" class="h-4 w-4"></i>Guardar datos de la empresa</button>
            </div>
        </div>
    </form>

    <!-- Correo (Resend) + verificación en dos pasos (OTP) -->
    <form method="post" class="crm-card cfg-card" style="margin-bottom:1rem">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="mail_settings">
        <div class="crm-card__head">
            <div><h2><i data-lucide="shield-check" class="cfg-ic"></i> Correo y acceso seguro (OTP)</h2><p>Envía un código de un solo uso al correo del usuario al iniciar sesión, vía Resend.</p></div>
        </div>
        <div class="crm-card__body" style="display:grid;gap:1rem">
            <label class="crm-field">
                <span>API key de Resend</span>
                <input type="password" name="resend_api_key" class="crm-input" autocomplete="off" placeholder="<?= $resendKeySet ? '•••••••• (ya configurada — escribe para reemplazar)' : 're_xxxxxxxxxxxxxxxx' ?>" <?= $dis ?>>
                <small class="cfg-hint">Se obtiene en <a class="underline" href="https://resend.com/api-keys" target="_blank" rel="noopener">resend.com/api-keys</a>. Déjala vacía para conservar la actual.</small>
            </label>
            <div class="crm-form-grid">
                <label class="crm-field"><span>Remitente (correo verificado en Resend)</span><input type="email" name="mail_from_email" value="<?= e($mailFromEmailSetting) ?>" class="crm-input" placeholder="no-reply@schmedicos.com" <?= $dis ?>></label>
                <label class="crm-field"><span>Nombre del remitente</span><input name="mail_from_name" value="<?= e($mailFromNameSetting) ?>" class="crm-input" placeholder="SCH MEDICOS" <?= $dis ?>></label>
            </div>
            <label class="crm-field"><span>Logo del correo (URL absoluta · opcional)</span><input type="url" name="mail_logo_url" value="<?= e($mailLogoSetting) ?>" class="crm-input" placeholder="https://schmedicos.com/assets/media/logo_SCH_-removebg-preview.png" <?= $dis ?>><small class="cfg-hint">Déjalo vacío para usar el logo del sitio automáticamente. Útil si quieres una versión específica del logo para los correos.</small></label>
            <label class="crm-field" style="flex-direction:row;align-items:center;gap:.6rem">
                <input type="checkbox" name="otp_enabled" value="1" <?= $otpEnabledSetting ? 'checked' : '' ?> <?= $dis ?> style="width:auto">
                <span style="margin:0">Exigir código de verificación (OTP) por correo al iniciar sesión</span>
            </label>
            <small class="cfg-hint"><i data-lucide="info" class="h-3.5 w-3.5" style="display:inline;vertical-align:-2px"></i> El dominio del remitente debe estar verificado en Resend (registros DNS). El admin de demo local nunca pide OTP. Si activas el OTP sin API key, no se guardará activo para evitar bloqueos.</small>
            <div class="crm-toolbar" style="justify-content:space-between">
                <button class="crm-secondary-btn" type="submit" form="mail-test-form" <?= $dis ?>><i data-lucide="send" class="h-4 w-4"></i>Enviar correo de prueba</button>
                <button class="crm-primary-btn" type="submit" <?= $dis ?>><i data-lucide="save" class="h-4 w-4"></i>Guardar correo y OTP</button>
            </div>
        </div>
    </form>
    <form method="post" id="mail-test-form" style="display:none"><?= csrf_field() ?><input type="hidden" name="form" value="mail_test"></form>

    <div class="cfg-grid">
        <!-- Preferencias comerciales + mantenimiento -->
        <form method="post" class="crm-card cfg-card">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="settings">
            <div class="crm-card__head">
                <div><h2><i data-lucide="file-text" class="cfg-ic"></i> Preferencias de cotización</h2><p>Se precargan al crear una cotización (editables en cada una).</p></div>
            </div>
            <div class="crm-card__body" style="display:grid;gap:1rem">
                <div class="crm-form-grid">
                    <label class="crm-field"><span>ITBIS por defecto (%)</span><input type="number" step="0.01" min="0" name="quote_tax_rate" value="<?= e($quoteTaxSetting) ?>" class="crm-input" <?= $dis ?>></label>
                    <label class="crm-field"><span>Tasa de cambio (US$ 1 = RD$)</span><input type="number" step="0.01" min="0" name="quote_exchange_rate" value="<?= e($quoteRateSetting) ?>" class="crm-input" <?= $dis ?>></label>
                </div>
                <label class="crm-field">
                    <span>Términos y condiciones por defecto</span>
                    <textarea name="quote_terms" rows="8" class="crm-textarea" <?= $dis ?>><?= e($quoteTermsSetting) ?></textarea>
                    <small class="cfg-hint">Aparecen al final del PDF de cada cotización.</small>
                </label>
                <div class="crm-card__head" style="padding:.4rem 0 0;border-top:1px solid var(--line)">
                    <div><h2 style="font-size:.95rem"><i data-lucide="wrench" class="cfg-ic"></i> Mantenimiento</h2></div>
                </div>
                <label class="crm-field">
                    <span>Intervalo de servicio (días)</span>
                    <input type="number" step="1" min="1" name="service_interval_days" value="<?= e($serviceIntervalSetting) ?>" class="crm-input" <?= $dis ?>>
                    <small class="cfg-hint">Al resolver un ticket con equipo, el próximo servicio se agenda con este intervalo.</small>
                </label>
                <div class="crm-toolbar" style="justify-content:flex-end">
                    <button class="crm-primary-btn" type="submit" <?= $dis ?>><i data-lucide="save" class="h-4 w-4"></i>Guardar preferencias</button>
                </div>
            </div>
        </form>

        <!-- Preferencias de facturación (DGII / NCF) -->
        <form method="post" class="crm-card cfg-card">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="invoice_settings">
            <div class="crm-card__head">
                <div><h2><i data-lucide="receipt" class="cfg-ic"></i> Preferencias de facturación</h2><p>Valores por defecto al crear comprobantes fiscales (editables en cada factura).</p></div>
            </div>
            <div class="crm-card__body" style="display:grid;gap:1rem">
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Tipo de comprobante por defecto</span>
                        <select name="invoice_default_type" class="crm-select" <?= $dis ?>>
                            <optgroup label="Comprobante fiscal — serie B (vigente)">
                                <?php foreach (ncf_types_for('B') as $code => $def): ?><option value="<?= e($code) ?>" <?= $invoiceTypeSetting === $code ? 'selected' : '' ?>><?= e($code . ' — ' . $def[0]) ?></option><?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Electrónico e-CF — serie E (manual)">
                                <?php foreach (ncf_types_for('E') as $code => $def): ?><option value="<?= e($code) ?>" <?= $invoiceTypeSetting === $code ? 'selected' : '' ?>><?= e($code . ' — ' . $def[0]) ?></option><?php endforeach; ?>
                            </optgroup>
                        </select>
                    </label>
                    <label class="crm-field"><span>ITBIS por defecto (%)</span><input type="number" step="0.01" min="0" name="invoice_tax_rate" value="<?= e($invoiceTaxSetting) ?>" class="crm-input" <?= $dis ?>></label>
                </div>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Condición de pago por defecto</span>
                        <select name="invoice_default_condition" class="crm-select" <?= $dis ?>>
                            <?php foreach (invoice_payment_conditions() as $c): ?><option value="<?= e($c) ?>" <?= $invoiceConditionSetting === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="crm-field"><span>Días de crédito (vencimiento)</span><input type="number" step="1" min="0" name="invoice_due_days" value="<?= e($invoiceDueSetting) ?>" class="crm-input" <?= $dis ?>></label>
                </div>
                <label class="crm-field">
                    <span>Términos y condiciones por defecto</span>
                    <textarea name="invoice_terms" rows="6" class="crm-textarea" <?= $dis ?>><?= e($invoiceTermsSetting) ?></textarea>
                    <small class="cfg-hint">Aparecen al final del PDF de cada factura. Las secuencias NCF se gestionan en <a class="underline" href="<?= url('crm/facturas.php?action=ncf') ?>">Facturación → Secuencias NCF</a>.</small>
                </label>
                <div class="crm-toolbar" style="justify-content:flex-end">
                    <button class="crm-primary-btn" type="submit" <?= $dis ?>><i data-lucide="save" class="h-4 w-4"></i>Guardar facturación</button>
                </div>
            </div>
        </form>

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

    <!-- Zona de peligro: limpiar datos demo -->
    <article class="crm-card cfg-card" style="margin-top:1rem;border-color:#f3c9c9;background:#fffafa">
        <div class="crm-card__head">
            <div><h2 style="color:#b42318"><i data-lucide="alert-triangle" class="cfg-ic"></i> Limpiar datos demo</h2><p>Borra todos los datos operativos de ejemplo para arrancar producción en limpio.</p></div>
        </div>
        <div class="crm-card__body" style="display:grid;gap:.9rem">
            <div style="font-size:.86rem;color:#7c2d2d;background:#fff1f1;border:1px solid #f3c9c9;border-radius:10px;padding:.7rem .85rem;line-height:1.55">
                <strong>Se eliminarán:</strong> clientes, contactos, equipos, cotizaciones, tickets, leads y facturas.<br>
                <strong>Se conservan:</strong> usuarios, configuración (empresa / OTP) y roles.<br>
                <strong>⚠️ Esta acción no se puede deshacer.</strong>
            </div>
            <form method="post" onsubmit="return confirm('¿Seguro que quieres borrar TODOS los datos demo? Esto no se puede deshacer.')" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="wipe_demo">
                <label class="crm-field" style="flex:1;min-width:220px"><span>Escribe <b>BORRAR</b> para confirmar</span><input name="confirm" class="crm-input" autocomplete="off" placeholder="BORRAR" <?= $dis ?>></label>
                <button class="crm-primary-btn" type="submit" style="background:#b42318;border-color:#b42318" <?= $dis ?>><i data-lucide="trash-2" class="h-4 w-4"></i>Limpiar datos demo</button>
            </form>
        </div>
    </article>
</section>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
