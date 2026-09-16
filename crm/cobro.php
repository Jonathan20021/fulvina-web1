<?php
/**
 * Registrar un cobro del cliente y repartirlo entre sus comprobantes.
 *
 * El caso real: el hospital manda UNA transferencia por cinco facturas. Antes
 * había que abrir factura por factura y adivinar cuánto tocaba a cada una.
 * Aquí se captura el cobro completo, se reparte (automático por antigüedad o a
 * mano) y sale un solo recibo de ingreso que las cubre todas.
 *
 *   ?client=ID            → pantalla de reparto para ese cliente.
 *   ?client=ID&moneda=USD → los comprobantes de esa moneda.
 *   ?client=ID&recibo=... → además, el panel del recibo recién generado.
 *
 * Un cobro nunca cruza monedas: no se puede repartir una transferencia en pesos
 * entre facturas en dólares sin inventar una tasa. Cada moneda va por separado.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('facturas.edit');
verify_csrf();

$hasDb = db(false) && table_exists('invoices');
if ($hasDb) {
    ensure_invoice_schema();
}

$clientId = (int) ($_POST['client_id'] ?? $_GET['client'] ?? 0);
$currency = strtoupper((string) ($_POST['currency'] ?? $_GET['moneda'] ?? '')) === 'USD' ? 'USD' : 'DOP';

/* ---- Registrar el cobro -------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb && ($_POST['form'] ?? '') === 'cobro') {
    $back = 'crm/cobro.php?client=' . $clientId . '&moneda=' . $currency;
    $received = amount_parse($_POST['amount'] ?? 0);
    $method = trim((string) ($_POST['method'] ?? ''));
    $reference = trim((string) ($_POST['reference'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));
    $paidAt = invoice_valid_date((string) ($_POST['paid_at'] ?? '')) ?? date('Y-m-d');
    $alloc = (array) ($_POST['alloc'] ?? []);

    $cartera = client_receivables($clientId);
    if (!$cartera['client']) {
        flash('warning', 'El cliente no existe.');
        redirect('crm/cobro.php');
    }

    // Saldo real de cada comprobante, leído del servidor: lo que venga del
    // formulario solo dice CUÁNTO aplicar, nunca cuánto se debe.
    $balances = [];
    foreach ($cartera['rows'] as $r) {
        if ((string) $r['currency'] === $currency) {
            $balances[(int) $r['id']] = (float) $r['balance'];
        }
    }

    $applied = [];
    $sum = 0.0;
    $errors = [];
    foreach ($alloc as $invId => $raw) {
        $invId = (int) $invId;
        $amt = amount_parse($raw);
        if ($amt <= 0.009) {
            continue;
        }
        if (!isset($balances[$invId])) {
            $errors[] = 'Un comprobante del reparto ya no tiene saldo o cambió de moneda. Vuelve a cargar la pantalla.';
            continue;
        }
        if ($amt > $balances[$invId] + 0.009) {
            $errors[] = sprintf('No puedes aplicar %s a un comprobante cuyo saldo es %s.', money_cur($amt, $currency), money_cur($balances[$invId], $currency));
            continue;
        }
        $applied[$invId] = round($amt, 2);
        $sum += $amt;
    }
    $sum = round($sum, 2);

    if ($received <= 0.009) {
        $errors[] = 'Indica el monto recibido.';
    }
    // Solo si no hubo un problema más concreto: cuando una asignación se
    // descarta por exceder el saldo, decir además «asigna al menos uno» confunde
    // en vez de ayudar — el usuario sí había asignado algo.
    if (!$applied && !$errors) {
        $errors[] = 'Asigna el cobro a por lo menos un comprobante.';
    }
    // El reparto tiene que cuadrar con lo recibido: un descuadre silencioso es
    // exactamente el error que este módulo existe para evitar.
    if ($applied && abs($sum - $received) > 0.01) {
        $errors[] = sprintf(
            'El reparto suma %s y el monto recibido es %s. Ajusta uno de los dos: la diferencia es %s.',
            money_cur($sum, $currency),
            money_cur($received, $currency),
            money_cur(abs($sum - $received), $currency)
        );
    }

    if ($errors) {
        flash('warning', implode(' ', array_unique($errors)));
        redirect($back);
    }

    $hasReceiptCol = column_exists('invoice_payments', 'receipt_number');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Dentro de la transacción: la reserva bloquea el contador y dos
        // cajeros simultáneos se llevan números distintos.
        $receiptNo = reserve_receipt_number($pdo);
        foreach ($applied as $invId => $amt) {
            if ($hasReceiptCol) {
                $pdo->prepare('INSERT INTO invoice_payments (receipt_number, invoice_id, amount, method, reference, paid_at, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([$receiptNo, $invId, $amt, $method, $reference, $paidAt, $note, current_user()['id'] ?? null]);
            } else {
                $pdo->prepare('INSERT INTO invoice_payments (invoice_id, amount, method, reference, paid_at, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([$invId, $amt, $method, $reference, $paidAt, $note, current_user()['id'] ?? null]);
            }
            /*
             * FOR UPDATE, y no un fetch_one suelto: el saldo que validamos
             * arriba es el que tenía la pantalla al pintarse, y entre eso y
             * este momento otro cajero pudo cobrar la misma factura. Sin el
             * bloqueo, dos cobros de RD$40,000 sobre una factura de
             * RD$40,000 entraban los dos y dejaban amount_paid en 80,000.
             */
            $inv = $pdo->query('SELECT * FROM invoices WHERE id=' . (int) $invId . ' FOR UPDATE')->fetch();
            $saldoReal = invoice_balance($inv);
            if ($amt > $saldoReal + 0.009) {
                throw new RuntimeException(sprintf(
                    'Mientras llenabas el cobro, otra persona aplicó pagos a %s. Su saldo ahora es %s y no admite %s. Vuelve a cargar la pantalla.',
                    (string) $inv['invoice_number'], money_cur($saldoReal, $currency), money_cur($amt, $currency)
                ));
            }
            $newPaid = round((float) $inv['amount_paid'] + $amt, 2);
            if ($newPaid + 0.009 >= invoice_net($inv)) {
                $pdo->prepare('UPDATE invoices SET amount_paid=?, status=?, paid_at=COALESCE(paid_at, NOW()), updated_at=NOW() WHERE id=?')->execute([$newPaid, 'Pagada', $invId]);
            } else {
                $pdo->prepare('UPDATE invoices SET amount_paid=?, updated_at=NOW() WHERE id=?')->execute([$newPaid, $invId]);
            }
        }
        $pdo->commit();
        log_activity('client', $clientId, 'cobro_registrado', $receiptNo . ' · ' . money_cur($sum, $currency) . ' en ' . count($applied) . ' comprobante(s)');
        flash('success', 'Cobro de ' . money_cur($sum, $currency) . ' repartido entre ' . count($applied) . ' comprobante' . (count($applied) === 1 ? '' : 's') . '. Recibo ' . $receiptNo . '.');
        redirect($back . '&recibo=' . rawurlencode($receiptNo));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('cobro: ' . $e->getMessage());
        /* Un RuntimeException aquí lo lanzamos nosotros a propósito y explica
           exactamente qué pasó —normalmente que otra persona cobró la misma
           factura mientras tanto—. Decir «inténtalo de nuevo» sin más obliga a
           adivinar; el mensaje concreto dice qué recargar y por qué. */
        $suyo = $e instanceof RuntimeException;
        flash('error', $suyo ? $e->getMessage() : 'No se pudo registrar el cobro. Inténtalo de nuevo.');
        redirect($back);
    }
}

/* ---- Datos de pantalla --------------------------------------------------- */
$clientsWithBalance = $hasDb ? receivables_clients() : [];
$cartera = ($hasDb && $clientId > 0) ? client_receivables($clientId) : null;
$client = $cartera['client'] ?? null;

// Si el cliente solo debe en una moneda, se abre en esa sin preguntar.
$byCurrency = $cartera['by_currency'] ?? [];
if ($cartera && !isset($byCurrency[$currency]) && $byCurrency) {
    $currency = (string) array_key_first($byCurrency);
}
$rows = [];
foreach ($cartera['rows'] ?? [] as $r) {
    if ((string) $r['currency'] === $currency) {
        $rows[] = $r;
    }
}
$curTotal = array_sum(array_map(fn ($r) => (float) $r['balance'], $rows));

$justIssued = trim((string) ($_GET['recibo'] ?? ''));

// Payload para el reparto en vivo (Alpine): id y saldo de cada comprobante.
$allocRows = array_map(fn ($r) => [
    'id' => (int) $r['id'],
    'saldo' => round((float) $r['balance'], 2),
], $rows);

$crmTitle = $client ? 'Cobro · ' . (string) $client['name'] : 'Registrar cobro';
require_once __DIR__ . '/../includes/crm_header.php';
?>
<?= sch_encabezado('Cobranza', 'Un cobro repartido entre los comprobantes que salda') ?>


<section class="crm-cockpit">
    <div class="crm-cockpit__top">
        <div class="crm-cockpit__hero crm-cockpit__hero--sales">
            <?php if ($client): ?>
                <h2>Cobro de <?= e((string) $client['name']) ?>.</h2>
                <p>Captura la transferencia, el cheque o el efectivo una sola vez y repártelo entre los comprobantes que salda. Sale un recibo de ingreso único que los cubre todos.</p>
            <?php else: ?>
                <h2>Un solo cobro, repartido entre los comprobantes que salda.</h2>
                <p>Elige el cliente que pagó. Verás sus comprobantes con saldo y podrás repartir el monto recibido entre ellos, automáticamente por antigüedad o a mano.</p>
            <?php endif; ?>
            <div class="crm-cockpit__actions">
                <a href="<?= url('crm/facturas.php') ?>" class="crm-secondary-btn"><i data-lucide="receipt" class="h-4 w-4"></i>Facturación</a>
                <?php if ($client): ?>
                    <a href="<?= url('crm/cliente.php?id=' . (int) $client['id']) ?>" class="crm-secondary-btn"><i data-lucide="building-2" class="h-4 w-4"></i>Ficha del cliente</a>
                    <a href="<?= url('crm/cobro.php') ?>" class="crm-secondary-btn"><i data-lucide="users" class="h-4 w-4"></i>Otro cliente</a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($cartera): ?>
        <div class="crm-cockpit__metrics" aria-label="Cartera del cliente">
            <article><span>Saldo total</span><strong style="font-size:1.05rem"><?= money($cartera['total_dop']) ?></strong><small>equivalente en RD$</small></article>
            <article><span>Comprobantes</span><strong><?= e((string) $cartera['count']) ?></strong><small>con saldo</small></article>
            <article><span>Vencido</span><strong style="font-size:1.05rem"><?= money($cartera['overdue_dop']) ?></strong><small><?= e((string) $cartera['overdue_count']) ?> comprobantes</small></article>
            <article><span>Mayor atraso</span><strong><?= e((string) $cartera['max_days']) ?></strong><small>días</small></article>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$hasDb): ?>
        <div class="crm-empty"><i data-lucide="database" class="h-6 w-6"></i><strong>Sin base de datos</strong><p>Ejecuta <a href="<?= url('install.php') ?>">install.php</a> para trabajar con datos reales.</p></div>

    <?php elseif ($justIssued !== ''): ?>
        <article class="crm-card sch-caja sch-caja--ok">
            <div class="crm-card__head">
                <div>
                    <h2><i data-lucide="check-circle-2" class="cfg-ic"></i> Recibo <?= e($justIssued) ?> generado</h2>
                    <p>Entrégaselo al cliente. Cubre todos los comprobantes a los que se aplicó el cobro.</p>
                </div>
                <div class="crm-toolbar" style="gap:.5rem;padding:0">
                    <button type="button" class="crm-primary-btn" onclick="crmPdfPreviewOpen('<?= url('crm/recibo_pdf.php?receipt=' . rawurlencode($justIssued)) ?>','<?= url('crm/recibo_pdf.php?receipt=' . rawurlencode($justIssued) . '&download=1') ?>','<?= e(addslashes($justIssued)) ?>','Recibo de ingreso')"><i data-lucide="receipt-text" class="h-4 w-4"></i>Ver recibo</button>
                    <a class="crm-secondary-btn" href="<?= url('crm/recibo_pdf.php?receipt=' . rawurlencode($justIssued) . '&download=1') ?>"><i data-lucide="download" class="h-4 w-4"></i>Descargar</a>
                </div>
            </div>
        </article>
    <?php endif; ?>

    <?php if ($hasDb && !$client): ?>
        <article class="crm-data-surface">
            <div class="crm-data-surface__head"><div><h3>Clientes con saldo</h3><p>Ordenados por el que lleva más tiempo debiendo.</p></div></div>
            <?php if (!$clientsWithBalance): ?>
                <div class="crm-empty"><i data-lucide="check-circle-2" class="h-6 w-6"></i><strong>Nadie debe nada</strong><p>No hay comprobantes con saldo pendiente.</p></div>
            <?php else: ?>
                <div class="crm-table-wrap">
                    <table class="crm-table crm-data-table">
                        <thead><tr><th>Cliente</th><th class="text-right">Comprobantes</th><th>Mayor atraso</th><th class="text-right">Vencido</th><th class="text-right">Saldo</th><th class="text-right">Acción</th></tr></thead>
                        <tbody>
                        <?php foreach ($clientsWithBalance as $c): ?>
                            <tr>
                                <td>
                                    <a href="<?= url('crm/cliente.php?id=' . (int) $c['client_id']) ?>"><strong><?= e($c['name']) ?></strong></a>
                                    <?php if ($c['rnc'] !== ''): ?><p class="text-xs text-slate-500">RNC <?= e($c['rnc']) ?></p><?php endif; ?>
                                </td>
                                <td class="text-right"><?= e((string) $c['count']) ?></td>
                                <td><span class="inv-age-chip inv-age-chip--<?= $c['max_days'] > 60 ? 'bad' : ($c['max_days'] > 0 ? 'warn' : 'ok') ?>"><?= $c['max_days'] > 0 ? e((string) $c['max_days']) . ' d' : 'Al día' ?></span></td>
                                <td class="text-right"><?= $c['overdue_dop'] > 0 ? '<strong class="sch-monto--baja">' . money($c['overdue_dop']) . '</strong>' : '<span style="color:var(--muted)">—</span>' ?></td>
                                <td class="text-right"><strong><?= money($c['total_dop']) ?></strong></td>
                                <td class="text-right"><a class="crm-secondary-btn" href="<?= url('crm/cobro.php?client=' . (int) $c['client_id']) ?>"><i data-lucide="hand-coins" class="h-4 w-4"></i>Registrar cobro</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
    <?php endif; ?>

    <?php if ($hasDb && $client): ?>
        <?php if (count($byCurrency) > 1): ?>
            <div class="crm-toolbar" style="gap:.5rem;padding:0 0 .8rem">
                <span class="text-xs text-slate-500" style="align-self:center">Este cliente debe en más de una moneda. Un cobro no cruza monedas:</span>
                <?php foreach ($byCurrency as $c => $amt): ?>
                    <a class="<?= $currency === $c ? 'crm-primary-btn' : 'crm-secondary-btn' ?>" href="<?= url('crm/cobro.php?client=' . (int) $client['id'] . '&moneda=' . e($c)) ?>"><?= e($c) ?> · <?= e(money_cur($amt, (string) $c)) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$rows): ?>
            <div class="crm-empty"><i data-lucide="check-circle-2" class="h-6 w-6"></i><strong>Sin saldo en <?= e($currency) ?></strong><p>Este cliente no tiene comprobantes pendientes en esta moneda.</p></div>
        <?php else: ?>
        <form method="post" x-data="crmCobro(<?= e(json_encode(['rows' => $allocRows, 'saldoTotal' => round($curTotal, 2)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="cobro">
            <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
            <input type="hidden" name="currency" value="<?= e($currency) ?>">

            <article class="crm-card">
                <div class="crm-card__head"><div><h2><i data-lucide="wallet" class="cfg-ic"></i> El cobro recibido</h2><p>Los datos del depósito, cheque o efectivo. Van igual en todos los comprobantes que salde.</p></div></div>
                <div class="crm-form-grid">
                    <label class="crm-field"><span class="required">Monto recibido (<?= e($currency) ?>)</span>
                        <input type="text" name="amount" inputmode="decimal" class="crm-input" x-model="monto" @input="autoSiVacio()" placeholder="0.00" required>
                    </label>
                    <label class="crm-field"><span>Forma de pago</span>
                        <select name="method" class="crm-select">
                            <?php foreach (invoice_payment_methods() as $m): ?><option value="<?= e($m) ?>" <?= $m === 'Transferencia' ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="crm-field"><span>Referencia</span>
                        <input type="text" name="reference" class="crm-input" placeholder="Nº de transferencia, cheque o depósito">
                    </label>
                    <label class="crm-field"><span>Fecha del cobro</span>
                        <input type="date" name="paid_at" value="<?= e(date('Y-m-d')) ?>" class="crm-input">
                    </label>
                </div>
                <label class="crm-field" style="margin-top:.6rem"><span>Observación</span>
                    <input type="text" name="note" class="crm-input" placeholder="Opcional, queda en el recibo">
                </label>
            </article>

            <article class="crm-data-surface" style="margin-top:1rem">
                <div class="crm-data-surface__head">
                    <div><h3>Repartir entre comprobantes</h3><p><?= e((string) count($rows)) ?> con saldo en <?= e($currency) ?> · total <?= e(money_cur($curTotal, $currency)) ?></p></div>
                    <div class="crm-toolbar" style="gap:.5rem;padding:0">
                        <button type="button" class="crm-secondary-btn" @click="repartir()"><i data-lucide="wand-2" class="h-4 w-4"></i>Repartir por antigüedad</button>
                        <button type="button" class="crm-secondary-btn" @click="limpiar()"><i data-lucide="eraser" class="h-4 w-4"></i>Limpiar</button>
                    </div>
                </div>
                <div class="crm-table-wrap">
                    <table class="crm-table crm-data-table">
                        <thead><tr><th>Comprobante</th><th>Vencimiento</th><th>Antigüedad</th><th class="text-right">Saldo</th><th class="text-right" style="width:160px">A aplicar</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): $age = $r['aging']; ?>
                            <tr>
                                <td>
                                    <a href="<?= url('crm/facturas.php?action=view&id=' . (int) $r['id']) ?>"><strong><?= e((string) ($r['ncf'] ?: $r['invoice_number'])) ?></strong></a>
                                    <?php if (!empty($r['title'])): ?><p class="text-xs text-slate-500"><?= e(mb_strimwidth((string) $r['title'], 0, 48, '…')) ?></p><?php endif; ?>
                                </td>
                                <td><?= e(date_es($r['due_effective'] ?? null)) ?><?php if (!empty($r['plan']['actual'])): ?><p class="text-xs text-slate-500">cuota <?= (int) $r['plan']['actual']['seq'] ?> de <?= (int) $r['plan']['cuotas'] ?></p><?php endif; ?></td>
                                <td><span class="inv-age-chip inv-age-chip--<?= e((string) $age['tone']) ?>"><?= e((string) $age['label']) ?></span></td>
                                <td class="text-right"><strong><?= e(money_cur($r['balance'], $currency)) ?></strong></td>
                                <td class="text-right">
                                    <input type="text" inputmode="decimal" class="crm-input text-right"
                                           name="alloc[<?= (int) $r['id'] ?>]"
                                           x-model="alloc[<?= (int) $r['id'] ?>]"
                                           placeholder="0.00" style="max-width:150px">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div x-show="sobrepago()" x-cloak class="gas-aviso" style="margin:.6rem .2rem 0;line-height:1.6">
                    <b><i data-lucide="alert-triangle" style="width:15px;height:15px;vertical-align:-2px"></i> El cliente está pagando más de lo que debe en <?= e($currency) ?>.</b>
                    Su saldo total es <?= e(money_cur($curTotal, $currency)) ?> y el monto recibido lo supera en <strong x-text="fmt(num(monto) - cfgSaldo)"></strong>.
                    El CRM todavía no maneja anticipos, así que ese excedente no se puede aplicar aquí: registra solo <?= e(money_cur($curTotal, $currency)) ?> y deja constancia del resto por fuera, o corrige el monto si fue un error de tecleo.
                    <button type="button" class="crm-secondary-btn" style="margin-top:.5rem" @click="ajustarAlSaldo()"><i data-lucide="wand-2" class="h-4 w-4"></i>Ajustar al saldo (<?= e(money_cur($curTotal, $currency)) ?>)</button>
                </div>

                <div class="cobro-sum">
                    <div class="cobro-sum__cell"><span>Recibido</span><strong x-text="fmt(num(monto))">—</strong></div>
                    <div class="cobro-sum__cell"><span>Asignado</span><strong x-text="fmt(asignado())">—</strong></div>
                    <div class="cobro-sum__cell" :class="cuadra() ? 'is-ok' : 'is-bad'">
                        <span x-text="diferencia() > 0 ? 'Falta por asignar' : (diferencia() < 0 ? 'Asignado de más' : 'Diferencia')"></span>
                        <strong x-text="fmt(Math.abs(diferencia()))">—</strong>
                    </div>
                    <button type="submit" class="crm-primary-btn" :disabled="!cuadra()">
                        <i data-lucide="check" class="h-4 w-4"></i>Registrar cobro y generar recibo
                    </button>
                </div>
                <p class="text-xs text-slate-500" style="padding:0 .2rem .4rem;line-height:1.6">
                    <i data-lucide="info" style="width:13px;height:13px;vertical-align:-2px"></i>
                    El reparto tiene que cuadrar exactamente con el monto recibido. «Repartir por antigüedad» salda primero lo más viejo, que es lo que hace contabilidad a mano. El servidor vuelve a comprobar cada saldo antes de guardar.
                </p>
            </article>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</section>

<script>
function crmCobro(cfg) {
    return {
        rows: cfg.rows || [],
        cfgSaldo: cfg.saldoTotal || 0,
        monto: '',
        alloc: {},
        // El cliente manda más de lo que debe. No es un error del usuario: es una
        // situación real que este módulo no puede resolver todavía (no hay
        // anticipos), así que se dice en voz alta en vez de dejar el botón muerto.
        sobrepago() {
            return this.num(this.monto) - this.cfgSaldo > 0.011;
        },
        ajustarAlSaldo() {
            this.monto = this.cfgSaldo.toFixed(2);
            this.repartir();
        },
        num(v) {
            var n = parseFloat(String(v == null ? '' : v).replace(/[^0-9.-]/g, ''));
            return isNaN(n) ? 0 : n;
        },
        fmt(n) {
            return (Math.round(n * 100) / 100).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        asignado() {
            var t = 0, self = this;
            this.rows.forEach(function (r) { t += self.num(self.alloc[r.id]); });
            return Math.round(t * 100) / 100;
        },
        diferencia() {
            return Math.round((this.num(this.monto) - this.asignado()) * 100) / 100;
        },
        cuadra() {
            return this.num(this.monto) > 0 && Math.abs(this.diferencia()) < 0.011;
        },
        // Salda primero lo más viejo: las filas ya vienen ordenadas por vencimiento.
        repartir() {
            var resto = this.num(this.monto), self = this;
            this.rows.forEach(function (r) {
                var toma = Math.min(resto, r.saldo);
                toma = Math.round(toma * 100) / 100;
                self.alloc[r.id] = toma > 0.009 ? toma.toFixed(2) : '';
                resto = Math.round((resto - toma) * 100) / 100;
            });
        },
        limpiar() {
            var self = this;
            this.rows.forEach(function (r) { self.alloc[r.id] = ''; });
        },
        // Si escriben el monto y no hay nada asignado todavía, se reparte solo.
        autoSiVacio() {
            if (this.asignado() === 0) { this.repartir(); }
        },
    };
}
</script>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
