<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_can('agenda.view');
verify_csrf();

$hasDb = db(false) && table_exists('equipment');

/* ---- Schedule a service (sets equipment.next_service_at) ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasDb && ($_POST['form'] ?? '') === 'schedule') {
        if (!current_can('agenda.edit')) { flash('warning', 'Acción no permitida por tu rol.'); redirect('crm/agenda.php'); }
    $eq = (int) ($_POST['equipment_id'] ?? 0);
    $date = $_POST['next_service_at'] ?: null;
    if ($eq > 0 && $date) {
        db()->prepare('UPDATE equipment SET next_service_at=?, updated_at=NOW() WHERE id=?')->execute([$date, $eq]);
        flash('success', 'Servicio programado en la agenda.');
    } else {
        flash('warning', 'Selecciona un equipo y una fecha.');
    }
    redirect('crm/agenda.php?ym=' . preg_replace('/[^0-9-]/', '', (string) ($_POST['ym'] ?? date('Y-m'))));
}

/* ---- Month being viewed -------------------------------------------------- */
$ym = (string) ($_GET['ym'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
    $ym = date('Y-m');
}
$monthStart = $ym . '-01';
$firstTs = strtotime($monthStart);
$monthEnd = date('Y-m-t', $firstTs);
$months_es = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
$monthLabel = $months_es[(int) date('n', $firstTs)] . ' ' . date('Y', $firstTs);
$prevYm = date('Y-m', strtotime('-1 month', $firstTs));
$nextYm = date('Y-m', strtotime('+1 month', $firstTs));
$today = date('Y-m-d');

/* ---- Events for the month ------------------------------------------------ */
$priorityColor = fn ($p) => in_array($p, ['Critica', 'Alta'], true) ? '#dc2626' : ($p === 'Media' ? '#d97706' : '#0666b3');

$eventsByDay = [];
$add = function (string $date, array $ev) use (&$eventsByDay) {
    if (!isset($eventsByDay[$date])) $eventsByDay[$date] = [];
    $eventsByDay[$date][] = $ev;
};

if ($hasDb) {
    foreach (fetch_all('SELECT equipment.id, equipment.name, equipment.area, equipment.next_service_at AS d, clients.name AS client_name FROM equipment LEFT JOIN clients ON clients.id = equipment.client_id WHERE next_service_at BETWEEN ? AND ? ORDER BY next_service_at', [$monthStart, $monthEnd]) as $r) {
        $add($r['d'], ['type' => 'Mantenimiento', 'icon' => 'wrench', 'color' => '#0a7d36', 'title' => $r['client_name'] ?? 'Cliente', 'sub' => ($r['name'] ?? 'Equipo') . ' · ' . ($r['area'] ?? 'Área'), 'href' => url('crm/equipos.php?edit=' . (int) $r['id'])]);
    }
    if (table_exists('tickets')) {
        foreach (fetch_all('SELECT tickets.id, tickets.subject, tickets.priority, tickets.due_at AS d, clients.name AS client_name FROM tickets LEFT JOIN clients ON clients.id = tickets.client_id WHERE due_at BETWEEN ? AND ? ORDER BY due_at', [$monthStart, $monthEnd]) as $r) {
            $add($r['d'], ['type' => 'Ticket', 'icon' => 'life-buoy', 'color' => $priorityColor($r['priority'] ?? ''), 'title' => $r['client_name'] ?? 'Cliente', 'sub' => $r['subject'] ?? 'Ticket', 'href' => url('crm/tickets.php?id=' . (int) $r['id'])]);
        }
    }
} else {
    foreach ([['+0', 'Hospital Metropolitano', 'Tomógrafo Siemens · Imagenología'], ['+1', 'Plaza de la Salud', 'Ventilador Dräger · UCI'], ['+3', 'CEDIMAT', 'Monitor GE B450 · Cardiología'], ['+8', 'CAID', 'Central de gases · Terapia'], ['+8', 'Hospital General Plaza', 'Autoclave 90L · Esterilización'], ['+15', 'Plaza de la Salud', 'Bomba de infusión · Farmacia'], ['-2', 'CEDIMAT', 'Lámpara quirúrgica · Quirófano 2']] as [$off, $cli, $eq]) {
        $d = date('Y-m-d', strtotime($off . ' days'));
        if (date('Y-m', strtotime($d)) === $ym) {
            $add($d, ['type' => 'Mantenimiento', 'icon' => 'wrench', 'color' => '#0a7d36', 'title' => $cli, 'sub' => $eq, 'href' => url('crm/equipos.php')]);
        }
    }
}

$monthCount = array_sum(array_map('count', $eventsByDay));

/* Upcoming list (from today, next services) */
$upcoming = $hasDb
    ? fetch_all('SELECT equipment.name, equipment.area, equipment.next_service_at AS d, clients.name AS client_name FROM equipment LEFT JOIN clients ON clients.id = equipment.client_id WHERE next_service_at >= ? ORDER BY next_service_at ASC LIMIT 6', [$today])
    : [
        ['client_name' => 'Hospital Metropolitano', 'name' => 'Tomógrafo Siemens', 'area' => 'Imagenología', 'd' => date('Y-m-d')],
        ['client_name' => 'Plaza de la Salud', 'name' => 'Ventilador Dräger', 'area' => 'UCI', 'd' => date('Y-m-d', strtotime('+1 day'))],
        ['client_name' => 'CEDIMAT', 'name' => 'Monitor GE B450', 'area' => 'Cardiología', 'd' => date('Y-m-d', strtotime('+3 days'))],
    ];

/* Equipment options for the schedule modal */
$equipmentOptions = $hasDb ? fetch_all('SELECT equipment.id, equipment.name, clients.name AS client_name FROM equipment LEFT JOIN clients ON clients.id = equipment.client_id ORDER BY clients.name, equipment.name') : [];

/* Calendar grid (Monday-first) */
$dow = (int) date('N', $firstTs);     // 1=Mon..7=Sun
$lead = $dow - 1;
$gridStart = strtotime("-{$lead} days", $firstTs);
$cells = (int) (ceil(($lead + (int) date('t', $firstTs)) / 7) * 7);

$crmTitle = 'Agenda';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<div class="dash" x-data="agendaCalendar(<?= e(json_encode($eventsByDay, JSON_UNESCAPED_UNICODE)) ?>)">
    <div class="dash-bar">
        <div class="dash-bar__title">
            <h2><i data-lucide="calendar-days" class="h-5 w-5 text-sch-blue"></i> Agenda de mantenimiento</h2>
            <p><?= e((string) $monthCount) ?> servicio<?= $monthCount === 1 ? '' : 's' ?> programado<?= $monthCount === 1 ? '' : 's' ?> en <?= e($monthLabel) ?>.</p>
        </div>
        <div class="dash-bar__tools">
            <div class="cal-nav">
                <a class="cal-nav__btn" href="<?= url('crm/agenda.php?ym=' . $prevYm) ?>" aria-label="Mes anterior"><i data-lucide="chevron-left"></i></a>
                <a class="cal-nav__today" href="<?= url('crm/agenda.php') ?>">Hoy</a>
                <a class="cal-nav__btn" href="<?= url('crm/agenda.php?ym=' . $nextYm) ?>" aria-label="Mes siguiente"><i data-lucide="chevron-right"></i></a>
            </div>
            <button type="button" class="crm-primary-btn" @click="openSchedule()"<?= $hasDb ? '' : ' disabled title="Requiere base de datos"' ?>><i data-lucide="calendar-plus" class="h-4 w-4"></i>Programar servicio</button>
        </div>
    </div>

    <section class="dash-mid agenda-grid">
        <article class="dash-card">
            <div class="dash-card__head">
                <h3><i data-lucide="calendar"></i> <?= e($monthLabel) ?></h3>
                <div class="dash-card__meta cal-legend">
                    <span><i style="background:#0a7d36"></i>Mantenimiento</span>
                    <span><i style="background:#0666b3"></i>Ticket</span>
                </div>
            </div>
            <div class="dash-card__body" style="padding-top:.5rem">
                <div class="cal">
                    <div class="cal__head">
                        <?php foreach (['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $wd): ?><span><?= $wd ?></span><?php endforeach; ?>
                    </div>
                    <div class="cal__grid">
                        <?php for ($i = 0; $i < $cells; $i++):
                            $ts = strtotime("+{$i} days", $gridStart);
                            $cellDate = date('Y-m-d', $ts);
                            $inMonth = date('Y-m', $ts) === $ym;
                            $isToday = $cellDate === $today;
                            $isWeekend = in_array((int) date('N', $ts), [6, 7], true);
                            $dayEvents = $eventsByDay[$cellDate] ?? [];
                            $hasEv = count($dayEvents) > 0;
                            $cls = 'cal__day' . ($inMonth ? '' : ' is-out') . ($isToday ? ' is-today' : '') . ($isWeekend ? ' is-weekend' : '') . ($hasEv ? ' has-events' : '');
                        ?>
                            <div class="<?= $cls ?>"<?= $hasEv ? ' role="button" tabindex="0" @click="openDay(\'' . $cellDate . '\', \'' . e(date_long_es($cellDate)) . '\')" @keydown.enter="openDay(\'' . $cellDate . '\', \'' . e(date_long_es($cellDate)) . '\')"' : '' ?>>
                                <span class="cal__num"><?= (int) date('j', $ts) ?></span>
                                <?php if ($hasEv): ?>
                                    <div class="cal__events">
                                        <?php foreach (array_slice($dayEvents, 0, 3) as $ev): ?>
                                            <span class="cal__chip" style="--c:<?= e($ev['color']) ?>"><i></i><b><?= e($ev['title']) ?></b></span>
                                        <?php endforeach; ?>
                                        <?php if (count($dayEvents) > 3): ?><span class="cal__more">+<?= count($dayEvents) - 3 ?> más</span><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </article>

        <article class="dash-card">
            <div class="dash-card__head">
                <h3><i data-lucide="clock"></i> Próximos servicios</h3>
            </div>
            <div class="dash-card__body" style="display:grid;gap:.55rem">
                <?php foreach ($upcoming as $u): ?>
                    <div class="agenda-up">
                        <div class="agenda-up__date">
                            <b><?= e(date('d', strtotime($u['d']))) ?></b>
                            <span><?= e($months_es[(int) date('n', strtotime($u['d']))]) ?></span>
                        </div>
                        <div class="agenda-up__body">
                            <b><?= e($u['client_name'] ?? 'Cliente') ?></b>
                            <span><?= e($u['name'] ?? 'Equipo') ?> · <?= e($u['area'] ?? 'Área') ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$upcoming): ?>
                    <div class="crm-empty"><i data-lucide="calendar-check" class="h-6 w-6"></i><strong>Sin servicios próximos</strong><p>Programa uno con el botón de arriba.</p></div>
                <?php endif; ?>
                <a href="<?= url('crm/equipos.php') ?>" class="crm-secondary-btn" style="margin-top:.3rem"><i data-lucide="monitor" class="h-4 w-4"></i>Ver inventario de equipos</a>
            </div>
        </article>
    </section>

    <!-- Day detail modal -->
    <dialog x-ref="dayDlg" class="crm-modal" @click.self="closeDay()" @cancel.prevent="closeDay()">
        <div class="crm-modal__form">
            <header class="crm-modal__head">
                <span class="crm-modal__icon"><i data-lucide="calendar-days"></i></span>
                <div class="crm-modal__titles">
                    <h2 x-text="dayLabel">Servicios del día</h2>
                    <p><span x-text="dayList.length"></span> servicio(s) programado(s)</p>
                </div>
                <button type="button" class="crm-modal__close" @click="closeDay()" aria-label="Cerrar"><i data-lucide="x"></i></button>
            </header>
            <div class="crm-modal__body" style="gap:.6rem">
                <template x-for="(ev,i) in dayList" :key="i">
                    <a class="agenda-ev" :href="ev.href">
                        <span class="agenda-ev__dot" :style="'background:'+ev.color"></span>
                        <span class="agenda-ev__body">
                            <b x-text="ev.title"></b>
                            <span x-text="ev.sub"></span>
                        </span>
                        <span class="agenda-ev__tag" :style="'color:'+ev.color+';background:'+ev.color+'1a'" x-text="ev.type"></span>
                    </a>
                </template>
            </div>
            <footer class="crm-modal__foot">
                <button type="button" class="crm-secondary-btn" @click="closeDay()">Cerrar</button>
                <?php if ($hasDb): ?><button type="button" class="crm-primary-btn" @click="closeDay(); openSchedule()"><i data-lucide="calendar-plus" class="h-4 w-4"></i>Programar otro</button><?php endif; ?>
            </footer>
        </div>
    </dialog>

    <!-- Schedule modal -->
    <dialog x-ref="schedDlg" class="crm-modal" @click.self="closeSchedule()" @cancel.prevent="closeSchedule()">
        <form method="post" class="crm-modal__form">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="schedule">
            <input type="hidden" name="ym" value="<?= e($ym) ?>">
            <header class="crm-modal__head">
                <span class="crm-modal__icon"><i data-lucide="calendar-plus"></i></span>
                <div class="crm-modal__titles">
                    <h2>Programar servicio</h2>
                    <p>Asigna la próxima fecha de mantenimiento a un equipo.</p>
                </div>
                <button type="button" class="crm-modal__close" @click="closeSchedule()" aria-label="Cerrar"><i data-lucide="x"></i></button>
            </header>
            <div class="crm-modal__body">
                <label class="crm-field"><span class="required">Equipo</span>
                    <select name="equipment_id" required class="crm-select">
                        <option value="">Seleccionar equipo</option>
                        <?php foreach ($equipmentOptions as $eq): ?>
                            <option value="<?= (int) $eq['id'] ?>"><?= e(($eq['client_name'] ?? 'Cliente') . ' — ' . $eq['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="crm-field"><span class="required">Fecha del servicio</span>
                    <input type="date" name="next_service_at" required value="<?= e(date('Y-m-d')) ?>" class="crm-input">
                </label>
            </div>
            <footer class="crm-modal__foot">
                <button type="button" class="crm-secondary-btn" @click="closeSchedule()">Cancelar</button>
                <button type="submit" class="crm-primary-btn"><i data-lucide="check" class="h-4 w-4"></i>Programar</button>
            </footer>
        </form>
    </dialog>
</div>

<script>
window.agendaCalendar = function (events) {
    return {
        events: events || {},
        dayLabel: '',
        dayList: [],
        openDay(date, label) {
            this.dayList = this.events[date] || [];
            this.dayLabel = label;
            this.$refs.dayDlg.showModal();
            this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); });
        },
        closeDay() { if (this.$refs.dayDlg.open) this.$refs.dayDlg.close(); },
        openSchedule() { this.$refs.schedDlg.showModal(); this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); }); },
        closeSchedule() { if (this.$refs.schedDlg.open) this.$refs.schedDlg.close(); },
    };
};
</script>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
