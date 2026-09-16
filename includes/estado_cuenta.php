<?php

declare(strict_types=1);

/**
 * Estado de cuenta editable, por cliente.
 *
 * El estado de cuenta es el documento que recibe el cliente con lo que debe.
 * Sus MONTOS no se editan aquí, y a propósito: salen de las facturas y de los
 * recibos. Un saldo retocado en el PDF y no en el sistema deja un documento que
 * dice una cosa y una cartera que dice otra. Para corregir un monto se corrige
 * el recibo (o se emite la nota de crédito) y el estado de cuenta lo refleja.
 *
 * Lo que sí se edita es el documento:
 *   · qué comprobantes incluye (uno en disputa, uno pagado que falta aplicar),
 *   · el tono, a quién va dirigido, el asunto, la carta y una nota destacada,
 *   · las formas de pago y la firma, si para este cliente son otras.
 *
 * Un texto guardado como NULL significa «el estándar». Quien no toca un campo
 * sigue recibiendo el texto estándar —y con el tono automático, la carta sigue
 * endureciéndose sola a medida que crece el atraso—. Por eso al guardar, un
 * texto idéntico al estándar se guarda como NULL y no como copia.
 */

function ensure_statement_schema(): void
{
    $pdo = db(false);
    if (!$pdo) {
        return;
    }
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS client_statements (
            client_id INT UNSIGNED NOT NULL PRIMARY KEY,
            tone VARCHAR(16) NULL,
            attention VARCHAR(160) NULL,
            subject VARCHAR(255) NULL,
            intro TEXT NULL,
            closing TEXT NULL,
            note TEXT NULL,
            payment_info TEXT NULL,
            contact VARCHAR(255) NULL,
            excluded_ids TEXT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            updated_by INT UNSIGNED NULL,
            updated_by_name VARCHAR(120) NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    } catch (Throwable $e) {
        error_log('ensure_statement_schema: ' . $e->getMessage());
    }
}

function statements_available(): bool
{
    return db(false) !== null && table_exists('client_statements');
}

/** Campos de texto editables: [rótulo, máximo de caracteres]. */
function statement_fields(): array
{
    return [
        'attention'    => ['Atención', 160],
        'subject'      => ['Asunto', 255],
        'intro'        => ['Carta', 2000],
        'note'         => ['Nota destacada', 1500],
        'closing'      => ['Cierre', 2000],
        'payment_info' => ['Formas de pago', 2000],
        'contact'      => ['Contacto de cobros', 255],
    ];
}

/** Marcas que se reemplazan con los datos del día al generar el documento. */
function statement_marks(): array
{
    return [
        'cliente'  => 'Cliente',
        'total'    => 'Saldo total',
        'vencido'  => 'Vencido',
        'dias'     => 'Días de atraso',
        'fecha'    => 'Fecha',
        'empresa'  => 'Empresa',
        'contacto' => 'Contacto',
    ];
}

/** Los campos donde se pueden insertar datos del día. */
function statement_field_takes_marks(string $field): bool
{
    return in_array($field, ['subject', 'intro', 'note', 'closing', 'payment_info'], true);
}

function statement_default_attention(): string
{
    return 'Departamento de Cuentas por Pagar';
}

function statement_custom(int $clientId): ?array
{
    if ($clientId <= 0 || !statements_available()) {
        return null;
    }
    return fetch_one('SELECT * FROM client_statements WHERE client_id = ?', [$clientId]);
}

/** Todos los ajustes guardados, por cliente: una consulta para listados y lotes. */
function statement_customs_all(): array
{
    if (!statements_available()) {
        return [];
    }
    $out = [];
    foreach (fetch_all('SELECT * FROM client_statements') as $row) {
        $out[(int) $row['client_id']] = $row;
    }
    return $out;
}

/** @return int[] */
function statement_excluded_ids(?array $custom): array
{
    if (!$custom || empty($custom['excluded_ids'])) {
        return [];
    }
    $ids = json_decode((string) $custom['excluded_ids'], true);
    if (!is_array($ids)) {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
}

/**
 * Lo que imprime el documento: el texto propio del cliente o el estándar.
 * La atención vacía es una decisión («Dirigido a» a secas), no un olvido; los
 * demás textos vacíos vuelven al estándar.
 */
function statement_texts(?array $custom, string $toneKey): array
{
    $tones = reminder_tones();
    $t = $tones[$toneKey] ?? $tones['cordial'];
    $own = static function (string $k) use ($custom): ?string {
        $v = $custom[$k] ?? null;
        return ($v === null || trim((string) $v) === '') ? null : (string) $v;
    };
    return [
        'attention'    => ($custom !== null && $custom['attention'] !== null) ? (string) $custom['attention'] : statement_default_attention(),
        'subject'      => $own('subject') ?? $t['subject'],
        'intro'        => $own('intro') ?? $t['intro'],
        'closing'      => $own('closing') ?? $t['close'],
        'note'         => $own('note') ?? '',
        'payment_info' => $own('payment_info') ?? reminder_payment_info(),
        'contact'      => $own('contact') ?? reminder_contact(),
    ];
}

/**
 * El estado de cuenta de un cliente con sus ajustes aplicados.
 *
 * Devuelve ['data' => client_receivables() de lo incluido, 'custom' => fila|null,
 *           'excluded' => ids con saldo que quedaron fuera,
 *           'pending' => cuántos comprobantes con saldo tiene en total].
 * data.count === 0 con pending > 0 significa que se excluyó todo.
 */
function statement_build(int $clientId, ?array $custom = null, bool $customGiven = false): array
{
    if (!$customGiven) {
        $custom = statement_custom($clientId);
    }
    $all = client_receivables($clientId);
    $out = ['data' => $all, 'custom' => $custom, 'excluded' => [], 'pending' => $all['count']];

    $wanted = statement_excluded_ids($custom);
    if (!$wanted || $all['count'] === 0) {
        return $out;
    }
    $ids = array_map(fn ($r) => (int) $r['id'], $all['rows']);
    // Solo cuentan los que siguen con saldo: uno excluido que ya se pagó no es
    // una exclusión, es un comprobante que salió solo.
    $excluded = array_values(array_intersect($ids, $wanted));
    if (!$excluded) {
        return $out;
    }
    $included = array_values(array_diff($ids, $excluded));
    $out['excluded'] = $excluded;
    $out['data'] = $included
        ? client_receivables($clientId, $included)
        : array_merge(client_receivables(0), ['client' => $all['client']]);
    return $out;
}

/**
 * Clientes del lote de estados de cuenta, con los ajustes ya aplicados: mismos
 * conteos que el PDF. Si el botón dice «12 clientes», el lote trae 12.
 */
function statement_batch_clients(bool $onlyOverdue = false, ?array $clients = null, ?array $customs = null): array
{
    // Quien ya cargó la cartera o los ajustes (Facturación) los pasa y se
    // ahorran las consultas repetidas.
    $customs ??= statement_customs_all();
    $out = [];
    foreach ($clients ?? receivables_clients() as $c) {
        $cid = (int) $c['client_id'];
        $custom = $customs[$cid] ?? null;
        $c['custom'] = $custom;
        $c['excluded_count'] = 0;
        if ($custom !== null && statement_excluded_ids($custom)) {
            $b = statement_build($cid, $custom, true);
            if ($b['data']['count'] === 0) {
                continue;
            }
            foreach (['count', 'total_dop', 'overdue_count', 'overdue_dop', 'max_days'] as $k) {
                $c[$k] = $b['data'][$k];
            }
            $c['excluded_count'] = count($b['excluded']);
        }
        if ($onlyOverdue && (int) $c['overdue_count'] === 0) {
            continue;
        }
        $out[] = $c;
    }
    usort($out, fn ($a, $b) => [$b['max_days'], $b['total_dop']] <=> [$a['max_days'], $a['total_dop']]);
    return $out;
}

/**
 * Deja un texto del formulario listo para guardar: UTF-8 válido, un solo tipo de
 * salto de línea, sin caracteres de control (Dompdf los pinta como cajitas) y
 * sin espacios sobrantes en los bordes.
 */
function statement_clean(string $text): string
{
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    return trim($text);
}

/** Marcas escritas que no existen, p. ej. {clinte}: saldrían tal cual en el PDF. */
function statement_unknown_marks(string $text): array
{
    if (!preg_match_all('/\{([^{}\s]{1,40})\}/u', $text, $m)) {
        return [];
    }
    $known = statement_marks();
    return array_values(array_unique(array_filter($m[1], fn ($k) => !isset($known[$k]))));
}

/**
 * Guarda los ajustes del estado de cuenta de un cliente.
 *
 * $in: tone ('' = automático), los campos de statement_fields(), shown[] (los
 * comprobantes que la pantalla mostró) e include[] (los que dejó marcados).
 * Se excluye lo que se mostró y se desmarcó. No se usa «todo lo que no vino
 * marcado»: un comprobante emitido mientras alguien editaba quedaría excluido
 * sin que nadie lo hubiera visto.
 *
 * $version es la que tenía la pantalla al abrirse (0 si no había ajustes). Si
 * otra persona guardó entretanto, no se pisa su trabajo.
 *
 * @return array{0: bool, 1: string, 2: bool}  [guardado, mensaje, conflicto]
 */
function statement_save(int $clientId, array $in, int $version): array
{
    if (!statements_available()) {
        ensure_statement_schema();
        if (!statements_available()) {
            return [false, 'No se pudo preparar el guardado del estado de cuenta. Avisa al administrador.', false];
        }
    }

    $all = client_receivables($clientId);
    if (!$all['client']) {
        return [false, 'El cliente no existe.', false];
    }
    if ($all['count'] === 0) {
        return [false, 'Este cliente ya no tiene comprobantes con saldo: no hay estado de cuenta que ajustar.', false];
    }

    $tone = (string) ($in['tone'] ?? '');
    if ($tone !== '' && !isset(reminder_tones()[$tone])) {
        $tone = '';
    }

    $vals = [];
    foreach (statement_fields() as $k => [$label, $max]) {
        $v = statement_clean((string) ($in[$k] ?? ''));
        if (mb_strlen($v) > $max) {
            return [false, sprintf('«%s» admite hasta %d caracteres y tiene %d. Acórtalo un poco.', $label, $max, mb_strlen($v)), false];
        }
        // La atención y el contacto se imprimen tal cual: ahí una marca no se
        // llenaría nunca (y {contacto} dentro del contacto sería circular).
        if (!statement_field_takes_marks($k) && preg_match('/\{[^{}\s]{1,40}\}/u', $v)) {
            return [false, sprintf('«%s» no admite datos entre llaves: escribe el texto tal como debe salir.', $label), false];
        }
        $bad = statement_unknown_marks($v);
        if ($bad) {
            return [false, sprintf(
                '«%s» usa %s, que no es un dato conocido y saldría tal cual en el PDF. Los datos que se pueden insertar son: %s.',
                $label,
                implode(', ', array_map(fn ($b) => '{' . $b . '}', $bad)),
                implode(', ', array_map(fn ($b) => '{' . $b . '}', array_keys(statement_marks())))
            ), false];
        }
        $vals[$k] = $v;
    }

    // Comprobantes: fuera lo que se vio y se desmarcó, y que todavía se debe.
    $ids = array_map(fn ($r) => (int) $r['id'], $all['rows']);
    $shown = array_values(array_intersect($ids, array_map('intval', (array) ($in['shown'] ?? []))));
    $include = array_map('intval', (array) ($in['include'] ?? []));
    $excluded = array_values(array_diff($shown, $include));
    sort($excluded);
    if (count($excluded) >= count($ids)) {
        return [false, 'Deja al menos un comprobante en el estado de cuenta. Si no quieres enviarle nada a este cliente, simplemente no lo generes.', false];
    }

    // El tono con el que se compara «¿es el texto estándar?».
    $maxDays = 0;
    foreach ($all['rows'] as $r) {
        if (!in_array((int) $r['id'], $excluded, true) && (float) $r['overdue'] > 0.009) {
            $maxDays = max($maxDays, (int) $r['aging']['days']);
        }
    }
    $tones = reminder_tones();
    $norm = static fn (string $s): string => trim(str_replace(["\r\n", "\r"], "\n", $s));
    $compareTones = $tone !== '' ? [$tone] : array_keys($tones);
    $std = ['subject' => 'subject', 'intro' => 'intro', 'closing' => 'close'];

    $row = [];
    foreach (['subject', 'intro', 'closing'] as $k) {
        $row[$k] = $vals[$k];
        if ($vals[$k] === '') {
            $row[$k] = null;
            continue;
        }
        // Con tono automático, el texto de cualquier tono cuenta como estándar:
        // la pantalla lo cambia sola al cambiar el atraso, no lo escribió nadie.
        foreach ($compareTones as $tk) {
            if ($norm($vals[$k]) === $norm($tones[$tk][$std[$k]])) {
                $row[$k] = null;
                break;
            }
        }
    }
    $row['attention'] = $vals['attention'] === statement_default_attention() ? null : $vals['attention'];
    $row['note'] = $vals['note'] === '' ? null : $vals['note'];
    $row['payment_info'] = ($vals['payment_info'] === '' || $norm($vals['payment_info']) === $norm(reminder_payment_info())) ? null : $vals['payment_info'];
    $row['contact'] = ($vals['contact'] === '' || $vals['contact'] === reminder_contact()) ? null : $vals['contact'];
    $row['tone'] = $tone === '' ? null : $tone;
    $row['excluded_ids'] = $excluded ? json_encode($excluded) : null;

    $user = current_user() ?? [];
    $uid = isset($user['id']) ? (int) $user['id'] : null;
    $uname = mb_substr((string) ($user['name'] ?? ''), 0, 120);
    $cols = ['tone', 'attention', 'subject', 'intro', 'closing', 'note', 'payment_info', 'contact', 'excluded_ids'];
    $values = array_map(fn ($c) => $row[$c], $cols);
    $nothing = !array_filter($values, fn ($v) => $v !== null);

    $pdo = db();
    try {
        if ($nothing) {
            // Todo quedó en estándar: equivale a no tener ajustes.
            if ($version <= 0) {
                // La pantalla se abrió sin ajustes, pero otra persona pudo
                // guardar unos mientras tanto. Responder «todo estándar» con
                // su fila intacta sería mentir.
                if (statement_custom($clientId) !== null) {
                    return [false, statement_conflict_message(), true];
                }
            } else {
                $st = $pdo->prepare('DELETE FROM client_statements WHERE client_id = ? AND version = ?');
                $st->execute([$clientId, $version]);
                // Si no borró nada porque otra persona ya lo había restablecido,
                // los dos querían lo mismo: no es un conflicto.
                if ($st->rowCount() === 0 && statement_custom($clientId) !== null) {
                    return [false, statement_conflict_message(), true];
                }
                log_activity('client', $clientId, 'estado_cuenta_restablecido', 'Todo volvió al texto estándar');
            }
            return [true, 'Guardado. El estado de cuenta de este cliente usa el texto estándar y todos sus comprobantes.', false];
        }

        if ($version <= 0) {
            $st = $pdo->prepare('INSERT INTO client_statements (client_id, ' . implode(', ', $cols) . ', version, updated_by, updated_by_name, updated_at)
                                 VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', 1, ?, ?, NOW())');
            $st->execute(array_merge([$clientId], $values, [$uid, $uname]));
        } else {
            $st = $pdo->prepare('UPDATE client_statements SET ' . implode(', ', array_map(fn ($c) => $c . ' = ?', $cols)) . ',
                                        version = version + 1, updated_by = ?, updated_by_name = ?, updated_at = NOW()
                                 WHERE client_id = ? AND version = ?');
            $st->execute(array_merge($values, [$uid, $uname, $clientId, $version]));
            if ($st->rowCount() === 0) {
                return [false, statement_conflict_message(), true];
            }
        }
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return [false, statement_conflict_message(), true];
        }
        error_log('statement_save: ' . $e->getMessage());
        return [false, 'No se pudo guardar el estado de cuenta. Inténtalo de nuevo.', false];
    }

    $partes = [];
    $partes[] = 'Tono: ' . ($tone === '' ? 'automático' : $tones[$tone]['label']);
    if ($excluded) {
        $partes[] = count($excluded) . ' comprobante' . (count($excluded) === 1 ? '' : 's') . ' fuera';
    }
    $propios = array_keys(array_filter(
        ['asunto' => $row['subject'], 'carta' => $row['intro'], 'cierre' => $row['closing'], 'nota' => $row['note'], 'atención' => $row['attention'], 'formas de pago' => $row['payment_info'], 'contacto' => $row['contact']],
        fn ($v) => $v !== null
    ));
    if ($propios) {
        $partes[] = 'texto propio: ' . implode(', ', $propios);
    }
    log_activity('client', $clientId, 'estado_cuenta_editado', implode(' · ', $partes));

    return [true, 'Estado de cuenta guardado. Así saldrá cada vez que se genere para este cliente, también en el lote.', false];
}

function statement_conflict_message(): string
{
    return 'Otra persona guardó cambios en este estado de cuenta mientras lo editabas, así que no se guardó nada para no borrar su trabajo. '
        . 'Abajo siguen tus cambios: revisa la versión guardada con «Ver PDF» y vuelve a guardar si quieres dejar los tuyos.';
}

/** Borra los ajustes: el cliente vuelve al documento estándar. */
function statement_reset(int $clientId): bool
{
    if ($clientId <= 0 || !statements_available()) {
        return false;
    }
    $st = db()->prepare('DELETE FROM client_statements WHERE client_id = ?');
    $st->execute([$clientId]);
    if ($st->rowCount() > 0) {
        log_activity('client', $clientId, 'estado_cuenta_restablecido', 'Volvió al documento estándar');
        return true;
    }
    return false;
}
