<?php

declare(strict_types=1);

/**
 * SCH MEDICOS CRM — Catálogo de productos y servicios.
 *
 * Existe por una razón concreta: hasta ahora las partidas de cotizaciones y
 * facturas eran texto libre, así que el mismo monitor podía salir a tres precios
 * distintos según quién cotizara, no se podía medir qué se vende más, y —lo más
 * grave— no había COSTO en ninguna parte, de modo que nadie podía responder
 * cuánto se ganó en una venta.
 *
 * El costo del catálogo es el costo de HOY. El que importa para el margen de una
 * venta es el que regía cuando se vendió, así que al armar el documento se copia
 * a la partida (quote_items.unit_cost / invoice_items.unit_cost) y ahí se queda
 * congelado. Cambiar el precio de un producto no reescribe la historia.
 *
 * unit_cost NULL significa «no se sabe», que no es lo mismo que cero. Las
 * partidas viejas y las escritas a mano nacen en NULL y quedan fuera del cálculo
 * de margen en vez de aparecer como margen del 100%.
 */

/** Tipos de ficha del catálogo. */
function product_kinds(): array
{
    return ['producto' => 'Producto', 'servicio' => 'Servicio'];
}

/** Unidades sugeridas (el campo es libre; esto solo alimenta el datalist). */
function product_units(): array
{
    return ['unidad', 'pieza', 'caja', 'metro', 'm²', 'litro', 'm³', 'hora', 'día', 'servicio', 'mes'];
}

/** Provisión del esquema del catálogo. Idempotente, como el resto del CRM. */
function ensure_products_schema(): void
{
    $pdo = db(false);
    if (!$pdo) {
        return;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(60) NULL,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'producto',
            category VARCHAR(80) NULL,
            brand VARCHAR(120) NULL,
            unit VARCHAR(40) NULL,
            cost DECIMAL(12,2) NULL DEFAULT NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(3) NOT NULL DEFAULT 'DOP',
            is_exempt TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_products_sku (sku),
            INDEX idx_products_name (name),
            INDEX idx_products_active (active),
            INDEX idx_products_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Enlace y COSTO CONGELADO en las partidas de ambos documentos.
        foreach (['quote_items', 'invoice_items'] as $table) {
            if (!table_exists($table)) {
                continue;
            }
            if (!column_exists($table, 'product_id')) {
                try { $pdo->exec("ALTER TABLE {$table} ADD COLUMN product_id INT UNSIGNED NULL AFTER id"); } catch (Throwable) { /* ignore */ }
            }
            // NULL a propósito: distingue «costo desconocido» de «costo cero».
            if (!column_exists($table, 'unit_cost')) {
                try { $pdo->exec("ALTER TABLE {$table} ADD COLUMN unit_cost DECIMAL(12,2) NULL DEFAULT NULL AFTER unit_price"); } catch (Throwable) { /* ignore */ }
            }
        }
    } catch (Throwable) {
        /* best-effort, igual que el resto de las provisiones */
    }
}

/** ¿Está disponible el catálogo? */
function products_available(): bool
{
    return db(false) !== null && table_exists('products');
}

/** ¿Las partidas ya pueden guardar costo? */
function items_track_cost(): bool
{
    return db(false) !== null && column_exists('invoice_items', 'unit_cost');
}

/**
 * Listado del catálogo con filtros.
 * $filters: q (texto), category, kind, active ('1'|'0'|'' para todos).
 */
function products_all(array $filters = [], int $limit = 500): array
{
    if (!products_available()) {
        return [];
    }
    $where = ['1=1'];
    $params = [];

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(name LIKE ? OR sku LIKE ? OR brand LIKE ? OR description LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    foreach (['category', 'kind'] as $f) {
        $v = trim((string) ($filters[$f] ?? ''));
        if ($v !== '') {
            $where[] = "{$f} = ?";
            $params[] = $v;
        }
    }
    $active = (string) ($filters['active'] ?? '1');
    if ($active === '1' || $active === '0') {
        $where[] = 'active = ?';
        $params[] = (int) $active;
    }

    $limit = max(1, min(2000, $limit));
    return fetch_all(
        'SELECT * FROM products WHERE ' . implode(' AND ', $where) . " ORDER BY active DESC, name ASC LIMIT {$limit}",
        $params
    );
}

/** Fichas activas, en la forma mínima que necesita el selector de partidas. */
function products_for_picker(): array
{
    if (!products_available()) {
        return [];
    }
    return array_map(fn ($p) => [
        'id' => (int) $p['id'],
        'sku' => (string) ($p['sku'] ?? ''),
        'name' => (string) $p['name'],
        'label' => trim(((string) ($p['sku'] ?? '') !== '' ? $p['sku'] . ' · ' : '') . $p['name']),
        'desc' => trim((string) ($p['description'] ?? '')) !== '' ? (string) $p['description'] : (string) $p['name'],
        'price' => (float) $p['price'],
        'cost' => $p['cost'] === null ? null : (float) $p['cost'],
        'currency' => (string) ($p['currency'] ?? 'DOP'),
        'exempt' => (int) $p['is_exempt'] === 1,
        'unit' => (string) ($p['unit'] ?? ''),
    ], fetch_all('SELECT * FROM products WHERE active = 1 ORDER BY name ASC LIMIT 1000'));
}

/**
 * Columnas de partida que admite el esquema, en orden de inserción.
 * Deja fuera product_id / unit_cost en bases que todavía no migraron, para que
 * una instalación vieja siga guardando documentos sin romperse.
 */
function item_columns(string $table, array $base): array
{
    foreach (['unit_cost', 'product_id'] as $extra) {
        if (column_exists($table, $extra)) {
            $base[] = $extra;
        }
    }
    return $base;
}

/**
 * Inserta las partidas de un documento en quote_items / invoice_items.
 *
 * Punto único de escritura de partidas: antes cada camino (guardar, duplicar,
 * nota de crédito, facturar cotización) repetía su propio INSERT, y bastaba con
 * olvidar uno para que ese camino perdiera el costo y su margen desapareciera.
 */
function items_insert(PDO $pdo, string $table, string $fk, int $docId, array $items, array $baseCols): void
{
    $cols = item_columns($table, $baseCols);
    $sql = "INSERT INTO {$table} ({$fk}, " . implode(', ', $cols) . ')'
        . ' VALUES (' . implode(', ', array_fill(0, count($cols) + 1, '?')) . ')';
    $stmt = $pdo->prepare($sql);
    foreach ($items as $it) {
        $values = [$docId];
        foreach ($cols as $c) {
            $values[] = $it[$c] ?? null;
        }
        $stmt->execute($values);
    }
}

function product_find(int $id): ?array
{
    return ($id > 0 && products_available()) ? fetch_one('SELECT * FROM products WHERE id = ?', [$id]) : null;
}

/** Margen unitario y porcentaje de una ficha. Devuelve null si no hay costo. */
function product_margin(array $p): ?array
{
    if ($p['cost'] === null) {
        return null;
    }
    $price = (float) $p['price'];
    $cost = (float) $p['cost'];
    $amount = round($price - $cost, 2);
    return [
        'amount' => $amount,
        // Margen sobre precio de venta, que es como se lee comercialmente.
        'pct' => $price > 0.009 ? round($amount / $price * 100, 1) : null,
    ];
}

/**
 * Guarda una ficha (alta o edición). Devuelve [ok, mensaje, id].
 * El SKU, si se indica, es único: sirve para que dos personas no creen la misma
 * ficha dos veces con nombres ligeramente distintos.
 */
function product_save(array $in, int $id = 0): array
{
    if (!products_available()) {
        return [false, 'El catálogo no está disponible.', 0];
    }
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        return [false, 'El nombre es obligatorio.', 0];
    }
    $sku = trim((string) ($in['sku'] ?? ''));
    if ($sku !== '') {
        $dupe = fetch_one('SELECT id FROM products WHERE sku = ? AND id <> ?', [$sku, $id]);
        if ($dupe) {
            return [false, 'Ya existe otra ficha con el código «' . $sku . '».', 0];
        }
    }

    $kind = isset(product_kinds()[(string) ($in['kind'] ?? '')]) ? (string) $in['kind'] : 'producto';
    $category = trim((string) ($in['category'] ?? ''));
    if ($category !== '' && !isset(quote_categories()[$category])) {
        $category = '';
    }
    // El costo puede quedar en blanco: significa «todavía no lo sabemos», y es
    // mejor eso que un cero que luego se leería como margen del 100%.
    $costRaw = trim((string) ($in['cost'] ?? ''));
    $cost = $costRaw === '' ? null : round(max(0, amount_parse($costRaw)), 2);
    $price = round(max(0, amount_parse($in['price'] ?? 0)), 2);

    /* Las columnas son DECIMAL(12,2) y este MySQL no corre en modo estricto:
       un cero de más no daba error, se recortaba solo hasta el tope y quedaba
       una ficha de 9 999 999 999,99 sin que nadie se enterara. Vale más
       devolver el formulario que guardar un precio inventado. */
    $tope = 9999999999.99;
    if ($price > $tope || ($cost !== null && $cost > $tope)) {
        return [false, 'El monto excede el máximo permitido (' . money($tope) . '). Revisa si sobra algún dígito.', 0];
    }

    $fields = [
        'sku' => $sku !== '' ? $sku : null,
        'name' => $name,
        'description' => trim((string) ($in['description'] ?? '')),
        'kind' => $kind,
        'category' => $category !== '' ? $category : null,
        'brand' => trim((string) ($in['brand'] ?? '')),
        'unit' => trim((string) ($in['unit'] ?? '')),
        'cost' => $cost,
        'price' => $price,
        'currency' => strtoupper((string) ($in['currency'] ?? 'DOP')) === 'USD' ? 'USD' : 'DOP',
        'is_exempt' => ((string) ($in['is_exempt'] ?? '') === '1') ? 1 : 0,
        'active' => ((string) ($in['active'] ?? '1') === '1') ? 1 : 0,
        'notes' => trim((string) ($in['notes'] ?? '')),
    ];

    try {
        if ($id > 0) {
            $set = implode(', ', array_map(fn ($k) => "{$k} = ?", array_keys($fields)));
            db()->prepare("UPDATE products SET {$set}, updated_at = NOW() WHERE id = ?")
                ->execute([...array_values($fields), $id]);
            log_activity('product', $id, 'producto_actualizado', $name);
            return [true, 'Ficha actualizada.', $id];
        }
        $cols = implode(', ', array_keys($fields));
        $marks = implode(', ', array_fill(0, count($fields), '?'));
        db()->prepare("INSERT INTO products ({$cols}, created_at, updated_at) VALUES ({$marks}, NOW(), NOW())")
            ->execute(array_values($fields));
        $newId = (int) db()->lastInsertId();
        log_activity('product', $newId, 'producto_creado', $name);
        return [true, 'Ficha creada.', $newId];
    } catch (Throwable $e) {
        error_log('product_save: ' . $e->getMessage());
        return [false, 'No se pudo guardar la ficha. Inténtalo de nuevo.', 0];
    }
}

/**
 * Elimina una ficha. Los documentos que la usaron conservan su descripción y su
 * costo congelado: la partida no depende del catálogo para seguir siendo válida,
 * así que borrar una ficha nunca altera una factura ya emitida.
 */
function product_delete(int $id): array
{
    if ($id <= 0 || !products_available()) {
        return [false, 'La ficha no existe.'];
    }
    $used = 0;
    foreach (['quote_items', 'invoice_items'] as $t) {
        if (table_exists($t) && column_exists($t, 'product_id')) {
            $used += (int) (fetch_one("SELECT COUNT(*) c FROM {$t} WHERE product_id = ?", [$id])['c'] ?? 0);
        }
    }
    try {
        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        log_activity('product', $id, 'producto_eliminado', 'usada en ' . $used . ' partida(s)');
        if ($used === 0) {
            return [true, 'Ficha eliminada.'];
        }
        return [true, $used === 1
            ? 'Ficha eliminada. La partida que la usaba conserva su descripción y su costo.'
            : 'Ficha eliminada. Las ' . $used . ' partidas que la usaban conservan su descripción y su costo.'];
    } catch (Throwable $e) {
        error_log('product_delete: ' . $e->getMessage());
        return [false, 'No se pudo eliminar la ficha.'];
    }
}
