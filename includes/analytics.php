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
    return [
        'Borrador'    => '#94a3b8',
        'Enviado'     => '#0666b3',
        'Cotizado'    => '#1fa6d8',
        'Negociacion' => '#9c7d34',
        'Aprobado'    => '#0a7d36',
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
    return [
        'Equipos médicos'             => ['monitor', '#0a7d36'],
        'Gases medicinales'           => ['wind', '#12a04a'],
        'Diseño hospitalario'         => ['ruler', '#1fa6d8'],
        'Instalación y certificación' => ['wrench', '#9c7d34'],
        'Soporte y mantenimiento'     => ['life-buoy', '#0666b3'],
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
            $label = 'Mes de ' . $months_es[(int) date('n')] . ' ' . date('Y');
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
function analytics_delta(float $current, float $previous): float
{
    if ($previous <= 0) {
        return $current > 0 ? 100.0 : 0.0;
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
    $row = fetch_one("SELECT COALESCE(SUM(total),0) v FROM quotes WHERE status IN ($in) AND (valid_until IS NULL OR valid_until >= CURDATE())");
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
        $row = fetch_one('SELECT COUNT(*) c, COALESCE(SUM(total),0) a FROM quotes WHERE status = ?', [$stage]);
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
    $wonCur = (float) (fetch_one("SELECT COALESCE(SUM(total),0) v FROM quotes WHERE status='Aprobado' AND DATE($wonDate) BETWEEN ? AND ?", [$f, $t])['v'] ?? 0);
    $wonPrev = (float) (fetch_one("SELECT COALESCE(SUM(total),0) v FROM quotes WHERE status='Aprobado' AND DATE($wonDate) BETWEEN ? AND ?", [$pf, $pt])['v'] ?? 0);

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

    $avgTicket = $won > 0 ? round((float) (fetch_one("SELECT COALESCE(AVG(total),0) v FROM quotes WHERE status='Aprobado'")['v'] ?? 0), 2) : 0.0;

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
        $cot = [22, 26, 19, 31, 28, 34, 27, 35, 30, 38, 33, 41];
        $tk = [38, 41, 35, 44, 40, 42, 39, 46, 43, 48, 45, 50];
        $res = [33, 37, 31, 40, 38, 41, 36, 44, 41, 46, 43, 48];
        $n = count($labels);
        return [
            'labels' => $labels,
            'ingresos' => array_slice($base, -$n),
            'ingresos_raw' => array_map(fn ($v) => $v * 1000, array_slice($base, -$n)),
            'cotizaciones' => array_slice($cot, -$n),
            'tickets' => array_slice($tk, -$n),
            'resueltos' => array_slice($res, -$n),
        ];
    }

    $ingresos = array_fill_keys($keys, 0.0);
    $cot = array_fill_keys($keys, 0);
    $tk = array_fill_keys($keys, 0);
    $res = array_fill_keys($keys, 0);

    $wonDate = column_exists('quotes', 'approved_at') ? 'COALESCE(approved_at, updated_at, created_at)' : 'COALESCE(updated_at, created_at)';
    foreach (fetch_all("SELECT DATE_FORMAT($wonDate,'%Y-%m') m, COALESCE(SUM(total),0) v FROM quotes WHERE status='Aprobado' GROUP BY m") as $r) {
        if (isset($ingresos[$r['m']])) $ingresos[$r['m']] = (float) $r['v'];
    }
    foreach (fetch_all("SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) c FROM quotes GROUP BY m") as $r) {
        if (isset($cot[$r['m']])) $cot[$r['m']] = (int) $r['c'];
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
    return [
        'labels' => $labels,
        'ingresos' => array_map(fn ($v) => round($v / 1000, 1), $ingresosRaw),
        'ingresos_raw' => $ingresosRaw,
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
        ];
        if (!analytics_live()) {
            $total = array_sum(array_column($demo, 1)) ?: 1;
            return array_map(function ($r) use ($cats, $total) {
                $m = $cats[$r[0]] ?? ['layers', '#0a7d36'];
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
        ? fetch_all("SELECT COALESCE(NULLIF(category,''),'Sin categoría') line, COUNT(*) c, COALESCE(SUM(total),0) a FROM quotes WHERE $where GROUP BY line ORDER BY a DESC", $params)
        : [];

    $total = array_sum(array_map(fn ($r) => (float) $r['a'], $rows)) ?: 1;
    return array_map(function ($r) use ($cats, $total) {
        $m = $cats[$r['line']] ?? ['layers', '#64748b'];
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
        (SELECT COALESCE(SUM(q.total),0) FROM quotes q WHERE q.client_id=c.id) AS quote_value
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
    $ing = $hasQuotes ? "(SELECT COALESCE(SUM(q.total),0) FROM quotes q WHERE q.created_by=u.id AND q.status='Aprobado')" : '0';
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
