<?php

declare(strict_types=1);

/**
 * SCH MEDICOS CRM — Role-Based Access Control.
 *
 * Capabilities are "module.action" strings (e.g. clientes.edit). Each role maps
 * to a list of capabilities, stored as JSON in the settings table and editable
 * from crm/roles.php. The 'admin' role always has every capability and can never
 * be locked out. 'panel.view' is granted to every role so no one is stranded
 * after login. Enforcement is server-side: require_can() gates pages and POST
 * handlers; the nav and action buttons additionally hide what a role cannot use.
 */

/** Module catalog: key => [label, [actions]]. Drives the permissions matrix. */
function rbac_modules(): array
{
    return [
        'panel'        => ['Panel',         ['view']],
        'clientes'     => ['Clientes',      ['view', 'edit', 'delete']],
        'cotizaciones' => ['Cotizaciones',  ['view', 'edit', 'delete']],
        'leads'        => ['Leads',         ['view', 'edit', 'delete']],
        'equipos'      => ['Equipos',       ['view', 'edit', 'delete']],
        'tickets'      => ['Tickets',       ['view', 'edit', 'delete']],
        'agenda'       => ['Agenda',        ['view', 'edit']],
        'reportes'     => ['Reportes',      ['view']],
        'usuarios'     => ['Usuarios',      ['manage']],
        'config'       => ['Configuración', ['manage']],
    ];
}

function rbac_action_label(string $action): string
{
    return match ($action) {
        'view' => 'Ver',
        'edit' => 'Crear / editar',
        'delete' => 'Eliminar',
        'manage' => 'Administrar',
        default => ucfirst($action),
    };
}

/** Flat list of every capability string. */
function rbac_all_caps(): array
{
    $caps = [];
    foreach (rbac_modules() as $key => [$label, $actions]) {
        foreach ($actions as $a) {
            $caps[] = $key . '.' . $a;
        }
    }
    return $caps;
}

/** Capability always granted to every role (so nobody is stranded after login). */
function rbac_mandatory_caps(): array
{
    return ['panel.view'];
}

/** Built-in role defaults (admin is implicit/all-access and not stored here). */
function rbac_default_roles(): array
{
    return [
        'ventas' => ['label' => 'Ventas', 'caps' => [
            'panel.view', 'clientes.view', 'clientes.edit',
            'cotizaciones.view', 'cotizaciones.edit', 'cotizaciones.delete',
            'leads.view', 'leads.edit', 'leads.delete',
            'equipos.view', 'tickets.view', 'agenda.view', 'reportes.view',
        ]],
        'soporte' => ['label' => 'Soporte', 'caps' => [
            'panel.view', 'clientes.view', 'equipos.view', 'equipos.edit',
            'tickets.view', 'tickets.edit', 'tickets.delete',
            'agenda.view', 'agenda.edit', 'reportes.view',
        ]],
        'ingenieria' => ['label' => 'Ingeniería', 'caps' => [
            'panel.view', 'clientes.view', 'cotizaciones.view',
            'equipos.view', 'equipos.edit', 'tickets.view', 'tickets.edit',
            'agenda.view', 'agenda.edit', 'reportes.view',
        ]],
    ];
}

/** All roles incl. admin: key => ['label','caps']. Reads settings, falls back to defaults. */
function rbac_roles(): array
{
    $stored = null;
    $raw = setting_get('rbac_roles', null);
    if ($raw !== null && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $stored = $decoded;
        }
    }
    $roles = $stored ?? rbac_default_roles();

    // Normalize + force mandatory caps; validate caps against the catalog.
    $valid = rbac_all_caps();
    $out = [];
    foreach ($roles as $key => $def) {
        if ($key === 'admin') { continue; } // admin is always all-access
        $label = trim((string) ($def['label'] ?? ucfirst($key)));
        $caps = array_values(array_intersect((array) ($def['caps'] ?? []), $valid));
        $caps = array_values(array_unique(array_merge($caps, rbac_mandatory_caps())));
        $out[$key] = ['label' => $label !== '' ? $label : ucfirst($key), 'caps' => $caps];
    }

    // admin first, always complete.
    return ['admin' => ['label' => 'Administrador', 'caps' => ['*']]] + $out;
}

function role_label(string $role): string
{
    $roles = rbac_roles();
    return $roles[$role]['label'] ?? ucfirst($role);
}

/** Capabilities of a role. admin (or '*') => all. */
function role_caps(string $role): array
{
    if ($role === 'admin') {
        return ['*'];
    }
    $roles = rbac_roles();
    return $roles[$role]['caps'] ?? [];
}

/** Does the current user hold a capability? admin always yes. */
function current_can(string $cap): bool
{
    $role = current_role();
    if ($role === 'admin') {
        return true;
    }
    $caps = role_caps($role);
    return in_array('*', $caps, true) || in_array($cap, $caps, true);
}

/** Gate: must be logged in AND hold at least one of the given capabilities. */
function require_can(string ...$caps): void
{
    require_login();
    foreach ($caps as $c) {
        if (current_can($c)) {
            return;
        }
    }
    http_response_code(403);
    exit('No autorizado. Tu rol no tiene permiso para esta sección. Contacta al administrador.');
}

/** Persist role definitions (admin is never stored; mandatory caps enforced). */
function rbac_save_roles(array $roles): void
{
    $valid = rbac_all_caps();
    $clean = [];
    foreach ($roles as $key => $def) {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key));
        if ($key === '' || $key === 'admin') { continue; }
        $label = trim((string) ($def['label'] ?? ''));
        $caps = array_values(array_intersect((array) ($def['caps'] ?? []), $valid));
        $caps = array_values(array_unique(array_merge($caps, rbac_mandatory_caps())));
        $clean[$key] = ['label' => $label !== '' ? $label : ucfirst($key), 'caps' => $caps];
    }
    setting_set('rbac_roles', json_encode($clean, JSON_UNESCAPED_UNICODE));
}

/** First CRM page the current user is allowed to open (used as a safe landing). */
function rbac_landing_page(): string
{
    $map = [
        'panel' => 'crm/index.php', 'clientes' => 'crm/clientes.php', 'cotizaciones' => 'crm/cotizaciones.php',
        'leads' => 'crm/leads.php', 'equipos' => 'crm/equipos.php', 'tickets' => 'crm/tickets.php',
        'agenda' => 'crm/agenda.php', 'reportes' => 'crm/reportes.php',
    ];
    foreach ($map as $mod => $page) {
        if (current_can($mod . '.view')) {
            return $page;
        }
    }
    return 'crm/perfil.php'; // everyone can always see their own profile
}
