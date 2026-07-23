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
        'facturas'     => ['Facturación',   ['view', 'edit', 'delete']],
        'leads'        => ['Leads',         ['view', 'edit', 'delete']],
        'equipos'      => ['Equipos',       ['view', 'edit', 'delete']],
        'tickets'      => ['Tickets',       ['view', 'edit', 'delete']],
        'agenda'       => ['Agenda',        ['view', 'edit']],
        'reportes'     => ['Reportes',      ['view']],
        'finanzas'     => ['Datos financieros', ['view']],
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
            'facturas.view', 'facturas.edit', 'facturas.delete',
            'leads.view', 'leads.edit', 'leads.delete',
            'equipos.view', 'tickets.view', 'agenda.view', 'reportes.view', 'finanzas.view',
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

/** Chip colour for a role (decoupled from status_class semantics). */
function role_class(string $role): string
{
    return $role === 'admin'
        ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200'
        : 'bg-blue-50 text-blue-700 ring-1 ring-blue-200';
}

/** Number of currently active administrators (for last-admin protection). */
function active_admin_count(): int
{
    if (!db(false) || !table_exists('users')) {
        return 1;
    }
    return db_count('users', "role='admin' AND status='activo'");
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

/* ---------------------------------------------------------------------------
 * Cartera y antigüedad de cuentas por cobrar — permiso NOMINAL, por usuario.
 * No depende del rol: es una lista blanca de IDs guardada en settings y editable
 * en CRM → Usuarios. Un administrador que no esté en la lista tampoco ve estos
 * datos (sí puede editar la lista, como cualquier gestor de usuarios).
 * ------------------------------------------------------------------------- */

/** IDs de usuario autorizados a ver cartera/antigüedad. */
function cartera_user_ids(): array
{
    $raw = setting_get('cartera_users', null);
    if ($raw === null || $raw === '') {
        return [];
    }
    $ids = json_decode((string) $raw, true);
    return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
}

/** Guarda la lista blanca (solo IDs existentes). */
function cartera_set_user_ids(array $ids): void
{
    $clean = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
    setting_set('cartera_users', json_encode($clean));
}

/**
 * Siembra la lista la primera vez con las personas de contabilidad autorizadas
 * (Fulvina, Fulvio y Delgis). Se ejecuta una sola vez: después manda lo que el
 * administrador configure en Usuarios, incluso si la lista queda vacía.
 */
function cartera_seed_defaults(): void
{
    if (!db(false) || !table_exists('users') || !table_exists('settings')) {
        return;
    }
    if (setting_get('cartera_users', null) !== null) {
        return; // ya configurada
    }
    $ids = [];
    foreach (['fulvina', 'fulvio', 'delgis'] as $needle) {
        foreach (fetch_all('SELECT id FROM users WHERE name LIKE ? OR email LIKE ?', ['%' . $needle . '%', $needle . '%']) as $u) {
            $ids[] = (int) $u['id'];
        }
    }
    cartera_set_user_ids($ids);
}

/** Concede o retira el permiso de cartera a un usuario. */
function cartera_toggle_user(int $userId, bool $granted): void
{
    if ($userId <= 0) {
        return;
    }
    $ids = cartera_user_ids();
    $has = in_array($userId, $ids, true);
    if ($granted === $has) {
        return;
    }
    $ids = $granted ? array_merge($ids, [$userId]) : array_values(array_diff($ids, [$userId]));
    cartera_set_user_ids($ids);
    log_activity('user', $userId, $granted ? 'cartera_permiso_otorgado' : 'cartera_permiso_retirado', null);
}

/** ¿El usuario en sesión puede ver cartera/antigüedad de cuentas por cobrar? */
function can_view_cartera(): bool
{
    $me = (int) (current_user()['id'] ?? 0);
    if ($me <= 0) {
        return false;
    }
    return in_array($me, cartera_user_ids(), true);
}

/** Gate de página/endpoint para la información de cartera. */
function require_cartera(): void
{
    require_login();
    if (!can_view_cartera()) {
        http_response_code(403);
        exit('No autorizado. La cartera y la antigüedad de cuentas por cobrar están restringidas a contabilidad.');
    }
}

/** First CRM page the current user is allowed to open (used as a safe landing). */
function rbac_landing_page(): string
{
    $map = [
        'panel' => 'crm/index.php', 'clientes' => 'crm/clientes.php', 'cotizaciones' => 'crm/cotizaciones.php',
        'facturas' => 'crm/facturas.php',
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
