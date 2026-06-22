<?php

declare(strict_types=1);

/**
 * Company profile fields — fully editable from the CRM (Configuración).
 * Each value is stored in the `settings` table (key shown here) and falls back
 * to the default below. app_define_company() turns these into the APP_*
 * constants used across the whole app, so every existing reference (header,
 * footer, brand_lock, quote/report PDFs, SEO) reflects the UI values with no
 * other code changes. Tuple = [setting_key => [label, default, constant]].
 */
function company_field_defs(): array
{
    return [
        'company_name'       => ['Nombre comercial', 'SCH MEDICOS', 'APP_NAME'],
        'company_legal'      => ['Razón social', 'SCH MEDICOS, SRL', 'APP_LEGAL'],
        'company_tagline'    => ['Eslogan', 'Servicios para Clinicas y Hospitales', 'APP_TAGLINE'],
        'company_rnc'        => ['RNC', '', 'APP_RNC'],
        'company_email'      => ['Correo principal', 'sch@sch.com.do', 'APP_EMAIL'],
        'company_info_email' => ['Correo de información', 'info@sch.com.do', 'APP_INFO_EMAIL'],
        'company_phone'      => ['Teléfono (RD)', '(809) 567-5559', 'APP_PHONE'],
        'company_phone_us'   => ['Teléfono (US)', '+1 (305) 597-4090', 'APP_PHONE_US'],
        'company_whatsapp'   => ['WhatsApp (solo números)', '18095675559', 'APP_WHATSAPP'],
        'company_address'    => ['Dirección (RD)', 'Santo Domingo, Republica Dominicana', 'APP_ADDRESS'],
        'company_address_2'  => ['Dirección secundaria (US)', 'Miami, Florida, USA', 'APP_SECONDARY_ADDRESS'],
        'company_founded'    => ['Año de fundación', '1995', 'APP_FOUNDED'],
        'company_seo_desc'   => ['Descripción SEO', 'SCH MEDICOS ofrece equipos medicos, gases medicinales, diseño hospitalario, instalacion, certificacion y soporte tecnico para clinicas y hospitales.', 'SEO_DEFAULT_DESCRIPTION'],
        'company_logo'       => ['Logo (ruta del archivo)', 'assets/media/logo_SCH_-removebg-preview.png', 'APP_LOGO'],
    ];
}

/** Current value of a company field (DB setting or default). */
function company_value(string $key): string
{
    $defs = company_field_defs();
    $default = $defs[$key][1] ?? '';
    if (function_exists('setting_get') && function_exists('db') && db(false) && function_exists('table_exists') && table_exists('settings')) {
        $v = setting_get($key, null);
        if ($v !== null && $v !== '') {
            return (string) $v;
        }
    }
    return (string) $default;
}

/**
 * Define the APP_* constants from the editable company profile (DB → default).
 * Called once from bootstrap (after the DB/settings layer is available) and from
 * install.php. Safe to call repeatedly (guarded).
 */
function app_define_company(): void
{
    if (defined('APP_NAME')) {
        return;
    }
    foreach (company_field_defs() as $key => [$label, $default, $const]) {
        if (!defined($const)) {
            define($const, company_value($key));
        }
    }
}

function app_base_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

    foreach (['/crm', '/admin'] as $suffix) {
        if (str_ends_with($dir, $suffix)) {
            $dir = substr($dir, 0, -strlen($suffix));
        }
    }

    return $dir === '/' ? '' : $dir;
}

function url(string $path = ''): string
{
    $base = app_base_path();
    $path = ltrim($path, '/');

    // Separate ?query / #anchor so we only clean the route part.
    $suffix = '';
    $cut = strcspn($path, '?#');
    if ($cut < strlen($path)) {
        $suffix = substr($path, $cut);
        $path = substr($path, 0, $cut);
    }

    // Strip the .php extension from PAGE routes (assets keep .css/.png/.js).
    if (str_ends_with($path, '.php')) {
        $path = substr($path, 0, -4);
        if ($path === 'index') {
            $path = '';
        }
    }

    $path = trim($path, '/');
    if ($path === '') {
        return ($base === '' ? '' : $base) . '/' . $suffix;
    }
    return $base . '/' . $path . $suffix;
}

function asset(string $path): string
{
    return url(ltrim($path, '/'));
}

function current_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? url();
    return $scheme . '://' . $host . $uri;
}

function is_active(string $file): string
{
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return $current === $file ? 'is-active' : '';
}
