<?php

declare(strict_types=1);

const APP_NAME = 'SCH MEDICOS';
const APP_EMAIL = 'sch@sch.com.do';
const APP_INFO_EMAIL = 'info@sch.com.do';
const APP_PHONE = '(809) 567-5559';
const APP_PHONE_US = '+1 (305) 597-4090';
const APP_WHATSAPP = '18095675559';
const APP_ADDRESS = 'Santo Domingo, Republica Dominicana';
const APP_SECONDARY_ADDRESS = 'Miami, Florida, USA';
const APP_FOUNDED = '1995';
const APP_TAGLINE = 'Servicios para Clinicas y Hospitales';
const APP_LOGO = 'assets/media/logo_SCH_-removebg-preview.png';
const SEO_DEFAULT_DESCRIPTION = 'SCH MEDICOS ofrece equipos medicos, gases medicinales, diseno hospitalario, instalacion, certificacion y soporte tecnico para clinicas y hospitales.';

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
