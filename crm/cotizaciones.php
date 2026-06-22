<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('cotizaciones.view');
verify_csrf();

$hasDb = db(false) && table_exists('quotes');
if ($hasDb) { ensure_quote_schema(); }
$hasQuoteCurrency = $hasDb && column_exists('quotes', 'currency');
$hasCategory = $hasDb && column_exists('quotes', 'category');
$hasApproved = $hasDb && column_exists('quotes', 'approved_at');
$defaultQuoteTerms = setting_get('quote_terms', quote_default_terms());
$defaultQuoteRate = (float) (setting_get('quote_exchange_rate', '60') ?: 60);
$defaultQuoteTax = (float) setting_get('quote_tax_rate', '18');
$clients = $hasDb ? fetch_all('SELECT id, name FROM clients ORDER BY name ASC') : [];
$categories = quote_categories();
$quoteStatuses = ['Borrador', 'Enviado', 'Cotizado', 'Negociacion', 'Aprobado', 'Rechazado'];

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

/** Parse posted line items into normalized rows. */
function quote_parse_items(): array
{
    $descriptions = (array) ($_POST['item_description'] ?? []);
    $quantities = (array) ($_POST['item_quantity'] ?? []);
    $prices = (array) ($_POST['item_price'] ?? []);
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
    return $items;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb) {
    $form = (string) ($_POST['form'] ?? 'save');

    /* ---- Delete -------------------------------------------------------- */
    if (isset($_POST['delete_id'])) {
        if (!current_can('cotizaciones.delete')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/cotizaciones.php'); }
        $did = (int) $_POST['delete_id'];
        if ($did > 0) {
            db()->prepare('DELETE FROM quotes WHERE id=?')->execute([$did]);
            log_activity('quote', $did, 'cotizacion_eliminada', null);
            flash('success', 'Cotización eliminada.');
        }
        redirect('crm/cotizaciones.php');
    }

    /* ---- Change status from the list ----------------------------------- */
    if ($form === 'status') {
        if (!current_can('cotizaciones.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/cotizaciones.php'); }
        $sid = (int) ($_POST['id'] ?? 0);
        $newStatus = trim((string) ($_POST['status'] ?? ''));
        if ($sid > 0 && in_array($newStatus, $quoteStatuses, true)) {
            if ($newStatus === 'Aprobado' && $hasApproved) {
                db()->prepare('UPDATE quotes SET status=?, approved_at=COALESCE(approved_at, NOW()), updated_at=NOW() WHERE id=?')->execute([$newStatus, $sid]);
            } else {
                db()->prepare('UPDATE quotes SET status=?, updated_at=NOW() WHERE id=?')->execute([$newStatus, $sid]);
            }
            log_activity('quote', $sid, 'estado_' . str_replace(' ', '_', strtolower($newStatus)), null);
            flash('success', 'Estado de la cotización actualizado a “' . $newStatus . '”.');
        }
        redirect('crm/cotizaciones.php');
    }

    /* ---- Duplicate ----------------------------------------------------- */
    if ($form === 'duplicate') {
        if (!current_can('cotizaciones.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/cotizaciones.php'); }
        $sid = (int) ($_POST['id'] ?? 0);
        $src = $sid > 0 ? fetch_one('SELECT * FROM quotes WHERE id=?', [$sid]) : null;
        if ($src) {
            $pdo = db();
            $pdo->beginTransaction();
            if ($hasQuoteCurrency) {
                $cat = $hasCategory ? ($src['category'] ?? null) : null;
                $stmt = $pdo->prepare('INSERT INTO quotes (client_id, quote_number, title, ' . ($hasCategory ? 'category, ' : '') . 'status, valid_until, subtotal, tax_rate, tax_amount, total, currency, exchange_rate, notes, terms, created_by, created_at, updated_at) VALUES (?, ?, ?, ' . ($hasCategory ? '?, ' : '') . '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                $params = [$src['client_id'], next_quote_number(), $src['title'] . ' (copia)'];
                if ($hasCategory) { $params[] = $cat; }
                array_push($params, 'Borrador', date('Y-m-d', strtotime('+30 days')), $src['subtotal'], $src['tax_rate'], $src['tax_amount'], $src['total'], $src['currency'] ?? 'DOP', $src['exchange_rate'] ?? 1, $src['notes'] ?? '', $src['terms'] ?? '', current_user()['id'] ?? null);
                $stmt->execute($params);
            } else {
                $stmt = $pdo->prepare('INSERT INTO quotes (client_id, quote_number, title, status, valid_until, subtotal, tax_rate, tax_amount, total, notes, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                $stmt->execute([$src['client_id'], next_quote_number(), $src['title'] . ' (copia)', 'Borrador', date('Y-m-d', strtotime('+30 days')), $src['subtotal'], $src['tax_rate'], $src['tax_amount'], $src['total'], $src['notes'] ?? '', current_user()['id'] ?? null]);
            }
            $newId = (int) $pdo->lastInsertId();
            $items = fetch_all('SELECT description, quantity, unit_price, total FROM quote_items WHERE quote_id=? ORDER BY id ASC', [$sid]);
            $itemStmt = $pdo->prepare('INSERT INTO quote_items (quote_id, description, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)');
            foreach ($items as $it) {
                $itemStmt->execute([$newId, $it['description'], $it['quantity'], $it['unit_price'], $it['total']]);
            }
            $pdo->commit();
            log_activity('quote', $newId, 'cotizacion_duplicada', null);
            flash('success', 'Cotización duplicada como borrador.');
            redirect('crm/cotizaciones.php?action=view&id=' . $newId);
        }
        redirect('crm/cotizaciones.php');
    }

    /* ---- Create / Update (save) ---------------------------------------- */
    if ($form === 'save') {
        if (!current_can('cotizaciones.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/cotizaciones.php'); }
        $editId = (int) ($_POST['id'] ?? 0);
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        if ($category !== '' && !isset($categories[$category])) { $category = ''; }
        $status = trim((string) ($_POST['status'] ?? 'Borrador'));
        if (!in_array($status, $quoteStatuses, true)) { $status = 'Borrador'; }
        $validUntil = $_POST['valid_until'] ?: date('Y-m-d', strtotime('+30 days'));
        $taxRate = (float) ($_POST['tax_rate'] ?? 18);
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'DOP'))) === 'USD' ? 'USD' : 'DOP';
        $exchangeRate = (float) ($_POST['exchange_rate'] ?? 1);
        $exchangeRate = ($currency === 'DOP' || $exchangeRate <= 0) ? 1.0 : $exchangeRate;
        $terms = trim((string) ($_POST['terms'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $items = quote_parse_items();

        if ($clientId <= 0 || $title === '' || count($items) === 0) {
            flash('warning', 'Selecciona cliente, título y al menos una línea de cotización.');
        } else {
            $subtotal = array_sum(array_column($items, 'total'));
            $tax = $subtotal * ($taxRate / 100);
            $total = $subtotal + $tax;
            $pdo = db();
            $pdo->beginTransaction();

            if ($editId > 0 && fetch_one('SELECT id FROM quotes WHERE id=?', [$editId])) {
                $sets = 'client_id=?, title=?, ' . ($hasCategory ? 'category=?, ' : '') . 'status=?, valid_until=?, subtotal=?, tax_rate=?, tax_amount=?, total=?, ' . ($hasQuoteCurrency ? 'currency=?, exchange_rate=?, ' : '') . 'notes=?, ' . ($hasQuoteCurrency ? 'terms=?, ' : '') . 'updated_at=NOW()';
                $params = [$clientId, $title];
                if ($hasCategory) { $params[] = $category; }
                array_push($params, $status, $validUntil, $subtotal, $taxRate, $tax, $total);
                if ($hasQuoteCurrency) { array_push($params, $currency, $exchangeRate); }
                $params[] = $notes;
                if ($hasQuoteCurrency) { $params[] = $terms; }
                $params[] = $editId;
                $pdo->prepare("UPDATE quotes SET $sets WHERE id=?")->execute($params);
                $pdo->prepare('DELETE FROM quote_items WHERE quote_id=?')->execute([$editId]);
                $quoteId = $editId;
                $flashMsg = 'Cotización actualizada.';
                $logAction = 'cotizacion_actualizada';
            } else {
                // Retry on a UNIQUE(quote_number) collision so two concurrent
                // saves never 500 — recompute the number and retry (InnoDB keeps
                // the transaction usable after a duplicate-key error).
                for ($attempt = 0; ; $attempt++) {
                    try {
                        if ($hasQuoteCurrency) {
                            $cols = 'client_id, quote_number, title, ' . ($hasCategory ? 'category, ' : '') . 'status, valid_until, subtotal, tax_rate, tax_amount, total, currency, exchange_rate, notes, terms, created_by, created_at, updated_at';
                            $ph = '?, ?, ?, ' . ($hasCategory ? '?, ' : '') . '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()';
                            $params = [$clientId, next_quote_number(), $title];
                            if ($hasCategory) { $params[] = $category; }
                            array_push($params, $status, $validUntil, $subtotal, $taxRate, $tax, $total, $currency, $exchangeRate, $notes, $terms, current_user()['id'] ?? null);
                            $pdo->prepare("INSERT INTO quotes ($cols) VALUES ($ph)")->execute($params);
                        } else {
                            $pdo->prepare('INSERT INTO quotes (client_id, quote_number, title, status, valid_until, subtotal, tax_rate, tax_amount, total, notes, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
                                ->execute([$clientId, next_quote_number(), $title, $status, $validUntil, $subtotal, $taxRate, $tax, $total, $notes, current_user()['id'] ?? null]);
                        }
                        break;
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000' && $attempt < 4) { continue; }
                        throw $e;
                    }
                }
                $quoteId = (int) $pdo->lastInsertId();
                $flashMsg = 'Cotización creada.';
                $logAction = 'cotizacion_creada';
            }

            $stmt = $pdo->prepare('INSERT INTO quote_items (quote_id, description, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)');
            foreach ($items as $item) {
                $stmt->execute([$quoteId, $item['description'], $item['quantity'], $item['unit_price'], $item['total']]);
            }
            if ($hasApproved && $status === 'Aprobado') {
                $pdo->prepare('UPDATE quotes SET approved_at=COALESCE(approved_at, NOW()) WHERE id=?')->execute([$quoteId]);
            }
            $pdo->commit();
            log_activity('quote', $quoteId, $logAction ?? 'cotizacion_guardada', $title);
            flash('success', $flashMsg);
            redirect('crm/cotizaciones.php?action=view&id=' . $quoteId);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasDb) {
    flash('warning', 'Ejecuta install.php para guardar cotizaciones en MySQL.');
}

$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);

/* ============================ VIEW =================================== */
if ($action === 'view') {
    $quote = $hasDb ? fetch_one('SELECT quotes.*, clients.name AS client_name, clients.email, clients.phone, clients.address FROM quotes LEFT JOIN clients ON clients.id = quotes.client_id WHERE quotes.id=?', [$id]) : null;
    $items = $hasDb && $quote ? fetch_all('SELECT * FROM quote_items WHERE quote_id=? ORDER BY id ASC', [$id]) : [];
    if ($hasDb && !$quote) {
        flash('warning', 'La cotización solicitada no existe.');
        redirect('crm/cotizaciones.php');
    }
    if (!$quote) { // only when MySQL is absent → legitimate demo preview
        $quote = ['quote_number' => 'SCH-2026-0001', 'client_name' => 'Hospital Metropolitano de Santiago', 'email' => 'compras@hms.local', 'phone' => '809-000-0000', 'address' => 'Santiago', 'title' => 'Sistema central de gases medicinales', 'category' => 'Gases medicinales', 'status' => 'Enviado', 'valid_until' => date('Y-m-d', strtotime('+30 days')), 'subtotal' => 15000, 'tax_rate' => 18, 'tax_amount' => 2700, 'total' => 17700, 'notes' => 'Cotización demo. Ejecuta install.php para datos reales.'];
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
    $qCat = trim((string) ($quote['category'] ?? ''));
    $number = (string) ($quote['quote_number'] ?? 'COT');

    $crmTitle = 'Cotización ' . $number;
    require_once __DIR__ . '/../includes/crm_header.php';
    ?>
    <section class="mx-auto max-w-5xl">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between print:hidden">
            <a href="<?= url('crm/cotizaciones.php') ?>" class="crm-secondary-btn"><i data-lucide="arrow-left" class="h-4 w-4"></i>Volver</a>
            <div class="flex flex-wrap gap-2">
                <?php if ($hasDb && (int) ($quote['id'] ?? 0) > 0): ?>
                    <a href="<?= url('crm/cotizaciones.php?edit=' . (int) $quote['id']) ?>" class="crm-secondary-btn"><i data-lucide="pencil" class="h-4 w-4"></i>Editar</a>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="duplicate">
                        <input type="hidden" name="id" value="<?= (int) $quote['id'] ?>">
                        <button type="submit" class="crm-secondary-btn"><i data-lucide="copy" class="h-4 w-4"></i>Duplicar</button>
                    </form>
                    <?php if (current_can('facturas.edit')): ?>
                        <a href="<?= url('crm/facturas.php?action=new&from_quote=' . (int) $quote['id']) ?>" class="crm-secondary-btn"><i data-lucide="receipt" class="h-4 w-4"></i>Generar factura</a>
                    <?php endif; ?>
                <?php endif; ?>
                <button type="button" class="crm-primary-btn" onclick="crmPdfPreviewOpen('<?= url('crm/cotizacion_pdf.php?id=' . (int) ($quote['id'] ?? 0)) ?>','<?= url('crm/cotizacion_pdf.php?id=' . (int) ($quote['id'] ?? 0) . '&download=1') ?>','<?= e(addslashes($number)) ?>')"><i data-lucide="file-text" class="h-4 w-4"></i>Vista previa PDF</button>
            </div>
        </div>
        <article class="quote-doc">
            <header class="quote-doc__head">
                <div>
                    <span class="quote-doc__brand"><img src="<?= asset(APP_LOGO) ?>" alt="SCH MEDICOS"><strong>SCH MEDICOS</strong></span>
                    <p>Equipos medicos, gases medicinales, diseño, instalacion, certificacion y soporte tecnico. RNC y datos fiscales en factura final.</p>
                </div>
                <div>
                    <span>Cotización</span>
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
                    <?php if ($qCat !== ''): ?><p><span class="quote-cat"><i data-lucide="<?= e($categories[$qCat][0] ?? 'layers') ?>"></i><?= e($qCat) ?></span></p><?php endif; ?>
                    <p>Valida hasta <?= e(date_es($quote['valid_until'] ?? null)) ?></p>
                </section>
            </div>

            <div class="crm-table-wrap">
                <table class="crm-table quote-doc__table">
                    <thead>
                        <tr><th>Descripción</th><th class="text-right">Cant.</th><th class="text-right">Precio</th><th class="text-right">Total</th></tr>
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
    <?php
    require_once __DIR__ . '/../includes/crm_footer.php';
    return;
}

/* ====================== EDIT PAYLOAD (autoEdit) ====================== */
$editPayload = null;
if ($hasDb && $editId > 0) {
    $eq = fetch_one('SELECT * FROM quotes WHERE id=?', [$editId]);
    if ($eq) {
        $eItems = fetch_all('SELECT description, quantity, unit_price FROM quote_items WHERE quote_id=? ORDER BY id ASC', [$editId]);
        $editPayload = [
            'id' => (int) $eq['id'],
            'client_id' => (string) $eq['client_id'],
            'title' => (string) $eq['title'],
            'category' => (string) ($eq['category'] ?? ''),
            'status' => (string) $eq['status'],
            'valid_until' => (string) ($eq['valid_until'] ?? ''),
            'tax_rate' => (float) ($eq['tax_rate'] ?? 18),
            'currency' => (string) ($eq['currency'] ?? 'DOP'),
            'exchange_rate' => (float) ($eq['exchange_rate'] ?? 1),
            'notes' => (string) ($eq['notes'] ?? ''),
            'terms' => (string) ($eq['terms'] ?? ''),
            'items' => array_map(fn ($it) => ['d' => $it['description'], 'q' => (float) $it['quantity'], 'p' => (float) $it['unit_price']], $eItems),
        ];
    }
}

/* ============================ LIST ================================== */
$statusFilter = trim((string) ($_GET['status'] ?? ''));
if ($statusFilter !== '' && !in_array($statusFilter, $quoteStatuses, true)) { $statusFilter = ''; }
$clientFilter = (int) ($_GET['client_id'] ?? 0);
$listQ = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$totalPages = 1;
$totalMatching = 0;

if ($hasDb) {
    $where = '1=1';
    $params = [];
    if ($statusFilter !== '') { $where .= ' AND quotes.status = ?'; $params[] = $statusFilter; }
    if ($clientFilter > 0) { $where .= ' AND quotes.client_id = ?'; $params[] = $clientFilter; }
    if ($listQ !== '') {
        $like = '%' . $listQ . '%';
        $where .= ' AND (quotes.quote_number LIKE ? OR quotes.title LIKE ? OR clients.name LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $totalMatching = (int) (fetch_one("SELECT COUNT(*) total FROM quotes LEFT JOIN clients ON clients.id = quotes.client_id WHERE {$where}", $params)['total'] ?? 0);
    $totalPages = max(1, (int) ceil($totalMatching / $perPage));
    $quotes = fetch_all("SELECT quotes.*, clients.name AS client_name FROM quotes LEFT JOIN clients ON clients.id = quotes.client_id WHERE {$where} ORDER BY quotes.created_at DESC LIMIT {$perPage} OFFSET {$offset}", $params);
    // DB-wide KPIs (honest regardless of page/filter)
    $quoteTotal = (int) (fetch_one('SELECT COUNT(*) c FROM quotes')['c'] ?? 0);
    $quoteOpen = db_count('quotes', "status IN ('Borrador','Enviado','Cotizado','Negociacion')");
    $quoteApproved = db_count('quotes', "status='Aprobado'");
    $quoteValue = (float) (fetch_one("SELECT COALESCE(SUM(total),0) v FROM quotes WHERE status IN ('Borrador','Enviado','Cotizado','Negociacion','Aprobado') AND (valid_until IS NULL OR valid_until >= CURDATE())")['v'] ?? 0);
    $quoteExpiring = db_count('quotes', "valid_until IS NOT NULL AND valid_until >= CURDATE() AND valid_until <= DATE_ADD(CURDATE(), INTERVAL 10 DAY) AND status IN ('Borrador','Enviado','Cotizado','Negociacion')");
} else {
    $quotes = [
        ['id' => 1, 'quote_number' => 'SCH-2026-0007', 'client_name' => 'Cedimat', 'title' => 'Camas UCI y accesorios', 'category' => 'Equipos médicos', 'status' => 'Enviado', 'valid_until' => date('Y-m-d', strtotime('+20 days')), 'total' => 18450],
        ['id' => 2, 'quote_number' => 'SCH-2026-0006', 'client_name' => 'Hospital Jaime Mota', 'title' => 'Sistema central de gases', 'category' => 'Gases medicinales', 'status' => 'Aprobado', 'valid_until' => date('Y-m-d', strtotime('+15 days')), 'total' => 9200],
    ];
    $quoteTotal = count($quotes);
    $totalMatching = $quoteTotal;
    $quoteOpen = count(array_filter($quotes, fn ($q) => in_array((string) ($q['status'] ?? ''), ['Borrador', 'Enviado', 'Cotizado', 'Negociacion'], true)));
    $quoteApproved = count(array_filter($quotes, fn ($q) => (string) ($q['status'] ?? '') === 'Aprobado'));
    $quoteValue = array_sum(array_map(fn ($q) => (float) ($q['total'] ?? 0), $quotes));
    $quoteExpiring = 0;
}

$quoteQueryForPage = fn (int $p) => http_build_query(array_filter([
    'q' => $listQ, 'status' => $statusFilter, 'client_id' => $clientFilter ?: '', 'page' => $p,
], fn ($v) => $v !== '' && $v !== null));

$modalOpts = json_encode([
    'autoOpen' => (isset($_GET['new']) || $action === 'new') && !$editPayload,
    'autoEdit' => $editPayload,
    'defaults' => ['rate' => $defaultQuoteRate, 'tax' => $defaultQuoteTax, 'terms' => $defaultQuoteTerms, 'validUntil' => date('Y-m-d', strtotime('+30 days'))],
], JSON_UNESCAPED_UNICODE);

$crmTitle = 'Cotizaciones';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para guardar cotizaciones.</div>
<?php endif; ?>

<section class="crm-cockpit" x-data="crmQuoteModal(<?= e($modalOpts) ?>)">
    <div class="crm-cockpit__top">
        <div class="crm-cockpit__hero crm-cockpit__hero--sales">
            <span class="crm-kicker"><i data-lucide="file-text"></i>Pipeline comercial</span>
            <h2>Cotizaciones con monto, vigencia y acción comercial visibles.</h2>
            <p>Crea, edita o duplica propuestas, asigna su línea de negocio y cambia su estado sin abandonar el listado. El constructor de partidas vive en un modal.</p>
            <div class="crm-cockpit__actions">
                <?php if (current_can('cotizaciones.edit')): ?><button type="button" class="crm-primary-btn" @click="openNew()"><i data-lucide="plus" class="h-4 w-4"></i>Nueva cotización</button><?php endif; ?>
                <a href="<?= url('crm/clientes.php') ?>" class="crm-secondary-btn"><i data-lucide="building-2" class="h-4 w-4"></i>Clientes</a>
            </div>
        </div>
        <div class="crm-cockpit__metrics" aria-label="Resumen de cotizaciones">
            <article><span>Total</span><strong><?= e((string) $quoteTotal) ?></strong><small>propuestas visibles</small></article>
            <article><span>Abiertas</span><strong><?= e((string) $quoteOpen) ?></strong><small>en seguimiento</small></article>
            <article><span>Aprobadas</span><strong><?= e((string) $quoteApproved) ?></strong><small>cerradas a favor</small></article>
            <article><span>Valor</span><strong><?= money($quoteValue) ?></strong><small><?= e((string) $quoteExpiring) ?> vencen en 10 días</small></article>
        </div>
    </div>
    <article class="crm-data-surface">
        <div class="crm-data-surface__head">
            <div>
                <h3>Cotizaciones</h3>
                <p><?php if ($listQ !== '' || $statusFilter !== '' || $clientFilter > 0): ?><?= e((string) $totalMatching) ?> coincidencia<?= $totalMatching === 1 ? '' : 's' ?><?php else: ?>Pipeline, montos, validez, línea de negocio e impresión comercial.<?php endif; ?></p>
            </div>
            <?php if (current_can('cotizaciones.edit')): ?><button type="button" class="crm-primary-btn" @click="openNew()"><i data-lucide="plus" class="h-4 w-4"></i>Nueva cotización</button><?php endif; ?>
        </div>
        <form method="get" class="crm-toolbar" style="flex-wrap:wrap;gap:.5rem;padding:0 0 .8rem">
            <div class="crm-search-field" style="flex:1 1 220px"><i data-lucide="search" class="h-4 w-4"></i><input name="q" value="<?= e($listQ) ?>" placeholder="Número, título o cliente" class="crm-input"></div>
            <select name="status" class="crm-select" style="max-width:180px"><option value="">Todos los estados</option><?php foreach ($quoteStatuses as $st): ?><option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e($st) ?></option><?php endforeach; ?></select>
            <?php if ($hasDb): ?><select name="client_id" class="crm-select" style="max-width:200px"><option value="">Todos los clientes</option><?php foreach ($clients as $cl): ?><option value="<?= (int) $cl['id'] ?>" <?= $clientFilter === (int) $cl['id'] ? 'selected' : '' ?>><?= e($cl['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
            <button type="submit" class="crm-secondary-btn"><i data-lucide="filter" class="h-4 w-4"></i>Filtrar</button>
            <?php if ($listQ !== '' || $statusFilter !== '' || $clientFilter > 0): ?><a href="<?= url('crm/cotizaciones.php') ?>" class="crm-secondary-btn"><i data-lucide="x" class="h-4 w-4"></i>Limpiar</a><?php endif; ?>
        </form>
        <div class="crm-table-wrap">
        <table class="crm-table crm-data-table">
            <thead>
                <tr>
                    <th>Número</th>
                    <th>Cliente</th>
                    <th>Título / línea</th>
                    <th>Estado</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quotes as $quote): $qc = trim((string) ($quote['category'] ?? '')); ?>
                    <?php $isExpired = !empty($quote['valid_until']) && strtotime((string) $quote['valid_until']) < strtotime(date('Y-m-d')) && in_array((string) ($quote['status'] ?? ''), ['Borrador', 'Enviado', 'Cotizado', 'Negociacion'], true); ?>
                    <tr>
                        <td><strong><?= e($quote['quote_number']) ?></strong></td>
                        <td><?= e($quote['client_name'] ?? 'Cliente') ?></td>
                        <td>
                            <?= e($quote['title']) ?>
                            <?php if ($qc !== ''): ?><br><span class="quote-cat"><i data-lucide="<?= e($categories[$qc][0] ?? 'layers') ?>"></i><?= e($qc) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($hasDb && current_can('cotizaciones.edit')): ?>
                                <form method="post" class="quote-status-form" onchange="this.submit()">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="form" value="status">
                                    <input type="hidden" name="id" value="<?= (int) $quote['id'] ?>">
                                    <select name="status" aria-label="Cambiar estado">
                                        <?php foreach ($quoteStatuses as $st): ?>
                                            <option value="<?= e($st) ?>" <?= (string) $quote['status'] === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            <?php else: ?>
                                <span class="status-chip <?= e(status_class($quote['status'])) ?>"><?= e($quote['status']) ?></span>
                            <?php endif; ?>
                            <?php if ($isExpired): ?><span class="status-chip bg-red-50 text-red-700 ring-1 ring-red-200" title="Venció el <?= e(date_es($quote['valid_until'])) ?>">Vencida</span><?php endif; ?>
                        </td>
                        <td class="text-right"><strong><?= money($quote['total']) ?></strong></td>
                        <td class="text-right">
                            <div class="crm-row-actions">
                                <a class="crm-icon-action" href="<?= url('crm/cotizaciones.php?action=view&id=' . (int) $quote['id']) ?>" title="Ver"><i data-lucide="eye"></i></a>
                                <?php if ($hasDb): ?><a class="crm-icon-action" href="<?= url('crm/cotizaciones.php?edit=' . (int) $quote['id']) ?>" title="Editar"><i data-lucide="pencil"></i></a><?php endif; ?>
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
            <div class="crm-empty"><i data-lucide="file-text" class="h-6 w-6"></i><strong><?= $listQ !== '' || $statusFilter !== '' || $clientFilter > 0 ? 'Sin coincidencias' : 'Aún no hay cotizaciones' ?></strong><p><?= $listQ !== '' || $statusFilter !== '' || $clientFilter > 0 ? 'Prueba con otros filtros.' : 'Crea la primera con el botón “Nueva cotización”.' ?></p></div>
        <?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="crm-pager">
                <a class="<?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= $page <= 1 ? '#' : url('crm/cotizaciones.php?' . $quoteQueryForPage($page - 1)) ?>"><i data-lucide="chevron-left" class="h-4 w-4"></i>Anterior</a>
                <b><?= e((string) $page) ?> / <?= e((string) $totalPages) ?></b>
                <a class="<?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : url('crm/cotizaciones.php?' . $quoteQueryForPage($page + 1)) ?>">Siguiente<i data-lucide="chevron-right" class="h-4 w-4"></i></a>
            </div>
        <?php endif; ?>
    </article>

    <dialog x-ref="dlg" class="crm-modal crm-modal--wide" @click.self="close()" @cancel.prevent="close()">
        <form method="post" class="crm-modal__form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="save">
            <input type="hidden" name="id" :value="form.id">
            <header class="crm-modal__head">
                <span class="crm-modal__icon"><i data-lucide="file-text"></i></span>
                <div class="crm-modal__titles">
                    <h2 x-text="form.id ? 'Editar cotización' : 'Nueva cotización'">Nueva cotización</h2>
                    <p>Cliente, línea de negocio, vigencia, partidas, ITBIS y notas comerciales.</p>
                </div>
                <button type="button" class="crm-modal__close" @click="close()" aria-label="Cerrar"><i data-lucide="x"></i></button>
            </header>
            <div class="crm-modal__body">
                <div class="crm-form-grid">
                    <label class="crm-field"><span class="required">Cliente</span>
                        <select name="client_id" required x-model="form.client_id" class="crm-select">
                            <option value="">Seleccionar</option>
                            <?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>"><?= e($client['name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="crm-field"><span class="required">Título</span><input name="title" required x-model="form.title" placeholder="Ej. Equipamiento quirófano 2" class="crm-input"></label>
                </div>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Línea de negocio</span>
                        <select name="category" x-model="form.category" class="crm-select">
                            <option value="">Sin clasificar</option>
                            <?php foreach (array_keys($categories) as $cat): ?><option value="<?= e($cat) ?>"><?= e($cat) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="crm-field"><span>Estado</span><select name="status" x-model="form.status" class="crm-select"><?php foreach ($quoteStatuses as $st): ?><option value="<?= e($st) ?>"><?= e($st) ?></option><?php endforeach; ?></select></label>
                </div>
                <div class="crm-form-grid" style="grid-template-columns:repeat(4,minmax(0,1fr))">
                    <label class="crm-field"><span>Válida hasta</span><input type="date" name="valid_until" x-model="form.valid_until" class="crm-input"></label>
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
                        <label class="crm-field"><span>Notas comerciales</span><textarea name="notes" rows="2" x-model="form.notes" class="crm-textarea" placeholder="Condiciones específicas, alcance, exclusiones…"></textarea></label>
                        <label class="crm-field"><span>Términos y condiciones (editable)</span><textarea name="terms" rows="6" x-model="form.terms" class="crm-textarea"></textarea></label>
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
                <button type="submit" class="crm-primary-btn"><i data-lucide="file-plus-2" class="h-4 w-4"></i><span x-text="form.id ? 'Guardar cambios' : 'Crear cotización'">Crear cotización</span></button>
            </footer>
        </form>
    </dialog>
</section>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
