<?php
require_once __DIR__ . '/../includes/layout.php';
require_role(['DEPARTMENT']);
$requests = rows('SELECT r.*, d.name department_name FROM requests r JOIN departments d ON d.id=r.department_id WHERE r.requested_by=? ORDER BY r.id DESC', [current_user()['id']]);
render_header('Request History');
?>
<h1 class="h3 mb-3">Request History</h1>
<div class="card metric-card"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>No</th><th>Date</th><th>Purpose</th><th>Status</th><th>PDF</th></tr></thead><tbody><?php foreach($requests as $r): ?><tr><td><?= e($r['request_no']) ?></td><td><?= e($r['created_at']) ?></td><td><?= e($r['purpose']) ?></td><td><?= request_status_badge(visible_request_status($r)) ?></td><td><a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/download_pdf.php?type=request&id=<?= $r['id'] ?>"><i class="bi bi-file-pdf"></i></a></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php render_footer(); ?>
