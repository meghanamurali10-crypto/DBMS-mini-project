<?php
require_once __DIR__ . '/../includes/layout.php';
require_role(['GSSSR']);
verify_csrf();
require_once __DIR__ . '/../includes/SimpleExcel.php';
$pdo = Database::conn();

if (($_GET['export'] ?? '') !== '') {
    $report   = $_GET['export'];
    $filename = $report . '_' . date('Ymd_His') . '.xlsx';
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
        foreach (rows('SELECT r.*, d.name department_name FROM requests r JOIN departments d ON d.id=r.department_id') as $r) {
            $data[] = [$r['request_no'],$r['department_name'],$r['status'],$r['ietw_remarks'],$r['gsssr_remarks'],$r['purpose'],$r['created_at']];
        }
    }
    if ($report === 'categories') {
        $headers = ['Category','Items'];
        foreach (rows('SELECT c.name, COUNT(i.id) cnt FROM categories c LEFT JOIN items i ON i.category_id=c.id GROUP BY c.id') as $r) {
            $data[] = [$r['name'],$r['cnt']];
        }
    }
    if (!$headers) exit('Invalid export.');
    $pdo->prepare('INSERT INTO excel_import_logs (user_id, file_name, action_type, status, remarks) VALUES (?, ?, "EXPORT", "SUCCESS", ?)')->execute([current_user()['id'], $filename, $report]);
    log_activity('GSSSR exported ' . $report . ' report');
    SimpleExcel::downloadXlsx($filename, $headers, $data);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $path     = upload_file($_FILES['excel'] ?? [], 'excel', ['xlsx','csv']);
        $full     = dirname(__DIR__) . '/' . $path;
        $dataRows = SimpleExcel::read($full);
        array_shift($dataRows);
        $count = 0;
        foreach ($dataRows as $row) {
            [$code,$name,$category,$qty,$unit,$price,$min,$location,$description] = array_pad($row, 9, '');
            if (!$code || !$name) continue;
            $cat = one('SELECT id FROM categories WHERE name=?', [$category]) ?: null;
            if (!$cat) {
                $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$category ?: 'Others']);
                $cat = ['id' => $pdo->lastInsertId()];
            }
            $pdo->prepare(
                'INSERT INTO items (item_code,item_name,category_id,quantity,unit,unit_price,minimum_stock,storage_location,description,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE item_name=VALUES(item_name), category_id=VALUES(category_id), unit=VALUES(unit),
                   unit_price=VALUES(unit_price), minimum_stock=VALUES(minimum_stock),
                   storage_location=VALUES(storage_location), description=VALUES(description)'
            )->execute([$code,$name,$cat['id'],(float)$qty,$unit ?: 'Nos',(float)$price,(float)$min,$location,$description,current_user()['id']]);
            $count++;
        }
        $pdo->prepare('INSERT INTO excel_import_logs (user_id, file_name, action_type, status, rows_processed, remarks) VALUES (?, ?, "IMPORT", "SUCCESS", ?, ?)')->execute([current_user()['id'], basename($path), $count, 'Imported by GSSSR.']);
        log_activity('GSSSR imported Excel/CSV ' . basename($path));
        flash('success', "Imported $count rows.");
    } catch (Throwable $e) {
        $pdo->prepare('INSERT INTO excel_import_logs (user_id, file_name, action_type, status, remarks) VALUES (?, ?, "IMPORT", "FAILED", ?)')->execute([current_user()['id'], $_FILES['excel']['name'] ?? '', $e->getMessage()]);
        flash('danger', $e->getMessage());
    }
    redirect('/admin/reports.php');
}

$imports = rows('SELECT eil.*, u.name FROM excel_import_logs eil LEFT JOIN users u ON u.id=eil.user_id ORDER BY eil.id DESC LIMIT 50');
render_header('Reports — GSSSR');
?>
<h1 class="h3 mb-3">Reports, Import & PDF Export <span class="badge bg-warning text-dark ms-2">GSSSR</span></h1>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card metric-card">
      <div class="card-header bg-white fw-semibold">Excel Import</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data"><?php csrf_field(); ?>
          <label class="form-label">Upload .csv or .xlsx</label>
          <input name="excel" type="file" accept=".csv,.xlsx" class="form-control mb-3" required>
          <button class="btn btn-primary">Import Items</button>
        </form>
        <div class="small text-muted mt-3">Columns: code, name, category, quantity, unit, price, min, location, description.</div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card metric-card">
      <div class="card-header bg-white fw-semibold">Excel Export</div>
      <div class="card-body d-grid gap-2">
        <a class="btn btn-outline-primary" href="?export=stock">Export Stock</a>
        <a class="btn btn-outline-primary" href="?export=categories">Export Categories</a>
        <a class="btn btn-outline-primary" href="?export=requests">Export All Requests</a>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
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
            <option value="import_logs">Import/Export Log PDF</option>
            <option value="pdf_exports">PDF Export Log PDF</option>
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
  <div class="card-header bg-white fw-semibold">Import / Export Logs</div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Time</th><th>User</th><th>File</th><th>Action</th><th>Status</th><th>Rows</th><th>Remarks</th></tr></thead>
      <tbody>
        <?php foreach ($imports as $i): ?>
        <tr>
          <td><?= e($i['created_at']) ?></td><td><?= e($i['name']) ?></td>
          <td><?= e($i['file_name']) ?></td><td><?= e($i['action_type']) ?></td>
          <td><?= e($i['status']) ?></td><td><?= e($i['rows_processed']) ?></td><td><?= e($i['remarks']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); ?>
