<?php
require_once __DIR__ . '/../includes/layout.php';
require_role(['IETW']);
verify_csrf();
require_once __DIR__ . '/../includes/SimpleExcel.php';
$pdo = Database::conn();

if (($_GET['export'] ?? '') !== '') {
    $report   = $_GET['export'];
    $filename = 'ietw_' . $report . '_' . date('Ymd_His') . '.xlsx';
    $headers  = [];
    $data     = [];

    if ($report === 'stock') {
        $headers = ['Code','Item','Category','Quantity','Unit','Unit Price','Minimum','Location'];
        foreach (rows('SELECT i.*, c.name category_name FROM items i JOIN categories c ON c.id=i.category_id WHERE i.deleted_at IS NULL AND i.status="ACTIVE"') as $r) {
            $data[] = [$r['item_code'],$r['item_name'],$r['category_name'],$r['quantity'],$r['unit'],$r['unit_price'],$r['minimum_stock'],$r['storage_location']];
        }
    }

    if ($report === 'requests') {
        $headers = ['Request No','Department','Status','IETW Remarks','GSSSR Remarks','Purpose','Created'];
        foreach (rows('SELECT r.*, d.name department_name FROM requests r JOIN departments d ON d.id=r.department_id ORDER BY r.created_at DESC') as $r) {
            $data[] = [$r['request_no'],$r['department_name'],$r['status'],$r['ietw_remarks'],$r['gsssr_remarks'],$r['purpose'],$r['created_at']];
        }
    }

    if ($report === 'categories') {
        $headers = ['Category','Items'];
        foreach (rows('SELECT c.name, COUNT(i.id) cnt FROM categories c LEFT JOIN items i ON i.category_id=c.id AND i.deleted_at IS NULL GROUP BY c.id ORDER BY c.name') as $r) {
            $data[] = [$r['name'],$r['cnt']];
        }
    }

    if (!$headers) {
        exit('Invalid export.');
    }

    $pdo->prepare('INSERT INTO excel_import_logs (user_id, file_name, action_type, status, remarks) VALUES (?, ?, "EXPORT", "SUCCESS", ?)')
        ->execute([current_user()['id'], $filename, 'IETW ' . $report]);
    log_activity('IETW exported ' . $report . ' report');
    SimpleExcel::downloadXlsx($filename, $headers, $data);
}

$imports = rows('SELECT eil.*, u.name FROM excel_import_logs eil LEFT JOIN users u ON u.id=eil.user_id ORDER BY eil.id DESC LIMIT 50');
render_header('Reports - IETW');
?>
<h1 class="h3 mb-3">Reports <span class="badge bg-info text-dark ms-2">IETW</span></h1>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card metric-card">
      <div class="card-header bg-white fw-semibold">Excel Export</div>
      <div class="card-body d-grid gap-2">
        <a class="btn btn-outline-primary" href="?export=stock">Export Stock</a>
        <a class="btn btn-outline-primary" href="?export=categories">Export Categories</a>
        <a class="btn btn-outline-primary" href="?export=requests">Export All Requests</a>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card metric-card">
      <div class="card-header bg-white fw-semibold">PDF Reports</div>
      <div class="card-body">
        <div class="d-grid gap-2 mb-3">
          <a class="btn btn-outline-danger" href="<?= BASE_URL ?>/download_pdf.php?type=stockbook&quick=current_month">Current Month Stock Book</a>
          <a class="btn btn-outline-danger" href="<?= BASE_URL ?>/download_pdf.php?type=stockbook&quick=3m">Last 3 Months Stock Book</a>
          <a class="btn btn-outline-danger" href="<?= BASE_URL ?>/download_pdf.php?type=stockbook&quick=6m">Last 6 Months Stock Book</a>
        </div>
        <form method="get" action="<?= BASE_URL ?>/download_pdf.php" class="border-top pt-3">
          <label class="form-label">Custom Date Range</label>
          <select name="type" class="form-select mb-2">
            <option value="stockbook">Stock Book PDF</option>
            <option value="monthly">Monthly PDF</option>
          </select>
          <div class="row g-2">
            <div class="col-6"><input type="date" name="from" class="form-control" required></div>
            <div class="col-6"><input type="date" name="to" class="form-control" required></div>
          </div>
          <button class="btn btn-danger w-100 mt-2"><i class="bi bi-file-pdf me-1"></i>Download PDF</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="card metric-card mt-3">
  <div class="card-header bg-white fw-semibold">Recent Export Logs</div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Time</th><th>User</th><th>File</th><th>Action</th><th>Status</th><th>Rows</th><th>Remarks</th></tr></thead>
      <tbody>
        <?php foreach ($imports as $i): ?>
        <tr>
          <td><?= e($i['created_at']) ?></td>
          <td><?= e($i['name']) ?></td>
          <td><?= e($i['file_name']) ?></td>
          <td><?= e($i['action_type']) ?></td>
          <td><?= e($i['status']) ?></td>
          <td><?= e($i['rows_processed']) ?></td>
          <td><?= e($i['remarks']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); ?>
