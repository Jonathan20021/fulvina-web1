<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$hasDb = db(false) && table_exists('tickets');

if ($hasDb) {
    $ticketStatus = fetch_all('SELECT status, COUNT(*) total FROM tickets GROUP BY status ORDER BY total DESC');
    $quoteStatus = fetch_all('SELECT status, COUNT(*) total, COALESCE(SUM(total),0) amount FROM quotes GROUP BY status ORDER BY amount DESC');
    $topClients = fetch_all('SELECT clients.name, COUNT(DISTINCT equipment.id) equipment_count, COUNT(DISTINCT tickets.id) ticket_count FROM clients LEFT JOIN equipment ON equipment.client_id = clients.id LEFT JOIN tickets ON tickets.client_id = clients.id GROUP BY clients.id ORDER BY ticket_count DESC, equipment_count DESC LIMIT 8');
    $leads = table_exists('leads') ? fetch_all('SELECT * FROM leads ORDER BY created_at DESC LIMIT 8') : [];
} else {
    $ticketStatus = [['status' => 'Abierto', 'total' => 4], ['status' => 'En proceso', 'total' => 3], ['status' => 'Resuelto', 'total' => 9], ['status' => 'Cotizado', 'total' => 2]];
    $quoteStatus = [['status' => 'Enviado', 'total' => 7, 'amount' => 84400], ['status' => 'Aprobado', 'total' => 5, 'amount' => 62100], ['status' => 'Borrador', 'total' => 4, 'amount' => 28850]];
    $topClients = [
        ['name' => 'Hospital Metropolitano de Santiago', 'equipment_count' => 12, 'ticket_count' => 5],
        ['name' => 'Plaza de la Salud', 'equipment_count' => 8, 'ticket_count' => 4],
        ['name' => 'CAID', 'equipment_count' => 6, 'ticket_count' => 2],
    ];
    $leads = [];
}

$crmTitle = 'Reportes';
require_once __DIR__ . '/../includes/crm_header.php';
?>

<?php if (!$hasDb): ?>
    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Modo demo. Ejecuta <a class="underline" href="<?= url('install.php') ?>">install.php</a> para reportes reales.</div>
<?php endif; ?>

<section class="crm-mini-grid mb-4">
    <article class="crm-mini-stat">
        <span>Estados de tickets</span>
        <strong><?= e((string) array_sum(array_map('intval', array_column($ticketStatus, 'total')))) ?></strong>
    </article>
    <article class="crm-mini-stat">
        <span>Cotizaciones medidas</span>
        <strong><?= e((string) array_sum(array_map('intval', array_column($quoteStatus, 'total')))) ?></strong>
    </article>
    <article class="crm-mini-stat">
        <span>Clientes activos en reporte</span>
        <strong><?= e((string) count($topClients)) ?></strong>
    </article>
    <article class="crm-mini-stat">
        <span>Leads recientes</span>
        <strong><?= e((string) count($leads)) ?></strong>
    </article>
</section>

<section class="grid gap-4 xl:grid-cols-2">
    <article class="crm-card">
        <div class="crm-card__head">
            <div>
                <h2>Tickets por estado</h2>
                <p>Distribucion operativa para soporte tecnico.</p>
            </div>
        </div>
        <div class="crm-card__body"><canvas id="ticketsChart" class="report-chart"></canvas></div>
    </article>
    <article class="crm-card">
        <div class="crm-card__head">
            <div>
                <h2>Cotizaciones por monto</h2>
                <p>Valor comercial por etapa.</p>
            </div>
        </div>
        <div class="crm-card__body"><canvas id="quotesChart" class="report-chart"></canvas></div>
    </article>
</section>

<section class="mt-4 grid gap-4 xl:grid-cols-[1.1fr_.9fr]">
    <article class="crm-card">
        <div class="crm-card__head">
            <div>
                <h2>Clientes con mas actividad</h2>
                <p>Relacion entre equipos instalados y tickets.</p>
            </div>
        </div>
        <div class="crm-table-wrap">
            <table class="crm-table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th class="text-right">Equipos</th>
                        <th class="text-right">Tickets</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topClients as $client): ?>
                        <tr>
                            <td><strong><?= e($client['name']) ?></strong></td>
                            <td class="text-right"><strong><?= e((string) $client['equipment_count']) ?></strong></td>
                            <td class="text-right"><strong class="text-sch-blue"><?= e((string) $client['ticket_count']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="crm-card">
        <div class="crm-card__head">
            <div>
                <h2>Leads recientes</h2>
                <p>Solicitudes publicas recibidas desde contacto.</p>
            </div>
        </div>
        <div class="crm-record-list">
            <?php if (!$leads): ?>
                <div class="crm-empty">
                    <i data-lucide="inbox" class="h-6 w-6"></i>
                    <strong>No hay leads registrados</strong>
                    <p>Las solicitudes del sitio publico apareceran aqui.</p>
                </div>
            <?php endif; ?>
            <?php foreach ($leads as $lead): ?>
                <div class="crm-record-row">
                    <h3><?= e($lead['name']) ?> - <?= e($lead['company'] ?: 'Sin empresa') ?></h3>
                    <p><?= e($lead['type']) ?> - <?= e($lead['email']) ?></p>
                    <p><?= e($lead['message']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </article>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!window.Chart) return;
    const ticketLabels = <?= json_encode(array_column($ticketStatus, 'status'), JSON_UNESCAPED_UNICODE) ?>;
    const ticketData = <?= json_encode(array_map('intval', array_column($ticketStatus, 'total'))) ?>;
    const quoteLabels = <?= json_encode(array_column($quoteStatus, 'status'), JSON_UNESCAPED_UNICODE) ?>;
    const quoteData = <?= json_encode(array_map('floatval', array_column($quoteStatus, 'amount'))) ?>;
    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#52616f', font: { weight: 700 } } },
            y: { beginAtZero: true, ticks: { color: '#52616f' }, grid: { color: '#e5edf5' } }
        }
    };
    new Chart(document.getElementById('ticketsChart'), {
        type: 'bar',
        data: { labels: ticketLabels, datasets: [{ data: ticketData, backgroundColor: '#0a7d36', borderRadius: 6, maxBarThickness: 46 }] },
        options: chartOptions
    });
    new Chart(document.getElementById('quotesChart'), {
        type: 'bar',
        data: { labels: quoteLabels, datasets: [{ data: quoteData, backgroundColor: '#9c7d34', borderRadius: 6, maxBarThickness: 46 }] },
        options: chartOptions
    });
});
</script>

<?php require_once __DIR__ . '/../includes/crm_footer.php'; ?>
