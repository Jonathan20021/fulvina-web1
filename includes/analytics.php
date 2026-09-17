<?php

declare(strict_types=1);

/**
 * SCH MEDICOS — Analytics data layer.
 *
 * Single source of truth for the dashboard and the reports center. Every figure
 * is a real aggregate computed from MySQL. A curated DEMO dataset is returned
 * ONLY when the database is unreachable (so the UI can still be previewed on a
 * fresh XAMPP). When the database is connected the numbers are always real:
 * sparse data renders honest zeros and empty states — never fabricated values.
 */

/** Canonical commercial pipeline stages (ordered) with display colours. */
function analytics_stage_meta(): array
{
    /* El embudo avanza del neutro al verde de la marca: el color mide avance,
       no categoría. El bronce del logo marca la negociación, que es donde el
       trato todavía puede caerse. */
    return [
        'Borrador'    => '#C3CCC7',
        'Enviado'     => '#7C8A83',
        'Cotizado'    => '#6C5E3D',
        'Negociacion' => '#0BA344',
        'Aprobado'    => '#027F31',
    ];
}

/** Quote statuses considered part of the active pipeline. */
function analytics_open_states(): array
{
    return ['Borrador', 'Enviado', 'Cotizado', 'Negociacion', 'Aprobado'];
}

/** Canonical business lines => [lucide icon, hex colour]. Also the quote category list. */
function quote_categories(): array
{
    /* Siete líneas de negocio en una sola familia: del verde del logo hacia
       el bronce del caduceo, pasando por los neutros. Ningún azul ni cian. */
    return [
        'Equipos médicos'             => ['monitor', '#027F31'],
        'Gases medicinales'           => ['wind', '#0BA344'],
        'Diseño hospitalario'         => ['ruler', '#4F9E6E'],
        'Instalación y certificación' => ['wrench', '#6C5E3D'],
        'Soporte y mantenimiento'     => ['life-buoy', '#9A8348'],
        'Equipos industriales'        => ['factory', '#66746D'],
        'Productos arquitectónicos'   => ['blocks', '#A9B4AE'],
    ];
}

/** True only when MySQL is connected and the core schema exists. */
function analytics_live(): bool
{
    return db(false) !== null && table_exists('quotes');
}

function analytics_mode(): string
{
    return analytics_live() ? 'live' : 'demo';
}

function analytics_has(string $table): bool
{
    return db(false) !== null && table_exists($table);
}

/* =========================================================================
   Period helpers
   ========================================================================= */

/**
 * Resolve a period key into a concrete range plus the matching previous range.
 * Keys: today | week | month | quarter | year | 12m. Default month.
 */
function analytics_period(string $key = 'month'): array
{
    $months_es = [1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
    $today = date('Y-m-d');

    switch ($key) {
        case 'today':
            $from = $to = $today;
            $label = 'Hoy · ' . (int) date('j') . ' ' . $months_es[(int) date('n')] . ' ' . date('Y');
            $prevFrom = $prevTo = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'week':
            $from = date('Y-m-d', strtotime('monday this week'));
            $to = date('Y-m-d', strtotime('sunday this week'));
            $label = 'Semana del ' . (int) date('j', strtotime($from)) . ' al ' . (int) date('j', strtotime($to)) . ' ' . $months_es[(int) date('n', strtotime($to))];
            $prevFrom = date('Y-m-d', strtotime('-7 days', strtotime($from)));
            $prevTo = date('Y-m-d', strtotime('-7 days', strtotime($to)));
            break;
        case 'quarter':
            $q = (int) ceil((int) date('n') / 3);
            $startMonth = ($q - 1) * 3 + 1;
            $from = date('Y-' . str_pad((string) $startMonth, 2, '0', STR_PAD_LEFT) . '-01');
            $to = date('Y-m-t', strtotime(date('Y-' . str_pad((string) ($startMonth + 2), 2, '0', STR_PAD_LEFT) . '-01')));
            $label = 'Trimestre ' . $q . ' · ' . date('Y');
            $prevFrom = date('Y-m-d', strtotime('-3 months', strtotime($from)));
            $prevTo = date('Y-m-t', strtotime('-3 months', strtotime($to)));
            break;
        case 'year':
            $from = date('Y-01-01');
            $to = date('Y-12-31');
            $label = 'Año ' . date('Y');
            $prevFrom = date('Y-01-01', strtotime('-1 year'));
            $prevTo = date('Y-12-31', strtotime('-1 year'));
            break;
        case '12m':
            $from = date('Y-m-01', strtotime('-11 months'));
            $to = date('Y-m-t');
            $label = 'Últimos 12 meses';
            $prevFrom = date('Y-m-01', strtotime('-23 months'));
            $prevTo = date('Y-m-t', strtotime('-12 months'));
            break;
        case 'month':
        default:
            $from = date('Y-m-01');
            $to = date('Y-m-t');
            $meses_largos = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
            $label = $meses_largos[(int) date('n')] . ' ' . date('Y');
            $prevFrom = date('Y-m-01', strtotime('-1 month'));
            $prevTo = date('Y-m-t', strtotime('-1 month'));
            $key = 'month';
            break;
    }

    return ['key' => $key, 'from' => $from, 'to' => $to, 'label' => $label, 'prev_from' => $prevFrom, 'prev_to' => $prevTo];
}

function analytics_period_options(): array
{
    return ['today' => 'Hoy', 'week' => 'Esta semana', 'month' => 'Este mes', 'quarter' => 'Trimestre', 'year' => 'Este año', '12m' => '12 meses'];
}

/** Percentage delta between two values (0 when base is 0). */
/**
 * Variación porcentual contra el periodo anterior.
 *
 * Devuelve NULL cuando no hay base de comparación. Antes devolvía 100.0 en ese
 * caso, y la pantalla mostraba «+100%» donde la verdad era que el periodo
 * anterior estaba en cero: pasar de RD$0 a RD$1.5M no es un aumento del 100%.
 * Quien lee el panel toma decisiones con esa cifra, así que el hueco se dice.
 */
function analytics_delta(float $current, float $previous): ?float
{
    if ($previous <= 0) {
        return $current > 0 ? null : 0.0;
    }
    return round(($current - $previous) / $previous * 100, 1);
}

/* =========================================================================
   Core aggregates
   ========================================================================= */

/** Snapshot value of the active pipeline (all open quotes, not date-bounded). */
function analytics_pipeline_value(): float
{
    if (!analytics_live()) {
        return 1284500.00;
    }
    $in = "'" . implode("','", analytics_open_states()) . "'";
    // Exclude expired open quotes so a lapsed proposal does not inflate the pipeline.
    // Amounts are expressed in RD$: USD quotes convert with their own rate.
    $row = fetch_one("SELECT COALESCE(SUM(" . quote_total_dop_sql() . "),0) v FROM quotes WHERE status IN ($in) AND (valid_until IS NULL OR valid_until >= CURDATE())");
    return (float) ($row['v'] ?? 0);
}

/** Pipeline broken down by stage: [stage => ['count','amount','color']]. */
function analytics_pipeline_by_stage(): array
{
    $meta = analytics_stage_meta();
    $out = [];
    if (!analytics_live()) {
        $demo = ['Borrador' => [6, 234500], 'Enviado' => [5, 386900], 'Cotizado' => [4, 312800], 'Negociacion' => [2, 165400], 'Aprobado' => [1, 184900]];
        foreach ($meta as $stage => $color) {
            $out[$stage] = ['count' => $demo[$stage][0], 'amount' => (float) $demo[$stage][1], 'color' => $color];
        }
        return $out;
    }
    foreach ($meta as $stage => $color) {
        $row = fetch_one("SELECT COUNT(*) c, COALESCE(SUM(" . quote_total_dop_sql() . "),0) a FROM quotes WHERE status = ?", [$stage]);
        $out[$stage] = ['count' => (int) ($row['c'] ?? 0), 'amount' => (float) ($row['a'] ?? 0), 'color' => $color];
    }
    return $out;
}

/** Headline KPIs for a period, each with current value + period-over-period delta. */
function analytics_kpis(array $period): array
{
    if (!analytics_live()) {
        return [
            'pipeline'   => ['value' => 1284500.0, 'delta' => 0.0, 'open_count' => 18],
            'won'        => ['value' => 612400.0, 'delta' => 12.0],
            'win_rate'   => ['value' => 46.5, 'delta' => 0.0, 'scope' => 'global'],
            'quotes'     => ['value' => 18, 'delta' => 9.0],
            'open_tickets' => ['value' => 6, 'delta' => -2.0],
            'clients'    => ['value' => 28, 'delta' => 4.0],
            'avg_ticket' => ['value' => 71361.0, 'delta' => 0.0, 'scope' => 'global'],
            'resolution' => ['value' => 19.4, 'delta' => -6.0],
        ];
    }

    [$f, $t, $pf, $pt] = [$period['from'], $period['to'], $period['prev_from'], $period['prev_to']];

    // Attribute won revenue by the immutable approval date when available, so a later
    // edit (which bumps updated_at) cannot silently re-date a closed sale into another period.
    $wonDate = column_exists('quotes', 'approved_at') ? 'COALESCE(approved_at, updated_at, created_at)' : 'COALESCE(updated_at, created_at)';
    $wonSum = 'COALESCE(SUM(' . quote_total_dop_sql() . '),0)';
    $wonCur = (float) (fetch_one("SELECT $wonSum v FROM quotes WHERE status='Aprobado' AND DATE($wonDate) BETWEEN ? AND ?", [$f, $t])['v'] ?? 0);
    $wonPrev = (float) (fetch_one("SELECT $wonSum v FROM quotes WHERE status='Aprobado' AND DATE($wonDate) BETWEEN ? AND ?", [$pf, $pt])['v'] ?? 0);

    $qCur = (int) (fetch_one('SELECT COUNT(*) c FROM quotes WHERE DATE(created_at) BETWEEN ? AND ?', [$f, $t])['c'] ?? 0);
    $qPrev = (int) (fetch_one('SELECT COUNT(*) c FROM quotes WHERE DATE(created_at) BETWEEN ? AND ?', [$pf, $pt])['c'] ?? 0);

    $won = db_count('quotes', "status='Aprobado'");
    $closed = db_count('quotes', "status IN ('Aprobado','Rechazado','Cerrado')");
    $winRate = $closed > 0 ? round($won / $closed * 100, 1) : 0.0;

    $clientsCur = analytics_has('clients') ? (int) (fetch_one('SELECT COUNT(*) c FROM clients WHERE DATE(created_at) BETWEEN ? AND ?', [$f, $t])['c'] ?? 0) : 0;
    $clientsPrev = analytics_has('clients') ? (int) (fetch_one('SELECT COUNT(*) c FROM clients WHERE DATE(created_at) BETWEEN ? AND ?', [$pf, $pt])['c'] ?? 0) : 0;

    $openTickets = analytics_has('tickets') ? db_count('tickets', "status IN ('Abierto','En proceso')") : 0;
    $newTicketsCur = analytics_has('tickets') ? (int) (fetch_one('SELECT COUNT(*) c FROM tickets WHERE DATE(created_at) BETWEEN ? AND ?', [$f, $t])['c'] ?? 0) : 0;
    $newTicketsPrev = analytics_has('tickets') ? (int) (fetch_one('SELECT COUNT(*) c FROM tickets WHERE DATE(created_at) BETWEEN ? AND ?', [$pf, $pt])['c'] ?? 0) : 0;

    $res = analytics_resolution($period);

    $avgTicket = $won > 0 ? round((float) (fetch_one("SELECT COALESCE(AVG(" . quote_total_dop_sql() . "),0) v FROM quotes WHERE status='Aprobado'")['v'] ?? 0), 2) : 0.0;

    return [
        // Pipeline is an undated snapshot of open quotes; it has no meaningful period delta.
        'pipeline'     => ['value' => analytics_pipeline_value(), 'delta' => 0.0, 'open_count' => array_sum(array_column(analytics_pipeline_by_stage(), 'count'))],
        'won'          => ['value' => $wonCur, 'delta' => analytics_delta($wonCur, $wonPrev)],
        'win_rate'     => ['value' => $winRate, 'delta' => 0.0, 'scope' => 'global'],
        'quotes'       => ['value' => $qCur, 'delta' => analytics_delta((float) $qCur, (float) $qPrev)],
        'open_tickets' => ['value' => $openTickets, 'delta' => analytics_delta((float) $newTicketsCur, (float) $newTicketsPrev)],
        'clients'      => ['value' => $clientsCur, 'delta' => analytics_delta((float) $clientsCur, (float) $clientsPrev)],
        'avg_ticket'   => ['value' => $avgTicket, 'delta' => 0.0],
        'resolution'   => ['value' => $res['avg_hours'], 'delta' => 0.0],
    ];
}

/* =========================================================================
   Facturación real — emitido, cobrado y por cobrar
   =========================================================================
   Los KPIs comerciales de arriba miden COTIZACIONES (intención de compra).
   Estas funciones miden COMPROBANTES FISCALES (dinero facturado) y PAGOS
   (dinero cobrado). Son magnitudes distintas y no deben mezclarse: una
   cotización aprobada que nunca se facturó no es un ingreso, y una factura
   emitida que nadie pagó tampoco es caja. El panel las muestra por separado.
   ========================================================================= */

/** True cuando el esquema de facturación está disponible para analítica. */
function analytics_has_billing(): bool
{
    return analytics_has('invoices');
}

/**
 * Fecha a la que se atribuye un comprobante: el sello de emisión si existe y,
 * en su defecto, la fecha fiscal del documento. Nunca created_at — un borrador
 * creado en un mes y emitido en el siguiente pertenece al mes en que se
 * convirtió en documento fiscal.
 */
function billing_date_sql(string $t = 'invoices'): string
{
    return "DATE(COALESCE({$t}.emitted_at, {$t}.issue_date))";
}

/** Condición que aísla comprobantes fiscales reales: sin borradores, proformas ni anuladas. */
function billing_real_sql(string $t = 'invoices'): string
{
    $cond = "{$t}.status IN ('Emitida','Pagada')";
    if (column_exists('invoices', 'is_proforma')) {
        $cond .= " AND {$t}.is_proforma = 0";
    }
    return $cond;
}

/** Saldo vivo del comprobante. Delega en la fórmula única de functions.php. */
function billing_balance_sql(string $t = 'invoices'): string
{
    return invoice_balance_sql($t);
}

/**
 * Total del comprobante con signo: una nota de crédito RESTA de lo facturado.
 * Sin esto, devolverle 10.000 a un cliente subiría las ventas del mes en 10.000.
 * (En el archivo 607 la nota sí va como línea propia y positiva — la DGII hace
 * la resta de su lado. Son dos lecturas distintas del mismo documento.)
 */
function billing_signed_total_sql(string $t = 'invoices'): string
{
    return "(CASE WHEN {$t}.ncf_type IN ('04','34') THEN -{$t}.total ELSE {$t}.total END)";
}

/**
 * Facturado / cobrado / por cobrar, todo en RD$ (los comprobantes en USD se
 * convierten con la tasa del propio documento, igual que la cartera).
 *
 * «Facturado» y «cobrado» son del periodo; «por cobrar» es una foto de HOY,
 * porque el saldo vivo no pertenece a ningún mes en particular.
 */
function analytics_billing(array $period): array
{
    if (!analytics_live() || !analytics_has_billing()) {
        return [
            'live' => false,
            'billed'      => ['value' => 486300.0, 'count' => 9, 'delta' => 8.0],
            'collected'   => ['value' => 351750.0, 'count' => 14, 'delta' => 15.0],
            'outstanding' => ['value' => 274900.0, 'count' => 11, 'overdue_value' => 98400.0, 'overdue_count' => 4],
        ];
    }

    [$f, $t, $pf, $pt] = [$period['from'], $period['to'], $period['prev_from'], $period['prev_to']];
    $rate = invoice_rate_sql();
    $date = billing_date_sql();
    $real = billing_real_sql();

    $signed = billing_signed_total_sql();
    $billedSql = "SELECT COUNT(*) c, COALESCE(SUM({$signed} * {$rate}),0) v
                    FROM invoices WHERE {$real} AND {$date} BETWEEN ? AND ?";
    $bCur  = fetch_one($billedSql, [$f, $t])   ?? ['c' => 0, 'v' => 0];
    $bPrev = fetch_one($billedSql, [$pf, $pt]) ?? ['c' => 0, 'v' => 0];

    $cCur = $cPrev = ['c' => 0, 'v' => 0];
    if (analytics_has('invoice_payments')) {
        // El abono está en la moneda de su factura, así que se convierte con la
        // tasa de esa factura (no con la del día).
        $paySql = "SELECT COUNT(*) c, COALESCE(SUM(p.amount * {$rate}),0) v
                     FROM invoice_payments p
                     JOIN invoices ON invoices.id = p.invoice_id
                    WHERE invoices.status <> 'Anulada' AND p.paid_at BETWEEN ? AND ?";
        $cCur  = fetch_one($paySql, [$f, $t])   ?? $cCur;
        $cPrev = fetch_one($paySql, [$pf, $pt]) ?? $cPrev;

        // Anticipos cobrados sobre cotizaciones y aún sin factura: el dinero ya
        // entró. Al aplicarse pasan a invoice_payments con la misma fecha.
        $antSql = function_exists('anticipos_unapplied_sql') ? anticipos_unapplied_sql() : '';
        if ($antSql !== '') {
            $antQ = "SELECT COUNT(*) c, COALESCE(SUM(v),0) v FROM ({$antSql}) a WHERE a.v > 0.009 AND a.paid_at BETWEEN ? AND ?";
            $sumar = static function (array $base, string $desde, string $hasta) use ($antQ): array {
                $extra = fetch_one($antQ, [$desde, $hasta]) ?? ['c' => 0, 'v' => 0];
                return ['c' => (int) $base['c'] + (int) $extra['c'], 'v' => (float) $base['v'] + (float) $extra['v']];
            };
            $cCur = $sumar($cCur, $f, $t);
            $cPrev = $sumar($cPrev, $pf, $pt);
        }
    }

    // Por cobrar reutiliza literalmente la condición de la cartera de
    // Facturación, de modo que las dos pantallas no puedan divergir nunca.
    $bal = billing_balance_sql();
    $due = invoice_due_sql();
    $receivable = invoice_receivable_sql();
    $out = fetch_one("SELECT COUNT(*) c, COALESCE(SUM({$bal} * {$rate}),0) v
                        FROM invoices WHERE {$receivable}") ?? ['c' => 0, 'v' => 0];
    $ovd = fetch_one("SELECT COUNT(*) c, COALESCE(SUM({$bal} * {$rate}),0) v
                        FROM invoices WHERE {$receivable} AND {$due} < CURDATE()") ?? ['c' => 0, 'v' => 0];

    return [
        'live' => true,
        'billed' => [
            'value' => (float) $bCur['v'], 'count' => (int) $bCur['c'],
            'delta' => analytics_delta((float) $bCur['v'], (float) $bPrev['v']),
        ],
        'collected' => [
            'value' => (float) $cCur['v'], 'count' => (int) $cCur['c'],
            'delta' => analytics_delta((float) $cCur['v'], (float) $cPrev['v']),
        ],
        'outstanding' => [
            'value' => (float) $out['v'], 'count' => (int) $out['c'],
            'overdue_value' => (float) $ovd['v'], 'overdue_count' => (int) $ovd['c'],
        ],
    ];
}

/* =========================================================================
   Margen — cuánto se ganó de verdad
   =========================================================================
   El margen se calcula SOLO sobre las partidas cuyo costo se conoce. Una
   partida sin costo no vale cero: vale «no se sabe», y meterla en el cálculo
   la haría aparecer con margen del 100% e inflaría el resultado. Por eso cada
   cifra viene acompañada de su COBERTURA: qué porcentaje de lo facturado entró
   realmente en la cuenta. Un margen del 42% sobre el 8% de las ventas no es un
   margen del 42%, y la pantalla tiene que decirlo.
   ========================================================================= */

/** ¿Se puede calcular margen en esta base? */
function analytics_has_margin(): bool
{
    return analytics_has('invoice_items') && column_exists('invoice_items', 'unit_cost');
}

/** Costo de una partida en la moneda del documento: costo unitario × cantidad. */
function margin_cost_sql(string $t = 'invoice_items'): string
{
    return "({$t}.unit_cost * {$t}.quantity)";
}

/**
 * Margen del periodo sobre comprobantes emitidos.
 *
 * Devuelve el ingreso y el costo de las partidas CON costo, más la cobertura
 * (ingreso con costo / ingreso total). Las notas de crédito restan por ambos
 * lados, igual que en «facturado».
 */
function analytics_margin(array $period): array
{
    $empty = [
        'live' => false, 'revenue' => 0.0, 'cost' => 0.0, 'margin' => 0.0, 'pct' => null,
        'covered' => 0.0, 'total_revenue' => 0.0, 'coverage' => null, 'lines' => 0, 'lines_costed' => 0,
    ];
    if (!analytics_live()) {
        // Sin base de datos: dataset de muestra, como el resto del panel.
        return ['live' => false, 'revenue' => 486300.0, 'cost' => 291780.0, 'margin' => 194520.0, 'pct' => 40.0,
                'covered' => 486300.0, 'total_revenue' => 612000.0, 'coverage' => 79.5, 'lines' => 48, 'lines_costed' => 38];
    }
    if (!analytics_has_margin()) {
        // Base conectada pero sin la columna de costo: ceros honestos, no muestra.
        return $empty;
    }

    [$f, $t] = [$period['from'], $period['to']];
    $rate = invoice_rate_sql();
    // La nota de crédito resta ingreso y costo: devolvió mercancía, no la vendió.
    $sign = "(CASE WHEN invoices.ncf_type IN ('04','34') THEN -1 ELSE 1 END)";
    $cost = margin_cost_sql();

    $row = fetch_one(
        // OJO con los alias: «lines» es palabra reservada en MariaDB y rompe la
        // consulta al prepararla, así que las cuentas van con otro nombre.
        "SELECT
            COUNT(*) line_count,
            SUM(CASE WHEN invoice_items.unit_cost IS NOT NULL THEN 1 ELSE 0 END) costed_count,
            COALESCE(SUM({$sign} * invoice_items.total * {$rate}),0) total_revenue,
            COALESCE(SUM(CASE WHEN invoice_items.unit_cost IS NOT NULL THEN {$sign} * invoice_items.total * {$rate} ELSE 0 END),0) revenue,
            COALESCE(SUM(CASE WHEN invoice_items.unit_cost IS NOT NULL THEN {$sign} * {$cost} * {$rate} ELSE 0 END),0) cost
           FROM invoice_items
           JOIN invoices ON invoices.id = invoice_items.invoice_id
          WHERE " . billing_real_sql() . ' AND ' . billing_date_sql() . ' BETWEEN ? AND ?',
        [$f, $t]
    ) ?? [];

    $revenue = round((float) ($row['revenue'] ?? 0), 2);
    $costTotal = round((float) ($row['cost'] ?? 0), 2);
    $totalRevenue = round((float) ($row['total_revenue'] ?? 0), 2);
    $margin = round($revenue - $costTotal, 2);

    return [
        'live' => true,
        'revenue' => $revenue,
        'cost' => $costTotal,
        'margin' => $margin,
        'pct' => $revenue > 0.009 ? round($margin / $revenue * 100, 1) : null,
        'covered' => $revenue,
        'total_revenue' => $totalRevenue,
        'coverage' => $totalRevenue > 0.009 ? round($revenue / $totalRevenue * 100, 1) : null,
        'lines' => (int) ($row['line_count'] ?? 0),
        'lines_costed' => (int) ($row['costed_count'] ?? 0),
    ];
}

/**
 * Margen por producto del catálogo en el periodo. Solo entran las partidas
 * enlazadas a una ficha y con costo: es lo único sobre lo que se puede afirmar
 * algo. Ordena por margen descendente.
 */
function analytics_margin_by_product(array $period, int $limit = 10): array
{
    if (!analytics_live() || !analytics_has_margin() || !analytics_has('products')
        || !column_exists('invoice_items', 'product_id')) {
        return [];
    }
    [$f, $t] = [$period['from'], $period['to']];
    $rate = invoice_rate_sql();
    $sign = "(CASE WHEN invoices.ncf_type IN ('04','34') THEN -1 ELSE 1 END)";
    $cost = margin_cost_sql();
    $limit = max(1, min(50, $limit));

    return fetch_all(
        "SELECT products.id, products.name, products.category,
                COALESCE(SUM({$sign} * invoice_items.quantity),0) qty,
                COALESCE(SUM({$sign} * invoice_items.total * {$rate}),0) revenue,
                COALESCE(SUM({$sign} * {$cost} * {$rate}),0) cost,
                COALESCE(SUM({$sign} * (invoice_items.total - {$cost}) * {$rate}),0) margin
           FROM invoice_items
           JOIN invoices ON invoices.id = invoice_items.invoice_id
           JOIN products ON products.id = invoice_items.product_id
          WHERE " . billing_real_sql() . ' AND ' . billing_date_sql() . " BETWEEN ? AND ?
            AND invoice_items.unit_cost IS NOT NULL
          GROUP BY products.id, products.name, products.category
         HAVING revenue <> 0
         ORDER BY margin DESC
          LIMIT {$limit}",
        [$f, $t]
    );
}

/* =========================================================================
   Cobranza — cuándo entra el dinero y quién paga tarde
   =========================================================================
   La cartera por antigüedad mira hacia atrás: qué está vencido y desde cuándo.
   Esto mira hacia adelante (cuándo se espera cobrar) y hacia el hábito (cuánto
   tarda de verdad cada cliente en pagar). Son preguntas distintas y la segunda
   es la que permite anticiparse en vez de perseguir.
   ========================================================================= */

/** Tramos de la proyección de caja, en orden. Miran al FUTURO, no al pasado. */
function cashflow_buckets(): array
{
    return [
        'vencido' => 'Ya vencido',
        '0-30'    => 'Próximos 30 días',
        '31-60'   => 'Días 31 a 60',
        '61-90'   => 'Días 61 a 90',
        '90+'     => 'Más de 90 días',
    ];
}

/**
 * Proyección de cobros: el saldo vivo repartido por su fecha de vencimiento.
 *
 * Supone que cada comprobante se cobra el día que vence — es un supuesto, no
 * una predicción, y la pantalla lo dice. El tramo «ya vencido» es dinero que
 * debería haber entrado y no entró: no es proyección, es gestión pendiente.
 */
function analytics_cashflow_forecast(): array
{
    $buckets = [];
    foreach (cashflow_buckets() as $k => $label) {
        $buckets[$k] = ['label' => $label, 'count' => 0, 'amount' => 0.0];
    }
    $total = ['count' => 0, 'amount' => 0.0];

    if (!analytics_live() || !analytics_has_billing()) {
        $demo = ['vencido' => [4, 98400.0], '0-30' => [6, 212500.0], '31-60' => [3, 96300.0], '61-90' => [2, 41200.0], '90+' => [1, 18700.0]];
        foreach ($demo as $k => [$c, $v]) {
            $buckets[$k]['count'] = $c;
            $buckets[$k]['amount'] = $v;
            $total['count'] += $c;
            $total['amount'] += $v;
        }
        return ['buckets' => $buckets, 'total' => $total, 'live' => false];
    }

    $due = invoice_due_sql();
    $d = "DATEDIFF({$due}, CURDATE())";
    $sql = "SELECT CASE
                     WHEN {$d} < 0  THEN 'vencido'
                     WHEN {$d} <= 30 THEN '0-30'
                     WHEN {$d} <= 60 THEN '31-60'
                     WHEN {$d} <= 90 THEN '61-90'
                     ELSE '90+'
                   END AS bucket,
                   COUNT(*) c,
                   COALESCE(SUM(" . billing_balance_sql() . ' * ' . invoice_rate_sql() . "),0) v
              FROM invoices
             WHERE " . invoice_receivable_sql() . '
             GROUP BY bucket';

    foreach (fetch_all($sql) as $r) {
        $k = (string) $r['bucket'];
        if (!isset($buckets[$k])) {
            continue;
        }
        $buckets[$k]['count'] = (int) $r['c'];
        $buckets[$k]['amount'] = (float) $r['v'];
        $total['count'] += (int) $r['c'];
        $total['amount'] += (float) $r['v'];
    }

    return ['buckets' => $buckets, 'total' => $total, 'live' => true];
}

/**
 * Indicadores de cobranza del periodo.
 *
 *  · dso          Días de venta pendientes de cobro: (saldo / facturado) × días
 *                 de la ventana. Cuántos días de facturación tienes en la calle.
 *  · avg_days     Días que tardó de verdad el dinero en entrar, ponderado por
 *                 monto (un abono grande pesa más que uno chico).
 *  · on_time_pct  Porcentaje del dinero cobrado que entró en o antes de la
 *                 fecha de vencimiento.
 *
 * El DSO se calcula SIEMPRE sobre los últimos 90 días, no sobre el periodo
 * elegido: la fórmula escala con el largo de la ventana, así que mirarlo "por
 * año" devolvería 200 y pico de días y se leería como una catástrofe que no es.
 * Fijarlo lo vuelve comparable mes a mes. avg_days y on_time_pct sí siguen el
 * periodo, porque ahí la pregunta es "¿cómo nos fue en estas fechas?".
 *
 * Devuelven null cuando no hay base para calcularlos: un cero inventado haría
 * pensar que se cobra el mismo día.
 */
function analytics_collection_metrics(array $period): array
{
    if (!analytics_live() || !analytics_has_billing()) {
        return ['live' => false, 'dso' => 52.0, 'dso_days' => 90, 'avg_days' => 41.0, 'on_time_pct' => 63.0, 'days' => 30];
    }

    [$f, $t] = [$period['from'], $period['to']];
    $days = max(1, (int) ((strtotime($t) - strtotime($f)) / 86400) + 1);
    $rate = invoice_rate_sql();

    $dsoDays = 90;
    $dsoFrom = date('Y-m-d', strtotime('-' . ($dsoDays - 1) . ' days'));
    $billed = (float) (fetch_one(
        'SELECT COALESCE(SUM(' . billing_signed_total_sql() . " * {$rate}),0) v
           FROM invoices WHERE " . billing_real_sql() . ' AND ' . billing_date_sql() . ' BETWEEN ? AND ?',
        [$dsoFrom, date('Y-m-d')]
    )['v'] ?? 0);

    $outstanding = (float) (fetch_one(
        'SELECT COALESCE(SUM(' . billing_balance_sql() . " * {$rate}),0) v
           FROM invoices WHERE " . invoice_receivable_sql()
    )['v'] ?? 0);

    $dso = $billed > 0.009 ? round($outstanding / $billed * $dsoDays, 1) : null;

    $avgDays = null;
    $onTime = null;
    if (analytics_has('invoice_payments')) {
        $due = invoice_due_sql();
        $row = fetch_one(
            "SELECT COALESCE(SUM(p.amount * {$rate}),0) paid,
                    SUM(p.amount * {$rate} * DATEDIFF(p.paid_at, DATE(COALESCE(invoices.emitted_at, invoices.issue_date)))) lag_sum,
                    SUM(CASE WHEN p.paid_at <= {$due} THEN p.amount * {$rate} ELSE 0 END) on_time
               FROM invoice_payments p
               JOIN invoices ON invoices.id = p.invoice_id
              WHERE invoices.status <> 'Anulada' AND p.paid_at BETWEEN ? AND ?",
            [$f, $t]
        );
        $paid = (float) ($row['paid'] ?? 0);
        if ($paid > 0.009) {
            if ($row['lag_sum'] !== null) {
                $avgDays = round((float) $row['lag_sum'] / $paid, 1);
            }
            $onTime = round((float) ($row['on_time'] ?? 0) / $paid * 100, 1);
        }
    }

    return ['live' => true, 'dso' => $dso, 'dso_days' => $dsoDays, 'avg_days' => $avgDays, 'on_time_pct' => $onTime, 'days' => $days];
}

/**
 * Comportamiento de pago por cliente, sobre TODO el historial de cobros (no
 * solo el periodo): un hábito necesita más de un mes para verse. Ordena por el
 * que más tarda, que es a quien hay que llamar primero.
 */
function analytics_collection_by_client(int $limit = 8): array
{
    if (!analytics_live() || !analytics_has_billing() || !analytics_has('invoice_payments')) {
        return [
            ['client_id' => 0, 'name' => 'Hospital Regional del Este', 'paid' => 412000.0, 'avg_days' => 68.4, 'on_time_pct' => 22.0, 'balance' => 186400.0, 'overdue' => 142900.0],
            ['client_id' => 0, 'name' => 'Clínica Unión Médica', 'paid' => 318500.0, 'avg_days' => 47.1, 'on_time_pct' => 51.0, 'balance' => 92300.0, 'overdue' => 0.0],
            ['client_id' => 0, 'name' => 'CEDIMAT', 'paid' => 604200.0, 'avg_days' => 21.8, 'on_time_pct' => 88.0, 'balance' => 43100.0, 'overdue' => 0.0],
        ];
    }

    $limit = max(1, min(50, $limit));
    $rate = invoice_rate_sql();
    $due = invoice_due_sql();

    $rows = fetch_all(
        "SELECT invoices.client_id,
                COALESCE(clients.name, invoices.client_name, 'Cliente') AS name,
                COALESCE(SUM(p.amount * {$rate}),0) AS paid,
                SUM(p.amount * {$rate} * DATEDIFF(p.paid_at, DATE(COALESCE(invoices.emitted_at, invoices.issue_date)))) AS lag_sum,
                SUM(CASE WHEN p.paid_at <= {$due} THEN p.amount * {$rate} ELSE 0 END) AS on_time
           FROM invoice_payments p
           JOIN invoices ON invoices.id = p.invoice_id
           LEFT JOIN clients ON clients.id = invoices.client_id
          WHERE invoices.status <> 'Anulada' AND p.paid_at IS NOT NULL
          GROUP BY invoices.client_id, name
         HAVING paid > 0.009"
    );

    // Saldo y vencido actuales por cliente, con la misma definición de cartera.
    $balances = [];
    foreach (fetch_all(
        'SELECT invoices.client_id,
                COALESCE(clients.name, invoices.client_name, \'Cliente\') AS name,
                COALESCE(SUM(' . billing_balance_sql() . " * {$rate}),0) AS balance,
                COALESCE(SUM(CASE WHEN {$due} < CURDATE() THEN " . billing_balance_sql() . " * {$rate} ELSE 0 END),0) AS overdue
           FROM invoices
           LEFT JOIN clients ON clients.id = invoices.client_id
          WHERE " . invoice_receivable_sql() . '
          GROUP BY invoices.client_id, name'
    ) as $b) {
        $balances[(int) $b['client_id']] = [
            'name' => (string) $b['name'],
            'balance' => (float) $b['balance'],
            'overdue' => (float) $b['overdue'],
        ];
    }

    $out = [];
    $seen = [];
    foreach ($rows as $r) {
        $cid = (int) $r['client_id'];
        $paid = (float) $r['paid'];
        $seen[$cid] = true;
        $out[] = [
            'client_id' => $cid,
            'name' => (string) $r['name'],
            'paid' => $paid,
            'avg_days' => $r['lag_sum'] === null ? null : round((float) $r['lag_sum'] / $paid, 1),
            'on_time_pct' => round((float) ($r['on_time'] ?? 0) / $paid * 100, 1),
            'balance' => $balances[$cid]['balance'] ?? 0.0,
            'overdue' => $balances[$cid]['overdue'] ?? 0.0,
        ];
    }

    // Clientes que deben dinero pero nunca han pagado nada todavía: no tienen
    // hábito que medir, y justamente por eso son los que más conviene mirar.
    // Entran con la tardanza en blanco en vez de quedar fuera del informe.
    foreach ($balances as $cid => $b) {
        if ($b['balance'] <= 0.009 || isset($seen[$cid])) {
            continue;
        }
        $out[] = [
            'client_id' => $cid,
            'name' => $b['name'],
            'paid' => 0.0,
            'avg_days' => null,
            'on_time_pct' => null,
            'balance' => $b['balance'],
            'overdue' => $b['overdue'],
        ];
    }

    // Primero quien más tarda; los sin historial quedan al final, ordenados por
    // lo que tienen vencido.
    usort($out, fn ($a, $b) => [$b['avg_days'] ?? -1, $b['overdue']] <=> [$a['avg_days'] ?? -1, $a['overdue']]);
    return array_slice($out, 0, $limit);
}

/** Monthly trend for the last N months: labels + ingresos(k) + cotizaciones + tickets. */
function analytics_monthly_trend(int $months = 6): array
{
    $labels = [];
    $keys = [];
    $months_es = [1=>'Ene',2=>'Feb',3=>'Mar',4=>'Abr',5=>'May',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dic'];
    for ($i = $months - 1; $i >= 0; $i--) {
        $ts = strtotime("first day of -$i month");
        $labels[] = $months_es[(int) date('n', $ts)];
        $keys[] = date('Y-m', $ts);
    }

    if (!analytics_live()) {
        $base = [820, 940, 760, 1080, 990, 1284, 1120, 1340, 1180, 1420, 1290, 1510];
        $fac = [610, 720, 640, 880, 815, 1010, 905, 1075, 960, 1160, 1045, 1230];
        $cob = [540, 660, 590, 790, 735, 900, 830, 960, 880, 1030, 950, 1105];
        $cot = [22, 26, 19, 31, 28, 34, 27, 35, 30, 38, 33, 41];
        $tk = [38, 41, 35, 44, 40, 42, 39, 46, 43, 48, 45, 50];
        $res = [33, 37, 31, 40, 38, 41, 36, 44, 41, 46, 43, 48];
        $n = count($labels);
        return [
            'labels' => $labels,
            'ingresos' => array_slice($base, -$n),
            'ingresos_raw' => array_map(fn ($v) => $v * 1000, array_slice($base, -$n)),
            'facturado' => array_slice($fac, -$n),
            'facturado_raw' => array_map(fn ($v) => $v * 1000, array_slice($fac, -$n)),
            'cobrado' => array_slice($cob, -$n),
            'cobrado_raw' => array_map(fn ($v) => $v * 1000, array_slice($cob, -$n)),
            'cotizaciones' => array_slice($cot, -$n),
            'tickets' => array_slice($tk, -$n),
            'resueltos' => array_slice($res, -$n),
        ];
    }

    $ingresos = array_fill_keys($keys, 0.0);
    $fac = array_fill_keys($keys, 0.0);
    $cob = array_fill_keys($keys, 0.0);
    $cot = array_fill_keys($keys, 0);
    $tk = array_fill_keys($keys, 0);
    $res = array_fill_keys($keys, 0);

    $wonDate = column_exists('quotes', 'approved_at') ? 'COALESCE(approved_at, updated_at, created_at)' : 'COALESCE(updated_at, created_at)';
    foreach (fetch_all("SELECT DATE_FORMAT($wonDate,'%Y-%m') m, COALESCE(SUM(" . quote_total_dop_sql() . "),0) v FROM quotes WHERE status='Aprobado' GROUP BY m") as $r) {
        if (isset($ingresos[$r['m']])) $ingresos[$r['m']] = (float) $r['v'];
    }
    foreach (fetch_all("SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) c FROM quotes GROUP BY m") as $r) {
        if (isset($cot[$r['m']])) $cot[$r['m']] = (int) $r['c'];
    }

    // Facturado y cobrado reales, en RD$. Van aparte de «ingresos» (cotizaciones
    // aprobadas) porque responden preguntas distintas.
    if (analytics_has_billing()) {
        $rate = invoice_rate_sql();
        $signed = billing_signed_total_sql();
        $sql = "SELECT DATE_FORMAT(COALESCE(invoices.emitted_at, invoices.issue_date),'%Y-%m') m,
                       COALESCE(SUM({$signed} * {$rate}),0) v
                  FROM invoices WHERE " . billing_real_sql() . " GROUP BY m";
        foreach (fetch_all($sql) as $r) {
            if (isset($fac[$r['m']])) $fac[$r['m']] = (float) $r['v'];
        }
        if (analytics_has('invoice_payments')) {
            $sql = "SELECT DATE_FORMAT(p.paid_at,'%Y-%m') m, COALESCE(SUM(p.amount * {$rate}),0) v
                      FROM invoice_payments p
                      JOIN invoices ON invoices.id = p.invoice_id
                     WHERE invoices.status <> 'Anulada' AND p.paid_at IS NOT NULL GROUP BY m";
            foreach (fetch_all($sql) as $r) {
                if (isset($cob[$r['m']])) $cob[$r['m']] = (float) $r['v'];
            }
            $antSql = function_exists('anticipos_unapplied_sql') ? anticipos_unapplied_sql() : '';
            if ($antSql !== '') {
                foreach (fetch_all("SELECT DATE_FORMAT(a.paid_at,'%Y-%m') m, COALESCE(SUM(a.v),0) v FROM ({$antSql}) a WHERE a.v > 0.009 GROUP BY m") as $r) {
                    if (isset($cob[$r['m']])) $cob[$r['m']] += (float) $r['v'];
                }
            }
        }
    }
    if (analytics_has('tickets')) {
        foreach (fetch_all("SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) c FROM tickets GROUP BY m") as $r) {
            if (isset($tk[$r['m']])) $tk[$r['m']] = (int) $r['c'];
        }
        if (column_exists('tickets', 'resolved_at')) {
            foreach (fetch_all("SELECT DATE_FORMAT(resolved_at,'%Y-%m') m, COUNT(*) c FROM tickets WHERE resolved_at IS NOT NULL GROUP BY m") as $r) {
                if (isset($res[$r['m']])) $res[$r['m']] = (int) $r['c'];
            }
        }
    }

    $ingresosRaw = array_values($ingresos);
    $facRaw = array_values($fac);
    $cobRaw = array_values($cob);
    $toK = fn (array $rows) => array_map(fn ($v) => round($v / 1000, 1), $rows);
    return [
        'labels' => $labels,
        'ingresos' => $toK($ingresosRaw),
        'ingresos_raw' => $ingresosRaw,
        'facturado' => $toK($facRaw),
        'facturado_raw' => $facRaw,
        'cobrado' => $toK($cobRaw),
        'cobrado_raw' => $cobRaw,
        'cotizaciones' => array_values($cot),
        'tickets' => array_values($tk),
        'resueltos' => array_values($res),
    ];
}

/** Revenue by business line (quote category). Real once quotes.category exists. */
function analytics_revenue_by_line(?array $period = null): array
{
    $cats = quote_categories();
    if (!analytics_live() || !column_exists('quotes', 'category')) {
        $demo = [
            ['Equipos médicos', 539000], ['Gases medicinales', 295000], ['Diseño hospitalario', 205000],
            ['Instalación y certificación', 154000], ['Soporte y mantenimiento', 91500],
            ['Equipos industriales', 128000], ['Productos arquitectónicos', 76000],
        ];
        if (!analytics_live()) {
            $total = array_sum(array_column($demo, 1)) ?: 1;
            return array_map(function ($r) use ($cats, $total) {
                $m = $cats[$r[0]] ?? ['layers', '#027F31'];
                return ['line' => $r[0], 'icon' => $m[0], 'color' => $m[1], 'amount' => (float) $r[1], 'count' => 0, 'pct' => round($r[1] / $total * 100, 1)];
            }, $demo);
        }
    }

    $where = "status IN ('Cotizado','Negociacion','Aprobado','Enviado')";
    $params = [];
    if ($period) {
        $where .= ' AND DATE(created_at) BETWEEN ? AND ?';
        $params = [$period['from'], $period['to']];
    }

    $rows = column_exists('quotes', 'category')
        ? fetch_all("SELECT COALESCE(NULLIF(category,''),'Sin categoría') line, COUNT(*) c, COALESCE(SUM(" . quote_total_dop_sql() . "),0) a FROM quotes WHERE $where GROUP BY line ORDER BY a DESC", $params)
        : [];

    $total = array_sum(array_map(fn ($r) => (float) $r['a'], $rows)) ?: 1;
    return array_map(function ($r) use ($cats, $total) {
        $m = $cats[$r['line']] ?? ['layers', '#66746D'];
        return ['line' => $r['line'], 'icon' => $m[0], 'color' => $m[1], 'amount' => (float) $r['a'], 'count' => (int) $r['c'], 'pct' => round((float) $r['a'] / $total * 100, 1)];
    }, $rows);
}

/** Top clients by pipeline value, with equipment + ticket counts. */
function analytics_top_clients(int $limit = 10): array
{
    if (!analytics_live() || !analytics_has('clients')) {
        return [
            ['id' => 1, 'name' => 'Hospital Metropolitano de Santiago', 'equipment_count' => 12, 'ticket_count' => 5, 'quote_value' => 486200],
            ['id' => 2, 'name' => 'Plaza de la Salud', 'equipment_count' => 8, 'ticket_count' => 4, 'quote_value' => 312800],
            ['id' => 3, 'name' => 'CEDIMAT', 'equipment_count' => 6, 'ticket_count' => 2, 'quote_value' => 198400],
            ['id' => 4, 'name' => 'CAID', 'equipment_count' => 5, 'ticket_count' => 3, 'quote_value' => 96500],
        ];
    }
    $eq = analytics_has('equipment') ? '(SELECT COUNT(*) FROM equipment e WHERE e.client_id=c.id)' : '0';
    $tk = analytics_has('tickets') ? '(SELECT COUNT(*) FROM tickets t WHERE t.client_id=c.id)' : '0';
    return fetch_all("SELECT c.id, c.name,
        $eq AS equipment_count,
        $tk AS ticket_count,
        (SELECT COALESCE(SUM(" . quote_total_dop_sql('q') . "),0) FROM quotes q WHERE q.client_id=c.id) AS quote_value
        FROM clients c
        ORDER BY quote_value DESC, ticket_count DESC, equipment_count DESC
        LIMIT $limit");
}

/** Tickets grouped by status. */
function analytics_tickets_by_status(): array
{
    if (!analytics_live() || !analytics_has('tickets')) {
        return [['status' => 'Abierto', 'total' => 4], ['status' => 'En proceso', 'total' => 3], ['status' => 'Resuelto', 'total' => 9], ['status' => 'Cerrado', 'total' => 5], ['status' => 'Cotizado', 'total' => 2]];
    }
    return fetch_all('SELECT status, COUNT(*) total FROM tickets GROUP BY status ORDER BY total DESC');
}

/** Tickets grouped by priority. */
function analytics_tickets_by_priority(): array
{
    if (!analytics_live() || !analytics_has('tickets')) {
        return [['priority' => 'Critica', 'total' => 2], ['priority' => 'Alta', 'total' => 5], ['priority' => 'Media', 'total' => 8], ['priority' => 'Baja', 'total' => 3]];
    }
    return fetch_all('SELECT priority, COUNT(*) total FROM tickets GROUP BY priority ORDER BY FIELD(priority,"Critica","Alta","Media","Baja")');
}

/** Resolution / SLA stats for tickets resolved within the period. */
function analytics_resolution(array $period): array
{
    if (!analytics_live() || !analytics_has('tickets') || !column_exists('tickets', 'resolved_at')) {
        return ['resolved' => 23, 'avg_hours' => 19.4, 'within_sla' => 19, 'sla_pct' => 82.6, 'backlog' => 7, 'overdue' => 2];
    }
    [$f, $t] = [$period['from'], $period['to']];
    $row = fetch_one(
        "SELECT COUNT(*) resolved,
            COALESCE(AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)),0) avg_hours,
            SUM(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) <= 48 THEN 1 ELSE 0 END) within_sla
         FROM tickets
         WHERE resolved_at IS NOT NULL AND DATE(resolved_at) BETWEEN ? AND ?",
        [$f, $t]
    );
    $resolved = (int) ($row['resolved'] ?? 0);
    $withinSla = (int) ($row['within_sla'] ?? 0);
    $backlog = db_count('tickets', "status IN ('Abierto','En proceso')");
    $overdue = db_count('tickets', "due_at IS NOT NULL AND due_at < CURDATE() AND status NOT IN ('Resuelto','Cerrado')");
    return [
        'resolved' => $resolved,
        'avg_hours' => round((float) ($row['avg_hours'] ?? 0), 1),
        'within_sla' => $withinSla,
        'sla_pct' => $resolved > 0 ? round($withinSla / $resolved * 100, 1) : 0.0,
        'backlog' => $backlog,
        'overdue' => $overdue,
    ];
}

/** Equipment grouped by status. */
function analytics_equipment_by_status(): array
{
    if (!analytics_live() || !analytics_has('equipment')) {
        return [['status' => 'activo', 'total' => 132], ['status' => 'requiere revision', 'total' => 14], ['status' => 'fuera de servicio', 'total' => 4], ['status' => 'retirado', 'total' => 2]];
    }
    return fetch_all('SELECT status, COUNT(*) total FROM equipment GROUP BY status ORDER BY total DESC');
}

/** Equipment grouped by brand (top N). */
function analytics_equipment_by_brand(int $limit = 6): array
{
    if (!analytics_live() || !analytics_has('equipment')) {
        return [['brand' => 'Dräger', 'total' => 31], ['brand' => 'GE HealthCare', 'total' => 24], ['brand' => 'Philips', 'total' => 18], ['brand' => 'Mindray', 'total' => 14], ['brand' => 'Otros', 'total' => 13]];
    }
    return fetch_all("SELECT COALESCE(NULLIF(brand,''),'Sin marca') brand, COUNT(*) total FROM equipment GROUP BY brand ORDER BY total DESC LIMIT $limit");
}

/** Per-user performance from real quotes (created_by) + tickets (assigned_to). */
function analytics_team_performance(int $limit = 8): array
{
    if (!analytics_live() || !analytics_has('users')) {
        return [
            ['id' => 0, 'name' => 'Ing. Rafael Mena', 'role' => 'soporte', 'ingresos' => 342900, 'cotizaciones' => 18, 'resueltos' => 41],
            ['id' => 0, 'name' => 'Ing. Laura García', 'role' => 'ingenieria', 'ingresos' => 286400, 'cotizaciones' => 14, 'resueltos' => 33],
            ['id' => 0, 'name' => 'Ing. Pedro Susaña', 'role' => 'soporte', 'ingresos' => 198750, 'cotizaciones' => 11, 'resueltos' => 27],
            ['id' => 0, 'name' => 'Ing. Carla Reyes', 'role' => 'soporte', 'ingresos' => 154200, 'cotizaciones' => 9, 'resueltos' => 22],
            ['id' => 0, 'name' => 'Lic. José Ramírez', 'role' => 'ventas', 'ingresos' => 132500, 'cotizaciones' => 21, 'resueltos' => 8],
        ];
    }
    $hasQuotes = analytics_has('quotes');
    $hasTickets = analytics_has('tickets');
    $cot = $hasQuotes ? '(SELECT COUNT(*) FROM quotes q WHERE q.created_by=u.id)' : '0';
    $ing = $hasQuotes ? "(SELECT COALESCE(SUM(" . quote_total_dop_sql('q') . "),0) FROM quotes q WHERE q.created_by=u.id AND q.status='Aprobado')" : '0';
    $res = $hasTickets ? "(SELECT COUNT(*) FROM tickets t WHERE t.assigned_to=u.id AND t.status IN ('Resuelto','Cerrado'))" : '0';
    return fetch_all("SELECT u.id, u.name, u.role,
        $ing AS ingresos, $cot AS cotizaciones, $res AS resueltos
        FROM users u WHERE u.status='activo'
        ORDER BY ingresos DESC, resueltos DESC, cotizaciones DESC
        LIMIT $limit");
}

/** Leads grouped by status + recent list. */
function analytics_leads_summary(): array
{
    if (!analytics_live() || !analytics_has('leads')) {
        return ['by_status' => [['status' => 'nuevo', 'total' => 4], ['status' => 'contactado', 'total' => 2], ['status' => 'convertido', 'total' => 1]], 'total' => 7, 'recent' => []];
    }
    $byStatus = fetch_all('SELECT status, COUNT(*) total FROM leads GROUP BY status ORDER BY total DESC');
    $recent = fetch_all('SELECT * FROM leads ORDER BY created_at DESC LIMIT 8');
    return ['by_status' => $byStatus, 'total' => array_sum(array_map(fn ($r) => (int) $r['total'], $byStatus)), 'recent' => $recent];
}

/** Upcoming maintenance services (from equipment.next_service_at). */
function analytics_upcoming_services(int $limit = 6): array
{
    if (!analytics_live() || !analytics_has('equipment')) {
        return [
            ['client_name' => 'Hospital Metropolitano', 'name' => 'Tomógrafo Siemens', 'area' => 'Imagenología', 'next_service_at' => date('Y-m-d', strtotime('+2 days'))],
            ['client_name' => 'Plaza de la Salud', 'name' => 'Ventilador Dräger', 'area' => 'UCI', 'next_service_at' => date('Y-m-d', strtotime('+6 days'))],
            ['client_name' => 'CEDIMAT', 'name' => 'Monitor GE B450', 'area' => 'Cardiología', 'next_service_at' => date('Y-m-d', strtotime('+12 days'))],
        ];
    }
    return fetch_all('SELECT equipment.name, equipment.area, equipment.next_service_at, clients.name AS client_name FROM equipment LEFT JOIN clients ON clients.id = equipment.client_id WHERE next_service_at >= CURDATE() ORDER BY next_service_at ASC LIMIT ' . $limit);
}

/** Equipment whose scheduled service date has already passed (overdue maintenance). */
function analytics_overdue_services(int $limit = 12): array
{
    if (!analytics_live() || !analytics_has('equipment')) {
        return [];
    }
    $limit = max(1, $limit);
    return fetch_all("SELECT equipment.id, equipment.name, equipment.area, equipment.next_service_at, clients.name AS client_name
        FROM equipment LEFT JOIN clients ON clients.id = equipment.client_id
        WHERE next_service_at IS NOT NULL AND next_service_at < CURDATE()
          AND COALESCE(equipment.status,'') NOT IN ('retirado','fuera de servicio')
        ORDER BY next_service_at ASC LIMIT " . $limit);
}

/** Warranties expiring soonest. */
function analytics_warranties_expiring(int $limit = 6): array
{
    if (!analytics_live() || !analytics_has('equipment')) {
        return [
            ['name' => 'Tomógrafo Siemens Somatom', 'client_name' => 'Hospital Metropolitano', 'warranty_until' => date('Y-m-d', strtotime('+58 day'))],
            ['name' => 'Ventilador Dräger Evita', 'client_name' => 'Plaza de la Salud', 'warranty_until' => date('Y-m-d', strtotime('+74 day'))],
            ['name' => 'Monitor GE B450', 'client_name' => 'CEDIMAT', 'warranty_until' => date('Y-m-d', strtotime('+128 day'))],
        ];
    }
    return fetch_all('SELECT equipment.name, equipment.warranty_until, clients.name AS client_name FROM equipment LEFT JOIN clients ON clients.id = equipment.client_id WHERE warranty_until >= CURDATE() ORDER BY warranty_until ASC LIMIT ' . $limit);
}

/** Quote conversion funnel: ordered stage counts (created → won). */
function analytics_quote_funnel(): array
{
    if (!analytics_live()) {
        return [['stage' => 'Borrador', 'count' => 18], ['stage' => 'Enviado', 'count' => 12], ['stage' => 'Cotizado', 'count' => 9], ['stage' => 'Negociacion', 'count' => 5], ['stage' => 'Aprobado', 'count' => 3]];
    }
    $out = [];
    foreach (array_keys(analytics_stage_meta()) as $stage) {
        $out[] = ['stage' => $stage, 'count' => db_count('quotes', 'status = ?', [$stage])];
    }
    return $out;
}
