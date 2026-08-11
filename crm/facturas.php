<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('facturas.view');
verify_csrf();

$hasDb = db(false) && table_exists('clients');
if (db(false)) { ensure_invoice_schema(); cartera_seed_defaults(); }
$hasInvoices = $hasDb && table_exists('invoices');

/* Cartera y antigüedad de cuentas por cobrar: permiso nominal (contabilidad),
   no depende del rol. Ver includes/rbac.php → can_view_cartera(). */
$canCartera = can_view_cartera();

$ncfTypes = ncf_types();
$ncfPrefixes = ncf_prefixes();
$invStatuses = invoice_status_list();
$payConditions = invoice_payment_conditions();
$payMethods = invoice_payment_methods();

$defaultTerms = setting_get('invoice_terms', invoice_default_terms());
$defaultTax = (float) setting_get('invoice_tax_rate', setting_get('quote_tax_rate', '18'));
$defaultType = (string) setting_get('invoice_default_type', '01');
if (!isset($ncfTypes[$defaultType])) { $defaultType = '01'; }
$defaultCondition = (string) setting_get('invoice_default_condition', 'Contado');
$defaultDueDays = max(0, (int) setting_get('invoice_due_days', '30'));
$defaultRate = (float) (setting_get('quote_exchange_rate', '60') ?: 60);

$clients = $hasDb ? fetch_all('SELECT id, name, rnc, address, city FROM clients ORDER BY name ASC') : [];

/* ------------------------------------------------------------------ helpers */

function next_invoice_number(): string
{
    $year = date('Y');
    $last = fetch_one('SELECT invoice_number FROM invoices WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT 1', ["FAC-{$year}-%"]);
    $n = 1;
    if ($last && preg_match('/-(\d+)$/', (string) $last['invoice_number'], $m)) {
        $n = ((int) $m[1]) + 1;
    }
    return 'FAC-' . $year . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/** Normalize posted line items into rows with per-line discount + ITBIS-exempt flag. */
function invoice_parse_items(): array
{
    $desc = (array) ($_POST['item_description'] ?? []);
    $qty = (array) ($_POST['item_quantity'] ?? []);
    $price = (array) ($_POST['item_price'] ?? []);
    $disc = (array) ($_POST['item_discount'] ?? []);
    $exempt = (array) ($_POST['item_exempt'] ?? []);
    $items = [];
    foreach ($desc as $i => $d) {
        $d = trim((string) $d);
        $q = max(0, (float) ($qty[$i] ?? 0));
        $p = max(0, (float) ($price[$i] ?? 0));
        $dd = max(0, (float) ($disc[$i] ?? 0));
        if ($d === '' || $q <= 0) { continue; }
        $net = max(0, $q * $p - $dd);
        $items[] = [
            'description' => $d, 'quantity' => $q, 'unit_price' => $p,
            'discount' => $dd, 'is_exempt' => (!empty($exempt[$i]) && (string) $exempt[$i] === '1') ? 1 : 0,
            'total' => $net,
        ];
    }
    return $items;
}

/** Authoritative server-side totals (taxed/exempt split, ITBIS, ISC, retenciones). */
function invoice_compute_totals(array $items, float $taxRate, float $isc, float $itbisRet, float $isrRet): array
{
    $taxed = 0.0; $exempt = 0.0; $disc = 0.0;
    foreach ($items as $it) {
        $net = (float) $it['total'];
        $disc += (float) ($it['discount'] ?? 0);
        if (!empty($it['is_exempt'])) { $exempt += $net; } else { $taxed += $net; }
    }
    $tax = round($taxed * ($taxRate / 100), 2);
    $isc = max(0, $isc); $itbisRet = max(0, $itbisRet); $isrRet = max(0, $isrRet);
    $total = round($taxed + $exempt + $tax + $isc, 2);
    return [
        'taxed_base' => round($taxed, 2), 'exempt_base' => round($exempt, 2),
        'discount_amount' => round($disc, 2), 'subtotal' => round($taxed + $exempt, 2),
        'tax_amount' => $tax, 'isc_amount' => round($isc, 2),
        'itbis_retained' => round($itbisRet, 2), 'isr_retained' => round($isrRet, 2),
        'total' => $total, 'net_receivable' => round($total - $itbisRet - $isrRet, 2),
    ];
}

/** Derived collection state of an invoice row (for chips/filters). */
function invoice_is_overdue(array $inv): bool
{
    if ((string) ($inv['status'] ?? '') !== 'Emitida') { return false; }
    $due = (string) ($inv['due_date'] ?? '');
    if ($due === '' || $due === '0000-00-00') { return false; }
    $net = (float) ($inv['total'] ?? 0) - (float) ($inv['itbis_retained'] ?? 0) - (float) ($inv['isr_retained'] ?? 0);
    return strtotime($due) < strtotime(date('Y-m-d')) && (float) ($inv['amount_paid'] ?? 0) + 0.009 < $net;
}

/* =========================== POST handlers =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasInvoices) {
    $form = (string) ($_POST['form'] ?? 'save');

    /* ---- NCF sequence pool (create / update / delete) ----------------- */
    if (in_array($form, ['ncf_save', 'ncf_delete'], true)) {
        if (!current_can('facturas.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/facturas.php?action=ncf'); }
        if ($form === 'ncf_delete') {
            $sid = (int) ($_POST['id'] ?? 0);
            if ($sid > 0) {
                db()->prepare('DELETE FROM ncf_sequences WHERE id=?')->execute([$sid]);
                log_activity('ncf_sequence', $sid, 'secuencia_eliminada', null);
                flash('success', 'Secuencia NCF eliminada.');
            }
            redirect('crm/facturas.php?action=ncf');
        }
        $sid = (int) ($_POST['id'] ?? 0);
        $prefix = strtoupper(trim((string) ($_POST['prefix'] ?? 'B'))) === 'E' ? 'E' : 'B';
        $type = substr(preg_replace('/\D/', '', (string) ($_POST['ncf_type'] ?? '')) ?: '', 0, 2);
        // El tipo debe pertenecer a la serie elegida; si no, el rango nunca casaría
        // con una factura (B01 vs E31) y el NCF no se asignaría al emitir.
        $type = ncf_normalize_type($type, $prefix);
        $from = max(1, (int) ($_POST['seq_from'] ?? 1));
        $to = max($from, (int) ($_POST['seq_to'] ?? $from));
        // «Próximo a usar»: si el campo llega vacío al editar un rango existente
        // se conserva el contador actual; nunca se rebobina en silencio.
        $nextRaw = trim((string) ($_POST['seq_next'] ?? ''));
        if ($nextRaw === '' && $sid > 0) {
            $next = (int) (fetch_one('SELECT seq_next FROM ncf_sequences WHERE id=?', [$sid])['seq_next'] ?? $from);
        } else {
            $next = (int) $nextRaw;
        }
        if ($next < $from) { $next = $from; }
        $exp = trim((string) ($_POST['expiration'] ?? '')) ?: null;
        $active = isset($_POST['active']) ? 1 : 0;
        $note = trim((string) ($_POST['note'] ?? ''));
        if (!isset(ncf_types()[$type])) {
            flash('warning', 'Selecciona un tipo de comprobante válido.');
        } elseif ($sid > 0) {
            db()->prepare('UPDATE ncf_sequences SET prefix=?, ncf_type=?, seq_from=?, seq_to=?, seq_next=?, expiration=?, active=?, note=?, updated_at=NOW() WHERE id=?')
                ->execute([$prefix, $type, $from, $to, $next, $exp, $active, $note, $sid]);
            log_activity('ncf_sequence', $sid, 'secuencia_actualizada', $prefix . $type);
            flash('success', 'Secuencia NCF actualizada.');
        } else {
            db()->prepare('INSERT INTO ncf_sequences (prefix, ncf_type, seq_from, seq_to, seq_next, expiration, active, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
                ->execute([$prefix, $type, $from, $to, $next, $exp, $active, $note]);
            log_activity('ncf_sequence', (int) db()->lastInsertId(), 'secuencia_creada', $prefix . $type);
            flash('success', 'Secuencia NCF registrada.');
        }
        redirect('crm/facturas.php?action=ncf');
    }

    /* ---- Delete (borradores y facturas anuladas) ---------------------- */
    if (isset($_POST['delete_id'])) {
        if (!current_can('facturas.delete')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/facturas.php'); }
        $did = (int) $_POST['delete_id'];
        $inv = $did > 0 ? fetch_one('SELECT * FROM invoices WHERE id=?', [$did]) : null;
        if (!$inv) {
            redirect('crm/facturas.php');
        }
        $st = (string) $inv['status'];
        // Emitidas/Pagadas conservan valor fiscal: hay que anularlas primero.
        if ($st === 'Emitida' || $st === 'Pagada') {
            flash('warning', 'Una factura emitida no se elimina directamente: anúlala primero (así queda el rastro) y luego, si lo necesitas, elimínala.');
            redirect('crm/facturas.php?action=view&id=' . $did);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Si es anulada y aún retiene su NCF, devuélvelo al pool cuando sea el
            // último número de su rango (evita huecos y permite reutilizarlo).
            $releasedNcf = '';
            if ($st === 'Anulada' && (string) ($inv['ncf'] ?? '') !== '') {
                $ncfWas = (string) $inv['ncf'];
                if (invoice_release_ncf($did)) { $releasedNcf = $ncfWas; }
            }
            $pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$did]);
            $pdo->commit();
            log_activity('invoice', $did, 'factura_eliminada', trim((string) ($inv['invoice_number'] ?? '') . ($inv['ncf'] ? ' · ' . $inv['ncf'] : '')));
            $msg = $st === 'Anulada' ? 'Factura anulada eliminada.' : 'Borrador de factura eliminado.';
            if ($releasedNcf !== '') { $msg .= ' El NCF ' . $releasedNcf . ' quedó libre para reutilizarse.'; }
            flash('success', $msg);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('facturas delete: ' . $e->getMessage());
            flash('warning', 'No se pudo eliminar la factura. Inténtalo de nuevo.');
        }
        redirect('crm/facturas.php');
    }

    /* ---- Emit: assign NCF from the sequence pool ---------------------- */
    if ($form === 'emit') {
        if (!current_can('facturas.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/facturas.php'); }
        $iid = (int) ($_POST['id'] ?? 0);
        if ($iid <= 0) { redirect('crm/facturas.php'); }
        $res = invoice_emit($iid);
        flash($res['ok'] ? 'success' : 'warning', $res['message']);
        redirect('crm/facturas.php?action=view&id=' . $iid);
    }

    /* ---- Register a payment ------------------------------------------- */
    if ($form === 'pay') {
        if (!current_can('facturas.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/facturas.php'); }
        $iid = (int) ($_POST['id'] ?? 0);
        $inv = $iid > 0 ? fetch_one('SELECT * FROM invoices WHERE id=?', [$iid]) : null;
        if ($inv && in_array((string) $inv['status'], ['Emitida', 'Pagada'], true)) {
            $amount = round((float) ($_POST['amount'] ?? 0), 2);
            $method = trim((string) ($_POST['method'] ?? ''));
            $reference = trim((string) ($_POST['reference'] ?? ''));
            $note = trim((string) ($_POST['note'] ?? ''));
            $paidAt = trim((string) ($_POST['paid_at'] ?? '')) ?: date('Y-m-d');
            if ($amount > 0) {
                $pdo = db();
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO invoice_payments (invoice_id, amount, method, reference, paid_at, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([$iid, $amount, $method, $reference, $paidAt, $note, current_user()['id'] ?? null]);
                $newPaid = round((float) $inv['amount_paid'] + $amount, 2);
                $net = round((float) $inv['total'] - (float) $inv['itbis_retained'] - (float) $inv['isr_retained'], 2);
                if ($newPaid + 0.009 >= $net) {
                    $pdo->prepare('UPDATE invoices SET amount_paid=?, status=?, paid_at=COALESCE(paid_at, NOW()), updated_at=NOW() WHERE id=?')->execute([$newPaid, 'Pagada', $iid]);
                } else {
                    $pdo->prepare('UPDATE invoices SET amount_paid=?, updated_at=NOW() WHERE id=?')->execute([$newPaid, $iid]);
                }
                $pdo->commit();
                log_activity('invoice', $iid, 'pago_registrado', money_cur($amount, (string) $inv['currency']));
                flash('success', 'Pago registrado.');
            } else {
                flash('warning', 'Indica un monto de pago mayor que cero.');
            }
        }
        redirect('crm/facturas.php?action=view&id=' . $iid);
    }

    /* ---- Void (anular) ------------------------------------------------ */
    if ($form === 'void') {
        if (!current_can('facturas.delete') && !current_can('facturas.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/facturas.php'); }
        $iid = (int) ($_POST['id'] ?? 0);
        $reason = trim((string) ($_POST['void_reason'] ?? ''));
        $release = (string) ($_POST['release_ncf'] ?? '') === '1';
        $inv = $iid > 0 ? fetch_one('SELECT * FROM invoices WHERE id=?', [$iid]) : null;
        if ($inv && (string) $inv['status'] !== 'Anulada') {
            if ($reason === '') {
                flash('warning', 'Indica el motivo de la anulación.');
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE invoices SET status=?, voided_at=NOW(), void_reason=?, updated_at=NOW() WHERE id=?')->execute(['Anulada', $reason, $iid]);
                    // Liberar el NCF: solo si se pidió y este comprobante tomó el
                    // ÚLTIMO número de su rango (seq_next-1). Así se devuelve al pool
                    // sin dejar huecos y la próxima factura reusa el mismo NCF.
                    $released = false;
                    if ($release) {
                        $released = invoice_release_ncf($iid);
                    }
                    $pdo->commit();
                    log_activity('invoice', $iid, 'factura_anulada', $reason . ($released ? ' · NCF liberado' : ''));
                    if ($released) {
                        flash('success', 'Factura anulada y NCF ' . (string) $inv['ncf'] . ' liberado: la próxima factura de esa serie volverá a tomar ese número.');
                    } else {
                        flash('success', 'Factura anulada.' . ($release ? ' El NCF no se pudo liberar (no era el último número emitido de su rango); se conserva como anulado.' : ' Considera emitir una Nota de Crédito que la modifique.'));
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    error_log('facturas void: ' . $e->getMessage());
                    flash('warning', 'No se pudo anular la factura. Inténtalo de nuevo.');
                }
            }
        }
        redirect('crm/facturas.php?action=view&id=' . $iid);
    }

    /* ---- Duplicate as a new draft ------------------------------------- */
    if ($form === 'duplicate') {
        if (!current_can('facturas.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/facturas.php'); }
        $sid = (int) ($_POST['id'] ?? 0);
        $src = $sid > 0 ? fetch_one('SELECT * FROM invoices WHERE id=?', [$sid]) : null;
        if ($src) {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO invoices (client_id, quote_id, invoice_number, ncf_type, ncf_prefix, title, status, payment_condition, payment_method, taxed_base, exempt_base, discount_amount, subtotal, tax_rate, tax_amount, isc_amount, itbis_retained, isr_retained, total, currency, exchange_rate, notes, terms, client_name, client_rnc, client_address, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
                ->execute([$src['client_id'], $src['quote_id'], next_invoice_number(), $src['ncf_type'], $src['ncf_prefix'], $src['title'], 'Borrador', $src['payment_condition'], $src['payment_method'], $src['taxed_base'], $src['exempt_base'], $src['discount_amount'], $src['subtotal'], $src['tax_rate'], $src['tax_amount'], $src['isc_amount'], $src['itbis_retained'], $src['isr_retained'], $src['total'], $src['currency'], $src['exchange_rate'], $src['notes'], $src['terms'], $src['client_name'], $src['client_rnc'], $src['client_address'], current_user()['id'] ?? null]);
            $newId = (int) $pdo->lastInsertId();
            $rows = fetch_all('SELECT description, quantity, unit_price, discount, is_exempt, total FROM invoice_items WHERE invoice_id=? ORDER BY id ASC', [$sid]);
            $stmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, discount, is_exempt, total) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($rows as $r) {
                $stmt->execute([$newId, $r['description'], $r['quantity'], $r['unit_price'], $r['discount'], $r['is_exempt'], $r['total']]);
            }
            $srcProforma = invoice_is_proforma($src) ? 1 : 0;
            $srcEcf = (!$srcProforma && ($src['ncf_prefix'] ?? 'B') === 'E') ? 1 : 0;
            $pdo->prepare('UPDATE invoices SET is_proforma=?, is_ecf=?, ecf_status=? WHERE id=?')->execute([$srcProforma, $srcEcf, $srcEcf ? 'Manual' : null, $newId]);
            $pdo->commit();
            log_activity('invoice', $newId, 'factura_duplicada', null);
            flash('success', $srcProforma ? 'Proforma duplicada.' : 'Factura duplicada como borrador (sin NCF).');
            redirect('crm/facturas.php?action=view&id=' . $newId);
        }
        redirect('crm/facturas.php');
    }

    /* ---- Create / Update (draft) -------------------------------------- */
    if ($form === 'save') {
        if (!current_can('facturas.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/facturas.php'); }
        $editId = (int) ($_POST['id'] ?? 0);
        $existing = $editId > 0 ? fetch_one('SELECT * FROM invoices WHERE id=?', [$editId]) : null;
        if ($existing && !invoice_is_editable($existing['status'])) {
            flash('warning', 'Una factura emitida no se edita. Anúlala y emite una nueva o una Nota de Crédito.');
            redirect('crm/facturas.php?action=view&id=' . $editId);
        }

        $clientId = (int) ($_POST['client_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        // Serie «P» = proforma: no es una serie de la DGII, se guarda como bandera y
        // el comprobante queda neutro (B/02) para no ensuciar el resto del módulo.
        $seriesPick = strtoupper(trim((string) ($_POST['ncf_prefix'] ?? 'B')));
        $isProforma = $seriesPick === 'P' ? 1 : 0;
        $prefix = $seriesPick === 'E' ? 'E' : 'B';
        $type = substr(preg_replace('/\D/', '', (string) ($_POST['ncf_type'] ?? '')) ?: '', 0, 2);
        $type = ncf_normalize_type($type, $prefix);
        if ($isProforma) { $type = '02'; }
        $condition = in_array((string) ($_POST['payment_condition'] ?? ''), $payConditions, true) ? (string) $_POST['payment_condition'] : 'Contado';
        $method = trim((string) ($_POST['payment_method'] ?? ''));
        $issueDate = trim((string) ($_POST['issue_date'] ?? '')) ?: null;
        $dueDate = trim((string) ($_POST['due_date'] ?? '')) ?: null;
        $modifiesNcf = (!$isProforma && in_array($type, ['03', '04', '33', '34'], true)) ? trim((string) ($_POST['modifies_ncf'] ?? '')) : '';
        $taxRate = (float) ($_POST['tax_rate'] ?? $defaultTax);
        $isc = (float) ($_POST['isc_amount'] ?? 0);
        $itbisRet = (float) ($_POST['itbis_retained'] ?? 0);
        $isrRet = (float) ($_POST['isr_retained'] ?? 0);
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'DOP'))) === 'USD' ? 'USD' : 'DOP';
        $rate = (float) ($_POST['exchange_rate'] ?? 1);
        $rate = ($currency === 'DOP' || $rate <= 0) ? 1.0 : $rate;
        $terms = trim((string) ($_POST['terms'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $items = invoice_parse_items();

        $client = $clientId > 0 ? fetch_one('SELECT name, rnc, address, city FROM clients WHERE id=?', [$clientId]) : null;

        if (!$client || count($items) === 0) {
            flash('warning', 'Selecciona un cliente y al menos una partida con cantidad.');
            redirect('crm/facturas.php' . ($editId > 0 ? '?edit=' . $editId : '?new=1'));
        }

        $t = invoice_compute_totals($items, $taxRate, $isc, $itbisRet, $isrRet);
        $clientName = (string) $client['name'];
        $clientRnc = (string) ($client['rnc'] ?? '');
        $clientAddress = trim(implode(', ', array_filter([(string) ($client['address'] ?? ''), (string) ($client['city'] ?? '')])));

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($editId > 0 && $existing) {
                $pdo->prepare('UPDATE invoices SET client_id=?, title=?, ncf_type=?, ncf_prefix=?, payment_condition=?, payment_method=?, issue_date=?, due_date=?, modifies_ncf=?, taxed_base=?, exempt_base=?, discount_amount=?, subtotal=?, tax_rate=?, tax_amount=?, isc_amount=?, itbis_retained=?, isr_retained=?, total=?, currency=?, exchange_rate=?, notes=?, terms=?, client_name=?, client_rnc=?, client_address=?, updated_at=NOW() WHERE id=?')
                    ->execute([$clientId, $title, $type, $prefix, $condition, $method, $issueDate, $dueDate, $modifiesNcf, $t['taxed_base'], $t['exempt_base'], $t['discount_amount'], $t['subtotal'], $taxRate, $t['tax_amount'], $t['isc_amount'], $t['itbis_retained'], $t['isr_retained'], $t['total'], $currency, $rate, $notes, $terms, $clientName, $clientRnc, $clientAddress, $editId]);
                $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$editId]);
                $invoiceId = $editId;
                $flashMsg = $isProforma ? 'Factura proforma actualizada.' : 'Factura actualizada.';
                $logAction = $isProforma ? 'proforma_actualizada' : 'factura_actualizada';
            } else {
                for ($attempt = 0; ; $attempt++) {
                    try {
                        $pdo->prepare('INSERT INTO invoices (client_id, invoice_number, ncf_type, ncf_prefix, title, status, payment_condition, payment_method, issue_date, due_date, modifies_ncf, taxed_base, exempt_base, discount_amount, subtotal, tax_rate, tax_amount, isc_amount, itbis_retained, isr_retained, total, currency, exchange_rate, notes, terms, client_name, client_rnc, client_address, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
                            ->execute([$clientId, next_invoice_number(), $type, $prefix, $title, 'Borrador', $condition, $method, $issueDate, $dueDate, $modifiesNcf, $t['taxed_base'], $t['exempt_base'], $t['discount_amount'], $t['subtotal'], $taxRate, $t['tax_amount'], $t['isc_amount'], $t['itbis_retained'], $t['isr_retained'], $t['total'], $currency, $rate, $notes, $terms, $clientName, $clientRnc, $clientAddress, current_user()['id'] ?? null]);
                        break;
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000' && $attempt < 4) { continue; }
                        throw $e;
                    }
                }
                $invoiceId = (int) $pdo->lastInsertId();
                $flashMsg = $isProforma
                    ? 'Factura proforma creada. No consume NCF ni tiene valor fiscal; si el cliente la aprueba, edítala, cambia la serie a B o E y emítela.'
                    : 'Factura creada como borrador. Revísala y púlsala «Emitir» para asignar el NCF.';
                $logAction = $isProforma ? 'proforma_creada' : 'factura_creada';
            }

            $stmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, discount, is_exempt, total) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($items as $it) {
                $stmt->execute([$invoiceId, $it['description'], $it['quantity'], $it['unit_price'], $it['discount'], $it['is_exempt'], $it['total']]);
            }
            // Mark the proforma / e-CF flags (e-CF sigue siendo captura manual hasta
            // que exista la transmisión a la DGII). Una proforma nunca es e-CF.
            $isEcf = (!$isProforma && $prefix === 'E') ? 1 : 0;
            $pdo->prepare('UPDATE invoices SET is_proforma=?, is_ecf=?, ecf_status=? WHERE id=?')->execute([$isProforma, $isEcf, $isEcf ? 'Manual' : null, $invoiceId]);
            $pdo->commit();
            log_activity('invoice', $invoiceId, $logAction, $title);
            // "Guardar y emitir": assign the NCF right away instead of leaving a draft.
            // Una proforma no se emite: se queda como documento sin valor fiscal.
            if (!$isProforma && (string) ($_POST['emit_now'] ?? '') === '1') {
                $res = invoice_emit($invoiceId);
                flash($res['ok'] ? 'success' : 'warning', $res['ok'] ? $res['message'] : 'Factura guardada como borrador, pero no se pudo emitir: ' . $res['message']);
            } else {
                flash('success', $flashMsg);
            }
            redirect('crm/facturas.php?action=view&id=' . $invoiceId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('facturas save: ' . $e->getMessage());
            flash('warning', 'No se pudo guardar la factura.');
            redirect('crm/facturas.php');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasInvoices) {
    flash('warning', 'Ejecuta install.php para guardar facturas en MySQL.');
}

$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);

/* ====================== NCF SEQUENCES PAGE =========================== */
if ($action === 'ncf') {
    require_can('facturas.edit');
    $sequences = $hasInvoices ? fetch_all('SELECT * FROM ncf_sequences ORDER BY prefix ASC, ncf_type ASC, id ASC') : [];
    $crmTitle = 'Secuencias NCF';
    require_once __DIR__ . '/../includes/crm_header.php';
    ?>
    <section class="crm-cockpit">
        <div class="crm-cockpit__top">
            <div class="crm-cockpit__hero crm-cockpit__hero--sales">
                <span class="crm-kicker"><i data-lucide="hash"></i>DGII · Comprobantes fiscales</span>
                <h2>Secuencias de NCF autorizadas por la DGII.</h2>
                <p>Registra los rangos que la DGII te autorizó por tipo de comprobante. Al emitir una factura, el sistema toma el siguiente número del rango activo y vigente, e impide pasarte del límite o de la fecha de vencimiento.</p>
                <div class="crm-cockpit__actions">
                    <a href="<?= url('crm/facturas.php') ?>" class="crm-secondary-btn"><i data-lucide="arrow-left" class="h-4 w-4"></i>Volver a facturación</a>
                </div>
            </div>
            <div class="crm-cockpit__metrics" aria-label="Resumen NCF">
                <?php
                $seqActive = count(array_filter($sequences, fn ($s) => (int) $s['active'] === 1));
                $seqRemaining = array_sum(array_map(fn ($s) => max(0, (int) $s['seq_to'] - (int) $s['seq_next'] + 1), array_filter($sequences, fn ($s) => (int) $s['active'] === 1)));
                $seqExpiring = count(array_filter($sequences, fn ($s) => !empty($s['expiration']) && strtotime((string) $s['expiration']) <= strtotime('+30 days') && strtotime((string) $s['expiration']) >= strtotime('today')));
                ?>
                <article><span>Rangos</span><strong><?= e((string) count($sequences)) ?></strong><small>registrados</small></article>
                <article><span>Activos</span><strong><?= e((string) $seqActive) ?></strong><small>en uso</small></article>
                <article><span>Disponibles</span><strong><?= e(number_format($seqRemaining)) ?></strong><small>NCF por emitir</small></article>
                <article><span>Por vencer</span><strong><?= e((string) $seqExpiring) ?></strong><small>en 30 días</small></article>
            </div>
        </div>

        <article class="crm-data-surface">
            <div class="crm-data-surface__head"><div><h3>Rangos autorizados</h3><p>Un rango por tipo de comprobante (puedes tener varios por tipo si te re-autorizan).</p></div></div>
            <div class="crm-table-wrap">
                <table class="crm-table crm-data-table">
                    <thead><tr><th>Comprobante</th><th>Rango</th><th>Próximo</th><th>Disponibles</th><th>Vence</th><th>Estado</th><th class="text-right">Acción</th></tr></thead>
                    <tbody>
                        <?php foreach ($sequences as $s): $rem = max(0, (int) $s['seq_to'] - (int) $s['seq_next'] + 1); $expSoon = !empty($s['expiration']) && strtotime((string) $s['expiration']) < strtotime('+30 days'); ?>
                            <tr>
                                <td><strong><?= e($s['prefix'] . $s['ncf_type']) ?></strong><br><span style="color:var(--muted);font-size:.8rem"><?= e(ncf_type_label((string) $s['ncf_type'])) ?></span></td>
                                <td><?= e(ncf_format((string) $s['prefix'], (string) $s['ncf_type'], (int) $s['seq_from'])) ?> → <?= e(ncf_format((string) $s['prefix'], (string) $s['ncf_type'], (int) $s['seq_to'])) ?></td>
                                <td><strong><?= e(ncf_format((string) $s['prefix'], (string) $s['ncf_type'], (int) $s['seq_next'])) ?></strong></td>
                                <td><?= $rem === 0 ? '<span class="status-chip bg-red-50 text-red-700 ring-1 ring-red-200">Agotado</span>' : e(number_format($rem)) ?></td>
                                <td><?= !empty($s['expiration']) ? '<span' . ($expSoon ? ' style="color:var(--red);font-weight:700"' : '') . '>' . e(date_es((string) $s['expiration'])) . '</span>' : '<span style="color:var(--muted)">Sin fecha</span>' ?></td>
                                <td><span class="status-chip <?= (int) $s['active'] === 1 ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200' ?>"><?= (int) $s['active'] === 1 ? 'Activa' : 'Inactiva' ?></span></td>
                                <td class="text-right">
                                    <div class="crm-row-actions">
                                        <button type="button" class="crm-icon-action" title="Editar" onclick='schEditSeq(<?= json_encode($s, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i data-lucide="pencil"></i></button>
                                        <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar este rango NCF? No afecta a las facturas ya emitidas.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="form" value="ncf_delete">
                                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                            <button type="submit" class="crm-icon-action crm-icon-action--danger" title="Eliminar"><i data-lucide="trash-2"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!$sequences): ?>
                    <div class="crm-empty"><i data-lucide="hash" class="h-6 w-6"></i><strong>Sin secuencias NCF</strong><p>Agrega el primer rango autorizado por la DGII con el formulario inferior.</p></div>
                <?php endif; ?>
            </div>
        </article>

        <article class="crm-card cfg-card" style="margin-top:1rem">
            <div class="crm-card__head"><div><h2 id="seq-form-title"><i data-lucide="plus-circle" class="cfg-ic"></i> Nuevo rango NCF</h2><p>Toma estos datos del acuse de autorización de la DGII.</p></div></div>
            <form method="post" class="crm-card__body" style="display:grid;gap:1rem" id="seq-form">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="ncf_save">
                <input type="hidden" name="id" id="seq-id" value="0">
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Serie</span><select name="prefix" id="seq-prefix" class="crm-select" onchange="schFilterSeqTypes()"><?php foreach ($ncfPrefixes as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?></select></label>
                    <label class="crm-field"><span>Tipo de comprobante</span><select name="ncf_type" id="seq-type" class="crm-select"><?php foreach ($ncfTypes as $code => $def): ?><option value="<?= e($code) ?>" data-series="<?= e($def[3]) ?>"><?= e($code . ' — ' . $def[0]) ?></option><?php endforeach; ?></select></label>
                </div>
                <div class="crm-form-grid" style="grid-template-columns:repeat(3,minmax(0,1fr))">
                    <label class="crm-field"><span>Secuencia inicial</span><input type="number" min="1" step="1" name="seq_from" id="seq-from" value="1" class="crm-input"></label>
                    <label class="crm-field"><span>Secuencia final</span><input type="number" min="1" step="1" name="seq_to" id="seq-to" value="50" class="crm-input"></label>
                    <label class="crm-field"><span>Próximo a usar</span><input type="number" min="1" step="1" name="seq_next" id="seq-next" value="1" class="crm-input"></label>
                </div>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Fecha de vencimiento</span><input type="date" name="expiration" id="seq-exp" class="crm-input"></label>
                    <label class="crm-field"><span>Nota (opcional)</span><input name="note" id="seq-note" class="crm-input" placeholder="Autorización DGII #..."></label>
                </div>
                <label class="crm-toggle" style="display:flex;align-items:center;gap:.5rem"><input type="checkbox" name="active" id="seq-active" checked> <span>Rango activo (disponible para emitir)</span></label>
                <div class="crm-toolbar" style="justify-content:flex-end;gap:.5rem">
                    <button type="button" class="crm-secondary-btn" onclick="schResetSeq()">Limpiar</button>
                    <button type="submit" class="crm-primary-btn"><i data-lucide="save" class="h-4 w-4"></i>Guardar rango</button>
                </div>
            </form>
        </article>
    </section>
    <script>
    function schFilterSeqTypes(){var p=document.getElementById('seq-prefix').value,sel=document.getElementById('seq-type'),first=null,ok=false;Array.from(sel.options).forEach(function(o){var m=o.getAttribute('data-series')===p;o.hidden=!m;o.disabled=!m;if(m){if(!first)first=o.value;if(o.value===sel.value)ok=true;}});if(!ok&&first)sel.value=first;}
    function schResetSeq(){document.getElementById('seq-id').value='0';document.getElementById('seq-form').reset();schFilterSeqTypes();document.getElementById('seq-form-title').innerHTML='<i data-lucide="plus-circle" class="cfg-ic"></i> Nuevo rango NCF';if(window.lucide)window.lucide.createIcons();}
    function schEditSeq(s){document.getElementById('seq-id').value=s.id;document.getElementById('seq-prefix').value=s.prefix;schFilterSeqTypes();document.getElementById('seq-type').value=s.ncf_type;document.getElementById('seq-from').value=s.seq_from;document.getElementById('seq-to').value=s.seq_to;document.getElementById('seq-next').value=s.seq_next;document.getElementById('seq-exp').value=s.expiration||'';document.getElementById('seq-note').value=s.note||'';document.getElementById('seq-active').checked=Number(s.active)===1;document.getElementById('seq-form-title').innerHTML='<i data-lucide="pencil" class="cfg-ic"></i> Editar rango '+s.prefix+s.ncf_type;window.scrollTo({top:document.getElementById('seq-form').offsetTop-90,behavior:'smooth'});if(window.lucide)window.lucide.createIcons();}
    schFilterSeqTypes();
    </script>
    <?php
    require_once __DIR__ . '/../includes/crm_footer.php';
    return;
}

/* ============================ VIEW =================================== */
if ($action === 'view') {
    $inv = $hasInvoices ? fetch_one('SELECT invoices.*, clients.name AS c_name, clients.email AS c_email, clients.phone AS c_phone FROM invoices LEFT JOIN clients ON clients.id = invoices.client_id WHERE invoices.id=?', [$id]) : null;
    $items = $hasInvoices && $inv ? fetch_all('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id ASC', [$id]) : [];
    $payments = $hasInvoices && $inv ? fetch_all('SELECT * FROM invoice_payments WHERE invoice_id=? ORDER BY paid_at ASC, id ASC', [$id]) : [];
    if ($hasInvoices && !$inv) {
        flash('warning', 'La factura solicitada no existe.');
        redirect('crm/facturas.php');
    }
    if (!$inv) {
        $inv = ['invoice_number' => 'FAC-' . date('Y') . '-0001', 'ncf' => 'B0100000001', 'ncf_type' => '01', 'ncf_prefix' => 'B', 'c_name' => 'Hospital Metropolitano de Santiago', 'client_name' => 'Hospital Metropolitano de Santiago', 'client_rnc' => '101-00000-1', 'client_address' => 'Santiago de los Caballeros', 'title' => 'Equipamiento biomédico', 'status' => 'Emitida', 'payment_condition' => 'Crédito', 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+30 days')), 'taxed_base' => 15000, 'exempt_base' => 0, 'discount_amount' => 0, 'subtotal' => 15000, 'tax_rate' => 18, 'tax_amount' => 2700, 'isc_amount' => 0, 'itbis_retained' => 0, 'isr_retained' => 0, 'total' => 17700, 'amount_paid' => 0, 'currency' => 'DOP', 'exchange_rate' => 1, 'notes' => 'Factura demo. Ejecuta install.php para datos reales.', 'terms' => invoice_default_terms()];
        $items = [['description' => 'Camas UCI eléctricas', 'quantity' => 3, 'unit_price' => 4200, 'discount' => 0, 'is_exempt' => 0, 'total' => 12600], ['description' => 'Instalación y certificación', 'quantity' => 1, 'unit_price' => 2400, 'discount' => 0, 'is_exempt' => 0, 'total' => 2400]];
    }

    $cur = strtoupper((string) ($inv['currency'] ?? 'DOP')) === 'USD' ? 'USD' : 'DOP';
    $rate = (float) ($inv['exchange_rate'] ?? 1); if ($rate <= 0) { $rate = 1; }
    $terms = trim((string) ($inv['terms'] ?? '')) ?: $defaultTerms;
    $status = (string) ($inv['status'] ?? 'Borrador');
    $editable = invoice_is_editable($status);
    $isProforma = invoice_is_proforma($inv);
    $net = round((float) $inv['total'] - (float) $inv['itbis_retained'] - (float) $inv['isr_retained'], 2);
    $balance = round($net - (float) ($inv['amount_paid'] ?? 0), 2);
    $hasActiveSeq = ($hasInvoices && !$isProforma) ? invoice_has_sequence((string) ($inv['ncf_prefix'] ?? 'B'), (string) ($inv['ncf_type'] ?? '02')) : true;
    $overdue = invoice_is_overdue($inv);
    // Reconciliación partidas ↔ encabezado: detecta facturas sin sus líneas.
    $itemsSum = 0.0;
    foreach ($items as $it) { $itemsSum += (float) ($it['total'] ?? 0); }
    $itemsMismatch = $hasInvoices && (int) ($inv['id'] ?? 0) > 0 && abs($itemsSum - (float) ($inv['subtotal'] ?? 0)) > 0.01;
    // ¿El NCF de esta factura es el último consumido de su rango? → se puede liberar al anular.
    $ncfIsLast = false;
    if ($hasInvoices && $status === 'Emitida' && (string) ($inv['ncf'] ?? '') !== '' && ctype_digit(substr((string) $inv['ncf'], 3))) {
        $seqNum = (int) substr((string) $inv['ncf'], 3);
        $ncfIsLast = (int) (fetch_one('SELECT COUNT(*) c FROM ncf_sequences WHERE prefix=? AND ncf_type=? AND seq_next=?', [(string) $inv['ncf_prefix'], (string) $inv['ncf_type'], $seqNum + 1])['c'] ?? 0) > 0;
    }

    $crmTitle = 'Factura ' . ($inv['invoice_number'] ?? '');
    require_once __DIR__ . '/../includes/crm_header.php';
    ?>
    <section class="mx-auto max-w-5xl">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between print:hidden">
            <a href="<?= url('crm/facturas.php') ?>" class="crm-secondary-btn"><i data-lucide="arrow-left" class="h-4 w-4"></i>Volver</a>
            <div class="flex flex-wrap gap-2">
                <?php if ($hasInvoices && (int) ($inv['id'] ?? 0) > 0): ?>
                    <?php if ($editable && current_can('facturas.edit')): ?>
                        <a href="<?= url('crm/facturas.php?edit=' . (int) $inv['id']) ?>" class="crm-secondary-btn"><i data-lucide="pencil" class="h-4 w-4"></i>Editar</a>
                    <?php endif; ?>
                    <?php if (current_can('facturas.edit')): ?>
                        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="form" value="duplicate"><input type="hidden" name="id" value="<?= (int) $inv['id'] ?>"><button type="submit" class="crm-secondary-btn"><i data-lucide="copy" class="h-4 w-4"></i>Duplicar</button></form>
                    <?php endif; ?>
                <?php endif; ?>
                <button type="button" class="crm-primary-btn" onclick="crmPdfPreviewOpen('<?= url('crm/factura_pdf.php?id=' . (int) ($inv['id'] ?? 0)) ?>','<?= url('crm/factura_pdf.php?id=' . (int) ($inv['id'] ?? 0) . '&download=1') ?>','<?= e(addslashes((string) ($inv['ncf'] ?? $inv['invoice_number']))) ?>')"><i data-lucide="file-text" class="h-4 w-4"></i>Vista previa PDF</button>
            </div>
        </div>

        <?php if ($isProforma): ?>
            <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800"><i data-lucide="file-clock" style="width:15px;height:15px;vertical-align:-2px"></i> <b>Factura proforma</b> — documento sin validez fiscal. No consume NCF ni se reporta a la DGII; sirve como oferta formal con formato de factura.<?php if ($editable && current_can('facturas.edit')): ?> Si el cliente la aprueba, <a class="underline" href="<?= url('crm/facturas.php?edit=' . (int) $inv['id']) ?>">edítala</a> y cambia la «Serie NCF» a B o E para poder emitirla como comprobante fiscal.<?php endif; ?></div>
        <?php endif; ?>

        <?php if ($editable && !$isProforma && !$hasActiveSeq && current_can('facturas.edit')): ?>
            <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">No hay secuencia NCF disponible para <b><?= e(($inv['ncf_prefix'] ?? 'B') . ($inv['ncf_type'] ?? '02')) ?></b> (<?= e(ncf_type_label((string) ($inv['ncf_type'] ?? '02'))) ?>). El rango debe existir con esa misma serie y tipo, estar marcado como activo, no estar agotado y no estar vencido. <a class="underline" href="<?= url('crm/facturas.php?action=ncf') ?>">Revisar secuencias NCF</a>.</div>
        <?php endif; ?>

        <!-- Fiscal action bar -->
        <?php if ($hasInvoices && (int) ($inv['id'] ?? 0) > 0 && current_can('facturas.edit')): ?>
        <div class="inv-actionbar print:hidden">
            <div class="inv-actionbar__state">
                <span class="status-chip <?= e(status_class($status)) ?>"><?= e($status) ?></span>
                <?php if ($isProforma): ?><span class="status-chip bg-amber-50 text-amber-800 ring-1 ring-amber-200" title="Documento sin validez fiscal">Proforma</span><?php endif; ?>
                <?php if ($overdue): ?><span class="status-chip bg-red-50 text-red-700 ring-1 ring-red-200">Vencida</span><?php endif; ?>
                <?php if ($canCartera && $status !== 'Borrador' && $status !== 'Anulada'): $age = invoice_aging($inv); ?>
                    <span class="inv-age-chip inv-age-chip--<?= e($age['tone']) ?>" title="Periodo de vencimiento">Vencimiento: <?= e($age['label']) ?></span>
                <?php endif; ?>
                <?php if ($status === 'Emitida' || $status === 'Pagada'): ?>
                    <span class="inv-actionbar__bal">Balance: <strong><?= money_cur($balance > 0 ? $balance : 0, $cur) ?></strong> de <?= money_cur($net, $cur) ?></span>
                <?php endif; ?>
            </div>
            <div class="inv-actionbar__btns">
                <?php if ($editable && $isProforma): ?>
                    <a href="<?= url('crm/facturas.php?edit=' . (int) $inv['id']) ?>" class="crm-primary-btn"><i data-lucide="badge-check" class="h-4 w-4"></i>Convertir en factura fiscal</a>
                <?php elseif ($editable): ?>
                    <form method="post" onsubmit="return confirm('Al emitir se asignará el NCF y la factura quedará bloqueada. ¿Continuar?');">
                        <?= csrf_field() ?><input type="hidden" name="form" value="emit"><input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                        <button type="submit" class="crm-primary-btn" <?= $hasActiveSeq ? '' : 'disabled title="Configura una secuencia NCF"' ?>><i data-lucide="badge-check" class="h-4 w-4"></i>Emitir y asignar NCF</button>
                    </form>
                <?php endif; ?>
                <?php if ($status === 'Emitida'): ?>
                    <button type="button" class="crm-secondary-btn" onclick="document.getElementById('inv-pay').showModal()"><i data-lucide="hand-coins" class="h-4 w-4"></i>Registrar pago</button>
                <?php endif; ?>
                <?php if ($status === 'Emitida' && $balance > 0.009): ?>
                    <button type="button" class="crm-secondary-btn" onclick="crmPdfPreviewOpen('<?= url('crm/recordatorio_pdf.php?id=' . (int) $inv['id']) ?>','<?= url('crm/recordatorio_pdf.php?id=' . (int) $inv['id'] . '&download=1') ?>','<?= e(addslashes((string) ($inv['invoice_number'] ?? ''))) ?>','Recordatorio de pago')"><i data-lucide="bell-ring" class="h-4 w-4"></i>Recordatorio de pago</button>
                    <?php if ((int) ($inv['client_id'] ?? 0) > 0): ?>
                        <button type="button" class="crm-secondary-btn" onclick="crmPdfPreviewOpen('<?= url('crm/recordatorio_pdf.php?client=' . (int) $inv['client_id']) ?>','<?= url('crm/recordatorio_pdf.php?client=' . (int) $inv['client_id'] . '&download=1') ?>','<?= e(addslashes((string) ($inv['client_name'] ?? $inv['c_name'] ?? 'Cliente'))) ?>','Estado de cuenta')"><i data-lucide="file-clock" class="h-4 w-4"></i>Estado de cuenta</button>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($status !== 'Anulada' && !$editable): ?>
                    <button type="button" class="crm-secondary-btn crm-secondary-btn--danger" onclick="document.getElementById('inv-void').showModal()"><i data-lucide="ban" class="h-4 w-4"></i>Anular</button>
                <?php endif; ?>
                <?php if (current_can('facturas.delete') && in_array($status, ['Borrador', 'Anulada'], true)): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('<?= e($status === 'Anulada' ? 'Eliminar definitivamente la factura ANULADA ' . addslashes((string) ($inv['invoice_number'] ?? '')) . '. Se borra por completo del sistema. ¿Continuar?' : '¿Eliminar este borrador?') ?>');">
                        <?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int) ($inv['id'] ?? 0) ?>">
                        <button type="submit" class="crm-secondary-btn crm-secondary-btn--danger"><i data-lucide="trash-2" class="h-4 w-4"></i>Eliminar</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <article class="quote-doc inv-doc">
            <header class="quote-doc__head">
                <div>
                    <span class="quote-doc__brand"><img src="<?= asset(APP_LOGO) ?>" alt="SCH MEDICOS"><strong>SCH MEDICOS</strong></span>
                    <p><?= e(APP_LEGAL) ?><?php if (APP_RNC !== ''): ?> · RNC: <?= e(APP_RNC) ?><?php endif; ?><br><?= e(APP_ADDRESS) ?> · Tel. <?= e(APP_PHONE) ?></p>
                </div>
                <div>
                    <span><?= e(invoice_doc_heading($inv)) ?></span>
                    <h1><?= e($inv['invoice_number'] ?? '') ?></h1>
                    <span class="status-chip <?= e(status_class($status)) ?>"><?= e($status) ?></span>
                    <?php if ($isProforma): ?><span class="status-chip bg-amber-50 text-amber-800 ring-1 ring-amber-200">Sin valor fiscal</span><?php endif; ?>
                    <?php if (!empty($inv['is_ecf'])): ?><span class="status-chip bg-blue-50 text-blue-700 ring-1 ring-blue-200" title="Comprobante fiscal electrónico">e-CF · <?= e($inv['ecf_status'] ?: 'Manual') ?></span><?php endif; ?>
                    <div class="inv-ncf-box">
                        <span>NCF</span>
                        <strong><?= $isProforma ? 'No aplica' : (e((string) ($inv['ncf'] ?? '')) ?: 'Pendiente de emisión') ?></strong>
                        <small><?= $isProforma ? 'Proforma — documento sin validez fiscal' : e($inv['ncf_type']) . ' · ' . e(ncf_type_label((string) $inv['ncf_type'])) ?></small>
                    </div>
                    <p class="quote-doc__currency">Moneda: <strong><?= e($cur) ?></strong><?php if ($cur === 'USD'): ?> · US$ 1 = RD$ <?= e(number_format($rate, 2)) ?><?php endif; ?></p>
                </div>
            </header>

            <div class="quote-doc__meta">
                <section>
                    <h2>Cliente</h2>
                    <strong><?= e($inv['client_name'] ?? $inv['c_name'] ?? 'Cliente') ?></strong>
                    <p>
                        <?php if (!empty($inv['client_rnc'])): ?>RNC/Cédula: <?= e($inv['client_rnc']) ?><br><?php endif; ?>
                        <?php if (!empty($inv['client_address'])): ?><?= e($inv['client_address']) ?><br><?php endif; ?>
                        <?php if (!empty($inv['c_email'])): ?><?= e($inv['c_email']) ?><?php endif; ?>
                    </p>
                </section>
                <section>
                    <h2>Comprobante</h2>
                    <strong><?= e($inv['title'] ?? 'Venta de bienes y servicios') ?></strong>
                    <p>
                        Emitida: <?= e(date_es($inv['issue_date'] ?? null)) ?><br>
                        Vence: <?= e(date_es($inv['due_date'] ?? null)) ?> · <?= e($inv['payment_condition'] ?? 'Contado') ?><br>
                        <?php if ($canCartera && $status !== 'Borrador' && $status !== 'Anulada'): ?>Periodo de vencimiento: <strong><?= e(invoice_aging_text($inv)) ?></strong><br><?php endif; ?>
                        <?php if (!empty($inv['ncf_expiration'])): ?>Vence NCF: <?= e(date_es($inv['ncf_expiration'])) ?><br><?php endif; ?>
                        <?php if (!empty($inv['modifies_ncf'])): ?>Modifica NCF: <?= e($inv['modifies_ncf']) ?><?php endif; ?>
                    </p>
                </section>
            </div>

            <div class="crm-table-wrap">
                <table class="crm-table quote-doc__table">
                    <thead><tr><th>Descripción</th><th class="text-right">Cant.</th><th class="text-right">Precio</th><th class="text-right">Desc.</th><th class="text-right">Importe</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td><strong><?= e($it['description'] ?? '') ?></strong><?php if (!empty($it['is_exempt'])): ?> <span class="inv-tag-exempt">Exento ITBIS</span><?php endif; ?></td>
                                <td class="text-right"><?= e(rtrim(rtrim(number_format((float) ($it['quantity'] ?? 0), 2), '0'), '.')) ?></td>
                                <td class="text-right"><?= money_cur($it['unit_price'] ?? 0, $cur) ?></td>
                                <td class="text-right"><?= (float) ($it['discount'] ?? 0) > 0 ? money_cur($it['discount'], $cur) : '—' ?></td>
                                <td class="text-right"><strong><?= money_cur($it['total'] ?? 0, $cur) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$items): ?><tr><td colspan="5" class="text-center" style="color:var(--muted);padding:1.2rem">Esta factura no tiene partidas registradas.</td></tr><?php endif; ?>
                        <?php if ($itemsMismatch): ?><tr><td colspan="5" style="padding:1rem;background:#fffaf0;color:#92660a;font-weight:600"><i data-lucide="alert-triangle" style="width:15px;height:15px;vertical-align:-2px"></i> Las partidas mostradas no cuadran con el total del encabezado (<?= money_cur($inv['subtotal'] ?? 0, $cur) ?>). Esta factura se guardó sin su detalle de líneas. <?php if ($editable && current_can('facturas.edit')): ?><a class="underline" href="<?= url('crm/facturas.php?edit=' . (int) $inv['id']) ?>">Edítala</a> para completar las partidas antes de emitir.<?php else: ?>Duplícala como borrador para reconstruir las partidas.<?php endif; ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="quote-doc__totals">
                <?php if ((float) $inv['exempt_base'] > 0): ?><div><span>Subtotal gravado</span><strong><?= money_cur($inv['taxed_base'], $cur) ?></strong></div><div><span>Subtotal exento</span><strong><?= money_cur($inv['exempt_base'], $cur) ?></strong></div><?php else: ?><div><span>Subtotal</span><strong><?= money_cur($inv['subtotal'], $cur) ?></strong></div><?php endif; ?>
                <?php if ((float) $inv['discount_amount'] > 0): ?><div><span>Descuento</span><strong>− <?= money_cur($inv['discount_amount'], $cur) ?></strong></div><?php endif; ?>
                <div><span>ITBIS <?= e(rtrim(rtrim(number_format((float) $inv['tax_rate'], 2), '0'), '.')) ?>%</span><strong><?= money_cur($inv['tax_amount'], $cur) ?></strong></div>
                <?php if ((float) $inv['isc_amount'] > 0): ?><div><span>ISC</span><strong><?= money_cur($inv['isc_amount'], $cur) ?></strong></div><?php endif; ?>
                <div><span>Total</span><strong><?= money_cur($inv['total'], $cur) ?></strong></div>
                <?php if ((float) $inv['itbis_retained'] > 0): ?><div class="quote-doc__equiv"><span>Retención ITBIS</span><strong>− <?= money_cur($inv['itbis_retained'], $cur) ?></strong></div><?php endif; ?>
                <?php if ((float) $inv['isr_retained'] > 0): ?><div class="quote-doc__equiv"><span>Retención ISR</span><strong>− <?= money_cur($inv['isr_retained'], $cur) ?></strong></div><?php endif; ?>
                <?php if ((float) $inv['itbis_retained'] > 0 || (float) $inv['isr_retained'] > 0): ?><div><span>Neto a pagar</span><strong><?= money_cur($net, $cur) ?></strong></div><?php endif; ?>
                <?php if ($cur === 'USD'): ?><div class="quote-doc__equiv"><span>Equivalente · US$ 1 = RD$ <?= e(number_format($rate, 2)) ?></span><strong><?= money_cur($net * $rate, 'DOP') ?></strong></div><?php endif; ?>
            </div>

            <div class="inv-words"><span>Son:</span> <?= e(money_in_words((float) $inv['total'], $cur)) ?></div>

            <?php if (!empty($inv['notes'])): ?><div class="quote-doc__notes"><?= nl2br(e($inv['notes'])) ?></div><?php endif; ?>
            <?php if ($status === 'Anulada' && !empty($inv['void_reason'])): ?><div class="quote-doc__notes" style="border-color:#fbcfcf;background:#fef2f2;color:#b91c1c"><strong>Factura anulada.</strong> Motivo: <?= e($inv['void_reason']) ?></div><?php endif; ?>
            <div class="quote-doc__terms"><h3>Términos y condiciones</h3><p><?= nl2br(e($terms)) ?></p></div>
        </article>

        <?php if ($payments): ?>
        <article class="crm-card" style="margin-top:1rem">
            <div class="crm-card__head"><div><h2><i data-lucide="hand-coins" class="cfg-ic"></i> Pagos registrados</h2></div></div>
            <div class="crm-table-wrap">
                <table class="crm-table"><thead><tr><th>Fecha</th><th>Método</th><th>Referencia</th><th class="text-right">Monto</th></tr></thead><tbody>
                    <?php foreach ($payments as $p): ?><tr><td><?= e(date_es($p['paid_at'])) ?></td><td><?= e($p['method'] ?: '—') ?></td><td><?= e($p['reference'] ?: '—') ?></td><td class="text-right"><strong><?= money_cur($p['amount'], $cur) ?></strong></td></tr><?php endforeach; ?>
                </tbody></table>
            </div>
        </article>
        <?php endif; ?>
    </section>

    <?php if ($hasInvoices && current_can('facturas.edit')): ?>
    <dialog id="inv-pay" class="crm-modal" onclick="if(event.target===this)this.close()">
        <form method="post" class="crm-modal__form">
            <?= csrf_field() ?><input type="hidden" name="form" value="pay"><input type="hidden" name="id" value="<?= (int) ($inv['id'] ?? 0) ?>">
            <header class="crm-modal__head"><span class="crm-modal__icon"><i data-lucide="hand-coins"></i></span><div class="crm-modal__titles"><h2>Registrar pago</h2><p>Balance pendiente: <?= money_cur($balance > 0 ? $balance : 0, $cur) ?></p></div><button type="button" class="crm-modal__close" onclick="document.getElementById('inv-pay').close()"><i data-lucide="x"></i></button></header>
            <div class="crm-modal__body">
                <div class="crm-form-grid">
                    <label class="crm-field"><span class="required">Monto</span><input type="number" step="0.01" min="0.01" name="amount" value="<?= e(number_format($balance > 0 ? $balance : 0, 2, '.', '')) ?>" required class="crm-input text-right"></label>
                    <label class="crm-field"><span>Fecha</span><input type="date" name="paid_at" value="<?= e(date('Y-m-d')) ?>" class="crm-input"></label>
                </div>
                <div class="crm-form-grid">
                    <label class="crm-field"><span>Método</span><select name="method" class="crm-select"><?php foreach ($payMethods as $m): ?><option value="<?= e($m) ?>"><?= e($m) ?></option><?php endforeach; ?></select></label>
                    <label class="crm-field"><span>Referencia</span><input name="reference" class="crm-input" placeholder="No. transferencia / cheque"></label>
                </div>
                <label class="crm-field"><span>Nota</span><input name="note" class="crm-input"></label>
            </div>
            <footer class="crm-modal__foot"><button type="button" class="crm-secondary-btn" onclick="document.getElementById('inv-pay').close()">Cancelar</button><button type="submit" class="crm-primary-btn"><i data-lucide="check" class="h-4 w-4"></i>Registrar</button></footer>
        </form>
    </dialog>
    <dialog id="inv-void" class="crm-modal" onclick="if(event.target===this)this.close()">
        <form method="post" class="crm-modal__form">
            <?= csrf_field() ?><input type="hidden" name="form" value="void"><input type="hidden" name="id" value="<?= (int) ($inv['id'] ?? 0) ?>">
            <header class="crm-modal__head"><span class="crm-modal__icon"><i data-lucide="ban"></i></span><div class="crm-modal__titles"><h2>Anular factura</h2><p>Queda registrada como anulada para el rastro fiscal.</p></div><button type="button" class="crm-modal__close" onclick="document.getElementById('inv-void').close()"><i data-lucide="x"></i></button></header>
            <div class="crm-modal__body">
                <label class="crm-field"><span class="required">Motivo de la anulación</span><textarea name="void_reason" rows="3" required class="crm-textarea" placeholder="Ej. Error en el RNC del cliente / venta cancelada"></textarea></label>
                <?php if ($ncfIsLast): ?>
                    <div class="crm-perm-box" style="margin-top:.7rem">
                        <label class="crm-toggle" style="display:flex;align-items:flex-start;gap:.55rem">
                            <input type="checkbox" name="release_ncf" value="1">
                            <span><b>Liberar el NCF <?= e((string) $inv['ncf']) ?> para reutilizarlo</b><br><small style="color:var(--muted)">Este es el último número emitido de su rango. Al liberarlo, la próxima factura de la serie <?= e((string) $inv['ncf_prefix'] . $inv['ncf_type']) ?> volverá a tomar exactamente este NCF, sin dejar huecos. Úsalo solo si el comprobante no se entregó al cliente ni se transmitió a la DGII.</small></span>
                        </label>
                    </div>
                <?php endif; ?>
            </div>
            <footer class="crm-modal__foot"><button type="button" class="crm-secondary-btn" onclick="document.getElementById('inv-void').close()">Cancelar</button><button type="submit" class="crm-primary-btn" style="background:var(--red);border-color:var(--red)"><i data-lucide="ban" class="h-4 w-4"></i>Anular factura</button></footer>
        </form>
    </dialog>
    <?php endif; ?>
    <?php
    require_once __DIR__ . '/../includes/crm_footer.php';
    return;
}

/* ====================== EDIT / PREFILL PAYLOAD ====================== */
$editPayload = null;
if ($hasInvoices && $editId > 0) {
    $ei = fetch_one('SELECT * FROM invoices WHERE id=?', [$editId]);
    if ($ei && invoice_is_editable($ei['status'])) {
        $eItems = fetch_all('SELECT description, quantity, unit_price, discount, is_exempt FROM invoice_items WHERE invoice_id=? ORDER BY id ASC', [$editId]);
        $editPayload = [
            'id' => (int) $ei['id'], 'client_id' => (string) $ei['client_id'], 'title' => (string) $ei['title'],
            'ncf_type' => (string) $ei['ncf_type'],
            // La proforma viaja al modal como serie «P» (no existe en la DGII).
            'ncf_prefix' => invoice_is_proforma($ei) ? 'P' : (string) $ei['ncf_prefix'],
            'payment_condition' => (string) $ei['payment_condition'], 'payment_method' => (string) ($ei['payment_method'] ?? ''),
            'issue_date' => (string) ($ei['issue_date'] ?? ''), 'due_date' => (string) ($ei['due_date'] ?? ''),
            'modifies_ncf' => (string) ($ei['modifies_ncf'] ?? ''), 'tax_rate' => (float) $ei['tax_rate'],
            'isc_amount' => (float) $ei['isc_amount'], 'itbis_retained' => (float) $ei['itbis_retained'], 'isr_retained' => (float) $ei['isr_retained'],
            'currency' => (string) $ei['currency'], 'exchange_rate' => (float) $ei['exchange_rate'],
            'notes' => (string) ($ei['notes'] ?? ''), 'terms' => (string) ($ei['terms'] ?? ''),
            'items' => array_map(fn ($it) => ['d' => $it['description'], 'q' => (float) $it['quantity'], 'p' => (float) $it['unit_price'], 'disc' => (float) $it['discount'], 'exempt' => (int) $it['is_exempt'] === 1], $eItems),
        ];
    } elseif ($ei) {
        flash('warning', 'Esta factura ya fue emitida y no puede editarse.');
        redirect('crm/facturas.php?action=view&id=' . $editId);
    }
}

/* ---- Prefill from an approved quote (?from_quote=ID) --------------- */
$prefillPayload = null;
if ($hasInvoices && !$editPayload && ($fromQuote = (int) ($_GET['from_quote'] ?? 0)) > 0 && table_exists('quotes')) {
    $q = fetch_one('SELECT quotes.*, clients.name AS client_name FROM quotes LEFT JOIN clients ON clients.id = quotes.client_id WHERE quotes.id=?', [$fromQuote]);
    if ($q) {
        $qHasDiscount = column_exists('quote_items', 'discount');
        $qItems = fetch_all('SELECT description, quantity, unit_price' . ($qHasDiscount ? ', discount' : '') . ' FROM quote_items WHERE quote_id=? ORDER BY id ASC', [$fromQuote]);
        $prefillPayload = [
            'client_id' => (string) $q['client_id'], 'title' => (string) $q['title'],
            'tax_rate' => (float) ($q['tax_rate'] ?? $defaultTax), 'currency' => (string) ($q['currency'] ?? 'DOP'),
            'exchange_rate' => (float) ($q['exchange_rate'] ?? 1), 'notes' => 'Generada desde la cotización ' . (string) $q['quote_number'] . '.',
            'items' => array_map(fn ($it) => ['d' => $it['description'], 'q' => (float) $it['quantity'], 'p' => (float) $it['unit_price'], 'disc' => (float) ($it['discount'] ?? 0), 'exempt' => false], $qItems),
        ];
    }
}

/* ============================ LIST ================================== */
$statusFilter = trim((string) ($_GET['status'] ?? ''));
if ($statusFilter !== '' && !in_array($statusFilter, $invStatuses, true)) { $statusFilter = ''; }
$clientFilter = (int) ($_GET['client_id'] ?? 0);
$agingBuckets = invoice_aging_buckets();
$agingFilter = $canCartera ? trim((string) ($_GET['aging'] ?? '')) : '';
if ($agingFilter !== '' && !isset($agingBuckets[$agingFilter])) { $agingFilter = ''; }
$listQ = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$totalPages = 1; $totalMatching = 0;

if ($hasInvoices) {
    $where = '1=1'; $params = [];
    if ($statusFilter !== '') { $where .= ' AND invoices.status = ?'; $params[] = $statusFilter; }
    if ($clientFilter > 0) { $where .= ' AND invoices.client_id = ?'; $params[] = $clientFilter; }
    if ($agingFilter !== '') { $where .= ' AND ' . invoice_aging_condition($agingFilter); }
    if ($listQ !== '') {
        $like = '%' . $listQ . '%';
        $where .= ' AND (invoices.invoice_number LIKE ? OR invoices.ncf LIKE ? OR invoices.title LIKE ? OR invoices.client_name LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    $totalMatching = (int) (fetch_one("SELECT COUNT(*) total FROM invoices WHERE {$where}", $params)['total'] ?? 0);
    $totalPages = max(1, (int) ceil($totalMatching / $perPage));
    $invoices = fetch_all("SELECT invoices.*, clients.name AS c_name FROM invoices LEFT JOIN clients ON clients.id = invoices.client_id WHERE {$where} ORDER BY invoices.created_at DESC, invoices.id DESC LIMIT {$perPage} OFFSET {$offset}", $params);

    // Las proformas no son comprobantes: quedan fuera del KPI de facturas.
    $invTotal = (int) (fetch_one('SELECT COUNT(*) c FROM invoices WHERE is_proforma=0')['c'] ?? 0);
    $invPending = db_count('invoices', "status='Emitida'");
    // KPIs en RD$: los comprobantes en USD se convierten con su propia tasa.
    $rateSql = invoice_rate_sql();
    $invBilled = (float) (fetch_one("SELECT COALESCE(SUM(total * {$rateSql}),0) v FROM invoices WHERE status IN ('Emitida','Pagada')")['v'] ?? 0);
    $invCollected = (float) (fetch_one("SELECT COALESCE(SUM(amount_paid * {$rateSql}),0) v FROM invoices WHERE status IN ('Emitida','Pagada')")['v'] ?? 0);
    $invReceivable = (float) (fetch_one("SELECT COALESCE(SUM((total - itbis_retained - isr_retained - amount_paid) * {$rateSql}),0) v FROM invoices WHERE status='Emitida'")['v'] ?? 0);
    $invOverdue = db_count('invoices', "status='Emitida' AND due_date IS NOT NULL AND due_date < CURDATE() AND (total - itbis_retained - isr_retained - amount_paid) > 0.009");
} else {
    $invoices = [
        ['id' => 1, 'invoice_number' => 'FAC-' . date('Y') . '-0002', 'ncf' => 'B0100000002', 'ncf_type' => '01', 'c_name' => 'Cedimat', 'client_name' => 'Cedimat', 'title' => 'Camas UCI', 'status' => 'Emitida', 'due_date' => date('Y-m-d', strtotime('+12 days')), 'total' => 18449.30, 'amount_paid' => 0, 'itbis_retained' => 0, 'isr_retained' => 0, 'currency' => 'DOP'],
        ['id' => 2, 'invoice_number' => 'FAC-' . date('Y') . '-0001', 'ncf' => 'B0200000001', 'ncf_type' => '02', 'c_name' => 'Plaza de la Salud', 'client_name' => 'Plaza de la Salud', 'title' => 'Repuestos manifold', 'status' => 'Pagada', 'due_date' => date('Y-m-d', strtotime('-5 days')), 'total' => 9200, 'amount_paid' => 9200, 'itbis_retained' => 0, 'isr_retained' => 0, 'currency' => 'DOP'],
    ];
    $invTotal = count($invoices); $totalMatching = $invTotal;
    $invPending = 1; $invBilled = 27649.30; $invCollected = 9200; $invReceivable = 18449.30; $invOverdue = 0;
}

$queryForPage = fn (int $p) => http_build_query(array_filter(['q' => $listQ, 'status' => $statusFilter, 'client_id' => $clientFilter ?: '', 'aging' => $agingFilter, 'page' => $p], fn ($v) => $v !== '' && $v !== null));
$hasFilters = $listQ !== '' || $statusFilter !== '' || $clientFilter > 0 || $agingFilter !== '';

/* Cuentas por cobrar por antigüedad (solo para quien tenga el permiso nominal). */
$agingSummary = [];
$agingTotal = ['count' => 0, 'amount' => 0.0];
if ($hasInvoices && $canCartera) {
    $report = receivables_aging();
    foreach ($report['buckets'] as $bk => $b) {
        $agingSummary[$bk] = ['label' => $b['label'], 'count' => $b['count'], 'amount' => $b['amount']];
    }
    $agingTotal = $report['total'];
}
$agingTone = ['por_vencer' => 'ok', '0-30' => 'warn', '31-60' => 'warn', '61-90' => 'bad', '90+' => 'bad'];

/* Bandeja de recordatorios de pago: un cliente por fila, deuda más antigua primero. */
$reminderClients = ($hasInvoices && $canCartera) ? receivables_clients() : [];
$reminderOverdue = array_values(array_filter($reminderClients, fn ($c) => $c['overdue_count'] > 0));

/*
 * Rango NCF vigente por serie+tipo: el mismo criterio y el mismo orden que usa
 * invoice_emit(), así que «próximo» es exactamente el NCF que se asignará.
 * Alimenta el botón «Emitir» de la lista y la vista previa dentro del modal.
 */
$activeSeqPairs = [];
if ($hasInvoices) {
    foreach (fetch_all('SELECT * FROM ncf_sequences WHERE active=1 AND seq_next<=seq_to AND (expiration IS NULL OR expiration>=CURDATE()) ORDER BY id ASC') as $s) {
        $pair = (string) $s['prefix'] . (string) $s['ncf_type'];
        if (isset($activeSeqPairs[$pair])) {
            continue; // ya tomamos el primer rango del par: es el que consumirá la emisión
        }
        $activeSeqPairs[$pair] = [
            'next' => ncf_format((string) $s['prefix'], (string) $s['ncf_type'], (int) $s['seq_next']),
            'remaining' => max(0, (int) $s['seq_to'] - (int) $s['seq_next'] + 1),
            'expiration' => $s['expiration'] ? date_es((string) $s['expiration']) : '',
        ];
    }
}

/* RNC por cliente, para avisar en el modal solo cuando de verdad falta. */
$clientRncMap = [];
foreach ($clients as $cl) {
    $clientRncMap[(string) $cl['id']] = trim((string) ($cl['rnc'] ?? ''));
}

$modalOpts = json_encode([
    'autoOpen' => (isset($_GET['new']) || $action === 'new' || $prefillPayload) && !$editPayload,
    'autoEdit' => $editPayload,
    'prefill' => $prefillPayload,
    'types' => array_values(array_map(fn ($code, $t) => ['code' => $code, 'label' => $t[0], 'series' => $t[3], 'rnc' => $t[2]], array_keys($ncfTypes), $ncfTypes)),
    'pairs' => ncf_pair_map(),
    'sequences' => (object) $activeSeqPairs,
    'clientRnc' => (object) $clientRncMap,
    'ncfUrl' => url('crm/facturas.php?action=ncf'),
    'defaults' => ['rate' => $defaultRate, 'tax' => $defaultTax, 'type' => $defaultType, 'prefix' => ncf_series($defaultType), 'condition' => $defaultCondition, 'terms' => $defaultTerms, 'issueDate' => date('Y-m-d'), 'dueDate' => date('Y-m-d', strtotime("+{$defaultDueDays} days"))],
], JSON_UNESCAPED_UNICODE);

$crmTitle = 'Facturación';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasInvoices): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para emitir facturas con comprobante fiscal.</div>
<?php endif; ?>

<section class="crm-cockpit" x-data="crmInvoiceModal(<?= e($modalOpts) ?>)">
    <div class="crm-cockpit__top">
        <div class="crm-cockpit__hero crm-cockpit__hero--sales">
            <span class="crm-kicker"><i data-lucide="receipt"></i>Facturación · DGII</span>
            <h2>Comprobantes fiscales con NCF, ITBIS, retenciones y cobros.</h2>
            <p>Emite facturas de crédito fiscal, de consumo, notas de crédito/débito y los demás tipos de la DGII. El NCF se asigna automáticamente desde tus secuencias autorizadas y el PDF conserva el formato de la cotización.</p>
            <div class="crm-cockpit__actions">
                <?php if (current_can('facturas.edit')): ?><button type="button" class="crm-primary-btn" @click="openNew()"><i data-lucide="plus" class="h-4 w-4"></i>Nueva factura</button><?php endif; ?>
                <?php if (current_can('facturas.edit')): ?><a href="<?= url('crm/facturas.php?action=ncf') ?>" class="crm-secondary-btn"><i data-lucide="hash" class="h-4 w-4"></i>Secuencias NCF</a><?php endif; ?>
                <a href="<?= url('crm/cotizaciones.php') ?>" class="crm-secondary-btn"><i data-lucide="file-text" class="h-4 w-4"></i>Cotizaciones</a>
            </div>
        </div>
        <div class="crm-cockpit__metrics" aria-label="Resumen de facturación">
            <article><span>Emitidas</span><strong><?= e((string) $invTotal) ?></strong><small><?= e((string) $invPending) ?> por cobrar</small></article>
            <article><span>Facturado</span><strong style="font-size:1.05rem"><?= money($invBilled) ?></strong><small>emitido + cobrado</small></article>
            <article><span>Por cobrar</span><strong style="font-size:1.05rem"><?= money($invReceivable) ?></strong><small><?= e((string) $invOverdue) ?> vencidas</small></article>
            <article><span>Cobrado</span><strong style="font-size:1.05rem"><?= money($invCollected) ?></strong><small>pagos recibidos</small></article>
        </div>
    </div>

    <?php if ($hasInvoices && $canCartera): ?>
    <article class="crm-card inv-aging">
        <div class="crm-card__head">
            <div>
                <h2><i data-lucide="calendar-clock" class="cfg-ic"></i> Cuentas por cobrar por antigüedad</h2>
                <p>Saldo pendiente por periodo de vencimiento. Pulsa un tramo para ver esas facturas. Montos en RD$ (las facturas en USD se convierten con la tasa del comprobante). Información restringida a contabilidad.</p>
            </div>
            <div class="crm-toolbar" style="gap:.5rem;padding:0">
                <button type="button" class="crm-secondary-btn" onclick="crmPdfPreviewOpen('<?= url('crm/cartera_pdf.php') ?>','<?= url('crm/cartera_pdf.php?download=1') ?>','Cartera-<?= e(date('Y-m-d')) ?>')"><i data-lucide="printer" class="h-4 w-4"></i>Imprimir / PDF</button>
                <a class="crm-secondary-btn" href="<?= url('crm/cartera_export.php') ?>"><i data-lucide="sheet" class="h-4 w-4"></i>Exportar Excel</a>
                <?php if ($agingFilter !== ''): ?><a href="<?= url('crm/facturas.php') ?>" class="crm-secondary-btn"><i data-lucide="x" class="h-4 w-4"></i>Quitar filtro</a><?php endif; ?>
            </div>
        </div>
        <div class="inv-aging__grid">
            <?php foreach ($agingSummary as $bk => $row): ?>
                <a class="inv-aging__cell inv-aging__cell--<?= e($agingTone[$bk] ?? 'muted') ?> <?= $agingFilter === $bk ? 'is-active' : '' ?>" href="<?= url('crm/facturas.php?aging=' . urlencode($bk)) ?>">
                    <span><?= e($row['label']) ?></span>
                    <strong><?= money($row['amount']) ?></strong>
                    <small><?= e((string) $row['count']) ?> factura<?= $row['count'] === 1 ? '' : 's' ?></small>
                </a>
            <?php endforeach; ?>
            <div class="inv-aging__cell inv-aging__cell--total">
                <span>Total por cobrar</span>
                <strong><?= money($agingTotal['amount']) ?></strong>
                <small><?= e((string) $agingTotal['count']) ?> factura<?= $agingTotal['count'] === 1 ? '' : 's' ?> con saldo</small>
            </div>
        </div>
    </article>
    <?php endif; ?>

    <?php if ($hasInvoices && $canCartera && $reminderClients): ?>
    <article class="crm-card inv-remind">
        <div class="crm-card__head">
            <div>
                <h2><i data-lucide="bell-ring" class="cfg-ic"></i> Recordatorios de pago</h2>
                <p>Estado de cuenta en PDF con el logo, los datos fiscales de la empresa y el detalle de cada comprobante pendiente. El tono del aviso se ajusta solo al atraso del documento más antiguo; puedes forzarlo antes de imprimir.</p>
            </div>
            <div class="crm-toolbar" style="gap:.5rem;padding:0">
                <button type="button" class="crm-secondary-btn" onclick="crmPdfPreviewOpen('<?= url('crm/recordatorio_pdf.php?scope=all') ?>','<?= url('crm/recordatorio_pdf.php?scope=all&download=1') ?>','de <?= e((string) count($reminderClients)) ?> clientes','Lote de recordatorios')"><i data-lucide="layers" class="h-4 w-4"></i>Lote · todos (<?= e((string) count($reminderClients)) ?>)</button>
                <?php if ($reminderOverdue): ?>
                    <button type="button" class="crm-secondary-btn" onclick="crmPdfPreviewOpen('<?= url('crm/recordatorio_pdf.php?scope=all&vencidas=1') ?>','<?= url('crm/recordatorio_pdf.php?scope=all&vencidas=1&download=1') ?>','de <?= e((string) count($reminderOverdue)) ?> clientes en mora','Lote de recordatorios')"><i data-lucide="alarm-clock" class="h-4 w-4"></i>Solo en mora (<?= e((string) count($reminderOverdue)) ?>)</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="crm-table-wrap">
            <table class="crm-table">
                <thead><tr><th>Cliente</th><th class="text-right">Comprobantes</th><th>Mayor atraso</th><th class="text-right">Vencido</th><th class="text-right">Saldo total</th><th class="text-right">Recordatorio</th></tr></thead>
                <tbody>
                <?php foreach ($reminderClients as $rc):
                    $rcTone = $rc['max_days'] > 60 ? 'bad' : ($rc['max_days'] > 0 ? 'warn' : 'ok');
                    $rcName = addslashes((string) $rc['name']);
                    $rcBase = 'crm/recordatorio_pdf.php?client=' . (int) $rc['client_id']; ?>
                    <tr>
                        <td>
                            <a href="<?= url('crm/cliente.php?id=' . (int) $rc['client_id']) ?>"><strong><?= e($rc['name']) ?></strong></a>
                            <?php if ($rc['rnc'] !== '' || $rc['email'] !== ''): ?><br><span style="color:var(--muted);font-size:.78rem"><?= e(trim(implode(' · ', array_filter([$rc['rnc'], $rc['email']])))) ?></span><?php endif; ?>
                        </td>
                        <td class="text-right"><?= e((string) $rc['count']) ?></td>
                        <td><span class="inv-age-chip inv-age-chip--<?= e($rcTone) ?>"><?= $rc['max_days'] > 0 ? e((string) $rc['max_days']) . ' d de atraso' : 'Sin atrasos' ?></span></td>
                        <td class="text-right"><?= $rc['overdue_dop'] > 0 ? '<strong style="color:#b42318">' . money($rc['overdue_dop']) . '</strong>' : '<span style="color:var(--muted)">—</span>' ?></td>
                        <td class="text-right"><strong><?= money($rc['total_dop']) ?></strong></td>
                        <td class="text-right">
                            <div class="crm-row-actions">
                                <button type="button" class="crm-icon-action" title="Vista previa del recordatorio" onclick="crmPdfPreviewOpen('<?= url($rcBase) ?>','<?= url($rcBase . '&download=1') ?>','<?= e($rcName) ?>','Recordatorio de pago')"><i data-lucide="eye"></i></button>
                                <a class="crm-icon-action" href="<?= url($rcBase . '&download=1') ?>" title="Descargar PDF"><i data-lucide="download"></i></a>
                                <button type="button" class="crm-icon-action" title="Tono cordial (aviso preventivo)" onclick="crmPdfPreviewOpen('<?= url($rcBase . '&tono=cordial') ?>','<?= url($rcBase . '&tono=cordial&download=1') ?>','<?= e($rcName) ?>','Recordatorio cordial')"><i data-lucide="smile"></i></button>
                                <button type="button" class="crm-icon-action" title="Tono firme (saldo vencido)" onclick="crmPdfPreviewOpen('<?= url($rcBase . '&tono=firme') ?>','<?= url($rcBase . '&tono=firme&download=1') ?>','<?= e($rcName) ?>','Recordatorio firme')"><i data-lucide="alert-triangle"></i></button>
                                <button type="button" class="crm-icon-action crm-icon-action--danger" title="Último aviso de cobro" onclick="crmPdfPreviewOpen('<?= url($rcBase . '&tono=final') ?>','<?= url($rcBase . '&tono=final&download=1') ?>','<?= e($rcName) ?>','Último aviso de cobro')"><i data-lucide="gavel"></i></button>
                                <?php if ($rc['email'] !== ''): ?>
                                    <a class="crm-icon-action" href="mailto:<?= e($rc['email']) ?>?subject=<?= e(rawurlencode('Estado de cuenta ' . APP_NAME . ' — saldo pendiente al ' . date('d/m/Y'))) ?>&body=<?= e(rawurlencode("Estimados señores de " . $rc['name'] . ":\n\nAdjuntamos el estado de cuenta con los comprobantes pendientes de pago al " . date('d/m/Y') . ", por un total de RD$ " . number_format($rc['total_dop'], 2) . ".\n\nQuedamos atentos a cualquier aclaración.\n\n" . reminder_contact() . "\n" . APP_LEGAL)) ?>" title="Redactar correo al cliente (adjunta el PDF descargado)"><i data-lucide="mail"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
    <?php endif; ?>

    <article class="crm-data-surface">
        <div class="crm-data-surface__head">
            <div><h3>Facturas</h3><p><?php if ($hasFilters): ?><?= e((string) $totalMatching) ?> coincidencia<?= $totalMatching === 1 ? '' : 's' ?><?php else: ?>Comprobantes fiscales, NCF, estado de cobro e impresión.<?php endif; ?></p></div>
            <?php if (current_can('facturas.edit')): ?><button type="button" class="crm-primary-btn" @click="openNew()"><i data-lucide="plus" class="h-4 w-4"></i>Nueva factura</button><?php endif; ?>
        </div>
        <form method="get" class="crm-toolbar" style="flex-wrap:wrap;gap:.5rem;padding:0 0 .8rem">
            <div class="crm-search-field" style="flex:1 1 220px"><i data-lucide="search" class="h-4 w-4"></i><input name="q" value="<?= e($listQ) ?>" placeholder="Número, NCF, título o cliente" class="crm-input"></div>
            <select name="status" class="crm-select" style="max-width:170px"><option value="">Todos los estados</option><?php foreach ($invStatuses as $st): ?><option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e($st) ?></option><?php endforeach; ?></select>
            <?php if ($hasInvoices): ?><select name="client_id" class="crm-select" style="max-width:200px"><option value="">Todos los clientes</option><?php foreach ($clients as $cl): ?><option value="<?= (int) $cl['id'] ?>" <?= $clientFilter === (int) $cl['id'] ? 'selected' : '' ?>><?= e($cl['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
            <?php if ($canCartera): ?><select name="aging" class="crm-select" style="max-width:180px" title="Periodo de vencimiento"><option value="">Todo vencimiento</option><?php foreach ($agingBuckets as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $agingFilter === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select><?php endif; ?>
            <button type="submit" class="crm-secondary-btn"><i data-lucide="filter" class="h-4 w-4"></i>Filtrar</button>
            <?php if ($hasFilters): ?><a href="<?= url('crm/facturas.php') ?>" class="crm-secondary-btn"><i data-lucide="x" class="h-4 w-4"></i>Limpiar</a><?php endif; ?>
        </form>
        <div class="crm-table-wrap">
        <table class="crm-table crm-data-table">
            <thead><tr><th>Comprobante</th><th>Cliente</th><th>Tipo</th><th>Estado</th><?php if ($canCartera): ?><th>Vencimiento</th><?php endif; ?><th class="text-right">Total</th><th class="text-right">Acción</th></tr></thead>
            <tbody>
                <?php foreach ($invoices as $inv): $ov = invoice_is_overdue($inv); $age = invoice_aging($inv); $rowProforma = invoice_is_proforma($inv); ?>
                    <tr>
                        <td><strong><?= e($inv['invoice_number'] ?? '') ?></strong><?php if (!empty($inv['ncf'])): ?><br><span class="inv-ncf-chip"><?= e($inv['ncf']) ?></span><?php else: ?><br><span style="color:var(--muted);font-size:.78rem"><?= $rowProforma ? 'Sin NCF · proforma' : 'Sin NCF' ?></span><?php endif; ?></td>
                        <td><?= e($inv['client_name'] ?? $inv['c_name'] ?? 'Cliente') ?><?php if (!empty($inv['title'])): ?><br><span style="color:var(--muted);font-size:.8rem"><?= e($inv['title']) ?></span><?php endif; ?></td>
                        <td><span class="inv-type-chip"><?= $rowProforma ? 'Factura Proforma' : e(($inv['ncf_type'] ?? '') . ' · ' . ncf_type_label((string) ($inv['ncf_type'] ?? ''))) ?></span></td>
                        <td><span class="status-chip <?= e(status_class($inv['status'] ?? 'Borrador')) ?>"><?= e($inv['status'] ?? 'Borrador') ?></span><?php if ($rowProforma): ?> <span class="status-chip bg-amber-50 text-amber-800 ring-1 ring-amber-200" title="Documento sin validez fiscal">Proforma</span><?php endif; ?><?php if ($ov): ?> <span class="status-chip bg-red-50 text-red-700 ring-1 ring-red-200" title="Vencida el <?= e(date_es($inv['due_date'])) ?>">Vencida</span><?php endif; ?></td>
                        <?php if ($canCartera): ?>
                        <td>
                            <span class="inv-age-chip inv-age-chip--<?= e($age['tone']) ?>"><?= e($age['label']) ?></span>
                            <?php if ($age['days'] !== null): ?>
                                <br><span style="color:var(--muted);font-size:.75rem"><?= $age['key'] === 'por_vencer' ? 'faltan ' . e((string) abs((int) $age['days'])) . ' d' : e((string) (int) $age['days']) . ' d de vencida' ?><?php if (!empty($inv['due_date'])): ?> · <?= e(date_es($inv['due_date'])) ?><?php endif; ?></span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td class="text-right"><strong><?= money_cur($inv['total'] ?? 0, (string) ($inv['currency'] ?? 'DOP')) ?></strong></td>
                        <td class="text-right">
                            <div class="crm-row-actions">
                                <a class="crm-icon-action" href="<?= url('crm/facturas.php?action=view&id=' . (int) $inv['id']) ?>" title="Ver"><i data-lucide="eye"></i></a>
                                <?php if ($hasInvoices && invoice_is_editable($inv['status'] ?? '') && current_can('facturas.edit')): ?><a class="crm-icon-action" href="<?= url('crm/facturas.php?edit=' . (int) $inv['id']) ?>" title="Editar"><i data-lucide="pencil"></i></a><?php endif; ?>
                                <?php if ($hasInvoices && !$rowProforma && invoice_is_editable($inv['status'] ?? '') && current_can('facturas.edit')):
                                    $pair = (string) ($inv['ncf_prefix'] ?? 'B') . (string) ($inv['ncf_type'] ?? '');
                                    $canEmit = isset($activeSeqPairs[$pair]); ?>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Se asignará el NCF de la secuencia <?= e($pair) ?> y la factura quedará bloqueada. ¿Emitir <?= e(addslashes((string) $inv['invoice_number'])) ?>?');">
                                        <?= csrf_field() ?><input type="hidden" name="form" value="emit"><input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                                        <button type="submit" class="crm-icon-action" title="<?= $canEmit ? 'Emitir y asignar NCF' : 'Sin secuencia NCF activa para ' . e($pair) ?>" <?= $canEmit ? '' : 'disabled' ?>><i data-lucide="badge-check"></i></button>
                                    </form>
                                <?php endif; ?>
                                <button type="button" class="crm-icon-action" title="Vista previa PDF" onclick="crmPdfPreviewOpen('<?= url('crm/factura_pdf.php?id=' . (int) $inv['id']) ?>','<?= url('crm/factura_pdf.php?id=' . (int) $inv['id'] . '&download=1') ?>','<?= e(addslashes((string) ($inv['ncf'] ?? $inv['invoice_number']))) ?>')"><i data-lucide="file-text"></i></button>
                                <?php if ($hasInvoices && (string) ($inv['status'] ?? '') === 'Emitida' && ($age['balance'] ?? 0) > 0.009): ?>
                                    <button type="button" class="crm-icon-action" title="Recordatorio de pago (PDF)" onclick="crmPdfPreviewOpen('<?= url('crm/recordatorio_pdf.php?id=' . (int) $inv['id']) ?>','<?= url('crm/recordatorio_pdf.php?id=' . (int) $inv['id'] . '&download=1') ?>','<?= e(addslashes((string) $inv['invoice_number'])) ?>','Recordatorio de pago')"><i data-lucide="bell-ring"></i></button>
                                <?php endif; ?>
                                <?php if ($hasInvoices && current_can('facturas.delete') && in_array((string) ($inv['status'] ?? 'Borrador'), ['Borrador', 'Anulada'], true)):
                                    $isVoided = (string) ($inv['status'] ?? '') === 'Anulada';
                                    $delMsg = $isVoided
                                        ? 'Eliminar definitivamente la factura ANULADA ' . addslashes((string) $inv['invoice_number']) . ($inv['ncf'] ? ' (NCF ' . addslashes((string) $inv['ncf']) . ')' : '') . '. Esto la borra por completo del sistema. ¿Continuar?'
                                        : '¿Eliminar el borrador ' . addslashes((string) $inv['invoice_number']) . '?'; ?>
                                    <form method="post" style="display:inline" onsubmit="return confirm('<?= e($delMsg) ?>');"><?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int) $inv['id'] ?>"><button type="submit" class="crm-icon-action crm-icon-action--danger" title="<?= $isVoided ? 'Eliminar factura anulada' : 'Eliminar borrador' ?>"><i data-lucide="trash-2"></i></button></form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$invoices): ?>
            <div class="crm-empty"><i data-lucide="receipt" class="h-6 w-6"></i><strong><?= $hasFilters ? 'Sin coincidencias' : 'Aún no hay facturas' ?></strong><p><?= $hasFilters ? 'Prueba con otros filtros.' : 'Crea la primera con el botón “Nueva factura”.' ?></p></div>
        <?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="crm-pager">
                <a class="<?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= $page <= 1 ? '#' : url('crm/facturas.php?' . $queryForPage($page - 1)) ?>"><i data-lucide="chevron-left" class="h-4 w-4"></i>Anterior</a>
                <b><?= e((string) $page) ?> / <?= e((string) $totalPages) ?></b>
                <a class="<?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : url('crm/facturas.php?' . $queryForPage($page + 1)) ?>">Siguiente<i data-lucide="chevron-right" class="h-4 w-4"></i></a>
            </div>
        <?php endif; ?>
    </article>

    <dialog x-ref="dlg" class="crm-modal crm-modal--wide" @click.self="close()" @cancel.prevent="close()">
        <form method="post" class="crm-modal__form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="save">
            <input type="hidden" name="id" :value="form.id">
            <header class="crm-modal__head">
                <span class="crm-modal__icon"><i data-lucide="receipt"></i></span>
                <div class="crm-modal__titles">
                    <h2 x-text="form.id ? (isProforma() ? 'Editar factura proforma' : 'Editar factura (borrador)') : 'Nueva factura'">Nueva factura</h2>
                    <p x-show="!isProforma()">«Crear borrador» lo deja editable sin consumir NCF; «Guardar y emitir NCF» toma el número de tu secuencia autorizada al instante.</p>
                    <p x-show="isProforma()" x-cloak>La proforma es una oferta formal con formato de factura: no consume NCF, no se reporta a la DGII y puede editarse siempre.</p>
                </div>
                <button type="button" class="crm-modal__close" @click="close()" aria-label="Cerrar"><i data-lucide="x"></i></button>
            </header>
            <div class="crm-modal__body">
                <div class="crm-form-grid">
                    <label class="crm-field"><span class="required">Cliente</span>
                        <select name="client_id" required x-model="form.client_id" class="crm-select">
                            <option value="">Seleccionar</option>
                            <?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>" <?= empty($client['rnc']) ? 'data-rnc="0"' : 'data-rnc="1"' ?>><?= e($client['name']) ?><?= empty($client['rnc']) ? '' : ' · RNC ' . e($client['rnc']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="crm-field"><span>Concepto / título</span><input name="title" x-model="form.title" placeholder="Ej. Equipamiento quirófano 2" class="crm-input"></label>
                </div>
                <div class="crm-form-grid" style="grid-template-columns:repeat(3,minmax(0,1fr))">
                    <label class="crm-field"><span class="required">Serie NCF</span><select name="ncf_prefix" x-model="form.ncf_prefix" @change="syncType()" class="crm-select"><?php foreach (invoice_series_options() as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?></select></label>
                    <label class="crm-field" x-show="!isProforma()">
                        <span class="required">Tipo de comprobante</span>
                        <select name="ncf_type" x-model="form.ncf_type" class="crm-select">
                            <template x-for="t in availableTypes()" :key="t.code"><option :value="t.code" x-text="t.code + ' — ' + t.label"></option></template>
                        </select>
                    </label>
                    <label class="crm-field" x-show="isProforma()" x-cloak>
                        <span>Tipo de documento</span>
                        <input class="crm-input" value="Factura Proforma (sin NCF)" disabled>
                    </label>
                    <label class="crm-field"><span>Condición de pago</span><select name="payment_condition" x-model="form.payment_condition" class="crm-select"><?php foreach ($payConditions as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?></select></label>
                </div>
                <!-- Proforma: sin NCF, sin DGII. Sustituye a la vista previa del comprobante. -->
                <div class="inv-ncf-preview is-warn" x-show="isProforma()" x-cloak>
                    <span class="inv-ncf-preview__ic"><i data-lucide="file-clock"></i></span>
                    <div class="inv-ncf-preview__body">
                        <span class="inv-ncf-preview__k">Documento sin validez fiscal</span>
                        <strong class="inv-ncf-preview__v">FACTURA PROFORMA — sin NCF</strong>
                        <small>No consume ningún número de tu secuencia autorizada ni se reporta a la DGII, y no entra en cuentas por cobrar. Si el cliente la aprueba, edítala, cambia la serie a B o E y emítela como comprobante fiscal.</small>
                    </div>
                </div>
                <!-- NCF que tomará esta factura: el próximo número del rango autorizado. -->
                <div class="inv-ncf-preview" :class="seqInfo() ? 'is-ok' : 'is-warn'" x-show="form.client_id && !isProforma()" x-cloak>
                    <span class="inv-ncf-preview__ic"><i data-lucide="hash"></i></span>
                    <div class="inv-ncf-preview__body">
                        <span class="inv-ncf-preview__k" x-text="seqInfo() ? 'NCF que se asignará al emitir' : 'Sin secuencia NCF disponible'"></span>
                        <strong class="inv-ncf-preview__v" x-text="seqInfo() ? seqInfo().next : (form.ncf_prefix + form.ncf_type + ' — sin rango configurado')"></strong>
                        <small x-show="seqInfo()" x-text="'Rango ' + form.ncf_prefix + form.ncf_type + ' · ' + seqInfo().remaining.toLocaleString('en-US') + ' disponibles' + (seqInfo().expiration ? ' · vence ' + seqInfo().expiration : '') + (clientRnc() ? ' · RNC del cliente ' + clientRnc() : '')"></small>
                        <small x-show="!seqInfo()">Registra el rango autorizado por la DGII para este tipo de comprobante en <a class="underline" href="<?= url('crm/facturas.php?action=ncf') ?>">Secuencias NCF</a>; sin él la factura solo puede guardarse como borrador.</small>
                    </div>
                </div>
                <p class="inv-ecf-note" x-show="form.ncf_prefix==='E'" x-cloak><i data-lucide="zap"></i> e-CF (comprobante fiscal electrónico): por ahora se captura de forma manual; la transmisión y validación con la DGII se integrará más adelante.</p>
                <p class="inv-rnc-warn" x-show="requiresRnc() && form.client_id && !clientRnc()" x-cloak><i data-lucide="alert-triangle"></i> Este tipo de comprobante exige el RNC/Cédula del cliente y la ficha del cliente no lo tiene: complétalo antes de emitir.</p>
                <div class="crm-form-grid" x-show="!isProforma() && ['03','04','33','34'].includes(form.ncf_type)" x-cloak>
                    <label class="crm-field"><span>NCF que modifica (nota de crédito/débito)</span><input name="modifies_ncf" x-model="form.modifies_ncf" placeholder="Ej. B0100000123" class="crm-input"></label>
                </div>
                <div class="crm-form-grid" style="grid-template-columns:repeat(4,minmax(0,1fr))">
                    <label class="crm-field"><span>Fecha de emisión</span><input type="date" name="issue_date" x-model="form.issue_date" class="crm-input"></label>
                    <label class="crm-field"><span>Vencimiento</span><input type="date" name="due_date" x-model="form.due_date" class="crm-input"></label>
                    <label class="crm-field"><span>Método de pago</span><select name="payment_method" x-model="form.payment_method" class="crm-select"><option value="">—</option><?php foreach ($payMethods as $m): ?><option value="<?= e($m) ?>"><?= e($m) ?></option><?php endforeach; ?></select></label>
                    <label class="crm-field"><span>ITBIS %</span><input type="number" step="0.01" min="0" name="tax_rate" x-model.number="tax" class="crm-input"></label>
                </div>
                <div class="crm-form-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
                    <label class="crm-field"><span>Moneda</span><select name="currency" x-model="currency" class="crm-select"><option value="DOP">DOP — RD$</option><option value="USD">USD — US$</option></select></label>
                    <label class="crm-field" x-show="currency==='USD'" x-cloak><span>Tasa US$ 1 = RD$</span><input type="number" step="0.01" min="0" name="exchange_rate" x-model.number="rate" class="crm-input text-right"></label>
                </div>

                <div>
                    <p class="dash-section-label" style="margin:.2rem 0 .5rem">Partidas</p>
                    <div class="ib">
                        <div class="ib__head"><span>Descripción</span><span>Cant.</span><span>Precio</span><span>Desc.</span><span>Exento</span><span>Importe</span><span></span></div>
                        <template x-for="(item,index) in items" :key="index">
                            <div class="ib__row">
                                <input class="crm-input ib__desc" name="item_description[]" x-model="item.d" placeholder="Equipo o servicio">
                                <input class="crm-input text-right" type="number" step="0.01" min="0" name="item_quantity[]" x-model.number="item.q" aria-label="Cantidad">
                                <input class="crm-input text-right" type="number" step="0.01" min="0" name="item_price[]" x-model.number="item.p" aria-label="Precio">
                                <input class="crm-input text-right" type="number" step="0.01" min="0" name="item_discount[]" x-model.number="item.disc" aria-label="Descuento">
                                <label class="ib__exempt" title="Exento de ITBIS"><input type="checkbox" x-model="item.exempt"><input type="hidden" name="item_exempt[]" :value="item.exempt ? 1 : 0"></label>
                                <span class="qb__total" x-text="fmt(lineNet(item))">RD$ 0.00</span>
                                <button type="button" class="crm-icon-action crm-icon-action--danger" @click="removeLine(index)" title="Quitar partida"><i data-lucide="trash-2"></i></button>
                            </div>
                        </template>
                    </div>
                    <button type="button" @click="addLine()" class="crm-secondary-btn" style="margin-top:.6rem"><i data-lucide="plus" class="h-4 w-4"></i>Agregar línea</button>
                </div>

                <div class="grid gap-4 lg:grid-cols-[1fr_330px]">
                    <div class="grid gap-3" style="align-content:start">
                        <details class="inv-adv">
                            <summary><i data-lucide="sliders-horizontal" class="h-4 w-4"></i> Impuestos y retenciones avanzadas</summary>
                            <div class="crm-form-grid" style="margin-top:.7rem">
                                <label class="crm-field"><span>ISC (selectivo al consumo)</span><input type="number" step="0.01" min="0" name="isc_amount" x-model.number="isc" class="crm-input text-right"></label>
                                <label class="crm-field"><span>Retención ITBIS</span><input type="number" step="0.01" min="0" name="itbis_retained" x-model.number="itbisRet" class="crm-input text-right"></label>
                                <label class="crm-field"><span>Retención ISR</span><input type="number" step="0.01" min="0" name="isr_retained" x-model.number="isrRet" class="crm-input text-right"></label>
                            </div>
                        </details>
                        <label class="crm-field"><span>Notas</span><textarea name="notes" rows="2" x-model="form.notes" class="crm-textarea" placeholder="Orden de compra, alcance, observaciones…"></textarea></label>
                        <label class="crm-field"><span>Términos y condiciones (editable)</span><textarea name="terms" rows="5" x-model="form.terms" class="crm-textarea"></textarea></label>
                    </div>
                    <div class="quote-summary" style="align-self:start">
                        <div><span>Subtotal gravado</span><strong x-text="fmt(subtotalTaxed())">RD$ 0.00</strong></div>
                        <div x-show="subtotalExempt()>0" x-cloak><span>Subtotal exento</span><strong x-text="fmt(subtotalExempt())">RD$ 0.00</strong></div>
                        <div x-show="discountTotal()>0" x-cloak><span>Descuento</span><strong x-text="'− ' + fmt(discountTotal())">RD$ 0.00</strong></div>
                        <div><span x-text="'ITBIS ' + (Number(tax)||0) + '%'">ITBIS 18%</span><strong x-text="fmt(taxAmount())">RD$ 0.00</strong></div>
                        <div x-show="(Number(isc)||0)>0" x-cloak><span>ISC</span><strong x-text="fmt(Number(isc)||0)">RD$ 0.00</strong></div>
                        <div><span>Total</span><strong x-text="fmt(total())">RD$ 0.00</strong></div>
                        <div class="quote-summary__equiv" x-show="(Number(itbisRet)||0)+(Number(isrRet)||0)>0" x-cloak><span>Neto a cobrar</span><strong x-text="fmt(netReceivable())">RD$ 0.00</strong></div>
                        <div class="quote-summary__equiv" x-show="currency==='USD'" x-cloak><span>Equivalente (RD$)</span><strong x-text="altFmt(altTotal())">RD$ 0.00</strong></div>
                    </div>
                </div>
            </div>
            <footer class="crm-modal__foot">
                <input type="hidden" name="emit_now" value="0">
                <button type="button" class="crm-secondary-btn" @click="close()">Cancelar</button>
                <button type="submit" :class="isProforma() ? 'crm-primary-btn' : 'crm-secondary-btn'" onclick="this.form.emit_now.value='0'"><i data-lucide="save" class="h-4 w-4"></i><span x-text="isProforma() ? (form.id ? 'Guardar proforma' : 'Crear proforma') : (form.id ? 'Guardar cambios' : 'Crear borrador')">Crear borrador</span></button>
                <button type="submit" class="crm-primary-btn" x-show="!isProforma()" onclick="if(!confirm('Se guardará la factura y se le asignará el NCF de tu secuencia autorizada. Una vez emitida no se puede editar. ¿Continuar?')){return false;} this.form.emit_now.value='1';"><i data-lucide="badge-check" class="h-4 w-4"></i>Guardar y emitir NCF</button>
            </footer>
        </form>
    </dialog>
</section>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
