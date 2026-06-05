<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
verify_csrf();

$hasDb = db(false) && table_exists('quotes');
if ($hasDb) { ensure_quote_schema(); }
$hasQuoteCurrency = $hasDb && column_exists('quotes', 'currency');
$defaultQuoteTerms = setting_get('quote_terms', quote_default_terms());
$defaultQuoteRate = (float) (setting_get('quote_exchange_rate', '60') ?: 60);
$clients = $hasDb ? fetch_all('SELECT id, name FROM clients ORDER BY name ASC') : [];

function next_quote_number(): string
{
    $year = date('Y');
    $last = fetch_one('SELECT quote_number FROM quotes WHERE quote_number LIKE ? ORDER BY id DESC LIMIT 1', ["SCH-{$year}-%"]);
    $n = 1;
    if ($last && preg_match('/-(\d+)$/', $last['quote_number'], $m)) {
        $n = ((int) $m[1]) + 1;
    }
    return 'SCH-' . $year . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb && isset($_POST['delete_id'])) {
    $did = (int) $_POST['delete_id'];
    if ($did > 0) {
        db()->prepare('DELETE FROM quotes WHERE id=?')->execute([$did]);
        flash('success', 'Cotización eliminada.');
    }
    redirect('crm/cotizaciones.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb) {
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $status = trim((string) ($_POST['status'] ?? 'Borrador'));
    $validUntil = $_POST['valid_until'] ?: date('Y-m-d', strtotime('+30 days'));
    $taxRate = (float) ($_POST['tax_rate'] ?? 18);
    $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'DOP'))) === 'USD' ? 'USD' : 'DOP';
    $exchangeRate = (float) ($_POST['exchange_rate'] ?? 1);
    $exchangeRate = ($currency === 'DOP' || $exchangeRate <= 0) ? 1.0 : $exchangeRate;
    $terms = trim((string) ($_POST['terms'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $descriptions = $_POST['item_description'] ?? [];
    $quantities = $_POST['item_quantity'] ?? [];
    $prices = $_POST['item_price'] ?? [];

    $items = [];
    foreach ($descriptions as $i => $description) {
        $description = trim((string) $description);
        $qty = max(0, (float) ($quantities[$i] ?? 0));
        $price = max(0, (float) ($prices[$i] ?? 0));
        if ($description === '' || $qty <= 0) {
            continue;
        }
        $items[] = ['description' => $description, 'quantity' => $qty, 'unit_price' => $price, 'total' => $qty * $price];
    }

    if ($clientId <= 0 || $title === '' || count($items) === 0) {
        flash('warning', 'Selecciona cliente, titulo y al menos una linea de cotizacion.');
    } else {
        $subtotal = array_sum(array_column($items, 'total'));
        $tax = $subtotal * ($taxRate / 100);
        $total = $subtotal + $tax;
        $pdo = db();
        $pdo->beginTransaction();
        if ($hasQuoteCurrency) {
            $stmt = $pdo->prepare('INSERT INTO quotes (client_id, quote_number, title, status, valid_until, subtotal, tax_rate, tax_amount, total, currency, exchange_rate, notes, terms, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$clientId, next_quote_number(), $title, $status, $validUntil, $subtotal, $taxRate, $tax, $total, $currency, $exchangeRate, $notes, $terms, current_user()['id'] ?? null]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO quotes (client_id, quote_number, title, status, valid_until, subtotal, tax_rate, tax_amount, total, notes, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$clientId, next_quote_number(), $title, $status, $validUntil, $subtotal, $taxRate, $tax, $total, $notes, current_user()['id'] ?? null]);
        }
        $quoteId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO quote_items (quote_id, description, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)');
        foreach ($items as $item) {
            $stmt->execute([$quoteId, $item['description'], $item['quantity'], $item['unit_price'], $item['total']]);
        }
        $pdo->commit();
        flash('success', 'Cotizacion creada.');
        redirect('crm/cotizaciones.php?action=view&id=' . $quoteId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasDb) {
    flash('warning', 'Ejecuta install.php para guardar cotizaciones en MySQL.');
}

$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);

if ($action === 'view') {
    $quote = $hasDb ? fetch_one('SELECT quotes.*, clients.name AS client_name, clients.email, clients.phone, clients.address FROM quotes LEFT JOIN clients ON clients.id = quotes.client_id WHERE quotes.id=?', [$id]) : null;
    $items = $hasDb && $quote ? fetch_all('SELECT * FROM quote_items WHERE quote_id=? ORDER BY id ASC', [$id]) : [];
    if (!$quote) {
        $quote = ['quote_number' => 'SCH-2026-0001', 'client_name' => 'Hospital Metropolitano de Santiago', 'email' => 'compras@hms.local', 'phone' => '809-000-0000', 'address' => 'Santiago', 'title' => 'Sistema central de gases medicinales', 'status' => 'Enviado', 'valid_until' => date('Y-m-d', strtotime('+30 days')), 'subtotal' => 15000, 'tax_rate' => 18, 'tax_amount' => 2700, 'total' => 17700, 'notes' => 'Cotizacion demo. Ejecuta install.php para datos reales.'];
        $items = [
            ['description' => 'Suministro e instalacion de salidas de gases medicinales', 'quantity' => 12, 'unit_price' => 650, 'total' => 7800],
            ['description' => 'Alarmas sectoriales y caja de valvulas', 'quantity' => 1, 'unit_price' => 4200, 'total' => 4200],
            ['description' => 'Puesta en marcha y certificacion', 'quantity' => 1, 'unit_price' => 3000, 'total' => 3000],
        ];
    }

    $qCur = strtoupper((string) ($quote['currency'] ?? 'DOP')) === 'USD' ? 'USD' : 'DOP';
    $qRate = (float) ($quote['exchange_rate'] ?? 1);
    if ($qRate <= 0) { $qRate = 1; }
    $qTerms = trim((string) ($quote['terms'] ?? ''));
    if ($qTerms === '') { $qTerms = $defaultQuoteTerms; }
    $number = (string) ($quote['quote_number'] ?? 'COT');

    $crmTitle = 'Cotizacion ' . $number;
    require_once __DIR__ . '/../includes/crm_header.php';
    ?>
    <section class="mx-auto max-w-5xl">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between print:hidden">
            <a href="<?= url('crm/cotizaciones.php') ?>" class="crm-secondary-btn"><i data-lucide="arrow-left" class="h-4 w-4"></i>Volver</a>
            <button type="button" class="crm-primary-btn" onclick="crmPdfPreviewOpen('<?= url('crm/cotizacion_pdf.php?id=' . (int) ($quote['id'] ?? 0)) ?>','<?= url('crm/cotizacion_pdf.php?id=' . (int) ($quote['id'] ?? 0) . '&download=1') ?>','<?= e(addslashes($number)) ?>')"><i data-lucide="file-text" class="h-4 w-4"></i>Vista previa PDF</button>
        </div>
        <article class="quote-doc">
            <header class="quote-doc__head">
                <div>
                    <span class="quote-doc__brand"><img src="<?= asset(APP_LOGO) ?>" alt="SCH MEDICOS"><strong>SCH MEDICOS</strong></span>
                    <p>Equipos medicos, gases medicinales, diseno, instalacion, certificacion y soporte tecnico. RNC y datos fiscales en factura final.</p>
                </div>
                <div>
                    <span>Cotizacion</span>
                    <h1><?= e($quote['quote_number'] ?? 'COT') ?></h1>
                    <span class="status-chip <?= e(status_class($quote['status'] ?? 'Borrador')) ?>"><?= e($quote['status'] ?? 'Borrador') ?></span>
                    <p class="quote-doc__currency">Moneda: <strong><?= e($qCur) ?></strong><?php if ($qCur === 'USD'): ?> &middot; US$ 1 = RD$ <?= e(number_format($qRate, 2)) ?><?php endif; ?></p>
                </div>
            </header>

            <div class="quote-doc__meta">
                <section>
                    <h2>Cliente</h2>
                    <strong><?= e($quote['client_name'] ?? 'Cliente') ?></strong>
                    <p><?= e($quote['email'] ?? '') ?><br><?= e($quote['phone'] ?? '') ?><br><?= e($quote['address'] ?? '') ?></p>
                </section>
                <section>
                    <h2>Detalle</h2>
                    <strong><?= e($quote['title'] ?? '') ?></strong>
                    <p>Valida hasta <?= e(date_es($quote['valid_until'] ?? null)) ?></p>
                </section>
            </div>

            <div class="crm-table-wrap">
                <table class="crm-table quote-doc__table">
                    <thead>
                        <tr>
                            <th>Descripcion</th>
                            <th class="text-right">Cant.</th>
                            <th class="text-right">Precio</th>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><strong><?= e($item['description'] ?? '') ?></strong></td>
                                <td class="text-right"><?= e((string) ($item['quantity'] ?? '')) ?></td>
                                <td class="text-right"><?= money_cur($item['unit_price'] ?? 0, $qCur) ?></td>
                                <td class="text-right"><strong><?= money_cur($item['total'] ?? 0, $qCur) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$items): ?>
                            <tr><td colspan="4" class="text-center" style="color:var(--muted);padding:1.2rem">Esta cotización no tiene partidas registradas.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="quote-doc__totals">
                <div><span>Subtotal</span><strong><?= money_cur($quote['subtotal'] ?? 0, $qCur) ?></strong></div>
                <div><span>ITBIS <?= e((string) ($quote['tax_rate'] ?? 18)) ?>%</span><strong><?= money_cur($quote['tax_amount'] ?? 0, $qCur) ?></strong></div>
                <div><span>Total</span><strong><?= money_cur($quote['total'] ?? 0, $qCur) ?></strong></div>
                <?php if ($qCur === 'USD'): ?>
                    <div class="quote-doc__equiv"><span>Equivalente · US$ 1 = RD$ <?= e(number_format($qRate, 2)) ?></span><strong><?= money_cur(($quote['total'] ?? 0) * $qRate, 'DOP') ?></strong></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($quote['notes'])): ?>
                <div class="quote-doc__notes"><?= nl2br(e($quote['notes'])) ?></div>
            <?php endif; ?>
            <div class="quote-doc__terms">
                <h3>Términos y condiciones</h3>
                <p><?= nl2br(e($qTerms)) ?></p>
            </div>
        </article>
    </section>
    <?php if (!empty($_GET['print'])): ?>
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });</script>
    <?php endif; ?>
    <?php
    require_once __DIR__ . '/../includes/crm_footer.php';
    return;
}

$quotes = $hasDb
    ? fetch_all('SELECT quotes.*, clients.name AS client_name FROM quotes LEFT JOIN clients ON clients.id = quotes.client_id ORDER BY quotes.created_at DESC LIMIT 120')
    : [
        ['id' => 1, 'quote_number' => 'SCH-2026-0007', 'client_name' => 'Cedimat', 'title' => 'Camas UCI y accesorios', 'status' => 'Enviado', 'valid_until' => date('Y-m-d', strtotime('+20 days')), 'total' => 18450],
        ['id' => 2, 'quote_number' => 'SCH-2026-0006', 'client_name' => 'Hospital Jaime Mota', 'title' => 'Sistema central de gases', 'status' => 'Aprobado', 'valid_until' => date('Y-m-d', strtotime('+15 days')), 'total' => 9200],
    ];

$quoteTotal = count($quotes);
$quoteOpen = count(array_filter($quotes, fn ($q) => in_array((string) ($q['status'] ?? ''), ['Borrador', 'Enviado', 'Cotizado', 'Negociacion'], true)));
$quoteApproved = count(array_filter($quotes, fn ($q) => (string) ($q['status'] ?? '') === 'Aprobado'));
$quoteValue = array_sum(array_map(fn ($q) => (float) ($q['total'] ?? 0), $quotes));
$quoteExpiring = count(array_filter($quotes, function ($q) {
    $ts = !empty($q['valid_until']) ? strtotime((string) $q['valid_until']) : false;
    return $ts !== false && $ts >= strtotime(date('Y-m-d')) && $ts <= strtotime('+10 days');
}));

$crmTitle = 'Cotizaciones';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para guardar cotizaciones.</div>
<?php endif; ?>

<section class="crm-cockpit" x-data="crmQuoteModal(<?= (isset($_GET['new']) || $action === 'new') ? 'true' : 'false' ?>, <?= e((string) $defaultQuoteRate) ?>)">
    <div class="crm-cockpit__top">
        <div class="crm-cockpit__hero crm-cockpit__hero--sales">
            <span class="crm-kicker"><i data-lucide="file-text"></i>Pipeline comercial</span>
            <h2>Cotizaciones con monto, vigencia y accion comercial visibles.</h2>
            <p>Revisa propuestas abiertas, aprobadas y proximas a vencer. El constructor sigue en modal para preparar partidas sin abandonar el listado.</p>
            <div class="crm-cockpit__actions">
                <button type="button" class="crm-primary-btn" @click="open()"><i data-lucide="plus" class="h-4 w-4"></i>Nueva cotizacion</button>
                <a href="<?= url('crm/clientes.php') ?>" class="crm-secondary-btn"><i data-lucide="building-2" class="h-4 w-4"></i>Clientes</a>
            </div>
        </div>
        <div class="crm-cockpit__metrics" aria-label="Resumen de cotizaciones">
            <article><span>Total</span><strong><?= e((string) $quoteTotal) ?></strong><small>propuestas visibles</small></article>
            <article><span>Abiertas</span><strong><?= e((string) $quoteOpen) ?></strong><small>en seguimiento</small></article>
            <article><span>Aprobadas</span><strong><?= e((string) $quoteApproved) ?></strong><small>cerradas a favor</small></article>
            <article><span>Valor</span><strong><?= money($quoteValue) ?></strong><small><?= e((string) $quoteExpiring) ?> vencen en 10 dias</small></article>
        </div>
    </div>
    <article class="crm-data-surface">
        <div class="crm-data-surface__head">
            <div>
                <h3>Cotizaciones</h3>
                <p>Pipeline, montos, validez e impresion comercial.</p>
            </div>
            <button type="button" class="crm-primary-btn" @click="open()"><i data-lucide="plus" class="h-4 w-4"></i>Nueva cotizacion</button>
        </div>
        <div class="crm-table-wrap">
        <table class="crm-table crm-data-table">
            <thead>
                <tr>
                    <th>Numero</th>
                    <th>Cliente</th>
                    <th>Titulo</th>
                    <th>Estado</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Accion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quotes as $quote): ?>
                    <tr>
                        <td><strong><?= e($quote['quote_number']) ?></strong></td>
                        <td><?= e($quote['client_name'] ?? 'Cliente') ?></td>
                        <td><?= e($quote['title']) ?></td>
                        <td><span class="status-chip <?= e(status_class($quote['status'])) ?>"><?= e($quote['status']) ?></span></td>
                        <td class="text-right"><strong><?= money($quote['total']) ?></strong></td>
                        <td class="text-right">
                            <div class="crm-row-actions">
                                <a class="crm-icon-action" href="<?= url('crm/cotizaciones.php?action=view&id=' . (int) $quote['id']) ?>" title="Ver"><i data-lucide="eye"></i></a>
                                <button type="button" class="crm-icon-action" title="Vista previa PDF" onclick="crmPdfPreviewOpen('<?= url('crm/cotizacion_pdf.php?id=' . (int) $quote['id']) ?>','<?= url('crm/cotizacion_pdf.php?id=' . (int) $quote['id'] . '&download=1') ?>','<?= e(addslashes($quote['quote_number'])) ?>')"><i data-lucide="file-text"></i></button>
                                <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar la cotización <?= e(addslashes($quote['quote_number'])) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_id" value="<?= (int) $quote['id'] ?>">
                                    <button type="submit" class="crm-icon-action crm-icon-action--danger" title="Eliminar"><i data-lucide="trash-2"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$quotes): ?>
            <div class="crm-empty"><i data-lucide="file-text" class="h-6 w-6"></i><strong>Aún no hay cotizaciones</strong><p>Crea la primera con el botón “Nueva cotización”.</p></div>
        <?php endif; ?>
        </div>
    </article>

    <dialog x-ref="dlg" class="crm-modal crm-modal--wide" @click.self="close()" @cancel.prevent="close()">
        <form method="post" class="crm-modal__form">
            <?= csrf_field() ?>
            <header class="crm-modal__head">
                <span class="crm-modal__icon"><i data-lucide="file-text"></i></span>
                <div class="crm-modal__titles">
                    <h2>Nueva cotización</h2>
                    <p>Cliente, vigencia, partidas, ITBIS y notas comerciales.</p>
                </div>
                <button type="button" class="crm-modal__close" @click="close()" aria-label="Cerrar"><i data-lucide="x"></i></button>
            </header>
            <div class="crm-modal__body">
                <div class="crm-form-grid">
                    <label class="crm-field"><span class="required">Cliente</span>
                        <select name="client_id" required class="crm-select">
                            <option value="">Seleccionar</option>
                            <?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>"><?= e($client['name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="crm-field"><span class="required">Título</span><input name="title" required placeholder="Ej. Equipamiento quirófano 2" class="crm-input"></label>
                </div>
                <div class="crm-form-grid" style="grid-template-columns:repeat(4,minmax(0,1fr))">
                    <label class="crm-field"><span>Estado</span><select name="status" class="crm-select"><option>Borrador</option><option>Enviado</option><option>Aprobado</option><option>Rechazado</option></select></label>
                    <label class="crm-field"><span>Válida hasta</span><input type="date" name="valid_until" value="<?= e(date('Y-m-d', strtotime('+30 days'))) ?>" class="crm-input"></label>
                    <label class="crm-field"><span>Moneda</span><select name="currency" x-model="currency" class="crm-select"><option value="DOP">DOP — RD$</option><option value="USD">USD — US$</option></select></label>
                    <label class="crm-field" x-show="currency==='USD'" x-cloak><span>Tasa US$ 1 = RD$</span><input type="number" step="0.01" min="0" name="exchange_rate" x-model.number="rate" class="crm-input text-right"></label>
                    <label class="crm-field"><span>ITBIS %</span><input type="number" step="0.01" min="0" name="tax_rate" x-model.number="tax" class="crm-input"></label>
                </div>

                <div>
                    <p class="dash-section-label" style="margin:.2rem 0 .5rem">Partidas</p>
                    <div class="qb">
                        <div class="qb__head">
                            <span>Descripción</span><span>Cant.</span><span>Precio</span><span>Total</span><span></span>
                        </div>
                        <template x-for="(item,index) in items" :key="index">
                            <div class="qb__row">
                                <input class="crm-input qb__desc" name="item_description[]" x-model="item.d" placeholder="Equipo o servicio">
                                <input class="crm-input text-right" type="number" step="0.01" min="0" name="item_quantity[]" x-model.number="item.q" aria-label="Cantidad">
                                <input class="crm-input text-right" type="number" step="0.01" min="0" name="item_price[]" x-model.number="item.p" aria-label="Precio">
                                <span class="qb__total" x-text="fmt((Number(item.q)||0)*(Number(item.p)||0))">RD$ 0.00</span>
                                <button type="button" class="crm-icon-action crm-icon-action--danger" @click="removeLine(index)" title="Quitar partida"><i data-lucide="trash-2"></i></button>
                            </div>
                        </template>
                    </div>
                    <button type="button" @click="addLine()" class="crm-secondary-btn" style="margin-top:.6rem"><i data-lucide="plus" class="h-4 w-4"></i>Agregar línea</button>
                </div>

                <div class="grid gap-4 lg:grid-cols-[1fr_320px]">
                    <div class="grid gap-3" style="align-content:start">
                        <label class="crm-field"><span>Notas comerciales</span><textarea name="notes" rows="2" class="crm-textarea" placeholder="Condiciones específicas, alcance, exclusiones…"></textarea></label>
                        <label class="crm-field"><span>Términos y condiciones (editable)</span><textarea name="terms" rows="6" class="crm-textarea"><?= e($defaultQuoteTerms) ?></textarea></label>
                    </div>
                    <div class="quote-summary" style="align-self:start">
                        <div><span>Subtotal</span><strong x-text="fmt(subtotal())">RD$ 0.00</strong></div>
                        <div><span x-text="'ITBIS ' + (Number(tax)||0) + '%'">ITBIS 18%</span><strong x-text="fmt(taxAmount())">RD$ 0.00</strong></div>
                        <div><span>Total</span><strong x-text="fmt(total())">RD$ 0.00</strong></div>
                        <div class="quote-summary__equiv" x-show="currency==='USD'" x-cloak><span>Equivalente (RD$)</span><strong x-text="altFmt(altTotal())">RD$ 0.00</strong></div>
                    </div>
                </div>
            </div>
            <footer class="crm-modal__foot">
                <button type="button" class="crm-secondary-btn" @click="close()">Cancelar</button>
                <button type="submit" class="crm-primary-btn"><i data-lucide="file-plus-2" class="h-4 w-4"></i>Crear cotización</button>
            </footer>
        </form>
    </dialog>
</section>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
