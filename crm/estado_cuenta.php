<?php
/**
 * Editar el estado de cuenta de un cliente.
 *
 * Lo que recibe el cliente, ajustado antes de enviarlo: qué comprobantes
 * incluye, el tono, a quién va dirigido, la carta, una nota destacada, las
 * formas de pago y la firma. Los MONTOS no se editan aquí: salen de las facturas
 * y los recibos (ver includes/estado_cuenta.php).
 *
 *   (sin cliente)       → clientes con saldo, para elegir cuál ajustar.
 *   ?client=ID          → el editor.
 *   ?client=ID&ver=1    → además abre la vista previa del PDF (tras guardar).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('facturas.edit');
verify_csrf();

$hasDb = db(false) && table_exists('invoices');
if ($hasDb) {
    ensure_invoice_schema();
    ensure_statement_schema();
}

$clientId = (int) ($_POST['client_id'] ?? $_GET['client'] ?? 0);
$back = 'crm/estado_cuenta.php?client=' . $clientId;

/* Si guardar falla, la pantalla se vuelve a pintar con lo que la persona
   escribió. Redirigir con un aviso, como hacen otras pantallas, le borraría la
   carta que acaba de redactar. */
$posted = null;
$formError = '';
$conflict = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb && $clientId > 0) {
    $form = (string) ($_POST['form'] ?? '');
    if ($form === 'reset') {
        if (statement_reset($clientId)) {
            flash('success', 'Listo: el estado de cuenta de este cliente vuelve al documento estándar, con todos sus comprobantes.');
        } else {
            flash('info', 'Este estado de cuenta ya usaba el documento estándar.');
        }
        redirect($back);
    }
    if ($form === 'save') {
        $posted = ['tone' => (string) ($_POST['tone'] ?? '')];
        foreach (array_keys(statement_fields()) as $k) {
            $posted[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
        }
        $posted['shown'] = array_map('intval', array_filter((array) ($_POST['shown'] ?? []), 'is_scalar'));
        $posted['include'] = array_map('intval', array_filter((array) ($_POST['include'] ?? []), 'is_scalar'));
        [$ok, $msg, $conflict] = statement_save($clientId, $posted, (int) ($_POST['version'] ?? 0));
        if ($ok) {
            flash('success', $msg);
            redirect($back . (($_POST['then'] ?? '') === 'ver' ? '&ver=1' : ''));
        }
        $formError = $msg;
    }
}

/* ---- Datos de pantalla --------------------------------------------------- */
$tones = reminder_tones();
$all = null;
$client = null;
$custom = null;
$clientsList = [];
$customs = [];

if ($hasDb && $clientId > 0) {
    $all = client_receivables($clientId);
    $client = $all['client'];
    if (!$client) {
        flash('warning', 'Ese cliente no existe. Elige uno de la lista.');
        redirect('crm/estado_cuenta.php');
    }
    $custom = statement_custom($clientId);
} elseif ($hasDb) {
    $clientsList = receivables_clients();
    $customs = statement_customs_all();
}

$rows = $all['rows'] ?? [];
$rowIds = array_map(fn ($r) => (int) $r['id'], $rows);

if ($posted !== null) {
    $excludedIds = array_values(array_intersect($rowIds, array_diff($posted['shown'], $posted['include'])));
    $toneValue = $posted['tone'];
} else {
    $excludedIds = array_values(array_intersect($rowIds, statement_excluded_ids($custom)));
    $toneValue = (string) ($custom['tone'] ?? '');
}
if ($toneValue !== '' && !isset($tones[$toneValue])) {
    $toneValue = '';
}

// El tono que rige hoy con lo incluido: la misma regla que el PDF.
$maxDays = 0;
foreach ($rows as $r) {
    if (!in_array((int) $r['id'], $excludedIds, true) && (float) $r['overdue'] > 0.009) {
        $maxDays = max($maxDays, (int) $r['aging']['days']);
    }
}
$appliedTone = $toneValue !== '' ? $toneValue : reminder_tone_for($maxDays);

$values = statement_texts($custom, $appliedTone);
if ($posted !== null) {
    foreach (array_keys(statement_fields()) as $k) {
        $values[$k] = str_replace(["\r\n", "\r"], "\n", $posted[$k]);
    }
}
$version = (int) ($custom['version'] ?? 0);
$fields = statement_fields();

$viewUrl = url('crm/recordatorio_pdf.php?client=' . $clientId);
$downloadUrl = url('crm/recordatorio_pdf.php?client=' . $clientId . '&download=1');
// Para onclick (atributo HTML) y para <script> (texto crudo) el escape es distinto.
$jsArgs = implode(', ', array_map(
    fn ($v) => json_encode((string) $v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE),
    [$viewUrl, $downloadUrl, (string) ($client['name'] ?? ''), 'Estado de cuenta']
));

$cfg = null;
if ($client && $rows) {
    $cfg = [
        'filas' => array_map(fn ($r) => [
            'id' => (string) (int) $r['id'],
            'dias' => (int) ($r['aging']['days'] ?? 0),
            'saldo' => round((float) $r['balance_dop'], 2),
            'vencido' => round((float) $r['overdue_dop'], 2),
        ], $rows),
        'incluidos' => array_values(array_map('strval', array_diff($rowIds, $excludedIds))),
        'tono' => $toneValue,
        'umbrales' => reminder_tone_thresholds(),
        'tonos' => array_map(fn ($t) => [
            'nombre' => $t['label'],
            'titulo' => $t['title'],
            'color' => $t['color'],
            'subject' => $t['subject'],
            'intro' => $t['intro'],
            'closing' => $t['close'],
        ], $tones),
        'estandar' => [
            'attention' => statement_default_attention(),
            'note' => '',
            'payment_info' => reminder_payment_info(),
            'contact' => reminder_contact(),
        ],
        'f' => $values,
        'maximos' => array_map(fn ($d) => $d[1], $fields),
        'datos' => ['cliente' => (string) $client['name'], 'empresa' => APP_LEGAL, 'fecha' => date_es(date('Y-m-d'))],
        'marcas' => array_keys(statement_marks()),
        // Tras un guardado fallido lo que hay en pantalla NO está guardado.
        'pendiente' => $posted !== null,
    ];
}

// El nombre del cliente ya encabeza la pantalla: repetirlo en el título la
// llenaba, en el teléfono, de tres líneas de lo mismo antes del contenido.
$crmTitle = 'Estado de cuenta';
require_once __DIR__ . '/../includes/crm_header.php';

/** Un campo de texto del editor, con su restablecer, su ayuda y su contador. */
$campo = static function (string $k, string $hint, string $reset, int $rows = 0) use ($fields, $values): void {
    [$label, $max] = $fields[$k];
    $marcas = statement_field_takes_marks($k);
    $id = 'edo-' . $k;
    $comun = 'id="' . $id . '" name="' . $k . '" maxlength="' . $max . '" x-model="f.' . $k . '"'
        . ' :aria-invalid="malas(\'' . $k . '\').length > 0"'
        . ' aria-describedby="' . $id . '-ayuda"'
        . ($marcas ? ' data-marcas @focus="campo = $el"' : '')
        . ($k !== 'attention' && $k !== 'note' ? ' @blur="rellenarSiVacio(\'' . $k . '\')"' : '');
    ?>
    <div class="edo-campo" :class="{ 'has-error': malas('<?= $k ?>').length }">
        <div class="edo-campo__cab">
            <label for="<?= $id ?>"><?= e($label) ?></label>
            <button type="button" class="edo-restablecer" x-show="!esEstandar('<?= $k ?>')" x-cloak @click="restablecer('<?= $k ?>')"><i data-lucide="rotate-ccw"></i><?= e($reset) ?></button>
        </div>
        <?php if ($rows > 0): ?>
            <textarea <?= $comun ?> rows="<?= $rows ?>" class="crm-textarea"><?= e($values[$k]) ?></textarea>
        <?php else: ?>
            <input type="text" <?= $comun ?> class="crm-input" value="<?= e($values[$k]) ?>">
        <?php endif; ?>
        <div class="edo-campo__pie">
            <span id="<?= $id ?>-ayuda" class="cfg-hint"><?= e($hint) ?></span>
            <span class="edo-contador" x-show="f.<?= $k ?>.length > <?= (int) floor($max * 0.8) ?>" x-cloak x-text="f.<?= $k ?>.length + ' de <?= $max ?>'"></span>
        </div>
        <p class="edo-error" x-show="malas('<?= $k ?>').length" x-cloak x-text="avisoMarcas('<?= $k ?>')"></p>
    </div>
    <?php
};
?>
<?= sch_encabezado('Estado de cuenta', 'Lo que recibe el cliente: qué comprobantes incluye, con qué tono y qué le dice') ?>

<?php if (!$hasDb): ?>
    <div class="crm-empty"><i data-lucide="database" class="h-6 w-6"></i><strong>Sin base de datos</strong><p>Ejecuta <a href="<?= url('install.php') ?>">install.php</a> para trabajar con datos reales.</p></div>

<?php elseif (!$client): ?>
    <article class="crm-data-surface">
        <div class="crm-data-surface__head">
            <div><h3>¿De qué cliente?</h3><p>Solo aparecen los clientes con comprobantes pendientes: a los demás no hay estado de cuenta que enviarles.</p></div>
            <a href="<?= url('crm/facturas.php') ?>" class="crm-secondary-btn"><i data-lucide="arrow-left" class="h-4 w-4"></i>Facturación</a>
        </div>
        <?php if (!$clientsList): ?>
            <div class="crm-empty"><i data-lucide="check-circle-2" class="h-6 w-6"></i><strong>Nadie debe nada</strong><p>No hay comprobantes con saldo pendiente.</p></div>
        <?php else: ?>
            <div class="crm-table-wrap">
                <table class="crm-table crm-data-table">
                    <thead><tr><th>Cliente</th><th class="text-right">Comprobantes</th><th class="text-right">Saldo</th><th class="text-right">Acción</th></tr></thead>
                    <tbody>
                    <?php foreach ($clientsList as $c): $ajustado = isset($customs[(int) $c['client_id']]); ?>
                        <tr>
                            <td>
                                <strong><?= e($c['name']) ?></strong>
                                <?php if ($ajustado): ?><span class="edo-chip">Ajustado</span><?php endif; ?>
                                <?php if ($c['rnc'] !== ''): ?><p class="text-xs text-slate-500">RNC <?= e($c['rnc']) ?></p><?php endif; ?>
                            </td>
                            <td class="text-right"><?= e((string) $c['count']) ?></td>
                            <td class="text-right"><strong><?= money($c['total_dop']) ?></strong></td>
                            <td class="text-right"><a class="crm-secondary-btn" href="<?= url('crm/estado_cuenta.php?client=' . (int) $c['client_id']) ?>"><i data-lucide="file-pen-line" class="h-4 w-4"></i>Editar estado de cuenta</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

<?php else: ?>
    <div class="edo-cab">
        <div class="edo-cab__id">
            <h2><?= e((string) $client['name']) ?></h2>
            <p>
                <?php if (!empty($client['rnc'])): ?>RNC <?= e((string) $client['rnc']) ?> · <?php endif; ?>
                <?= e((string) count($rows)) ?> comprobante<?= count($rows) === 1 ? '' : 's' ?> con saldo · <?= money($all['total_dop']) ?>
            </p>
        </div>
        <div class="edo-cab__acc">
            <a href="<?= url('crm/facturas.php') ?>" class="crm-secondary-btn"><i data-lucide="arrow-left" class="h-4 w-4"></i>Facturación</a>
            <a href="<?= url('crm/cliente.php?id=' . (int) $client['id']) ?>" class="crm-secondary-btn"><i data-lucide="building-2" class="h-4 w-4"></i>Ficha del cliente</a>
        </div>
    </div>

    <?php if ($formError !== ''): ?>
        <div class="gas-aviso gas-aviso--alarma edo-aviso" role="alert">
            <i data-lucide="octagon-alert" class="h-4 w-4"></i>
            <p><b><?= $conflict ? 'No se guardó para no borrar el trabajo de otra persona.' : 'No se guardó.' ?></b> <?= e($formError) ?></p>
        </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
        <div class="crm-empty"><i data-lucide="check-circle-2" class="h-6 w-6"></i><strong>Este cliente no debe nada</strong><p>No tiene comprobantes con saldo pendiente, así que no hay estado de cuenta que enviarle.</p></div>

    <?php else: ?>
        <?php if ($custom): ?>
            <div class="edo-ajustado">
                <p>
                    <i data-lucide="file-pen-line"></i>
                    <span>Este estado de cuenta está ajustado<?php if (!empty($custom['updated_by_name'])): ?> por <b><?= e((string) $custom['updated_by_name']) ?></b><?php endif; ?><?php if (!empty($custom['updated_at'])): ?> el <?= e(date_es((string) $custom['updated_at'])) ?><?php endif; ?>. Así sale siempre para este cliente, también en el lote.</span>
                </p>
                <form method="post" onsubmit="if (!confirm('¿Volver al documento estándar? Se quitan el tono, los textos, la nota y los comprobantes excluidos de este cliente.')) return false; window.edoSaliendo = true;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="reset">
                    <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
                    <button type="submit" class="crm-secondary-btn"><i data-lucide="rotate-ccw" class="h-4 w-4"></i>Volver al estándar</button>
                </form>
            </div>
        <?php endif; ?>

        <script type="application/json" id="edo-cfg"><?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>

        <form method="post" class="edo" x-data="estadoCuenta(JSON.parse(document.getElementById('edo-cfg').textContent))" @submit="alEnviar($event)">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="save">
            <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
            <input type="hidden" name="version" value="<?= $version ?>">
            <input type="hidden" name="then" :value="luego">

            <div class="edo-grid">
                <div class="edo-col">

                    <article class="crm-data-surface" aria-labelledby="edo-t-comp">
                        <div class="crm-data-surface__head">
                            <div>
                                <h3 id="edo-t-comp">Comprobantes</h3>
                                <p>Desmarca los que no quieras reclamar en este documento, por ejemplo uno en disputa o uno ya pagado que falta aplicar. No se borra nada: siguen en la cartera.</p>
                            </div>
                            <p class="edo-cuenta" aria-live="polite"><b x-text="incluidos.length"><?= count($rows) - count($excludedIds) ?></b> de <?= count($rows) ?> incluidos</p>
                        </div>
                        <div class="crm-table-wrap">
                            <table class="crm-table crm-data-table edo-tabla">
                                <thead><tr><th class="edo-tabla__chk"><span class="edo-sr">Incluir</span></th><th>Comprobante</th><th>Vence</th><th class="text-right">Saldo</th></tr></thead>
                                <tbody>
                                <?php foreach ($rows as $r):
                                    $rid = (int) $r['id'];
                                    $age = $r['aging'];
                                    $cur = (string) $r['currency'];
                                    $plan = $r['plan'] ?? null;
                                    $fuera = in_array($rid, $excludedIds, true);
                                    $days = (int) ($age['days'] ?? 0); ?>
                                    <tr class="<?= $fuera ? 'is-fuera' : '' ?>" :class="{ 'is-fuera': !incluye('<?= $rid ?>') }">
                                        <td class="edo-tabla__chk">
                                            <input type="hidden" name="shown[]" value="<?= $rid ?>">
                                            <input type="checkbox" name="include[]" value="<?= $rid ?>" id="edo-inc-<?= $rid ?>" x-model="incluidos" <?= $fuera ? '' : 'checked' ?>>
                                        </td>
                                        <td class="edo-tabla__comp">
                                            <label for="edo-inc-<?= $rid ?>" class="edo-tabla__doc">
                                                <strong><?= e((string) $r['invoice_number']) ?></strong>
                                                <?php if (!empty($r['ncf'])): ?><span class="inv-ncf-chip"><?= e((string) $r['ncf']) ?></span><?php endif; ?>
                                            </label>
                                            <?php if (!empty($r['title'])): ?><p class="edo-sub"><?= e(mb_strimwidth((string) $r['title'], 0, 70, '…')) ?></p><?php endif; ?>
                                            <?php if ($plan): ?>
                                                <p class="edo-sub">Plan de <?= (int) $plan['cuotas'] ?> cuotas<?php if ((int) $plan['pagadas'] > 0): ?> · <?= (int) $plan['pagadas'] ?> pagada<?= (int) $plan['pagadas'] === 1 ? '' : 's' ?><?php endif; ?><?php if (!empty($plan['proxima'])): ?> · próxima <?= e(money_cur($plan['proxima']['pending'], $cur)) ?> el <?= e(date_es((string) $plan['proxima']['due_date'])) ?><?php endif; ?></p>
                                            <?php endif; ?>
                                            <p class="edo-fuera" x-show="!incluye('<?= $rid ?>')" <?= $fuera ? '' : 'x-cloak' ?>>Fuera de este estado de cuenta</p>
                                        </td>
                                        <td class="edo-tabla__vence">
                                            <span class="ops-nowrap"><?= e(date_es($r['due_effective'] ?? null)) ?></span>
                                            <span class="inv-age-chip inv-age-chip--<?= e((string) $age['tone']) ?>"><?= $days > 0 ? e((string) $days) . ' d de atraso' : ($days === 0 ? 'Vence hoy' : 'Faltan ' . e((string) abs($days)) . ' d') ?></span>
                                            <?php if (!empty($plan['actual'])): ?><p class="edo-sub">cuota <?= (int) $plan['actual']['seq'] ?> de <?= (int) $plan['cuotas'] ?></p><?php endif; ?>
                                        </td>
                                        <td class="text-right edo-tabla__saldo">
                                            <strong><?= e(money_cur($r['balance'], $cur)) ?></strong>
                                            <?php if ((float) $r['overdue'] > 0.009 && (float) $r['overdue'] + 0.009 < (float) $r['balance']): ?><p class="edo-sub">vencido <?= e(money_cur($r['overdue'], $cur)) ?></p><?php endif; ?>
                                            <?php if ($cur === 'USD'): ?><p class="edo-sub"><?= money($r['balance_dop']) ?></p><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="edo-falta" x-show="!incluidos.length" x-cloak role="alert">Deja al menos un comprobante: un estado de cuenta vacío no se puede generar.</p>
                    </article>

                    <article class="crm-card" aria-labelledby="edo-t-tono">
                        <div class="crm-card__head"><div><h2 id="edo-t-tono">Tono</h2><p>Cambia el color, el título y la carta estándar. En automático se endurece solo a medida que crece el atraso.</p></div></div>
                        <div class="crm-card__body">
                            <div class="edo-tonos" role="radiogroup" aria-labelledby="edo-t-tono">
                                <label class="edo-tono <?= $toneValue === '' ? 'is-on' : '' ?>" :class="{ 'is-on': tono === '' }">
                                    <input type="radio" name="tone" value="" x-model="tono" <?= $toneValue === '' ? 'checked' : '' ?>>
                                    <span class="edo-tono__tx">
                                        <b><span class="edo-tono__punto" style="background: <?= e($tones[$appliedTone]['color']) ?>" :style="{ background: tonos[tonoAuto()].color }"></span>Automático</b>
                                        <small x-text="'Hoy: ' + tonos[tonoAuto()].nombre">Hoy: <?= e($tones[reminder_tone_for($maxDays)]['label']) ?></small>
                                    </span>
                                </label>
                                <?php foreach ($tones as $tk => $t): ?>
                                    <label class="edo-tono <?= $toneValue === $tk ? 'is-on' : '' ?>" :class="{ 'is-on': tono === '<?= e($tk) ?>' }">
                                        <input type="radio" name="tone" value="<?= e($tk) ?>" x-model="tono" <?= $toneValue === $tk ? 'checked' : '' ?>>
                                        <span class="edo-tono__tx">
                                            <b><span class="edo-tono__punto" style="background: <?= e($t['color']) ?>"></span><?= e($t['label']) ?></b>
                                            <small><?= e($t['kicker']) ?></small>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="edo-tono-aviso" x-show="tonoSinVencido()" x-cloak>
                                <i data-lucide="alert-triangle"></i>
                                <span>Nada de lo incluido está vencido y el tono <b x-text="tonos[tono] ? tonos[tono].nombre : ''"></b> habla de saldo vencido. Revisa que sea lo que quieres decirle al cliente.</span>
                            </p>
                        </div>
                    </article>

                    <article class="crm-card" aria-labelledby="edo-t-carta">
                        <div class="crm-card__head"><div><h2 id="edo-t-carta">Carta</h2><p>Lo que le dices al cliente. Los datos entre llaves se llenan solos al generar el PDF, con las cifras del día.</p></div></div>
                        <div class="crm-card__body edo-campos">
                            <div class="edo-marcas" role="group" aria-label="Insertar un dato del día en el texto">
                                <span>Insertar dato</span>
                                <?php foreach (statement_marks() as $mk => $mlabel): ?>
                                    <button type="button" class="edo-marca" @mousedown.prevent @click="insertar('<?= e($mk) ?>')" title="Inserta {<?= e($mk) ?>} donde está el cursor"><?= e($mlabel) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <?php
                            $campo('attention', 'A quién va dirigido dentro del cliente. Vacío: solo «Dirigido a».', 'Restablecer');
                            $campo('subject', 'Vacío: el asunto estándar del tono.', 'Texto estándar');
                            $campo('intro', 'El párrafo que abre el documento.', 'Texto estándar', 5);
                            $campo('note', 'Opcional. Sale en un recuadro debajo del detalle: un acuerdo de pago, una aclaración.', 'Quitar nota', 3);
                            $campo('closing', 'El párrafo antes de la firma.', 'Texto estándar', 4);
                            ?>
                        </div>
                    </article>

                    <article class="crm-card" aria-labelledby="edo-t-pago">
                        <div class="crm-card__head"><div><h2 id="edo-t-pago">Pago y firma</h2><p>Si para este cliente son otros. Si no los tocas, salen los de Configuración → Facturación.</p></div></div>
                        <div class="crm-card__body edo-campos">
                            <?php
                            $campo('payment_info', 'Cuentas bancarias e instrucciones de pago.', 'Usar los de Configuración', 5);
                            $campo('contact', 'Va debajo de la firma.', 'Usar el de Configuración');
                            ?>
                        </div>
                    </article>
                </div>

                <aside class="edo-vista" aria-labelledby="edo-t-vista">
                    <div class="edo-vista__cab">
                        <h3 id="edo-t-vista">Así lo lee el cliente</h3>
                        <p>Cambia mientras escribes, con los montos de hoy.</p>
                    </div>
                    <div class="edo-hoja" :style="{ '--tono': tonos[tonoAplicado()].color }">
                        <p class="edo-hoja__titulo"><strong x-text="tonos[tonoAplicado()].titulo"><?= e($tones[$appliedTone]['title']) ?></strong><span x-text="tonos[tonoAplicado()].nombre"><?= e($tones[$appliedTone]['label']) ?></span></p>
                        <p class="edo-hoja__dir">Dirigido a<span x-show="f.attention.trim() !== ''"> · Atención: <span x-text="f.attention"></span></span></p>
                        <p class="edo-hoja__cliente"><?= e((string) $client['name']) ?></p>
                        <p class="edo-hoja__asunto" x-text="llenar(texto('subject'))"></p>
                        <p class="edo-hoja__texto" x-text="llenar(texto('intro'))"></p>
                        <dl class="edo-hoja__cifras">
                            <div><dt>Saldo total</dt><dd x-text="dinero(total())"></dd></div>
                            <div><dt>Vencido</dt><dd x-text="dinero(vencido())"></dd></div>
                            <div><dt>Comprobantes</dt><dd x-text="incluidos.length"></dd></div>
                        </dl>
                        <div class="edo-hoja__nota" x-show="f.note.trim() !== ''" x-cloak><span>Nota</span><p x-text="llenar(f.note)"></p></div>
                        <p class="edo-hoja__sub">Formas de pago</p>
                        <p class="edo-hoja__texto edo-hoja__texto--chico" x-text="llenar(texto('payment_info'))"></p>
                        <p class="edo-hoja__texto" x-text="llenar(texto('closing'))"></p>
                        <p class="edo-hoja__firma"><b>Por <?= e(APP_LEGAL) ?></b><span x-text="texto('contact')"></span></p>
                    </div>
                </aside>
            </div>

            <div class="edo-barra">
                <p class="edo-barra__estado" aria-live="polite">
                    <span class="is-mal" x-show="bloqueo() !== ''" x-cloak x-text="bloqueo()"></span>
                    <span class="is-sucio" x-show="bloqueo() === '' && sucio()" x-cloak>Tienes cambios sin guardar</span>
                    <span x-show="bloqueo() === '' && !sucio()">Sin cambios pendientes</span>
                </p>
                <div class="edo-barra__btns">
                    <button type="button" class="crm-secondary-btn" onclick="crmPdfPreviewOpen(<?= e($jsArgs) ?>)">
                        <i data-lucide="eye" class="h-4 w-4"></i><span x-text="sucio() ? 'Ver PDF guardado' : 'Ver PDF'">Ver PDF</span>
                    </button>
                    <button type="submit" class="crm-secondary-btn" :disabled="bloqueo() !== ''" @click="luego = ''"><i data-lucide="save" class="h-4 w-4"></i>Guardar</button>
                    <button type="submit" name="then" value="ver" class="crm-primary-btn" :disabled="bloqueo() !== ''" @click="luego = 'ver'"><i data-lucide="file-check-2" class="h-4 w-4"></i>Guardar y ver PDF</button>
                </div>
            </div>
        </form>

        <?php if (isset($_GET['ver'])): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window.crmPdfPreviewOpen) {
                crmPdfPreviewOpen(<?= $jsArgs ?>);
            }
            // Que recargar la página no vuelva a abrir la vista previa.
            try { history.replaceState(null, '', location.pathname + '?client=<?= (int) $client['id'] ?>'); } catch (e) {}
        });
        </script>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<script>
function estadoCuenta(cfg) {
    var TONO_TEXTOS = ['subject', 'intro', 'closing'];
    return {
        filas: cfg.filas || [],
        tonos: cfg.tonos,
        estandar: cfg.estandar,
        datos: cfg.datos,
        marcas: cfg.marcas || [],
        umbrales: cfg.umbrales,
        maximos: cfg.maximos || {},
        incluidos: (cfg.incluidos || []).slice(),
        tono: cfg.tono || '',
        f: Object.assign({}, cfg.f),
        previo: '',
        campo: null,
        luego: '',
        enviando: false,
        inicial: '',

        init() {
            this.previo = this.tonoAplicado();
            // Si viene de un guardado fallido, lo de pantalla no está guardado:
            // la huella inicial vacía hace que cuente como cambio pendiente.
            this.inicial = cfg.pendiente ? '' : this.huella();
            this.$watch('tono', () => this.sincronizar());
            this.$watch('incluidos', () => this.sincronizar());
            window.addEventListener('beforeunload', (e) => {
                if (!this.enviando && !window.edoSaliendo && this.sucio()) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        },
        alEnviar(e) {
            if (this.bloqueo() !== '' || this.enviando) { e.preventDefault(); return; }
            this.enviando = true;
        },

        huella() {
            return JSON.stringify([this.tono, this.incluidos.slice().sort(), this.f]);
        },
        sucio() { return this.huella() !== this.inicial; },

        incluye(id) { return this.incluidos.indexOf(String(id)) !== -1; },
        deLoIncluido() { return this.filas.filter((r) => this.incluye(r.id)); },
        total() { return this.deLoIncluido().reduce((s, r) => s + r.saldo, 0); },
        vencido() { return this.deLoIncluido().reduce((s, r) => s + r.vencido, 0); },
        dias() { return this.deLoIncluido().reduce((m, r) => (r.vencido > 0.009 ? Math.max(m, r.dias) : m), 0); },

        // Misma regla que reminder_tone_for() en PHP, con los mismos umbrales.
        tonoAuto() {
            var d = this.dias();
            return d <= this.umbrales.cordial ? 'cordial' : (d <= this.umbrales.firme ? 'firme' : 'final');
        },
        tonoAplicado() { return this.tono && this.tonos[this.tono] ? this.tono : this.tonoAuto(); },
        tonoSinVencido() {
            return (this.tono === 'firme' || this.tono === 'final') && this.incluidos.length > 0 && this.vencido() < 0.01;
        },

        norm(s) { return String(s == null ? '' : s).replace(/\r\n?/g, '\n').trim(); },
        estandarDe(k) {
            if (TONO_TEXTOS.indexOf(k) !== -1) { return this.tonos[this.tonoAplicado()][k]; }
            return this.estandar[k] || '';
        },
        esEstandar(k) { return this.norm(this.f[k]) === this.norm(this.estandarDe(k)); },
        restablecer(k) { this.f[k] = this.estandarDe(k); },
        // Un texto vaciado vuelve al estándar a la vista, que es lo que imprimirá el PDF.
        rellenarSiVacio(k) { if (this.norm(this.f[k]) === '') { this.f[k] = this.estandarDe(k); } },
        texto(k) {
            if (k !== 'attention' && k !== 'note' && this.norm(this.f[k]) === '') { return this.estandarDe(k); }
            return this.f[k];
        },

        // Al cambiar de tono (o cambiar el atraso en automático), los textos que
        // nadie tocó pasan al estándar del tono nuevo; los escritos a mano se quedan.
        sincronizar() {
            var nuevo = this.tonoAplicado();
            if (nuevo === this.previo) { return; }
            var antes = this.tonos[this.previo];
            TONO_TEXTOS.forEach((k) => {
                if (this.norm(this.f[k]) === '' || this.norm(this.f[k]) === this.norm(antes[k])) {
                    this.f[k] = this.tonos[nuevo][k];
                }
            });
            this.previo = nuevo;
        },

        dinero(n) {
            return 'RD$ ' + (Math.round(n * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        llenar(s) {
            var d = {
                cliente: this.datos.cliente,
                empresa: this.datos.empresa,
                fecha: this.datos.fecha,
                total: this.dinero(this.total()),
                vencido: this.dinero(this.vencido()),
                dias: String(this.dias()),
                contacto: this.texto('contact'),
            };
            return String(s == null ? '' : s).replace(/\{([a-z]+)\}/g, function (m, k) {
                return Object.prototype.hasOwnProperty.call(d, k) ? d[k] : m;
            });
        },

        malas(k) {
            var hallado = String(this.f[k] || '').match(/\{[^{}\s]{1,40}\}/g) || [];
            if (k === 'attention' || k === 'contact') { return hallado; }
            return hallado.filter((m) => this.marcas.indexOf(m.slice(1, -1)) === -1);
        },
        avisoMarcas(k) {
            var m = this.malas(k);
            if (!m.length) { return ''; }
            if (k === 'attention' || k === 'contact') {
                return 'Aquí no se pueden insertar datos entre llaves: escribe el texto tal como debe salir.';
            }
            return m.join(', ') + (m.length === 1 ? ' no es un dato conocido' : ' no son datos conocidos')
                + ' y saldría tal cual en el PDF. Usa los botones de «Insertar dato».';
        },
        bloqueo() {
            if (!this.incluidos.length) { return 'Deja al menos un comprobante incluido.'; }
            for (var k in this.f) {
                if (Object.prototype.hasOwnProperty.call(this.f, k) && this.malas(k).length) {
                    return 'Revisa los datos entre llaves marcados en rojo.';
                }
            }
            return '';
        },

        insertar(marca) {
            var el = this.campo && this.campo.isConnected ? this.campo : document.getElementById('edo-intro');
            if (!el) { return; }
            var txt = '{' + marca + '}';
            var max = el.maxLength > 0 ? el.maxLength : Infinity;
            if (el.value.length + txt.length > max) { return; }
            var ini = typeof el.selectionStart === 'number' ? el.selectionStart : el.value.length;
            var fin = typeof el.selectionEnd === 'number' ? el.selectionEnd : el.value.length;
            el.setRangeText(txt, ini, fin, 'end');
            this.f[el.name] = el.value;
            el.focus();
        },
    };
}
</script>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
