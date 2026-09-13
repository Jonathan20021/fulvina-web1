<?php
/**
 * Formatos de envío de datos de la DGII (607 y 608).
 *
 * Arma los archivos que se cargan cada mes en la Oficina Virtual a partir de
 * los comprobantes ya emitidos y anulados en el CRM. Antes de descargar, la
 * pantalla muestra fila por fila lo que se va a enviar y marca en rojo lo que
 * la DGII rechazaría, para corregirlo aquí y no en el portal.
 *
 * El 606 (compras) no se genera: falta la fuente de datos. Se explica en
 * pantalla en vez de producir un archivo vacío que pasaría como "sin compras".
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('dgii.view');
verify_csrf();

$hasDb = db(false) && table_exists('invoices');
if ($hasDb) {
    ensure_invoice_schema();
}

$ym = dgii_period((string) ($_POST['ym'] ?? $_GET['ym'] ?? ''));
$companyRnc = dgii_company_rnc();

/* Vistas del cierre mensual, todas sobre el mismo periodo. */
$vistas = [
    'formatos' => ['Formatos de envío', 'file-spreadsheet'],
    'libro'    => ['Libro de ventas', 'book-open'],
    'itbis'    => ['ITBIS del mes', 'percent'],
];
$vista = (string) ($_GET['vista'] ?? 'formatos');
if (!isset($vistas[$vista])) {
    $vista = 'formatos';
}

/* ---- Completar el código de anulación que exige el 608 ------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb && ($_POST['form'] ?? '') === 'void_code') {
    if (!current_can('facturas.edit')) {
        flash('warning', 'Acción no permitida por tu rol.');
        redirect('crm/dgii.php?ym=' . $ym);
    }
    $iid = (int) ($_POST['id'] ?? 0);
    $code = trim((string) ($_POST['void_code'] ?? ''));
    if ($iid > 0 && isset(dgii_void_reasons()[$code])) {
        db()->prepare("UPDATE invoices SET void_code=?, updated_at=NOW() WHERE id=? AND status='Anulada'")->execute([$code, $iid]);
        log_activity('invoice', $iid, 'dgii_codigo_anulacion', $code . ' · ' . dgii_void_reason_label($code));
        flash('success', 'Código de anulación guardado.');
    } else {
        flash('warning', 'Selecciona un código de anulación válido.');
    }
    redirect('crm/dgii.php?ym=' . $ym . '#f608');
}

/* ---- Tipo de ingreso por defecto (campo 5 del 607) ----------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'income_type') {
    if (!current_can('config.manage')) {
        flash('warning', 'Solo un administrador puede cambiar este parámetro.');
        redirect('crm/dgii.php?ym=' . $ym);
    }
    $v = (string) ($_POST['dgii_income_type'] ?? '01');
    if (isset(dgii_income_types()[$v])) {
        setting_set('dgii_income_type', $v);
        flash('success', 'Tipo de ingreso actualizado para el 607.');
    }
    redirect('crm/dgii.php?ym=' . $ym);
}

/* ---- Datos del periodo --------------------------------------------------- */
$rows607 = $hasDb ? dgii_607_rows($ym) : [];
$rows608 = $hasDb ? dgii_608_rows($ym) : [];
$totals = dgii_607_totals($rows607);
$warn608 = array_sum(array_map(fn ($r) => count($r['warnings']), $rows608));
/* Un solo campo obligatorio en blanco basta para que rechacen el archivo
   entero, asi que el boton se apaga en vez de prometer una descarga valida. */
$warn607 = array_sum(array_map(fn ($r) => count($r['warnings']), $rows607));
$status606 = dgii_606_status();
$huecos = $hasDb ? dgii_sequence_gaps($ym) : [];
$book = $hasDb ? dgii_sales_book($ym) : ['rows' => [], 'totals' => dgii_sales_book_totals([])];
$itbis = $hasDb ? dgii_itbis_summary($ym) : null;

/* ---- Descarga del TXT ---------------------------------------------------- */
$download = (string) ($_GET['download'] ?? '');
if ($download !== '' && in_array($download, ['607', '608'], true)) {
    if (!$hasDb) {
        flash('warning', 'No hay base de datos conectada.');
        redirect('crm/dgii.php?ym=' . $ym);
    }
    // Sin el RNC de la empresa la cabecera sale incompleta y la Oficina Virtual
    // rechaza el archivo entero: mejor detenerlo aquí con una instrucción clara.
    if ($companyRnc === '') {
        flash('warning', 'Falta el RNC de la empresa. Regístralo en Configuración antes de generar el archivo.');
        redirect('crm/dgii.php?ym=' . $ym);
    }
    $rows = $download === '607' ? $rows607 : $rows608;

    /* Un comprobante con un campo obligatorio en blanco hace que la Oficina
       Virtual rechace el archivo ENTERO, no la línea suelta, y eso se descubre
       después de subirlo. La pantalla ya marcaba estos casos en la tabla, pero
       dejaba descargar igual: aquí se detiene, nombrando el comprobante para
       que se pueda ir a corregirlo. */
    $incompletos = [];
    foreach ($rows as $r) {
        if (!empty($r['warnings'])) { $incompletos[] = (string) ($r['ncf'] ?? '?'); }
    }
    if ($incompletos) {
        $lista = implode(', ', array_slice($incompletos, 0, 5))
            . (count($incompletos) > 5 ? ' y ' . (count($incompletos) - 5) . ' más' : '');
        flash('warning', 'El ' . $download . ' no se generó: ' . count($incompletos)
            . ' comprobante(s) tienen datos pendientes (' . $lista
            . '). Están marcados en la tabla de abajo; corrígelos y vuelve a descargar.');
        redirect('crm/dgii.php?ym=' . $ym);
    }

    $txt = dgii_build_txt($download, $ym, $rows);
    $name = dgii_filename($download, $ym);
    log_activity('dgii', null, 'formato_' . $download . '_generado', $ym . ' · ' . count($rows) . ' registros');

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($txt));
    header('X-Content-Type-Options: nosniff');
    echo $txt;
    exit;
}

$prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
$nextYm = date('Y-m', strtotime($ym . '-01 +1 month'));
$deadline = dgii_deadline($ym);
$pastDeadline = strtotime($deadline) < strtotime(date('Y-m-d'));
$canFix = current_can('facturas.edit');

$crmTitle = 'Formatos DGII';
require_once __DIR__ . '/../includes/crm_header.php';
?>
<?= sch_encabezado('DGII', 'Cierre fiscal del mes: 607, 608, libro e ITBIS') ?>


<section class="crm-cockpit">
    <div class="crm-cockpit__top">
        <div class="crm-cockpit__hero crm-cockpit__hero--sales">
            <h2>607 y 608 del mes, listos para la Oficina Virtual.</h2>
            <p>Los archivos se arman con los comprobantes que ya emitiste y anulaste en el CRM. Revisa el detalle, corrige lo que aparezca marcado y descarga el TXT. Fecha límite de este periodo: <b><?= e(date_es($deadline)) ?></b>.</p>
            <form method="get" class="crm-cockpit__actions" style="align-items:center">
                <input type="hidden" name="vista" value="<?= e($vista) ?>">
                <a href="<?= url('crm/dgii.php?vista=' . $vista . '&ym=' . $prevYm) ?>" class="crm-secondary-btn" title="Mes anterior"><i data-lucide="chevron-left" class="h-4 w-4"></i>Anterior</a>
                <input type="month" name="ym" value="<?= e($ym) ?>" class="crm-input" style="max-width:170px" aria-label="Periodo a declarar">
                <button type="submit" class="crm-primary-btn"><i data-lucide="search" class="h-4 w-4"></i>Ver periodo</button>
                <a href="<?= url('crm/dgii.php?vista=' . $vista . '&ym=' . $nextYm) ?>" class="crm-secondary-btn" title="Mes siguiente">Siguiente<i data-lucide="chevron-right" class="h-4 w-4"></i></a>
            </form>
            <?php if ($huecos): ?>
                <?php
                /* Un número que no está ni en el 607 ni en el 608 es lo primero que
                   la DGII pregunta. Puede tener explicación, pero hay que darla. */
                $tot = array_sum(array_map(fn ($h) => count($h['faltan']), $huecos));
                ?>
                <div class="gas-aviso" style="margin-top:.7rem">
                    <i data-lucide="list-checks" style="width:16px;height:16px"></i>
                    <span><b><?= e((string) $tot) ?> número<?= $tot === 1 ? '' : 's' ?> de la secuencia sin declarar.</b>
                    No aparecen en el 607 ni como anulados en el 608:
                    <?php foreach (array_slice($huecos, 0, 4) as $h): ?>
                        <span class="gas-placa"><?= e($h['serie']) ?> <?= e((string) reset($h['faltan'])) ?><?= count($h['faltan']) > 1 ? '–' . e((string) end($h['faltan'])) : '' ?></span>
                    <?php endforeach; ?>
                    <?php if (count($huecos) > 4): ?> y <?= e((string) (count($huecos) - 4)) ?> tramo(s) más<?php endif; ?>.
                    Si el rango empezó en otro mes o el número se liberó para reutilizarlo, está bien; si no, falta declarar el comprobante.</span>
                </div>
            <?php endif; ?>

            <nav class="rep-seg" aria-label="Vista del cierre mensual" style="margin-top:.7rem">
                <?php foreach ($vistas as $k => [$label, $icon]): ?>
                    <a href="<?= url('crm/dgii.php?vista=' . $k . '&ym=' . $ym) ?>" class="<?= $vista === $k ? 'is-active' : '' ?>"><i data-lucide="<?= e($icon) ?>" style="width:14px;height:14px;vertical-align:-2px"></i> <?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <div class="crm-cockpit__metrics" aria-label="Resumen del periodo">
            <article><span>Periodo</span><strong style="font-size:1.05rem"><?= e(dgii_period_label($ym)) ?></strong><small><?= $pastDeadline ? 'plazo vencido' : 'vence ' . e(date_es($deadline)) ?></small></article>
            <article><span>607 · Ventas</span><strong><?= e((string) $totals['count']) ?></strong><small>comprobantes emitidos</small></article>
            <article><span>608 · Anulados</span><strong><?= e((string) count($rows608)) ?></strong><small>comprobantes anulados</small></article>
            <article><span>ITBIS facturado</span><strong style="font-size:1.05rem"><?= money($totals['itbis']) ?></strong><small>débito fiscal del mes</small></article>
        </div>
    </div>

    <?php if (!$hasDb): ?>
        <div class="crm-empty"><i data-lucide="database" class="h-6 w-6"></i><strong>Sin base de datos</strong><p>Ejecuta <a href="<?= url('install.php') ?>">install.php</a> para conectar la facturación y generar los formatos con datos reales.</p></div>
    <?php else: ?>

    <?php if ($companyRnc === ''): ?>
        <div class="gas-aviso gas-aviso--alarma">
            <i data-lucide="alert-octagon" style="width:15px;height:15px;vertical-align:-2px"></i>
            <b>Falta el RNC de la empresa.</b> La cabecera del archivo lo lleva obligatoriamente y sin él la DGII rechaza el envío completo.
            <?php if (current_can('config.manage')): ?><a class="underline" href="<?= url('crm/configuracion.php') ?>">Regístralo en Configuración</a>.<?php else: ?>Pídele a un administrador que lo registre en Configuración.<?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($vista === 'formatos'): ?>
    <!-- ============================== 607 ============================== -->
    <article class="crm-card" id="f607">
        <div class="crm-card__head">
            <div>
                <h2><i data-lucide="trending-up" class="cfg-ic"></i> 607 · Ventas de bienes y servicios</h2>
                <p>Comprobantes fiscales emitidos en <?= e(dgii_period_label($ym)) ?>, con su NCF, base facturada sin ITBIS, impuestos y retenciones. Los comprobantes en USD se convierten a RD$ con la tasa del propio documento. Las proformas y los borradores no entran: no son fiscales.</p>
            </div>
            <div class="crm-toolbar" style="gap:.5rem;padding:0">
                <a class="crm-primary-btn <?= ($companyRnc === '' || !$rows607 || $warn607 > 0) ? 'is-disabled' : '' ?>" title="<?= $warn607 > 0 ? 'Hay comprobantes con datos pendientes; corrígelos abajo.' : '' ?>" href="<?= url('crm/dgii.php?ym=' . $ym . '&download=607') ?>"><i data-lucide="download" class="h-4 w-4"></i>Descargar 607</a>
            </div>
        </div>

        <?php if ($totals['warnings'] > 0): ?>
            <div class="gas-aviso">
                <i data-lucide="alert-triangle" style="width:15px;height:15px;vertical-align:-2px"></i>
                <?= e((string) $totals['warnings']) ?> observación<?= $totals['warnings'] === 1 ? '' : 'es' ?> que la DGII puede rechazar. Están marcadas abajo; corrígelas en la factura antes de enviar.
            </div>
        <?php endif; ?>

        <?php if (!$rows607): ?>
            <div class="crm-empty"><i data-lucide="file-x" class="h-6 w-6"></i><strong>Sin ventas en el periodo</strong><p>No hay comprobantes fiscales emitidos en <?= e(dgii_period_label($ym)) ?>. Si el mes tuvo ventas, verifica que las facturas estén emitidas y no en borrador.</p></div>
        <?php else: ?>
            <div class="crm-table-wrap">
                <table class="crm-table crm-data-table">
                    <thead><tr>
                        <th>NCF</th><th>Cliente</th><th>RNC / Cédula</th><th>Fecha</th>
                        <th class="text-right">Facturado</th><th class="text-right">ITBIS</th>
                        <th class="text-right">Ret. ITBIS</th><th class="text-right">Ret. ISR</th><th class="text-right">Total</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows607 as $r): ?>
                        <tr<?= $r['warnings'] ? ' class="sch-caja--aviso"' : '' ?>>
                            <td>
                                <a href="<?= url('crm/facturas.php?action=view&id=' . $r['invoice_id']) ?>"><strong><?= e($r['ncf']) ?></strong></a>
                                <p class="text-xs text-slate-500"><?= e($r['type_label']) ?><?= $r['modifies'] !== '' ? ' · modifica ' . e($r['modifies']) : '' ?></p>
                            </td>
                            <td><?= e($r['client'] ?: '—') ?><?php if ($r['currency'] !== 'DOP'): ?><p class="text-xs text-slate-500">emitida en <?= e($r['currency']) ?></p><?php endif; ?></td>
                            <td>
                                <?= $r['rnc'] !== '' ? e($r['rnc']) : '<span style="color:var(--muted)">—</span>' ?>
                                <?php if ($r['id_type'] !== ''): ?><p class="text-xs text-slate-500"><?= e(['1' => 'RNC', '2' => 'Cédula', '3' => 'Pasaporte'][$r['id_type']] ?? '') ?></p><?php endif; ?>
                            </td>
                            <td><?= e(date_es($r['date'])) ?></td>
                            <td class="text-right"><?= money($r['billed']) ?></td>
                            <td class="text-right"><?= money($r['itbis']) ?></td>
                            <td class="text-right"><?= $r['itbis_ret'] > 0 ? money($r['itbis_ret']) : '<span style="color:var(--muted)">—</span>' ?></td>
                            <td class="text-right"><?= $r['isr_ret'] > 0 ? money($r['isr_ret']) : '<span style="color:var(--muted)">—</span>' ?></td>
                            <td class="text-right"><strong><?= money($r['total']) ?></strong></td>
                        </tr>
                        <?php if ($r['warnings']): ?>
                            <tr<?= ' class="sch-caja--aviso"' ?>>
                                <td colspan="9" style="padding-top:0">
                                    <?php foreach ($r['warnings'] as $w): ?>
                                        <span class="gas-aviso gas-aviso--chip" style="margin-right:.35rem"><i data-lucide="alert-triangle" style="width:12px;height:12px;vertical-align:-2px"></i> <?= e($w) ?></span>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr style="font-weight:700;border-top:2px solid var(--line)">
                        <td colspan="4">Totales · <?= e((string) $totals['count']) ?> comprobantes</td>
                        <td class="text-right"><?= money($totals['billed']) ?></td>
                        <td class="text-right"><?= money($totals['itbis']) ?></td>
                        <td class="text-right"><?= money($totals['itbis_ret']) ?></td>
                        <td class="text-right"><?= money($totals['isr_ret']) ?></td>
                        <td class="text-right"><?= money($totals['total']) ?></td>
                    </tr></tfoot>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <!-- ============================== 608 ============================== -->
    <article class="crm-card" id="f608">
        <div class="crm-card__head">
            <div>
                <h2><i data-lucide="file-x-2" class="cfg-ic"></i> 608 · Comprobantes anulados</h2>
                <p>Comprobantes anulados durante <?= e(dgii_period_label($ym)) ?>. Los que se anularon liberando su NCF no aparecen: ese número volvió al rango para reutilizarse, así que no hubo anulación ante la DGII. Cada fila necesita el código de anulación oficial.</p>
            </div>
            <div class="crm-toolbar" style="gap:.5rem;padding:0">
                <a class="crm-primary-btn <?= ($companyRnc === '' || !$rows608 || $warn608 > 0) ? 'is-disabled' : '' ?>" title="<?= $warn608 > 0 ? 'Hay comprobantes con datos pendientes; corrígelos abajo.' : '' ?>" href="<?= url('crm/dgii.php?ym=' . $ym . '&download=608') ?>"><i data-lucide="download" class="h-4 w-4"></i>Descargar 608</a>
            </div>
        </div>

        <?php if ($warn608 > 0): ?>
            <div class="gas-aviso">
                <i data-lucide="alert-triangle" style="width:15px;height:15px;vertical-align:-2px"></i>
                <?= e((string) $warn608) ?> comprobante<?= $warn608 === 1 ? '' : 's' ?> sin código de anulación. La DGII exige ese código y rechaza el archivo completo si falta, así que la descarga está detenida hasta completarlo: asígnalo en la misma fila.
            </div>
        <?php endif; ?>

        <?php if (!$rows608): ?>
            <div class="crm-empty"><i data-lucide="check-circle-2" class="h-6 w-6"></i><strong>Sin anulaciones en el periodo</strong><p>No se anuló ningún comprobante con NCF en <?= e(dgii_period_label($ym)) ?>. Si no hubo anulaciones, no hace falta enviar el 608.</p></div>
        <?php else: ?>
            <div class="crm-table-wrap">
                <table class="crm-table crm-data-table">
                    <thead><tr><th>NCF</th><th>Cliente</th><th>Fecha comprobante</th><th>Anulada</th><th>Código DGII</th><th>Motivo interno</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows608 as $r): ?>
                        <tr<?= $r['warnings'] ? ' class="sch-caja--aviso"' : '' ?>>
                            <td><a href="<?= url('crm/facturas.php?action=view&id=' . $r['invoice_id']) ?>"><strong><?= e($r['ncf']) ?></strong></a><p class="text-xs text-slate-500"><?= e($r['type_label']) ?></p></td>
                            <td><?= e($r['client'] ?: '—') ?></td>
                            <td><?= e(date_es($r['date'])) ?></td>
                            <td><?= e(date_es(substr($r['voided_at'], 0, 10))) ?></td>
                            <td>
                                <?php if ($canFix): ?>
                                    <form method="post" style="display:flex;gap:.35rem;align-items:center">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="form" value="void_code">
                                        <input type="hidden" name="ym" value="<?= e($ym) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $r['invoice_id'] ?>">
                                        <select name="void_code" class="crm-select" style="max-width:210px">
                                            <option value="">Selecciona…</option>
                                            <?php foreach (dgii_void_reasons() as $code => $label): ?>
                                                <option value="<?= e($code) ?>" <?= $r['code'] === $code ? 'selected' : '' ?>><?= e($code . ' · ' . $label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="crm-icon-action" title="Guardar código"><i data-lucide="check"></i></button>
                                    </form>
                                <?php elseif ($r['code'] !== ''): ?>
                                    <span class="status-chip gas-estado--ok"><?= e($r['code'] . ' · ' . $r['code_label']) ?></span>
                                <?php else: ?>
                                    <span class="gas-aviso gas-aviso--chip">Sin código</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-slate-600" style="max-width:260px"><?= e(mb_strimwidth($r['reason'], 0, 90, '…')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <!-- ============================== 606 ============================== -->
    <article class="crm-card">
        <div class="crm-card__head">
            <div>
                <h2><i data-lucide="shopping-cart" class="cfg-ic"></i> 606 · Compras de bienes y servicios</h2>
                <p>Este formato todavía no se puede generar desde el CRM, y decirlo es más útil que entregarte un archivo vacío: la DGII lo interpretaría como un mes sin compras.</p>
            </div>
        </div>
        <div class="crm-perm-box">
            <p style="margin:0 0 .5rem"><b>Por qué:</b> <?= e($status606['reason']) ?></p>
            <p style="margin:0 0 .35rem"><b>Qué haría falta:</b></p>
            <ul style="margin:0;padding-left:1.1rem;color:var(--muted);font-size:.85rem;line-height:1.7">
                <?php foreach ($status606['needs'] as $need): ?><li><?= e($need) ?></li><?php endforeach; ?>
            </ul>
        </div>
    </article>

    <!-- ===================== Parámetros del envío ====================== -->
    <article class="crm-card">
        <div class="crm-card__head">
            <div>
                <h2><i data-lucide="sliders-horizontal" class="cfg-ic"></i> Parámetros del envío</h2>
                <p>Datos de la cabecera y valores por defecto que viajan en cada registro del 607.</p>
            </div>
        </div>
        <div class="crm-table-wrap">
            <table class="crm-table">
                <tbody>
                    <tr>
                        <td style="width:230px"><b>RNC informante</b><p class="text-xs text-slate-500">Cabecera del archivo</p></td>
                        <td><?= $companyRnc !== '' ? '<strong>' . e($companyRnc) . '</strong>' : '<span class="gas-aviso gas-aviso--chip">Sin registrar</span>' ?></td>
                    </tr>
                    <tr>
                        <td><b>Periodo</b><p class="text-xs text-slate-500">Cabecera del archivo</p></td>
                        <td><strong><?= e(dgii_period_header($ym)) ?></strong> · <?= e(dgii_period_label($ym)) ?></td>
                    </tr>
                    <tr>
                        <td><b>Tipo de ingreso</b><p class="text-xs text-slate-500">Campo 5 de cada registro del 607</p></td>
                        <td>
                            <?php if (current_can('config.manage')): ?>
                                <form method="post" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="form" value="income_type">
                                    <input type="hidden" name="ym" value="<?= e($ym) ?>">
                                    <select name="dgii_income_type" class="crm-select" style="max-width:340px">
                                        <?php foreach (dgii_income_types() as $code => $label): ?>
                                            <option value="<?= e($code) ?>" <?= dgii_income_type() === $code ? 'selected' : '' ?>><?= e($code . ' · ' . $label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="crm-secondary-btn"><i data-lucide="check" class="h-4 w-4"></i>Guardar</button>
                                </form>
                            <?php else: ?>
                                <strong><?= e(dgii_income_type() . ' · ' . (dgii_income_types()[dgii_income_type()] ?? '')) ?></strong>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><b>Formas de pago</b><p class="text-xs text-slate-500">Campos 17 a 23 del 607</p></td>
                        <td class="text-slate-600" style="font-size:.85rem;line-height:1.6">Se reparten con los cobros realmente registrados en cada factura hasta el cierre del periodo; el saldo sin cobrar se informa como venta a crédito. Las notas de crédito y débito van sin formas de pago.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-slate-500" style="margin-top:.7rem;line-height:1.6">
            <i data-lucide="info" style="width:13px;height:13px;vertical-align:-2px"></i>
            Antes del primer envío real, carga un archivo de prueba en la Oficina Virtual para validar el diseño de registro. Si la DGII publica un layout nuevo, se ajusta en <code>includes/dgii.php</code> sin tocar el resto del sistema.
        </p>
    </article>
    <?php endif; ?>

    <!-- =========================== LIBRO DE VENTAS =========================== -->
    <?php if ($vista === 'libro'): $bt = $book['totals']; ?>
    <article class="crm-card">
        <div class="crm-card__head">
            <div>
                <h2><i data-lucide="book-open" class="cfg-ic"></i> Libro de ventas · <?= e(dgii_period_label($ym)) ?></h2>
                <p>Todos los comprobantes con NCF del mes, <b>ordenados por número</b> dentro de cada serie: así un salto en la secuencia se ve de inmediato, que es lo primero que pregunta un auditor. Por eso las <b>anuladas</b> también aparecen, con importe cero. Las <b>notas de crédito</b> se listan con el signo «−» y restan en los totales.</p>
            </div>
            <div class="crm-toolbar" style="gap:.5rem;padding:0">
                <button type="button" class="crm-secondary-btn <?= $bt['count'] === 0 ? 'is-disabled' : '' ?>" onclick="crmPdfPreviewOpen('<?= url('crm/libro_ventas_pdf.php?ym=' . $ym) ?>','<?= url('crm/libro_ventas_pdf.php?ym=' . $ym . '&download=1') ?>','<?= e(addslashes(dgii_period_label($ym))) ?>','Libro de ventas')"><i data-lucide="printer" class="h-4 w-4"></i>Imprimir / PDF</button>
                <a class="crm-secondary-btn <?= $bt['count'] === 0 ? 'is-disabled' : '' ?>" href="<?= url('crm/libro_ventas_export.php?ym=' . $ym) ?>"><i data-lucide="sheet" class="h-4 w-4"></i>Exportar Excel</a>
            </div>
        </div>

        <?php if (!$book['rows']): ?>
            <div class="crm-empty"><i data-lucide="book-open" class="h-6 w-6"></i><strong>Sin comprobantes en el periodo</strong><p>No se emitió ningún comprobante con NCF en <?= e(dgii_period_label($ym)) ?>.</p></div>
        <?php else: ?>
            <div class="crm-table-wrap">
                <table class="crm-table crm-data-table">
                    <thead><tr>
                        <th>Fecha</th><th>NCF</th><th>Cliente</th>
                        <th class="text-right">Gravado</th><th class="text-right">Exento</th>
                        <th class="text-right">ITBIS</th><th class="text-right">Ret. ITBIS</th><th class="text-right">Total</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($book['rows'] as $r):
                        $rowStyle = $r['is_void'] ? ' class="sch-caja--alarma"' : ($r['is_note'] ? ' class="sch-caja--aviso"' : ''); ?>
                        <tr<?= $rowStyle ?>>
                            <td><?= e(date_es($r['date'])) ?></td>
                            <td>
                                <a href="<?= url('crm/facturas.php?action=view&id=' . (int) $r['invoice_id']) ?>"><strong><?= e($r['ncf']) ?></strong></a>
                                <p class="text-xs text-slate-500">
                                    <?= e($r['type_label']) ?>
                                    <?php if ($r['is_void']): ?> · <b class="sch-monto--baja">ANULADA</b><?php endif; ?>
                                    <?php if ($r['modifies'] !== ''): ?> · modifica <?= e($r['modifies']) ?><?php endif; ?>
                                </p>
                            </td>
                            <td>
                                <?= e($r['client'] ?: '—') ?>
                                <?php if ($r['rnc'] !== '' || $r['currency'] !== 'DOP'): ?><p class="text-xs text-slate-500"><?= e(trim(implode(' · ', array_filter([$r['rnc'], $r['currency'] !== 'DOP' ? 'emitida en ' . $r['currency'] : '']))) ) ?></p><?php endif; ?>
                            </td>
                            <?php $sg = $r['is_note'] ? '− ' : ''; ?>
                            <td class="text-right"><?= $r['is_void'] ? '—' : $sg . money($r['taxed']) ?></td>
                            <td class="text-right"><?= $r['is_void'] ? '—' : ($r['exempt'] > 0 ? $sg . money($r['exempt']) : '<span style="color:var(--muted)">—</span>') ?></td>
                            <td class="text-right"><?= $r['is_void'] ? '—' : $sg . money($r['itbis']) ?></td>
                            <td class="text-right"><?= $r['is_void'] || $r['itbis_ret'] <= 0 ? '<span style="color:var(--muted)">—</span>' : money($r['itbis_ret']) ?></td>
                            <td class="text-right"><strong><?= $r['is_void'] ? '—' : $sg . money($r['total']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr style="font-weight:700;border-top:2px solid var(--line)">
                        <td colspan="3">
                            Totales del mes
                            <p class="text-xs" style="font-weight:500;color:var(--muted)">
                                <?= e((string) $bt['count']) ?> comprobantes<?php if ($bt['voided'] > 0): ?> · <?= e((string) $bt['voided']) ?> anulado<?= $bt['voided'] === 1 ? '' : 's' ?> (sin importe)<?php endif; ?><?php if ($bt['notes'] > 0): ?> · <?= e((string) $bt['notes']) ?> nota<?= $bt['notes'] === 1 ? '' : 's' ?> de crédito restando<?php endif; ?>
                            </p>
                        </td>
                        <td class="text-right"><?= money($bt['taxed']) ?></td>
                        <td class="text-right"><?= money($bt['exempt']) ?></td>
                        <td class="text-right"><?= money($bt['itbis']) ?></td>
                        <td class="text-right"><?= money($bt['itbis_ret']) ?></td>
                        <td class="text-right"><?= money($bt['total']) ?></td>
                    </tr></tfoot>
                </table>
            </div>
            <p class="text-xs text-slate-500" style="margin-top:.7rem;line-height:1.6">
                <i data-lucide="info" style="width:13px;height:13px;vertical-align:-2px"></i>
                Importes en RD$; los comprobantes en USD se convierten con la tasa del propio documento. El total gravado e ITBIS de este libro debe cuadrar con el <a href="<?= url('crm/dgii.php?vista=formatos&ym=' . $ym) ?>">607 del mismo mes</a>.
            </p>
        <?php endif; ?>
    </article>
    <?php endif; ?>

    <!-- ============================== ITBIS ============================== -->
    <?php if ($vista === 'itbis' && $itbis !== null): ?>
    <article class="crm-card">
        <div class="crm-card__head">
            <div>
                <h2><i data-lucide="percent" class="cfg-ic"></i> ITBIS de <?= e(dgii_period_label($ym)) ?></h2>
                <p>La parte de ventas de la declaración IT-1, calculada desde los comprobantes emitidos. Las notas de crédito ya vienen restadas y las anuladas no cuentan.</p>
            </div>
        </div>

        <div class="crm-cockpit__metrics" style="margin-bottom:1rem">
            <article><span>Ventas gravadas</span><strong style="font-size:1.05rem"><?= money($itbis['taxed']) ?></strong><small>base del ITBIS</small></article>
            <article><span>Ventas exentas</span><strong style="font-size:1.05rem"><?= money($itbis['exempt']) ?></strong><small>sin ITBIS</small></article>
            <article><span>Débito fiscal</span><strong style="font-size:1.05rem"><?= money($itbis['debit']) ?></strong><small>ITBIS facturado</small></article>
            <article><span>Retenido por terceros</span><strong style="font-size:1.05rem"><?= money($itbis['withheld']) ?></strong><small>ya enterado por el cliente</small></article>
        </div>

        <div class="gas-aviso" style="line-height:1.6">
            <b><i data-lucide="alert-triangle" style="width:15px;height:15px;vertical-align:-2px"></i> Esto NO es el ITBIS a pagar.</b>
            Falta restar el <b>crédito fiscal</b>: el ITBIS que SCH pagó en sus compras del mes. <?= e($itbis['credit_reason']) ?>
            Lleva esta cifra a tu contabilidad y réstale las compras antes de declarar.
        </div>

        <div class="crm-table-wrap">
            <table class="crm-table">
                <tbody>
                    <tr><td style="width:60%"><b>Débito fiscal</b><p class="text-xs text-slate-500">ITBIS cobrado a clientes en comprobantes emitidos</p></td><td class="text-right"><strong><?= money($itbis['debit']) ?></strong></td></tr>
                    <tr><td><b>− ITBIS retenido por terceros</b><p class="text-xs text-slate-500">Retenciones que te practicaron; el cliente ya las enteró</p></td><td class="text-right"><strong>− <?= money($itbis['withheld']) ?></strong></td></tr>
                    <tr><td><b>− Crédito fiscal de compras</b><p class="text-xs text-slate-500"><?= e($itbis['credit_reason']) ?></p></td><td class="text-right"><span class="gas-aviso gas-aviso--chip">No disponible</span></td></tr>
                    <tr style="border-top:2px solid var(--line)">
                        <td><b>Saldo parcial de ventas</b><p class="text-xs text-slate-500">Débito menos retenciones, <b>sin restar compras</b></p></td>
                        <td class="text-right"><strong style="font-size:1.1rem"><?= money($itbis['partial_due']) ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if ($itbis['by_type']): ?>
            <div class="crm-card__head" style="margin-top:1.2rem"><div><h2 style="font-size:.95rem"><i data-lucide="layers" class="cfg-ic"></i> Desglose por tipo de comprobante</h2><p>Para cuadrar contra el 607 antes de enviarlo.</p></div></div>
            <div class="crm-table-wrap">
                <table class="crm-table crm-data-table">
                    <thead><tr><th>Tipo</th><th class="text-right">Comprobantes</th><th class="text-right">Gravado</th><th class="text-right">Exento</th><th class="text-right">ITBIS</th></tr></thead>
                    <tbody>
                    <?php foreach ($itbis['by_type'] as $bt2): ?>
                        <tr>
                            <td><strong><?= e($bt2['type']) ?></strong> · <?= e($bt2['label']) ?></td>
                            <td class="text-right"><?= e((string) $bt2['count']) ?></td>
                            <td class="text-right"><?= money($bt2['taxed']) ?></td>
                            <td class="text-right"><?= $bt2['exempt'] != 0.0 ? money($bt2['exempt']) : '<span style="color:var(--muted)">—</span>' ?></td>
                            <td class="text-right"><strong><?= money($bt2['itbis']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr style="font-weight:700;border-top:2px solid var(--line)">
                        <td>Total</td>
                        <td class="text-right"><?= e((string) ($itbis['count'] - $itbis['voided'])) ?></td>
                        <td class="text-right"><?= money($itbis['taxed']) ?></td>
                        <td class="text-right"><?= money($itbis['exempt']) ?></td>
                        <td class="text-right"><?= money($itbis['debit']) ?></td>
                    </tr></tfoot>
                </table>
            </div>
        <?php endif; ?>
    </article>
    <?php endif; ?>

    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
