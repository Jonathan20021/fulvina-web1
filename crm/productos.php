<?php
/**
 * Catálogo de productos y servicios.
 *
 * Aquí vive el precio de lista y —lo que faltaba en todo el CRM— el COSTO.
 * Sin costo no hay margen, y sin margen nadie puede responder cuánto se ganó en
 * una venta. El costo puede quedar en blanco: eso significa «todavía no lo
 * sabemos», que es distinto de cero y se trata como tal en los reportes.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('productos.view');
verify_csrf();

$hasDb = db(false) !== null;
if ($hasDb) {
    ensure_products_schema();
}
$available = products_available();
$canEdit = current_can('productos.edit');

/* ---- Alta / edición ------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $available && ($_POST['form'] ?? '') === 'save') {
    if (!$canEdit) {
        flash('warning', 'Acción no permitida por tu rol.');
        redirect('crm/productos.php');
    }
    $id = (int) ($_POST['id'] ?? 0);
    [$ok, $msg, $newId] = product_save($_POST, $id);
    flash($ok ? 'success' : 'warning', $msg);
    redirect('crm/productos.php' . ($ok ? '' : ($id > 0 ? '?edit=' . $id : '?new=1')));
}

/* ---- Eliminar ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $available && isset($_POST['delete_id'])) {
    if (!current_can('productos.delete')) {
        flash('warning', 'Acción no permitida por tu rol.');
        redirect('crm/productos.php');
    }
    [$ok, $msg] = product_delete((int) $_POST['delete_id']);
    flash($ok ? 'success' : 'warning', $msg);
    redirect('crm/productos.php');
}

/* ---- Listado ------------------------------------------------------------- */
$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
    'kind' => trim((string) ($_GET['kind'] ?? '')),
    'active' => (string) ($_GET['active'] ?? '1'),
];
$rows = $available ? products_all($filters) : [];
$hasFilters = $filters['q'] !== '' || $filters['category'] !== '' || $filters['kind'] !== '' || $filters['active'] !== '1';

$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? product_find($editId) : null;
$openForm = $editing !== null || isset($_GET['new']);

// Resumen honesto: cuántas fichas tienen costo y cuántas no.
$totalActive = $available ? (int) (fetch_one('SELECT COUNT(*) c FROM products WHERE active = 1')['c'] ?? 0) : 0;
$withCost = $available ? (int) (fetch_one('SELECT COUNT(*) c FROM products WHERE active = 1 AND cost IS NOT NULL')['c'] ?? 0) : 0;
$costCoverage = $totalActive > 0 ? round($withCost / $totalActive * 100) : 0;
$avgMargin = null;
if ($available && $withCost > 0) {
    $r = fetch_one('SELECT COALESCE(SUM(price - cost),0) m, COALESCE(SUM(price),0) p FROM products WHERE active = 1 AND cost IS NOT NULL AND price > 0');
    $p = (float) ($r['p'] ?? 0);
    $avgMargin = $p > 0.009 ? round((float) $r['m'] / $p * 100, 1) : null;
}

$formValues = $editing ?? [
    'id' => 0, 'sku' => '', 'name' => '', 'description' => '', 'kind' => 'producto',
    'category' => '', 'brand' => '', 'unit' => 'unidad', 'cost' => '', 'price' => '',
    'currency' => 'DOP', 'is_exempt' => 0, 'active' => 1, 'notes' => '',
];

$crmTitle = 'Productos y servicios';
require_once __DIR__ . '/../includes/crm_header.php';
?>
<?= sch_encabezado('Catálogo', 'Precio de lista y costo de cada producto o servicio') ?>


<section class="crm-cockpit">
    <div class="crm-cockpit__top">
        <div class="crm-cockpit__hero crm-cockpit__hero--sales">
            <h2>Precio de lista y costo, en un solo sitio.</h2>
            <p>Lo que registres aquí se inserta en cotizaciones y facturas con un clic, con el mismo precio siempre. El <b>costo</b> viaja con la partida y queda congelado en el documento: es lo que permite medir el margen de cada venta sin que cambiarlo hoy reescriba la historia.</p>
            <?php if ($canEdit): ?>
            <div class="crm-cockpit__actions">
                <a href="<?= url('crm/productos.php?new=1') ?>" class="crm-primary-btn"><i data-lucide="plus" class="h-4 w-4"></i>Nueva ficha</a>
                <a href="<?= url('crm/reportes.php') ?>" class="crm-secondary-btn"><i data-lucide="bar-chart-3" class="h-4 w-4"></i>Ver márgenes</a>
            </div>
            <?php endif; ?>
        </div>
        <div class="crm-cockpit__metrics" aria-label="Resumen del catálogo">
            <article><span>Fichas activas</span><strong><?= e((string) $totalActive) ?></strong><small>productos y servicios</small></article>
            <article><span>Con costo</span><strong><?= e((string) $withCost) ?></strong><small><?= e((string) $costCoverage) ?>% del catálogo</small></article>
            <article><span>Margen promedio</span><strong><?= $avgMargin === null ? '—' : e((string) $avgMargin) . '%' ?></strong><small>sobre precio de lista</small></article>
            <article><span>Sin costo</span><strong><?= e((string) ($totalActive - $withCost)) ?></strong><small>margen no medible</small></article>
        </div>
    </div>

    <?php if (!$available): ?>
        <div class="crm-empty"><i data-lucide="database" class="h-6 w-6"></i><strong>Catálogo no disponible</strong><p>Ejecuta <code>php database/migrate.php</code> (o <a href="<?= url('install.php') ?>">install.php</a>) para crear la tabla de productos.</p></div>
    <?php else: ?>

    <?php if ($totalActive > 0 && $withCost < $totalActive): ?>
        <div class="gas-aviso" style="line-height:1.6">
            <b><i data-lucide="alert-triangle" style="width:15px;height:15px;vertical-align:-2px"></i> <?= e((string) ($totalActive - $withCost)) ?> ficha<?= ($totalActive - $withCost) === 1 ? '' : 's' ?> sin costo.</b>
            El reporte de margen las deja fuera en vez de darles margen del 100%. Completa el costo para que entren en el cálculo.
        </div>
    <?php endif; ?>

    <article class="crm-data-surface">
        <div class="crm-data-surface__head">
            <div><h3>Fichas</h3><p><?php if ($hasFilters): ?><?= e((string) count($rows)) ?> coincidencia<?= count($rows) === 1 ? '' : 's' ?><?php else: ?>Precio, costo y margen de cada producto o servicio.<?php endif; ?></p></div>
            <?php if ($canEdit): ?><a href="<?= url('crm/productos.php?new=1') ?>" class="crm-primary-btn"><i data-lucide="plus" class="h-4 w-4"></i>Nueva ficha</a><?php endif; ?>
        </div>

        <form method="get" class="crm-toolbar" style="flex-wrap:wrap;gap:.5rem;padding:0 0 .8rem">
            <div class="crm-search-field" style="flex:1 1 220px"><i data-lucide="search" class="h-4 w-4"></i><input name="q" value="<?= e($filters['q']) ?>" placeholder="Nombre, código, marca o descripción" class="crm-input"></div>
            <select name="kind" class="crm-select" style="max-width:160px"><option value="">Producto y servicio</option><?php foreach (product_kinds() as $k => $label): ?><option value="<?= e($k) ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
            <select name="category" class="crm-select" style="max-width:220px"><option value="">Todas las líneas</option><?php foreach (quote_categories() as $cat => $_m): ?><option value="<?= e($cat) ?>" <?= $filters['category'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option><?php endforeach; ?></select>
            <select name="active" class="crm-select" style="max-width:150px">
                <option value="1" <?= $filters['active'] === '1' ? 'selected' : '' ?>>Solo activas</option>
                <option value="0" <?= $filters['active'] === '0' ? 'selected' : '' ?>>Solo inactivas</option>
                <option value="" <?= $filters['active'] === '' ? 'selected' : '' ?>>Todas</option>
            </select>
            <button type="submit" class="crm-secondary-btn"><i data-lucide="filter" class="h-4 w-4"></i>Filtrar</button>
            <?php if ($hasFilters): ?><a href="<?= url('crm/productos.php') ?>" class="crm-secondary-btn"><i data-lucide="x" class="h-4 w-4"></i>Limpiar</a><?php endif; ?>
        </form>

        <?php if (!$rows): ?>
            <div class="crm-empty">
                <i data-lucide="package" class="h-6 w-6"></i>
                <strong><?= $hasFilters ? 'Sin coincidencias' : 'El catálogo está vacío' ?></strong>
                <p><?= $hasFilters ? 'Prueba con otro filtro.' : 'Registra tus productos y servicios más vendidos para que las cotizaciones salgan siempre con el mismo precio, y para poder medir el margen.' ?></p>
            </div>
        <?php else: ?>
            <div class="crm-table-wrap">
                <table class="crm-table crm-data-table">
                    <thead><tr>
                        <th>Ficha</th><th>Línea</th><th class="text-right">Costo</th><th class="text-right">Precio</th><th class="text-right">Margen</th><th class="text-right">Acción</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $p): $m = product_margin($p); $inactive = (int) $p['active'] !== 1; ?>
                        <tr<?= $inactive ? ' style="opacity:.6"' : '' ?>>
                            <td>
                                <strong><?= e((string) $p['name']) ?></strong>
                                <?php if ($inactive): ?> <span class="status-chip gas-estado--cerrado">Inactiva</span><?php endif; ?>
                                <?php if ((int) $p['is_exempt'] === 1): ?> <span class="inv-tag-exempt">Exento ITBIS</span><?php endif; ?>
                                <p class="text-xs text-slate-500">
                                    <?= e(trim(implode(' · ', array_filter([
                                        (string) ($p['sku'] ?? ''),
                                        product_kinds()[(string) $p['kind']] ?? '',
                                        (string) ($p['brand'] ?? ''),
                                        (string) ($p['unit'] ?? ''),
                                    ])))) ?>
                                </p>
                            </td>
                            <td><?= e((string) ($p['category'] ?: '—')) ?></td>
                            <td class="text-right"><?= $p['cost'] === null ? '<span class="gas-aviso gas-aviso--chip">Sin costo</span>' : e(money_cur($p['cost'], (string) $p['currency'])) ?></td>
                            <td class="text-right"><strong><?= e(money_cur($p['price'], (string) $p['currency'])) ?></strong></td>
                            <td class="text-right">
                                <?php if ($m === null): ?>
                                    <span style="color:var(--muted)">—</span>
                                <?php else: ?>
                                    <strong class="<?= $m['amount'] < 0 ? 'sch-monto--baja' : 'sch-monto--sube' ?>"><?= e(money_cur($m['amount'], (string) $p['currency'])) ?></strong>
                                    <?php if ($m['pct'] !== null): ?><p class="text-xs text-slate-500"><?= e((string) $m['pct']) ?>%</p><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <div class="crm-row-actions">
                                    <?php if ($canEdit): ?><a class="crm-icon-action" href="<?= url('crm/productos.php?edit=' . (int) $p['id']) ?>" title="Editar"><i data-lucide="pencil"></i></a><?php endif; ?>
                                    <?php if (current_can('productos.delete')): ?>
                                        <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar la ficha <?= e(addslashes((string) $p['name'])) ?>? Las partidas ya emitidas conservan su descripción y su costo.');">
                                            <?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int) $p['id'] ?>">
                                            <button type="submit" class="crm-icon-action crm-icon-action--danger" title="Eliminar"><i data-lucide="trash-2"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <?php if ($canEdit): ?>
    <dialog id="prod-form" class="crm-modal crm-modal--wide" onclick="if(event.target===this)this.close()" <?= $openForm ? 'open' : '' ?>>
        <form method="post" class="crm-modal__form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="save">
            <input type="hidden" name="id" value="<?= (int) ($formValues['id'] ?? 0) ?>">
            <header class="crm-modal__head">
                <span class="crm-modal__icon"><i data-lucide="package"></i></span>
                <div class="crm-modal__titles"><h2><?= $editing ? 'Editar ficha' : 'Nueva ficha' ?></h2><p>Lo que se inserta en cotizaciones y facturas.</p></div>
                <a class="crm-modal__close" href="<?= url('crm/productos.php') ?>"><i data-lucide="x"></i></a>
            </header>
            <div class="crm-modal__body">
                <div class="crm-form-grid">
                    <label class="crm-field" style="grid-column:1/-1"><span class="required">Nombre</span><input name="name" value="<?= e((string) $formValues['name']) ?>" class="crm-input" required placeholder="Ej. Monitor de signos vitales GE B450"></label>
                    <label class="crm-field"><span>Código / SKU</span><input name="sku" value="<?= e((string) ($formValues['sku'] ?? '')) ?>" class="crm-input" placeholder="Opcional, pero único"></label>
                    <label class="crm-field"><span>Tipo</span><select name="kind" class="crm-select"><?php foreach (product_kinds() as $k => $label): ?><option value="<?= e($k) ?>" <?= (string) $formValues['kind'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                    <label class="crm-field"><span>Línea de negocio</span><select name="category" class="crm-select"><option value="">Sin asignar</option><?php foreach (quote_categories() as $cat => $_m): ?><option value="<?= e($cat) ?>" <?= (string) ($formValues['category'] ?? '') === $cat ? 'selected' : '' ?>><?= e($cat) ?></option><?php endforeach; ?></select></label>
                    <label class="crm-field"><span>Marca</span><input name="brand" value="<?= e((string) ($formValues['brand'] ?? '')) ?>" class="crm-input" placeholder="GE, Dräger, Siemens…"></label>
                    <label class="crm-field"><span>Unidad</span><input name="unit" value="<?= e((string) ($formValues['unit'] ?? '')) ?>" class="crm-input" list="prod-units" placeholder="unidad"><datalist id="prod-units"><?php foreach (product_units() as $u): ?><option value="<?= e($u) ?>"><?php endforeach; ?></datalist></label>
                    <label class="crm-field"><span>Moneda</span><select name="currency" class="crm-select"><option value="DOP" <?= (string) ($formValues['currency'] ?? 'DOP') === 'DOP' ? 'selected' : '' ?>>DOP — RD$</option><option value="USD" <?= (string) ($formValues['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD — US$</option></select></label>
                </div>

                <div class="crm-form-grid" style="margin-top:.7rem">
                    <label class="crm-field"><span>Costo</span>
                        <input name="cost" value="<?= $formValues['cost'] === null || $formValues['cost'] === '' ? '' : e(number_format((float) $formValues['cost'], 2, '.', '')) ?>" class="crm-input text-right" inputmode="decimal" placeholder="En blanco = no se sabe">
                        <small style="color:var(--muted);font-size:.75rem">Déjalo vacío si aún no lo conoces. Un cero se leería como margen del 100%.</small>
                    </label>
                    <label class="crm-field"><span class="required">Precio de lista</span>
                        <input name="price" value="<?= e(number_format((float) ($formValues['price'] ?? 0), 2, '.', '')) ?>" class="crm-input text-right" inputmode="decimal" required>
                    </label>
                </div>

                <label class="crm-field" style="margin-top:.7rem"><span>Descripción para el documento</span>
                    <textarea name="description" rows="2" class="crm-textarea" placeholder="Si la dejas vacía se usa el nombre."><?= e((string) ($formValues['description'] ?? '')) ?></textarea>
                </label>
                <label class="crm-field" style="margin-top:.5rem"><span>Notas internas</span>
                    <input name="notes" value="<?= e((string) ($formValues['notes'] ?? '')) ?>" class="crm-input" placeholder="No se imprime en cotizaciones ni facturas">
                </label>

                <div class="crm-perm-box" style="margin-top:.8rem">
                    <label class="crm-toggle" style="display:flex;align-items:center;gap:.55rem;margin-bottom:.45rem">
                        <input type="checkbox" name="is_exempt" value="1" <?= (int) ($formValues['is_exempt'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span><b>Exento de ITBIS</b> — al insertarlo, la partida nace marcada como exenta</span>
                    </label>
                    <label class="crm-toggle" style="display:flex;align-items:center;gap:.55rem">
                        <input type="checkbox" name="active" value="1" <?= (int) ($formValues['active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <span><b>Activa</b> — aparece en el selector de partidas</span>
                    </label>
                </div>
            </div>
            <footer class="crm-modal__foot">
                <a class="crm-secondary-btn" href="<?= url('crm/productos.php') ?>">Cancelar</a>
                <button type="submit" class="crm-primary-btn"><i data-lucide="check" class="h-4 w-4"></i><?= $editing ? 'Guardar cambios' : 'Crear ficha' ?></button>
            </footer>
        </form>
    </dialog>
    <?php endif; ?>

    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
