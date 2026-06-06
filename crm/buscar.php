<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$hasDb = db(false) && table_exists('clients');
$q = trim((string) ($_GET['q'] ?? ''));
$like = '%' . $q . '%';

$results = ['clients' => [], 'equipment' => [], 'quotes' => [], 'tickets' => []];
if ($hasDb && $q !== '') {
    $results['clients'] = fetch_all('SELECT id, name, city, status FROM clients WHERE name LIKE ? OR email LIKE ? OR rnc LIKE ? OR phone LIKE ? ORDER BY name LIMIT 8', [$like, $like, $like, $like]);
    $results['equipment'] = fetch_all('SELECT equipment.id, equipment.name, equipment.serial, equipment.status, clients.name AS client_name FROM equipment LEFT JOIN clients ON clients.id = equipment.client_id WHERE equipment.name LIKE ? OR equipment.serial LIKE ? OR equipment.brand LIKE ? OR equipment.model LIKE ? ORDER BY equipment.name LIMIT 8', [$like, $like, $like, $like]);
    $results['quotes'] = fetch_all('SELECT quotes.id, quotes.quote_number, quotes.title, quotes.status, quotes.total, clients.name AS client_name FROM quotes LEFT JOIN clients ON clients.id = quotes.client_id WHERE quotes.quote_number LIKE ? OR quotes.title LIKE ? OR clients.name LIKE ? ORDER BY quotes.created_at DESC LIMIT 8', [$like, $like, $like]);
    $results['tickets'] = fetch_all('SELECT tickets.id, tickets.subject, tickets.status, tickets.priority, clients.name AS client_name FROM tickets LEFT JOIN clients ON clients.id = tickets.client_id WHERE tickets.subject LIKE ? OR tickets.description LIKE ? OR clients.name LIKE ? ORDER BY tickets.created_at DESC LIMIT 8', [$like, $like, $like]);
}
$total = array_sum(array_map('count', $results));

$crmTitle = 'Búsqueda global';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<section class="crm-card" style="margin-bottom:1rem">
    <div class="crm-card__head">
        <div>
            <h2>Búsqueda global</h2>
            <p><?= $q !== '' ? e((string) $total) . ' resultado' . ($total === 1 ? '' : 's') . ' para “' . e($q) . '”' : 'Busca en clientes, equipos, cotizaciones y tickets.' ?></p>
        </div>
        <form method="get" class="crm-search-field" style="width:min(100%,360px)">
            <i data-lucide="search" class="h-4 w-4"></i>
            <input name="q" value="<?= e($q) ?>" placeholder="Buscar en todo el CRM" class="crm-input" autofocus>
        </form>
    </div>
</section>

<?php if (!$hasDb): ?>
    <div class="crm-card"><div class="crm-empty"><i data-lucide="database" class="h-6 w-6"></i><strong>Base de datos no conectada</strong><p>Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para habilitar la búsqueda.</p></div></div>
<?php elseif ($q === ''): ?>
    <div class="crm-card"><div class="crm-empty"><i data-lucide="search" class="h-6 w-6"></i><strong>Escribe para buscar</strong><p>Encuentra clientes, equipos, cotizaciones y tickets desde un solo lugar.</p></div></div>
<?php elseif ($total === 0): ?>
    <div class="crm-card"><div class="crm-empty"><i data-lucide="search-x" class="h-6 w-6"></i><strong>Sin coincidencias</strong><p>No encontramos nada para “<?= e($q) ?>”. Prueba con otro término.</p></div></div>
<?php else: ?>
    <div class="search-results">
        <?php
        $groups = [
            'clients' => ['Clientes', 'building-2'],
            'equipment' => ['Equipos', 'monitor'],
            'quotes' => ['Cotizaciones', 'file-text'],
            'tickets' => ['Tickets', 'life-buoy'],
        ];
        foreach ($groups as $key => [$gLabel, $gIcon]):
            $rows = $results[$key];
            if (!$rows) continue;
        ?>
            <section class="crm-card">
                <div class="crm-card__head">
                    <div class="search-group__title"><i data-lucide="<?= e($gIcon) ?>"></i><?= e($gLabel) ?> <span><?= e((string) count($rows)) ?></span></div>
                </div>
                <div class="crm-card__body" style="display:grid;gap:.5rem">
                    <?php foreach ($rows as $r): ?>
                        <?php if ($key === 'clients'): ?>
                            <a class="search-hit" href="<?= url('crm/cliente.php?id=' . (int) $r['id']) ?>">
                                <span class="search-hit__icon"><i data-lucide="building-2"></i></span>
                                <span class="search-hit__body"><b><?= e($r['name']) ?></b><span><?= e($r['city'] ?: 'Sin ciudad') ?></span></span>
                                <span class="search-hit__meta"><span class="status-chip <?= e(status_class($r['status'])) ?>"><?= e(status_label($r['status'])) ?></span></span>
                            </a>
                        <?php elseif ($key === 'equipment'): ?>
                            <a class="search-hit" href="<?= url('crm/equipos.php?edit=' . (int) $r['id']) ?>">
                                <span class="search-hit__icon"><i data-lucide="monitor"></i></span>
                                <span class="search-hit__body"><b><?= e($r['name']) ?></b><span><?= e($r['client_name'] ?? 'Sin cliente') ?> &middot; <?= e($r['serial'] ?: 'Sin serie') ?></span></span>
                                <span class="search-hit__meta"><span class="status-chip <?= e(status_class($r['status'])) ?>"><?= e(status_label($r['status'])) ?></span></span>
                            </a>
                        <?php elseif ($key === 'quotes'): ?>
                            <a class="search-hit" href="<?= url('crm/cotizaciones.php?action=view&id=' . (int) $r['id']) ?>">
                                <span class="search-hit__icon"><i data-lucide="file-text"></i></span>
                                <span class="search-hit__body"><b><?= e($r['quote_number']) ?> &middot; <?= e($r['title']) ?></b><span><?= e($r['client_name'] ?? 'Cliente') ?></span></span>
                                <span class="search-hit__meta"><b style="color:var(--ink)"><?= money($r['total']) ?></b></span>
                            </a>
                        <?php else: ?>
                            <a class="search-hit" href="<?= url('crm/tickets.php?id=' . (int) $r['id']) ?>">
                                <span class="search-hit__icon"><i data-lucide="life-buoy"></i></span>
                                <span class="search-hit__body"><b>TK-<?= date('Y') ?>-<?= str_pad((string) $r['id'], 4, '0', STR_PAD_LEFT) ?> &middot; <?= e($r['subject']) ?></b><span><?= e($r['client_name'] ?? 'Cliente') ?></span></span>
                                <span class="search-hit__meta"><span class="status-chip <?= e(status_class($r['status'])) ?>"><?= e(status_label($r['status'])) ?></span></span>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
