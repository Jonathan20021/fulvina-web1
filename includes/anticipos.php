<?php

declare(strict_types=1);

/**
 * Anticipos: recibos de ingreso sobre una COTIZACIÓN.
 *
 * El caso real: el cliente aprueba la cotización y paga una parte (o todo)
 * antes de que exista la factura. Ese dinero entró y hay que darle un recibo,
 * pero todavía no hay comprobante al que aplicarlo. Antes no había dónde
 * registrarlo, y al facturar el anticipo se perdía: la factura salía debiendo
 * el total (así pasó con CISAM y sus 60,000).
 *
 * Cómo está hecho, y por qué:
 *
 *  1. EL ANTICIPO ES SU PROPIO RECIBO, con número de la misma serie REC que
 *     los cobros de facturas (reserve_receipt_number). Para el cliente es un
 *     recibo más.
 *
 *  2. APLICARLO A UNA FACTURA ES CREAR SUS LÍNEAS DE COBRO, con el MISMO número
 *     de recibo y la MISMA fecha en que se recibió el dinero. Desde ahí la
 *     factura lo ve como cualquier cobro: saldo, estado, cartera, estado de
 *     cuenta, 607 y reportes no necesitan saber que fue un anticipo.
 *
 *  3. LO APLICADO NO SE GUARDA APARTE: es la suma de las líneas de cobro con ese
 *     número. Si alguien corrige o anula esa línea desde la factura, el anticipo
 *     lo refleja solo, sin un contador que se desincronice.
 *
 *  4. Al EMITIR la factura generada desde la cotización, sus anticipos se le
 *     aplican solos (invoice_emit → anticipos_apply_for_invoice). Para los demás
 *     casos —otra cotización del mismo cliente, una factura hecha a mano— la
 *     factura muestra los anticipos disponibles y se aplican con un botón.
 */

function ensure_anticipos_schema(): void
{
    $pdo = db(false);
    if (!$pdo || !table_exists('quotes')) {
        return;
    }
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS quote_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            receipt_number VARCHAR(40) NOT NULL,
            quote_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            currency VARCHAR(3) NOT NULL DEFAULT \'DOP\',
            exchange_rate DECIMAL(12,4) NOT NULL DEFAULT 1,
            method VARCHAR(40) NULL,
            reference VARCHAR(120) NULL,
            paid_at DATE NOT NULL,
            note VARCHAR(255) NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'vigente\',
            void_reason VARCHAR(255) NULL,
            voided_at DATETIME NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_quote_payment_receipt (receipt_number),
            INDEX idx_quote_payments_quote (quote_id),
            INDEX idx_quote_payments_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    } catch (Throwable $e) {
        error_log('ensure_anticipos_schema: ' . $e->getMessage());
    }
}

function anticipos_available(): bool
{
    return db(false) !== null && table_exists('quote_payments') && table_exists('invoice_payments')
        && column_exists('invoice_payments', 'receipt_number');
}

function anticipo_methods(): array
{
    return ['Transferencia', 'Efectivo', 'Cheque', 'Tarjeta', 'Depósito'];
}

/** Líneas de cobro de facturas que aplican este anticipo (mismo número de recibo). */
function anticipo_applications(string $receipt, ?PDO $pdo = null, bool $lock = false): array
{
    if ($receipt === '' || !anticipos_available()) {
        return [];
    }
    $sql = 'SELECT p.id, p.invoice_id, p.amount, p.paid_at, i.invoice_number, i.ncf, i.status AS invoice_status
              FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id
             WHERE p.receipt_number = ? ORDER BY p.id';
    if ($pdo && $lock) {
        $st = $pdo->prepare($sql . ' FOR UPDATE');
        $st->execute([$receipt]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return fetch_all($sql, [$receipt]);
}

/** Completa una fila de anticipo con lo aplicado, lo pendiente y dónde se aplicó. */
function anticipo_enrich(array $a, ?PDO $pdo = null, bool $lock = false): array
{
    $apps = anticipo_applications((string) $a['receipt_number'], $pdo, $lock);
    $applied = round(array_sum(array_map(static fn ($x) => (float) $x['amount'], $apps)), 2);
    $a['applications'] = $apps;
    $a['applied'] = $applied;
    $a['pending'] = $a['status'] === 'vigente' ? max(0.0, round((float) $a['amount'] - $applied, 2)) : 0.0;
    return $a;
}

function anticipo_find(int $id): ?array
{
    if ($id <= 0 || !anticipos_available()) {
        return null;
    }
    $a = fetch_one('SELECT * FROM quote_payments WHERE id = ?', [$id]);
    return $a ? anticipo_enrich($a) : null;
}

function anticipo_by_receipt(string $receipt): ?array
{
    if ($receipt === '' || !anticipos_available()) {
        return null;
    }
    $a = fetch_one('SELECT * FROM quote_payments WHERE receipt_number = ?', [$receipt]);
    return $a ? anticipo_enrich($a) : null;
}

/** @return array{rows: array, total: float, applied: float, pending: float} */
function anticipos_for_quote(int $quoteId): array
{
    $out = ['rows' => [], 'total' => 0.0, 'applied' => 0.0, 'pending' => 0.0];
    if ($quoteId <= 0 || !anticipos_available()) {
        return $out;
    }
    foreach (fetch_all('SELECT * FROM quote_payments WHERE quote_id = ? ORDER BY paid_at, id', [$quoteId]) as $a) {
        $a = anticipo_enrich($a);
        $out['rows'][] = $a;
        if ($a['status'] === 'vigente') {
            $out['total'] += (float) $a['amount'];
            $out['applied'] += $a['applied'];
            $out['pending'] += $a['pending'];
        }
    }
    foreach (['total', 'applied', 'pending'] as $k) {
        $out[$k] = round($out[$k], 2);
    }
    return $out;
}

/**
 * SQL de lo cobrado en anticipos que TODAVÍA no se aplicó, por fecha de cobro,
 * en RD$: columnas paid_at y v. Lo aplicado ya está en invoice_payments con la
 * misma fecha, así que sumar las dos fuentes no cuenta nada dos veces.
 * '' si no hay anticipos.
 */
function anticipos_unapplied_sql(): string
{
    if (!anticipos_available()) {
        return '';
    }
    return "SELECT qp.paid_at, (qp.amount - COALESCE((SELECT SUM(ip.amount) FROM invoice_payments ip WHERE ip.receipt_number = qp.receipt_number), 0))"
        . " * IF(UPPER(qp.currency) = 'USD', GREATEST(qp.exchange_rate, 1), 1) AS v"
        . " FROM quote_payments qp WHERE qp.status = 'vigente'";
}

/** Anticipos con saldo sin aplicar de un cliente, en una moneda. */
function anticipos_pending_for_client(int $clientId, string $currency = ''): array
{
    if ($clientId <= 0 || !anticipos_available()) {
        return [];
    }
    $sql = 'SELECT qp.*, q.quote_number, q.title AS quote_title
              FROM quote_payments qp LEFT JOIN quotes q ON q.id = qp.quote_id
             WHERE qp.client_id = ? AND qp.status = \'vigente\'';
    $params = [$clientId];
    if ($currency !== '') {
        $sql .= ' AND qp.currency = ?';
        $params[] = $currency;
    }
    $out = [];
    foreach (fetch_all($sql . ' ORDER BY qp.paid_at, qp.id', $params) as $a) {
        $a = anticipo_enrich($a);
        if ($a['pending'] > 0.009) {
            $out[] = $a;
        }
    }
    return $out;
}

/** Lo que entra desde el formulario, limpio. */
function anticipo_input(array $in): array
{
    return [
        'amount' => round(amount_parse($in['amount'] ?? 0), 2),
        'paid_at' => valid_date(is_string($in['paid_at'] ?? null) ? $in['paid_at'] : null),
        'method' => mb_substr(trim((string) (is_scalar($in['method'] ?? null) ? $in['method'] : '')), 0, 40),
        'reference' => mb_substr(trim((string) (is_scalar($in['reference'] ?? null) ? $in['reference'] : '')), 0, 120),
        'note' => mb_substr(trim((string) (is_scalar($in['note'] ?? null) ? $in['note'] : '')), 0, 255),
    ];
}

/** Error de validación común a registrar y corregir, o '' si está bien. */
function anticipo_validate(array $v, array $quote, float $otros): string
{
    $cur = (string) ($quote['currency'] ?? 'DOP');
    if ($v['amount'] <= 0.009) {
        return 'Indica el monto recibido.';
    }
    if ($v['paid_at'] === null) {
        return 'La fecha del anticipo no es válida.';
    }
    if ($v['paid_at'] > date('Y-m-d')) {
        return 'La fecha del anticipo no puede ser futura: es el día en que el dinero entró.';
    }
    $total = round((float) $quote['total'], 2);
    if ($otros + $v['amount'] > $total + 0.009) {
        return sprintf(
            'Los anticipos sumarían %s y la cotización vale %s. Si el cliente pagó de más, registra solo hasta el total (quedan %s por cubrir).',
            money_cur($otros + $v['amount'], $cur), money_cur($total, $cur), money_cur(max(0.0, $total - $otros), $cur)
        );
    }
    return '';
}

/**
 * Registra un anticipo sobre una cotización y le da su número de recibo.
 * @return array{0: bool, 1: string, 2: string}  [ok, mensaje, número de recibo]
 */
function anticipo_register(int $quoteId, array $in): array
{
    if (!anticipos_available()) {
        ensure_anticipos_schema();
        if (!anticipos_available()) {
            return [false, 'No se pudo preparar el registro de anticipos. Avisa al administrador.', ''];
        }
    }
    $v = anticipo_input($in);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        // Bloquear la cotización serializa dos anticipos simultáneos: el tope
        // (no pasar del total) tiene que verse con el otro ya sumado.
        $st = $pdo->prepare('SELECT * FROM quotes WHERE id = ? FOR UPDATE');
        $st->execute([$quoteId]);
        $quote = $st->fetch(PDO::FETCH_ASSOC);
        if (!$quote) {
            $pdo->rollBack();
            return [false, 'La cotización no existe.', ''];
        }
        $st = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM quote_payments WHERE quote_id = ? AND status = \'vigente\' FOR UPDATE');
        $st->execute([$quoteId]);
        $otros = round((float) $st->fetchColumn(), 2);
        $error = anticipo_validate($v, $quote, $otros);
        if ($error !== '') {
            $pdo->rollBack();
            return [false, $error, ''];
        }

        $numero = reserve_receipt_number($pdo);
        $user = current_user() ?? [];
        $cur = strtoupper((string) ($quote['currency'] ?? 'DOP')) === 'USD' ? 'USD' : 'DOP';
        $pdo->prepare('INSERT INTO quote_payments (receipt_number, quote_id, client_id, amount, currency, exchange_rate, method, reference, paid_at, note, status, created_by, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'vigente\', ?, NOW(), NOW())')
            ->execute([$numero, $quoteId, (int) $quote['client_id'], $v['amount'], $cur, max(1.0, (float) ($quote['exchange_rate'] ?? 1)),
                       $v['method'], $v['reference'], $v['paid_at'], $v['note'], ($user['id'] ?? 0) ?: null]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('anticipo_register: ' . $e->getMessage());
        return [false, 'No se pudo registrar el anticipo. No se guardó nada.', ''];
    }
    log_activity('quote', $quoteId, 'anticipo_registrado', $numero . ' · ' . money_cur($v['amount'], $cur));
    return [true, 'Anticipo registrado con el recibo ' . $numero . '. Se aplicará solo a la factura cuando se emita desde esta cotización.', $numero];
}

/** Bloquea un anticipo y lo devuelve con lo aplicado leído también con bloqueo. */
function anticipo_lock(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM quote_payments WHERE id = ? FOR UPDATE');
    $st->execute([$id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    return $a ? anticipo_enrich($a, $pdo, true) : null;
}

/** Corrige un anticipo que todavía no se aplicó a ninguna factura. */
function anticipo_update(int $id, array $in): array
{
    $reason = mb_substr(trim((string) (is_scalar($in['reason'] ?? null) ? $in['reason'] : '')), 0, 200);
    if ($reason === '') {
        return [false, 'Indica por qué se corrige el anticipo: el cliente puede tener ya el recibo anterior.'];
    }
    if (!anticipos_available()) {
        return [false, 'Los anticipos no están disponibles.'];
    }
    $v = anticipo_input($in);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $a = anticipo_lock($pdo, $id);
        if (!$a) {
            $pdo->rollBack();
            return [false, 'Ese anticipo ya no existe.'];
        }
        if ($a['status'] !== 'vigente') {
            $pdo->rollBack();
            return [false, 'Ese anticipo está anulado: no se puede corregir.'];
        }
        if ($a['applied'] > 0.009) {
            $pdo->rollBack();
            return [false, 'Ese anticipo ya se aplicó a una factura. Corrígelo desde el recibo en la factura, donde queda su efecto sobre el saldo.'];
        }
        $st = $pdo->prepare('SELECT * FROM quotes WHERE id = ? FOR UPDATE');
        $st->execute([(int) $a['quote_id']]);
        $quote = $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'currency' => $a['currency']];
        $st = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM quote_payments WHERE quote_id = ? AND status = \'vigente\' AND id <> ? FOR UPDATE');
        $st->execute([(int) $a['quote_id'], $id]);
        $error = anticipo_validate($v, $quote, round((float) $st->fetchColumn(), 2));
        if ($error !== '') {
            $pdo->rollBack();
            return [false, $error];
        }
        $pdo->prepare('UPDATE quote_payments SET amount = ?, paid_at = ?, method = ?, reference = ?, note = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$v['amount'], $v['paid_at'], $v['method'], $v['reference'], $v['note'], $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('anticipo_update: ' . $e->getMessage());
        return [false, 'No se pudo corregir el anticipo. No se guardó ningún cambio.'];
    }
    log_activity('quote', (int) $a['quote_id'], 'anticipo_corregido', sprintf(
        '%s · %s → %s · %s', $a['receipt_number'], money_cur((float) $a['amount'], (string) $a['currency']), money_cur($v['amount'], (string) $a['currency']), $reason
    ));
    return [true, 'Anticipo ' . $a['receipt_number'] . ' corregido.'];
}

/** Anula un anticipo que no se aplicó. El número no se reutiliza. */
function anticipo_void(int $id, string $reason): array
{
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reason === '') {
        return [false, 'Indica por qué se anula el anticipo.'];
    }
    if (!anticipos_available()) {
        return [false, 'Los anticipos no están disponibles.'];
    }
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $a = anticipo_lock($pdo, $id);
        if (!$a || $a['status'] !== 'vigente') {
            $pdo->rollBack();
            return [false, 'Ese anticipo no existe o ya estaba anulado.'];
        }
        if ($a['applied'] > 0.009) {
            $pdo->rollBack();
            $docs = implode(', ', array_map(static fn ($x) => (string) ($x['ncf'] ?: $x['invoice_number']), $a['applications']));
            return [false, 'Ese anticipo ya se aplicó a ' . $docs . '. Primero anula el recibo en esa factura; después podrás anular el anticipo.'];
        }
        $pdo->prepare('UPDATE quote_payments SET status = \'anulado\', void_reason = ?, voided_at = NOW(), updated_at = NOW() WHERE id = ?')
            ->execute([$reason, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('anticipo_void: ' . $e->getMessage());
        return [false, 'No se pudo anular el anticipo.'];
    }
    log_activity('quote', (int) $a['quote_id'], 'anticipo_anulado', $a['receipt_number'] . ' · ' . $reason);
    return [true, 'Anticipo ' . $a['receipt_number'] . ' anulado. Su número no se vuelve a usar.'];
}

/**
 * Aplica lo pendiente de un anticipo a una factura, hasta donde alcance su saldo.
 * Corre en su propia transacción. @return array{0: bool, 1: string, 2: float}
 */
function anticipo_apply(int $anticipoId, int $invoiceId): array
{
    if (!anticipos_available()) {
        return [false, 'Los anticipos no están disponibles.', 0.0];
    }
    $pdo = db();
    try {
        $pdo->beginTransaction();
        // Siempre en el mismo orden (factura, luego anticipo) que los recibos:
        // así dos aplicaciones cruzadas no se interbloquean.
        $st = $pdo->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
        $st->execute([$invoiceId]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        $a = anticipo_lock($pdo, $anticipoId);
        $fallo = static function (string $m) use ($pdo): array {
            $pdo->rollBack();
            return [false, $m, 0.0];
        };
        if (!$inv) {
            return $fallo('La factura no existe.');
        }
        if (!$a || $a['status'] !== 'vigente') {
            return $fallo('Ese anticipo no existe o está anulado.');
        }
        $doc = (string) ($inv['ncf'] ?: $inv['invoice_number']);
        if (!in_array((string) $inv['status'], ['Emitida', 'Pagada'], true) || invoice_is_credit_note($inv)) {
            return $fallo('El anticipo solo se aplica a una factura emitida. Emite ' . $doc . ' primero.');
        }
        if ((int) $inv['client_id'] !== (int) $a['client_id']) {
            return $fallo('Ese anticipo es de otro cliente.');
        }
        $invCur = strtoupper((string) ($inv['currency'] ?? 'DOP')) === 'USD' ? 'USD' : 'DOP';
        if ($invCur !== (string) $a['currency']) {
            return $fallo(sprintf('El anticipo está en %s y %s en %s: no se mezclan monedas.', $a['currency'], $doc, $invCur));
        }
        $saldo = invoice_balance($inv);
        $monto = round(min($a['pending'], $saldo), 2);
        if ($a['pending'] <= 0.009) {
            return $fallo('Ese anticipo ya está aplicado por completo.');
        }
        if ($monto <= 0.009) {
            return $fallo($doc . ' ya está saldada: no hay a qué aplicar el anticipo.');
        }

        $nota = mb_substr('Anticipo de la cotización ' . (string) (fetch_one('SELECT quote_number FROM quotes WHERE id = ?', [(int) $a['quote_id']])['quote_number'] ?? ''), 0, 255);
        $user = current_user() ?? [];
        $existe = null;
        foreach ($a['applications'] as $ap) {
            if ((int) $ap['invoice_id'] === $invoiceId) {
                $existe = $ap;
            }
        }
        if ($existe) {
            // Un recibo va una sola vez por factura: si ya tenía una parte, crece.
            $pdo->prepare('UPDATE invoice_payments SET amount = amount + ? WHERE id = ?')->execute([$monto, (int) $existe['id']]);
        } else {
            $pdo->prepare('INSERT INTO invoice_payments (receipt_number, invoice_id, amount, method, reference, paid_at, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$a['receipt_number'], $invoiceId, $monto, $a['method'], $a['reference'], $a['paid_at'], $nota, ($user['id'] ?? 0) ?: null]);
        }
        invoice_recalc_paid($pdo, $invoiceId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('anticipo_apply: ' . $e->getMessage());
        return [false, 'No se pudo aplicar el anticipo. No se guardó nada.', 0.0];
    }
    $texto = $a['receipt_number'] . ' · ' . money_cur($monto, (string) $a['currency']) . ' a ' . $doc;
    log_activity('invoice', $invoiceId, 'anticipo_aplicado', $texto);
    log_activity('quote', (int) $a['quote_id'], 'anticipo_aplicado', $texto);
    return [true, 'Anticipo ' . $a['receipt_number'] . ' aplicado: ' . money_cur($monto, (string) $a['currency']) . ' a ' . $doc . '.', $monto];
}

/**
 * Al emitir una factura generada desde una cotización, le aplica los anticipos
 * de esa cotización, del más antiguo al más nuevo, hasta cubrir su saldo.
 * Devuelve lo aplicado: [[recibo, monto], ...].
 */
function anticipos_apply_for_invoice(int $invoiceId): array
{
    if (!anticipos_available() || !column_exists('invoices', 'quote_id')) {
        return [];
    }
    $inv = fetch_one('SELECT id, quote_id, client_id, currency FROM invoices WHERE id = ?', [$invoiceId]);
    if (!$inv || (int) ($inv['quote_id'] ?? 0) <= 0) {
        return [];
    }
    $hechos = [];
    foreach (fetch_all('SELECT id, receipt_number FROM quote_payments WHERE quote_id = ? AND status = \'vigente\' ORDER BY paid_at, id', [(int) $inv['quote_id']]) as $a) {
        [$ok, , $monto] = anticipo_apply((int) $a['id'], $invoiceId);
        if ($ok && $monto > 0.009) {
            $hechos[] = [(string) $a['receipt_number'], $monto];
        }
    }
    return $hechos;
}
