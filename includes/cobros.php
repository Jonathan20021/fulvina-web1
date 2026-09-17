<?php

declare(strict_types=1);

/**
 * Recibos de ingreso (corregir y anular) y planes de pago en cuotas.
 *
 * Hasta ahora un cobro solo se podía CREAR: no había ni un UPDATE ni un DELETE
 * sobre invoice_payments en todo el CRM. Un monto mal tecleado quedaba para
 * siempre, y con él el saldo de la factura.
 *
 * Dos decisiones de diseño que conviene no deshacer sin pensarlo:
 *
 *  1. ANULAR BORRA LA FILA Y GUARDA UNA FOTO APARTE. La alternativa obvia —una
 *     columna voided_at— obligaría a filtrar esa columna en cada consulta que
 *     suma cobros, y hay ocho repartidas entre analítica, cartera, recibo y
 *     DGII. La que se olvide contaría dinero anulado sin avisar. Sacando la fila
 *     de la tabla, todas esas consultas siguen siendo correctas sin tocarlas, y
 *     invoice_payment_log conserva exactamente cómo era el recibo.
 *
 *  2. EL SALDO SE RECALCULA SUMANDO, NO SUMANDO Y RESTANDO. Tras editar o
 *     anular, amount_paid se vuelve a calcular como la suma de los cobros que
 *     quedan. Ir restando la diferencia arrastra cualquier descuadre anterior;
 *     sumar desde cero lo corrige.
 */

/* =========================================================================
   Esquema
   ========================================================================= */

function ensure_cobros_schema(): void
{
    $pdo = db(false);
    if (!$pdo || !table_exists('invoices') || !table_exists('invoice_payments')) {
        return;
    }

    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS invoice_payment_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            receipt_number VARCHAR(40) NULL,
            invoice_ids VARCHAR(255) NULL,
            action VARCHAR(16) NOT NULL,
            reason VARCHAR(255) NULL,
            before_json MEDIUMTEXT NOT NULL,
            after_json MEDIUMTEXT NULL,
            user_id INT UNSIGNED NULL,
            user_name VARCHAR(120) NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_paylog_receipt (receipt_number),
            INDEX idx_paylog_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS invoice_installments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT UNSIGNED NOT NULL,
            seq SMALLINT UNSIGNED NOT NULL,
            due_date DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            cumulative DECIMAL(12,2) NOT NULL,
            created_at DATETIME NULL,
            UNIQUE KEY uniq_installment (invoice_id, seq),
            INDEX idx_installment_due (due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    } catch (Throwable $e) {
        error_log('ensure_cobros_schema: ' . $e->getMessage());
        return;
    }

    /* Lo que ya estaba abonado cuando se pactó el plan. NULL = sin plan. Las
       cuotas reparten lo que FALTABA en ese momento, no el total: si el cliente
       dio un inicial, el plan es sobre el resto. */
    if (!column_exists('invoices', 'installment_base')) {
        try {
            $pdo->exec('ALTER TABLE invoices ADD COLUMN installment_base DECIMAL(12,2) NULL DEFAULT NULL');
        } catch (Throwable) { /* ignore */ }
    }

    /* Ajuste de cartera: lo que se deja de cobrar SIN nota de crédito —un
       descuento acordado, un redondeo, una parte incobrable—. Baja lo que el
       cliente debe, no el comprobante fiscal: el 607 sigue declarando lo
       facturado y lo no cobrado queda como venta a crédito, que es la verdad. */
    if (!column_exists('invoices', 'balance_adjustment')) {
        try {
            $pdo->exec('ALTER TABLE invoices ADD COLUMN balance_adjustment DECIMAL(12,2) NOT NULL DEFAULT 0.00');
        } catch (Throwable) { /* ignore */ }
    }

    // Anticipos sobre cotizaciones: se aplican como cobros de estas facturas.
    if (function_exists('ensure_anticipos_schema')) {
        ensure_anticipos_schema();
    }
}

function balance_adjustment_available(): bool
{
    return db(false) !== null && column_exists('invoices', 'balance_adjustment');
}

function installments_available(): bool
{
    return db(false) !== null
        && table_exists('invoice_installments')
        && column_exists('invoices', 'installment_base');
}

function payment_log_available(): bool
{
    return db(false) !== null && table_exists('invoice_payment_log');
}

/* =========================================================================
   Saldo
   ========================================================================= */

/**
 * Recalcula lo abonado a una factura sumando los cobros que existen, y ajusta
 * su estado. Debe llamarse DENTRO de una transacción: bloquea la factura.
 *
 * Una factura que deja de estar cubierta vuelve de «Pagada» a «Emitida»: si se
 * anula el recibo que la saldaba, no puede seguir figurando como pagada.
 * Borradores y anuladas conservan su estado; solo se actualiza el importe.
 */
function invoice_recalc_paid(PDO $pdo, int $invoiceId): ?array
{
    $inv = $pdo->query('SELECT * FROM invoices WHERE id=' . $invoiceId . ' FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        return null;
    }
    /* FOR UPDATE también en la suma, y no es un detalle.
       Un SELECT normal dentro de una transacción lee la FOTO tomada en su
       primera lectura. Ocho personas anulando el mismo recibo a la vez: siete
       esperan el bloqueo de la factura, y al despertar sumaban los cobros con
       su foto vieja —que aún incluía el recibo borrado— y reescribían el saldo
       como si no se hubiera anulado. Una lectura con bloqueo siempre ve lo
       último que se confirmó. */
    $sum = round((float) $pdo->query('SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id=' . $invoiceId . ' FOR UPDATE')->fetchColumn(), 2);

    $status = (string) $inv['status'];
    if ($status === 'Emitida' || $status === 'Pagada') {
        $cubierta = $sum + 0.009 >= invoice_net($inv);
        if ($cubierta) {
            $pdo->prepare("UPDATE invoices SET amount_paid=?, status='Pagada', paid_at=COALESCE(paid_at, NOW()), updated_at=NOW() WHERE id=?")
                ->execute([$sum, $invoiceId]);
            $status = 'Pagada';
        } else {
            $pdo->prepare("UPDATE invoices SET amount_paid=?, status='Emitida', paid_at=NULL, updated_at=NOW() WHERE id=?")
                ->execute([$sum, $invoiceId]);
            $status = 'Emitida';
        }
    } else {
        $pdo->prepare('UPDATE invoices SET amount_paid=?, updated_at=NOW() WHERE id=?')->execute([$sum, $invoiceId]);
    }

    $inv['amount_paid'] = $sum;
    $inv['status'] = $status;
    return $inv;
}

/* =========================================================================
   Recibos
   ========================================================================= */

/**
 * Todas las líneas del recibo al que pertenece un cobro.
 *
 * Un recibo puede cubrir varias facturas —la pantalla de Cobro reparte una
 * transferencia entre cinco comprobantes con un solo número—, así que corregir
 * o anular siempre actúa sobre el recibo entero, no sobre una línea suelta. Los
 * cobros antiguos sin número son recibos de una sola línea.
 */
function receipt_group(int $paymentId, ?PDO $pdo = null, bool $lock = false): array
{
    $pdo = $pdo ?? db();
    $cierre = $lock ? ' FOR UPDATE' : '';
    // Con $lock, también la semilla es lectura actual: si otra persona borró la
    // fila, esta búsqueda no puede encontrarla en una foto vieja.
    $seed = $pdo->query('SELECT * FROM invoice_payments WHERE id=' . $paymentId . $cierre)->fetch(PDO::FETCH_ASSOC);
    if (!$seed) {
        return ['receipt' => '', 'rows' => []];
    }
    $receipt = trim((string) ($seed['receipt_number'] ?? ''));

    if ($receipt !== '') {
        $stmt = $pdo->prepare(
            'SELECT p.*, i.invoice_number, i.ncf, i.currency, i.client_id, i.client_name, i.status AS invoice_status
               FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id
              WHERE p.receipt_number = ? ORDER BY p.id ASC' . $cierre
        );
        $stmt->execute([$receipt]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT p.*, i.invoice_number, i.ncf, i.currency, i.client_id, i.client_name, i.status AS invoice_status
               FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id
              WHERE p.id = ?' . $cierre
        );
        $stmt->execute([$paymentId]);
    }
    return ['receipt' => $receipt, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
}

/** Bloquea las facturas SIEMPRE en el mismo orden: así dos correcciones
 *  simultáneas no pueden esperarse la una a la otra. */
function receipt_lock_invoices(PDO $pdo, array $invoiceIds): array
{
    $ids = array_values(array_unique(array_map('intval', $invoiceIds)));
    sort($ids);
    $out = [];
    foreach ($ids as $id) {
        $row = $pdo->query('SELECT * FROM invoices WHERE id=' . $id . ' FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $out[$id] = $row;
        }
    }
    return $out;
}

function payment_log_write(PDO $pdo, string $action, string $receipt, array $before, ?array $after, string $reason): void
{
    if (!payment_log_available()) {
        return;
    }
    $ids = array_values(array_unique(array_map(static fn ($r) => (int) $r['invoice_id'], $before)));
    $user = current_user() ?? [];
    $pdo->prepare('INSERT INTO invoice_payment_log (receipt_number, invoice_ids, action, reason, before_json, after_json, user_id, user_name, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([
            $receipt !== '' ? $receipt : null,
            ',' . implode(',', $ids) . ',',
            $action,
            mb_substr($reason, 0, 255),
            json_encode($before, JSON_UNESCAPED_UNICODE),
            $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            ($user['id'] ?? 0) ?: null,
            mb_substr((string) ($user['name'] ?? ''), 0, 120),
        ]);
}

/**
 * Corrige un recibo: datos comunes (fecha, método, referencia, nota) y el
 * importe aplicado a cada factura.
 *
 * $in = ['paid_at','method','reference','note','reason', 'alloc' => [paymentId => importe],
 *        'cifras' => [paymentId => [...]]]   (opcional, ver receipt_apply_figures())
 *
 * Un importe en 0 quita esa factura del recibo. Quitarlas todas no se permite:
 * eso es anular, y anular pide su propio motivo y deja su propio rastro.
 * Devuelve [ok, mensaje].
 */
function receipt_update(int $paymentId, array $in): array
{
    $reason = trim((string) ($in['reason'] ?? ''));
    if ($reason === '') {
        return [false, 'Indica por qué se corrige el recibo: queda en el historial, y el cliente puede tener ya la copia anterior.'];
    }
    $paidAt = valid_date($in['paid_at'] ?? null);
    if ($paidAt === null) {
        return [false, 'La fecha del cobro no es válida.'];
    }
    $method = mb_substr(trim((string) ($in['method'] ?? '')), 0, 40);
    $reference = mb_substr(trim((string) ($in['reference'] ?? '')), 0, 120);
    $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 255);
    $alloc = (array) ($in['alloc'] ?? []);
    $cifras = array_filter((array) ($in['cifras'] ?? []), 'is_array');

    $pdo = db();
    try {
        $res = cobros_con_reintento(static function () use ($pdo, $paymentId, $paidAt, $method, $reference, $note, $alloc, $reason, $cifras) {
            $pdo->beginTransaction();
            try {
                [$group, $invoices] = receipt_lock_group($pdo, $paymentId);
                if (!$group) {
                    $pdo->rollBack();
                    return ['estado' => 'no_existe'];
                }
                $before = $group['rows'];

                /* Lo cobrado de más que cada factura YA tenía (p. ej. una nota de
                   crédito posterior al pago). Sirve para no bloquear una corrección
                   por un exceso que no causa ella; solo se impide empeorarlo. */
                $excesoAntes = [];
                foreach ($invoices as $iid => $invRow) {
                    $excesoAntes[$iid] = max(0.0, round((float) $invRow['amount_paid'] - invoice_net($invRow), 2));
                }

                /* Retenciones, ajuste y abono anterior van ANTES de revisar los
                   importes: cambian cuánto admite cada factura. */
                $hechos = $cifras ? receipt_apply_figures($pdo, $group, $invoices, $cifras, $paidAt) : [];
                $lineasConAbono = [];
                $lineasConCifras = [];
                foreach ($hechos as $h) {
                    if ($h['tipo'] === 'abono') {
                        $lineasConAbono[$h['linea']] = $h;
                    } else {
                        $lineasConCifras[$h['linea']] = $h;
                    }
                }

                $nuevos = [];
                $quedan = 0;
                foreach ($before as $row) {
                    $pid = (int) $row['id'];
                    $inv = $invoices[(int) $row['invoice_id']];
                    $raw = array_key_exists($pid, $alloc) ? $alloc[$pid]
                        : (array_key_exists((string) $pid, $alloc) ? $alloc[(string) $pid] : $row['amount']);
                    $amt = round(amount_parse($raw), 2);
                    if ($amt < 0) {
                        throw new RuntimeException('Un importe no puede ser negativo.');
                    }
                    /* El tope es lo que la factura debe SIN contar esta línea: si antes
                       se aplicaron 5,000 y el saldo es 3,000, esta línea admite hasta
                       8,000. Comparar contra el saldo a secas impediría hasta dejarla
                       igual. Ambos valores salen de lecturas con bloqueo. */
                    $tope = round(invoice_balance($inv) + (float) $row['amount'], 2);
                    if ($amt > $tope + 0.009 && isset($lineasConAbono[$pid])) {
                        throw new RuntimeException(sprintf(
                            'Con el abono anterior de %s, a %s le quedaban %s antes de este recibo, y el recibo aplica %s. Baja el abono anterior o el importe de este pago.',
                            money_cur($lineasConAbono[$pid]['amount'], (string) $inv['currency']),
                            (string) ($inv['ncf'] ?: $inv['invoice_number']),
                            money_cur(max(0.0, $tope), (string) $inv['currency']),
                            money_cur($amt, (string) $inv['currency'])
                        ));
                    }
                    if ($amt > $tope + 0.009 && isset($lineasConCifras[$pid])) {
                        throw new RuntimeException(sprintf(
                            'Con las retenciones y el ajuste nuevos, %s vale %s y ya tiene cobrado más de eso: contando este recibo solo admite %s, y el recibo aplica %s. Baja las retenciones o el ajuste, o el importe de este pago.',
                            (string) ($inv['ncf'] ?: $inv['invoice_number']),
                            money_cur(invoice_net($inv), (string) $inv['currency']),
                            money_cur(max(0.0, $tope), (string) $inv['currency']),
                            money_cur($amt, (string) $inv['currency'])
                        ));
                    }
                    if ($amt > $tope + 0.009) {
                        throw new RuntimeException(sprintf(
                            'A %s no se le pueden aplicar %s: con este recibo incluido, lo que debe es %s.',
                            (string) ($inv['ncf'] ?: $inv['invoice_number']),
                            money_cur($amt, (string) $inv['currency']),
                            money_cur($tope, (string) $inv['currency'])
                        ));
                    }
                    if ($amt > 0.009) {
                        $quedan++;
                    }
                    $nuevos[$pid] = $amt;
                }
                if ($quedan === 0) {
                    throw new RuntimeException('Dejaste el recibo sin importe. Si el cobro no ocurrió, usa «Anular recibo» en lugar de corregirlo.');
                }

                $cambios = (bool) $hechos;
                foreach ($before as $row) {
                    $pid = (int) $row['id'];
                    $amt = $nuevos[$pid];
                    if ($amt <= 0.009) {
                        $pdo->prepare('DELETE FROM invoice_payments WHERE id=?')->execute([$pid]);
                        $cambios = true;
                        continue;
                    }
                    $igual = abs($amt - (float) $row['amount']) < 0.005
                        && (string) $row['paid_at'] === $paidAt
                        && (string) ($row['method'] ?? '') === $method
                        && (string) ($row['reference'] ?? '') === $reference
                        && (string) ($row['note'] ?? '') === $note;
                    if ($igual) {
                        continue;
                    }
                    $pdo->prepare('UPDATE invoice_payments SET amount=?, paid_at=?, method=?, reference=?, note=? WHERE id=?')
                        ->execute([$amt, $paidAt, $method, $reference, $note, $pid]);
                    $cambios = true;
                }

                if (!$cambios) {
                    $pdo->rollBack();
                    return ['estado' => 'sin_cambios'];
                }

                foreach (array_keys($invoices) as $iid) {
                    $fresca = invoice_recalc_paid($pdo, (int) $iid);
                    if (!$fresca) {
                        continue;
                    }
                    // Nunca dejar una factura cobrada por encima de lo que vale.
                    $exceso = round((float) $fresca['amount_paid'] - invoice_net($fresca), 2);
                    if ($exceso > ($excesoAntes[$iid] ?? 0.0) + 0.009) {
                        throw new RuntimeException(sprintf(
                            'Con esos cambios %s quedaría cobrada de más: sus cobros suman %s y lo que vale ahora es %s. Revisa las retenciones, el ajuste, el abono anterior o los importes.',
                            (string) ($fresca['ncf'] ?: $fresca['invoice_number']),
                            money_cur($fresca['amount_paid'], (string) $fresca['currency']),
                            money_cur(invoice_net($fresca), (string) $fresca['currency'])
                        ));
                    }
                }
                $after = $group['receipt'] !== ''
                    ? $pdo->query('SELECT * FROM invoice_payments WHERE receipt_number=' . $pdo->quote($group['receipt']) . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
                    : $pdo->query('SELECT * FROM invoice_payments WHERE id=' . $paymentId)->fetchAll(PDO::FETCH_ASSOC);
                /* Recibo de un anticipo: sus facturas no pueden aplicar más de lo que
                   se recibió, y la fecha y la forma de pago son las del dinero que
                   entró, así que se corrigen también en el anticipo. */
                if ($group['receipt'] !== '' && function_exists('anticipos_available') && anticipos_available()) {
                    $st = $pdo->prepare('SELECT * FROM quote_payments WHERE receipt_number = ? FOR UPDATE');
                    $st->execute([$group['receipt']]);
                    $anticipo = $st->fetch(PDO::FETCH_ASSOC);
                    if ($anticipo) {
                        $st = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE receipt_number = ? FOR UPDATE');
                        $st->execute([$group['receipt']]);
                        $aplicado = round((float) $st->fetchColumn(), 2);
                        if ($aplicado > (float) $anticipo['amount'] + 0.009) {
                            throw new RuntimeException(sprintf(
                                '%s es un anticipo de %s: sus facturas no pueden sumar %s. Si el cliente pagó más, registra la diferencia como otro cobro.',
                                $group['receipt'], money_cur((float) $anticipo['amount'], (string) $anticipo['currency']), money_cur($aplicado, (string) $anticipo['currency'])
                            ));
                        }
                        $pdo->prepare('UPDATE quote_payments SET paid_at = ?, method = ?, reference = ?, updated_at = NOW() WHERE id = ?')
                            ->execute([$paidAt, $method, $reference, (int) $anticipo['id']]);
                    }
                }

                $detalle = implode(' · ', array_column($hechos, 'texto'));
                payment_log_write($pdo, 'editado', $group['receipt'], $before, $after, $reason . ($detalle !== '' ? ' · ' . $detalle : ''));
                $pdo->commit();
                return ['estado' => 'ok', 'group' => $group, 'before' => $before, 'hechos' => $hechos];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
        });
    } catch (RuntimeException $e) {
        return [false, $e->getMessage()];
    } catch (Throwable $e) {
        error_log('receipt_update: ' . $e->getMessage());
        return [false, 'No se pudo corregir el recibo. No se guardó ningún cambio.'];
    }

    if ($res['estado'] === 'no_existe') {
        return [false, 'Ese recibo ya no existe: alguien lo anuló mientras lo corregías.'];
    }
    if ($res['estado'] === 'sin_cambios') {
        return [true, 'No había nada que cambiar.'];
    }
    $numero = $res['group']['receipt'] ?: 'cobro #' . $paymentId;
    foreach ($res['before'] as $row) {
        log_activity('invoice', (int) $row['invoice_id'], 'recibo_corregido', $numero . ' · ' . $reason);
    }
    $abonos = [];
    foreach ($res['hechos'] as $h) {
        log_activity('invoice', (int) $h['invoice_id'], $h['tipo'] === 'abono' ? 'abono_anterior_registrado' : 'cifras_factura_ajustadas', $h['texto'] . ' · al corregir ' . $numero);
        if ($h['tipo'] === 'abono' && $h['receipt'] !== '') {
            $abonos[] = $h['receipt'];
        }
    }
    return [true, 'Recibo ' . ($res['group']['receipt'] ?: '') . ' corregido.'
        . ($abonos ? ' El abono anterior quedó registrado con su propio recibo: ' . implode(', ', $abonos) . '.' : '')];
}

/**
 * Una línea del recibo con las cifras que imprime, para el diálogo de corregir.
 * Mismo cálculo que crm/recibo_pdf.php: el saldo es el del DÍA del cobro, así
 * que lo cobrado antes se cuenta por fecha (y por orden de registro si es el
 * mismo día), y el crédito por notas es el acumulado hasta esa fecha.
 */
function receipt_line_figures(array $row, int $currentInvoiceId): array
{
    static $facturas = [];
    $iid = (int) $row['invoice_id'];
    $facturas[$iid] ??= fetch_one('SELECT * FROM invoices WHERE id = ?', [$iid]) ?? [];
    $inv = $facturas[$iid];
    $paidAt = (string) $row['paid_at'];

    $previo = (float) (fetch_one(
        'SELECT COALESCE(SUM(amount), 0) v FROM invoice_payments
          WHERE invoice_id = ? AND id <> ? AND (paid_at < ? OR (paid_at = ? AND id < ?))',
        [$iid, (int) $row['id'], $paidAt, $paidAt, (int) $row['id']]
    )['v'] ?? 0);

    $n = static fn ($v) => round((float) $v, 2);
    return [
        'id' => (int) $row['id'],
        'invoice_id' => $iid,
        'doc' => (string) ($row['ncf'] ?: $row['invoice_number']),
        'amount' => number_format((float) $row['amount'], 2, '.', ''),
        'currency' => (string) $row['currency'],
        'here' => $iid === $currentInvoiceId,
        'total' => $n($inv['total'] ?? 0),
        'itbis' => $n($inv['tax_amount'] ?? 0),
        'base' => $n(max((float) ($inv['subtotal'] ?? 0), (float) ($inv['taxed_base'] ?? 0) + (float) ($inv['exempt_base'] ?? 0))),
        'credito' => $n(invoice_credited_as_of($iid, $paidAt)),
        'ret_itbis' => $n($inv['itbis_retained'] ?? 0),
        'ret_isr' => $n($inv['isr_retained'] ?? 0),
        'ajuste' => $n($inv['balance_adjustment'] ?? 0),
        'ajuste_ok' => balance_adjustment_available(),
        'previo' => $n($previo),
    ];
}

/**
 * Las cifras de la factura que el recibo muestra, corregidas desde el recibo.
 *
 * El recibo dice «neto del comprobante», «saldo antes de este pago» y «saldo
 * pendiente». Ninguna se guarda tal cual: todas se derivan. Por eso editarlas
 * no escribe un número suelto en el PDF —el recibo diría una cosa y la cartera,
 * el estado de cuenta y el 607 otra— sino el dato que las explica:
 *
 *   · el NETO baja con retenciones de ITBIS e ISR (fiscales, van al 607) o con
 *     un ajuste de cartera (lo que se deja de cobrar sin nota de crédito);
 *   · el SALDO ANTES baja porque el cliente ya había abonado algo que no estaba
 *     registrado: se registra ese abono como un cobro de verdad, con su fecha,
 *     su forma de pago y su propio recibo, y así entra en los reportes de cobro;
 *   · el SALDO PENDIENTE es la resta de los dos: la pantalla lo traduce a uno de
 *     los anteriores.
 *
 * $cifras[paymentId] = [ret_itbis, ret_isr, ajuste, orig_ret_itbis, orig_ret_isr,
 *                       orig_ajuste, abono, abono_fecha, abono_metodo, abono_ref]
 *
 * Corre dentro de la transacción de receipt_update(), con las facturas ya
 * bloqueadas; actualiza $invoices con lo que queda en la base. Devuelve lo que
 * hizo, para el historial.
 */
function receipt_apply_figures(PDO $pdo, array $group, array &$invoices, array $cifras, string $paidAt): array
{
    $hechos = [];
    $ajusteOk = balance_adjustment_available();
    $etiquetas = ['itbis_retained' => 'la retención de ITBIS', 'isr_retained' => 'la retención de ISR', 'balance_adjustment' => 'el ajuste de cartera'];

    foreach ($group['rows'] as $row) {
        $pid = (int) $row['id'];
        $c = $cifras[$pid] ?? ($cifras[(string) $pid] ?? null);
        if (!is_array($c)) {
            continue;
        }
        $iid = (int) $row['invoice_id'];
        $inv = $invoices[$iid];
        $doc = (string) ($inv['ncf'] ?: $inv['invoice_number']);
        $cur = (string) $inv['currency'];
        $tocada = false;

        /* ---- Retenciones y ajuste ------------------------------------------ */
        $campos = ['ret_itbis' => 'itbis_retained', 'ret_isr' => 'isr_retained'];
        if ($ajusteOk) {
            $campos['ajuste'] = 'balance_adjustment';
        }
        $set = [];
        foreach ($campos as $k => $col) {
            if (!array_key_exists($k, $c)) {
                continue;
            }
            $nuevo = round(amount_parse($c[$k]), 2);
            if ($nuevo < 0) {
                throw new RuntimeException(sprintf('En %s, %s no puede ser negativo.', $doc, $etiquetas[$col]));
            }
            $actual = round((float) ($inv[$col] ?? 0), 2);
            $orig = array_key_exists('orig_' . $k, $c) ? round(amount_parse($c['orig_' . $k]), 2) : $actual;
            if (abs($nuevo - $orig) < 0.005) {
                continue; // no lo tocó
            }
            // Si la base ya no tiene lo que la pantalla mostraba, otra persona lo
            // cambió: aplicar encima borraría su corrección sin que nadie lo vea.
            if (abs($actual - $orig) >= 0.005) {
                throw new RuntimeException(sprintf('Otra persona cambió %s de %s mientras corregías. Cierra y vuelve a abrir el recibo.', $etiquetas[$col], $doc));
            }
            $set[$col] = $nuevo;
        }

        if ($set) {
            $retI = $set['itbis_retained'] ?? (float) $inv['itbis_retained'];
            $retS = $set['isr_retained'] ?? (float) $inv['isr_retained'];
            $adj = $set['balance_adjustment'] ?? (float) ($inv['balance_adjustment'] ?? 0);
            $itbis = (float) $inv['tax_amount'];
            $base = max((float) ($inv['subtotal'] ?? 0), (float) ($inv['taxed_base'] ?? 0) + (float) ($inv['exempt_base'] ?? 0));
            if ($retI > $itbis + 0.009) {
                throw new RuntimeException(sprintf('La retención de ITBIS de %s (%s) no puede pasar del ITBIS facturado (%s).', $doc, money_cur($retI, $cur), money_cur($itbis, $cur)));
            }
            if ($retS > $base + 0.009) {
                throw new RuntimeException(sprintf('La retención de ISR de %s (%s) no puede pasar del monto sin ITBIS (%s).', $doc, money_cur($retS, $cur), money_cur($base, $cur)));
            }
            $neto = round((float) $inv['total'] - $retI - $retS - $adj - (float) ($inv['credited_amount'] ?? 0), 2);
            if ($neto < -0.009) {
                throw new RuntimeException(sprintf('Con esas retenciones y ese ajuste, %s valdría menos de cero.', $doc));
            }

            $pdo->prepare('UPDATE invoices SET ' . implode(', ', array_map(static fn ($col) => $col . ' = ?', array_keys($set))) . ', updated_at = NOW() WHERE id = ?')
                ->execute(array_merge(array_values($set), [$iid]));

            $partes = [];
            foreach ($set as $col => $v) {
                $partes[] = sprintf('%s %s → %s', $etiquetas[$col], money_cur((float) ($inv[$col] ?? 0), $cur), money_cur($v, $cur));
            }
            $hechos[] = ['tipo' => 'cifras', 'linea' => $pid, 'invoice_id' => $iid, 'receipt' => '', 'amount' => 0.0,
                         'texto' => $doc . ': ' . implode(', ', $partes)];
            $tocada = true;
        }

        /* ---- Abono anterior ------------------------------------------------- */
        $abono = round(amount_parse($c['abono'] ?? 0), 2);
        if ($abono < 0) {
            throw new RuntimeException(sprintf('En %s el abono anterior no puede ser negativo. Para subir el saldo antes de este pago, corrige o anula los recibos anteriores de esa factura.', $doc));
        }
        if ($abono > 0.009) {
            $fecha = valid_date(is_string($c['abono_fecha'] ?? null) ? $c['abono_fecha'] : null);
            if ($fecha === null) {
                throw new RuntimeException(sprintf('Indica la fecha en que el cliente hizo el abono anterior de %s.', $doc));
            }
            // «Anterior» de verdad: el recibo calcula su saldo por fecha, y un
            // abono del mismo día o posterior no bajaría lo que muestra.
            if ($fecha >= $paidAt) {
                throw new RuntimeException(sprintf(
                    'El abono anterior tiene que ser de antes de este recibo (%s). Si el cliente pagó ese mismo día o después, regístralo con «Registrar pago».',
                    date_es($paidAt)
                ));
            }
            $metodo = mb_substr(trim((string) (is_scalar($c['abono_metodo'] ?? null) ? $c['abono_metodo'] : '')), 0, 40);
            $ref = mb_substr(trim((string) (is_scalar($c['abono_ref'] ?? null) ? $c['abono_ref'] : '')), 0, 120);
            $nota = mb_substr('Abono anterior, registrado al corregir ' . ($group['receipt'] ?: 'el cobro #' . $pid), 0, 255);
            $user = current_user() ?? [];

            $numero = '';
            if (column_exists('invoice_payments', 'receipt_number')) {
                $numero = reserve_receipt_number($pdo);
                $pdo->prepare('INSERT INTO invoice_payments (receipt_number, invoice_id, amount, method, reference, paid_at, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([$numero, $iid, $abono, $metodo, $ref, $fecha, $nota, ($user['id'] ?? 0) ?: null]);
            } else {
                $pdo->prepare('INSERT INTO invoice_payments (invoice_id, amount, method, reference, paid_at, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([$iid, $abono, $metodo, $ref, $fecha, $nota, ($user['id'] ?? 0) ?: null]);
            }
            $hechos[] = ['tipo' => 'abono', 'linea' => $pid, 'invoice_id' => $iid, 'receipt' => $numero, 'amount' => $abono,
                         'texto' => sprintf('%s: abono anterior de %s del %s%s', $doc, money_cur($abono, $cur), date_es($fecha), $numero !== '' ? ' (' . $numero . ')' : '')];
            $tocada = true;
        }

        if ($tocada) {
            if ($set && column_exists('invoices', 'credited_amount') && column_exists('invoices', 'modifies_invoice_id')) {
                invoice_recalc_credited($iid);
            }
            $fresca = invoice_recalc_paid($pdo, $iid);
            if ($fresca) {
                $invoices[$iid] = $fresca;
            }
        }
    }
    return $hechos;
}

/**
 * Anula un recibo entero: quita sus cobros de todas las facturas que cubría,
 * recalcula sus saldos y guarda la foto del recibo en el historial.
 *
 * El número NO se reutiliza: el contador solo avanza, así que un recibo
 * anulado nunca reaparece con otro importe bajo el mismo número.
 */
function receipt_void(int $paymentId, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return [false, 'Indica por qué se anula el recibo: queda en el historial.'];
    }
    $pdo = db();
    try {
        $res = cobros_con_reintento(static function () use ($pdo, $paymentId, $reason) {
            $pdo->beginTransaction();
            try {
                [$group, $invoices] = receipt_lock_group($pdo, $paymentId);
                if (!$group) {
                    $pdo->rollBack();
                    return ['estado' => 'no_existe'];
                }
                $before = $group['rows'];
                foreach ($before as $row) {
                    $pdo->prepare('DELETE FROM invoice_payments WHERE id=?')->execute([(int) $row['id']]);
                }
                foreach (array_keys($invoices) as $iid) {
                    invoice_recalc_paid($pdo, (int) $iid);
                }
                payment_log_write($pdo, 'anulado', $group['receipt'], $before, null, $reason);
                $pdo->commit();
                return ['estado' => 'ok', 'group' => $group, 'before' => $before, 'facturas' => count(array_unique(array_column($before, 'invoice_id')))];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
        });
    } catch (Throwable $e) {
        error_log('receipt_void: ' . $e->getMessage());
        return [false, 'No se pudo anular el recibo. No se guardó ningún cambio.'];
    }

    if ($res['estado'] === 'no_existe') {
        return [false, 'Ese recibo ya no existe: alguien lo anuló antes.'];
    }
    $before = $res['before'];
    $total = array_sum(array_map(static fn ($r) => (float) $r['amount'], $before));
    $cur = (string) ($before[0]['currency'] ?? 'DOP');
    foreach ($before as $row) {
        log_activity('invoice', (int) $row['invoice_id'], 'recibo_anulado', ($res['group']['receipt'] ?: 'cobro #' . $paymentId) . ' · ' . $reason);
    }
    $n = (int) $res['facturas'];
    $esAnticipo = function_exists('anticipo_by_receipt') && ($res['group']['receipt'] ?? '') !== '' && anticipo_by_receipt((string) $res['group']['receipt']);
    return [true, 'Recibo ' . ($res['group']['receipt'] ?: '') . ' anulado. Se devolvieron ' . money_cur($total, $cur) . ' al saldo de ' . $n . ' factura' . ($n === 1 ? '' : 's') . '.'
        . ($esAnticipo ? ' Era un anticipo: el dinero vuelve a quedar pendiente de aplicar en su cotización. Si el cobro no ocurrió, anula también el anticipo allá.' : '')];
}

/**
 * Bloquea un recibo para modificarlo y devuelve [grupo, facturas] ya
 * VERIFICADOS, o [null, []] si el recibo no existe.
 *
 * Dos pasos, y el orden importa:
 *   1. Una lectura normal solo para saber QUÉ facturas bloquear. Puede estar
 *      vieja: únicamente orienta.
 *   2. Con las facturas bloqueadas, se relee el recibo CON bloqueo. Esa es la
 *      verdad. Si otra persona lo anuló mientras esperábamos, aquí ya no está,
 *      y no se escribe nada — antes, siete anulaciones del mismo recibo daban
 *      «éxito» y dejaban siete entradas falsas en el historial.
 */
function receipt_lock_group(PDO $pdo, int $paymentId): array
{
    $pista = receipt_group($paymentId, $pdo);
    if (!$pista['rows']) {
        return [null, []];
    }
    $invoices = receipt_lock_invoices($pdo, array_column($pista['rows'], 'invoice_id'));
    $group = receipt_group($paymentId, $pdo, true);
    if (!$group['rows']) {
        return [null, []];
    }
    // Si el recibo cubre ahora alguna factura que no se bloqueó, se bloquea.
    $faltan = array_diff(array_map('intval', array_column($group['rows'], 'invoice_id')), array_keys($invoices));
    if ($faltan) {
        $invoices += receipt_lock_invoices($pdo, $faltan);
    }
    // Las facturas se releen con bloqueo: el saldo que decide el tope tiene que
    // ser el confirmado, no el de la foto de la transacción.
    foreach (array_keys($invoices) as $iid) {
        $invoices[$iid] = $pdo->query('SELECT * FROM invoices WHERE id=' . (int) $iid . ' FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
    }
    return [$group, $invoices];
}

/**
 * Si MySQL elige la operación como víctima de un interbloqueo, se reintenta.
 * Pasa cuando un cobro nuevo y una anulación tocan la misma factura en el mismo
 * instante: se bloquean en orden inverso y la base mata a una. No hay nada roto
 * —se deshizo entera—, así que lo correcto es volver a intentarlo, no mostrar
 * un error a quien no hizo nada mal.
 */
function cobros_con_reintento(callable $fn, int $intentos = 4)
{
    for ($i = 1; ; $i++) {
        try {
            return $fn();
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            $reintentable = $e->getCode() === '40001'
                || str_contains($msg, '1213')      // deadlock
                || str_contains($msg, '1205');     // lock wait timeout
            if (!$reintentable || $i >= $intentos) {
                throw $e;
            }
            usleep(random_int(15000, 60000) * $i);
        }
    }
}

/** Recibos anulados o corregidos que tocaron una factura, más recientes primero. */
function receipt_history_for_invoice(int $invoiceId): array
{
    if (!payment_log_available() || $invoiceId <= 0) {
        return [];
    }
    return fetch_all(
        'SELECT * FROM invoice_payment_log WHERE invoice_ids LIKE ? ORDER BY created_at DESC, id DESC LIMIT 50',
        ['%,' . $invoiceId . ',%']
    );
}

/* =========================================================================
   Plan de cuotas
   ========================================================================= */

function installment_frequencies(): array
{
    return ['mensual' => 'Mensual', 'quincenal' => 'Quincenal', 'semanal' => 'Semanal'];
}

/** Las facturas a las que tiene sentido pactarles cuotas: las que se deben. */
function invoice_can_have_plan(array $inv): bool
{
    return (string) ($inv['status'] ?? '') === 'Emitida'
        && !invoice_is_proforma($inv)
        && !invoice_is_credit_note($inv)
        && invoice_balance($inv) > 0.009;
}

function installments_for(int $invoiceId): array
{
    if (!installments_available() || $invoiceId <= 0) {
        return [];
    }
    return fetch_all('SELECT * FROM invoice_installments WHERE invoice_id=? ORDER BY seq ASC', [$invoiceId]);
}

/**
 * Estado de cada cuota.
 *
 * Lo abonado desde que se pactó el plan se aplica a las cuotas EN ORDEN: la
 * primera se cubre antes que la segunda. Así una cuota se sabe pagada sin que
 * nadie tenga que decir a qué cuota iba cada recibo.
 *
 * Devuelve ['rows' => [... + covered, state, label, tone], 'total', 'progress',
 *           'next' => fila|null, 'overdue' => n, 'drift' => bool]
 */
function installments_status(array $inv, array $rows): array
{
    $base = (float) ($inv['installment_base'] ?? 0);
    $progress = max(0.0, round((float) ($inv['amount_paid'] ?? 0) - $base, 2));
    $today = date('Y-m-d');
    $acum = 0.0;
    $total = 0.0;
    $next = null;
    $overdue = 0;

    /* Un plan solo vive mientras la factura se debe. Antes, una factura ANULADA
       seguía alarmando «2 cuotas vencidas», y una que una nota de crédito dejó en
       cero también — con el botón de rehacer oculto, porque ya no hay saldo: una
       alarma que nadie podía resolver. */
    $anulada = (string) ($inv['status'] ?? '') === 'Anulada';
    $saldada = !$anulada && invoice_balance($inv) <= 0.009;
    $cierre = $anulada ? 'anulada' : ($saldada ? 'saldada' : '');

    foreach ($rows as &$r) {
        $amount = (float) $r['amount'];
        $covered = round(max(0.0, min($amount, $progress - $acum)), 2);
        $acum += $amount;
        $total += $amount;

        if ($cierre !== '' && $covered + 0.009 < $amount) {
            // Lo que no se cubrió con cobros ya no se va a cobrar.
            [$state, $label, $tone] = $anulada
                ? ['anulada', 'Sin efecto', 'cerrado']
                : ['saldada', 'Saldada por crédito', 'ok'];
            $r['covered'] = $covered;
            $r['pending'] = 0.0;
            $r['state'] = $state;
            $r['label'] = $label;
            $r['tone'] = $tone;
            continue;
        }

        if ($covered + 0.009 >= $amount) {
            [$state, $label, $tone] = ['pagada', 'Pagada', 'ok'];
        } elseif ((string) $r['due_date'] < $today) {
            [$state, $label, $tone] = ['vencida', $covered > 0.009 ? 'Vencida · abono parcial' : 'Vencida', 'alarma'];
            $overdue++;
        } elseif ($covered > 0.009) {
            [$state, $label, $tone] = ['parcial', 'Abono parcial', 'espera'];
        } else {
            [$state, $label, $tone] = ['pendiente', 'Pendiente', 'curso'];
        }
        $r['covered'] = $covered;
        $r['pending'] = round($amount - $covered, 2);
        $r['state'] = $state;
        $r['label'] = $label;
        $r['tone'] = $tone;
        if ($next === null && $state !== 'pagada') {
            $next = $r;
        }
    }
    unset($r);

    /* El plan reparte lo que faltaba al pactarlo. Si después cambió el total
       exigible —una nota de crédito, o se anuló un recibo anterior al plan—
       las cuotas ya no suman lo que se debe, y hay que decirlo. */
    $esperado = round(invoice_net($inv) - $base, 2);
    // Un plan cerrado no «descuadra»: que la factura ya no se deba es justo
    // la razón de que las cuotas no sumen lo pendiente.
    $drift = $cierre === '' && $rows && abs(round($total, 2) - $esperado) > 0.01;

    return [
        'rows' => $rows,
        'total' => round($total, 2),
        'progress' => $progress,
        'next' => $cierre === '' ? $next : null,
        'overdue' => $cierre === '' ? $overdue : 0,
        'drift' => $drift,
        'expected' => $esperado,
        'closed' => $cierre,
    ];
}

/**
 * Propuesta de cuotas iguales. El redondeo va a la ÚLTIMA cuota, para que la
 * suma sea exacta al centavo: tres cuotas de 100.00 no pagan 300.01.
 */
function installments_propose(float $balance, int $n, string $firstDue, string $frequency): array
{
    $n = max(2, min(36, $n));
    $cuota = floor(($balance / $n) * 100) / 100;
    $rows = [];
    $fecha = valid_date($firstDue) ?? date('Y-m-d', strtotime('+30 days'));
    for ($i = 1; $i <= $n; $i++) {
        $amount = $i === $n ? round($balance - $cuota * ($n - 1), 2) : $cuota;
        $rows[] = ['seq' => $i, 'due_date' => $fecha, 'amount' => $amount];
        $fecha = match ($frequency) {
            'quincenal' => date('Y-m-d', strtotime($fecha . ' +15 days')),
            'semanal' => date('Y-m-d', strtotime($fecha . ' +7 days')),
            default => installments_add_month($fecha),
        };
    }
    return $rows;
}

/** Un mes después sin saltarse meses: el 31 de enero pasa al 28/29 de febrero,
 *  no al 3 de marzo como hace strtotime('+1 month'). */
function installments_add_month(string $date): string
{
    $d = new DateTime($date);
    $dia = (int) $d->format('d');
    $d->modify('first day of next month');
    $ultimo = (int) $d->format('t');
    $d->setDate((int) $d->format('Y'), (int) $d->format('m'), min($dia, $ultimo));
    return $d->format('Y-m-d');
}

/**
 * Guarda el plan de una factura (reemplaza el que hubiera).
 * Las cuotas deben sumar EXACTAMENTE lo que se debe hoy, y vencer en orden.
 */
function installments_save(int $invoiceId, array $dates, array $amounts): array
{
    if (!installments_available()) {
        return [false, 'El plan de cuotas no está disponible todavía en esta base de datos.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $inv = $pdo->query('SELECT * FROM invoices WHERE id=' . $invoiceId . ' FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            throw new RuntimeException('La factura ya no existe.');
        }
        if (!invoice_can_have_plan($inv)) {
            throw new RuntimeException('Solo una factura emitida y con saldo pendiente admite un plan de cuotas.');
        }
        $balance = invoice_balance($inv);
        $cur = (string) $inv['currency'];

        $rows = [];
        $prev = '';
        $dates = array_values($dates);
        $amounts = array_values($amounts);
        $n = count($dates);
        if ($n < 2 || $n > 36 || $n !== count($amounts)) {
            throw new RuntimeException('Un plan lleva entre 2 y 36 cuotas.');
        }
        for ($i = 0; $i < $n; $i++) {
            $d = valid_date((string) $dates[$i]);
            if ($d === null) {
                throw new RuntimeException('La cuota ' . ($i + 1) . ' no tiene una fecha válida.');
            }
            if ($prev !== '' && $d <= $prev) {
                throw new RuntimeException('Las fechas tienen que ir en orden: la cuota ' . ($i + 1) . ' vence antes o el mismo día que la anterior.');
            }
            $a = round(amount_parse($amounts[$i]), 2);
            if ($a <= 0.009) {
                throw new RuntimeException('La cuota ' . ($i + 1) . ' no tiene importe.');
            }
            $rows[] = ['due' => $d, 'amount' => $a];
            $prev = $d;
        }
        $suma = round(array_sum(array_column($rows, 'amount')), 2);
        if (abs($suma - $balance) > 0.01) {
            throw new RuntimeException(sprintf(
                'Las cuotas suman %s y lo que se debe es %s. La diferencia (%s) tiene que ir en alguna cuota.',
                money_cur($suma, $cur), money_cur($balance, $cur), money_cur(abs($suma - $balance), $cur)
            ));
        }

        $pdo->prepare('DELETE FROM invoice_installments WHERE invoice_id=?')->execute([$invoiceId]);
        $acum = 0.0;
        $ins = $pdo->prepare('INSERT INTO invoice_installments (invoice_id, seq, due_date, amount, cumulative, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        foreach ($rows as $i => $r) {
            $acum = round($acum + $r['amount'], 2);
            $ins->execute([$invoiceId, $i + 1, $r['due'], $r['amount'], $acum]);
        }
        $pdo->prepare('UPDATE invoices SET installment_base=?, updated_at=NOW() WHERE id=?')
            ->execute([round((float) $inv['amount_paid'], 2), $invoiceId]);
        $pdo->commit();
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return [false, $e->getMessage()];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('installments_save: ' . $e->getMessage());
        return [false, 'No se pudo guardar el plan. No se guardó ningún cambio.'];
    }

    log_activity('invoice', $invoiceId, 'plan_cuotas', $n . ' cuotas · ' . money_cur($suma, $cur) . ' · primera ' . date_es($rows[0]['due']));
    return [true, 'Plan de ' . $n . ' cuotas guardado. La primera vence el ' . date_es($rows[0]['due']) . '.'];
}

function installments_delete(int $invoiceId): array
{
    if (!installments_available()) {
        return [false, 'El plan de cuotas no está disponible.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM invoices WHERE id=' . $invoiceId . ' FOR UPDATE')->fetch();
        $pdo->prepare('DELETE FROM invoice_installments WHERE invoice_id=?')->execute([$invoiceId]);
        $pdo->prepare('UPDATE invoices SET installment_base=NULL, updated_at=NOW() WHERE id=?')->execute([$invoiceId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('installments_delete: ' . $e->getMessage());
        return [false, 'No se pudo quitar el plan.'];
    }
    log_activity('invoice', $invoiceId, 'plan_cuotas_quitado', null);
    return [true, 'Plan de cuotas quitado. La factura vuelve a vencer en su fecha original.'];
}

/**
 * Fecha que manda para el cobro de una factura CON plan: la de la cuota más
 * antigua que no está cubierta. null si no hay plan o ya está todo cubierto.
 */
function installments_effective_due(array $inv): ?string
{
    return installments_summary($inv)['actual']['due_date'] ?? null;
}

/**
 * El plan de una factura resumido para listados y documentos, o null si no
 * tiene uno vigente (no hay plan, o se cerró porque la factura ya no se debe).
 *
 *   'cuotas'   cuántas son          'pagadas'  cuántas están cubiertas
 *   'vencidas' cuántas pasaron su fecha sin cubrirse
 *   'vencido'  lo que falta de esas cuotas vencidas (en la moneda de la factura)
 *   'actual'   la cuota más antigua sin cubrir: de ella se cuenta el atraso
 *   'proxima'  la primera sin cubrir que todavía no vence
 *
 * Con caché por petición: se pide por cada fila de la cartera y del estado de
 * cuenta. La clave lleva todo lo que cambia el resultado —lo abonado, el estado,
 * el crédito—, no solo el id.
 */
function installments_summary(array $inv): ?array
{
    if (($inv['installment_base'] ?? null) === null || !installments_available()) {
        return null;
    }
    $id = (int) ($inv['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    static $cache = [];
    $clave = implode('|', [
        $id,
        (string) ($inv['amount_paid'] ?? ''),
        (string) $inv['installment_base'],
        (string) ($inv['status'] ?? ''),
        (string) ($inv['total'] ?? ''),
        (string) ($inv['credited_amount'] ?? ''),
    ]);
    if (array_key_exists($clave, $cache)) {
        return $cache[$clave];
    }

    $cache[$clave] = null;
    $rows = installments_for($id);
    if (!$rows) {
        return null;
    }
    $st = installments_status($inv, $rows);
    if ($st['closed'] !== '') {
        return null;
    }
    $pagadas = 0;
    $vencido = 0.0;
    $proxima = null;
    foreach ($st['rows'] as $r) {
        if ($r['state'] === 'pagada') {
            $pagadas++;
        } elseif ($r['state'] === 'vencida') {
            $vencido += (float) $r['pending'];
        } elseif ($proxima === null) {
            $proxima = $r;
        }
    }
    return $cache[$clave] = [
        'cuotas' => count($st['rows']),
        'pagadas' => $pagadas,
        'vencidas' => (int) $st['overdue'],
        'vencido' => round($vencido, 2),
        'actual' => $st['next'],
        'proxima' => $proxima,
    ];
}
