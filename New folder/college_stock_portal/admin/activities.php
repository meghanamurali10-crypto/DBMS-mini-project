<?php
require_once __DIR__ . '/../includes/layout.php';
require_role(['GSSSR']);

$pdo = Database::conn();

$selectedDept = (int)($_GET['department_id'] ?? 0);
$selectedRole = $_GET['role'] ?? '';
$allowedRoles = ['IETW', 'DEPARTMENT'];
if (!in_array($selectedRole, $allowedRoles, true)) {
    $selectedRole = '';
}

$departments = rows('SELECT id, code, name FROM departments WHERE code <> "ADMIN" ORDER BY code');

$deptInventoryExists = (bool)one("SHOW TABLES LIKE 'department_inventory'");

$departmentSummary = rows(
    'SELECT d.id, d.code, d.name,
            COUNT(DISTINCT u.id) user_count,
            COUNT(DISTINCT r.id) request_count,
            COALESCE(SUM(ri.requested_quantity), 0) requested_qty,
            COALESCE(SUM(ri.issued_quantity), 0) issued_qty
     FROM departments d
     LEFT JOIN users u ON u.department_id = d.id AND u.role = "DEPARTMENT"
     LEFT JOIN requests r ON r.department_id = d.id
     LEFT JOIN request_items ri ON ri.request_id = r.id
     WHERE d.code <> "ADMIN"
     GROUP BY d.id, d.code, d.name
     ORDER BY d.code'
);

$centralStock = rows(
    'SELECT i.item_code, i.item_name, c.name category_name, i.quantity, i.unit,
            i.minimum_stock, i.storage_location
     FROM items i
     JOIN categories c ON c.id = i.category_id
     WHERE i.deleted_at IS NULL AND i.status = "ACTIVE"
     ORDER BY c.name, i.item_name'
);

$deptStock = [];
if ($deptInventoryExists) {
    $deptParams = [];
    $deptWhere = '';
    if ($selectedDept > 0) {
        $deptWhere = ' AND d.id = ?';
        $deptParams[] = $selectedDept;
    }
    $deptStock = rows(
        "SELECT d.code department_code, d.name department_name,
                i.item_code, i.item_name, i.unit, di.quantity
         FROM department_inventory di
         JOIN departments d ON d.id = di.department_id
         JOIN items i ON i.id = di.item_id
         WHERE d.code <> 'ADMIN' $deptWhere
         ORDER BY d.code, i.item_name",
        $deptParams
    );
}

$requestParams = [];
$requestWhere = 'WHERE d.code <> "ADMIN"';
if ($selectedDept > 0) {
    $requestWhere .= ' AND d.id = ?';
    $requestParams[] = $selectedDept;
}
$requests = rows(
    "SELECT r.*, d.code department_code, d.name department_name,
            u.name requested_by_name, ui.name ietw_name, ug.name gsssr_name
     FROM requests r
     JOIN departments d ON d.id = r.department_id
     JOIN users u ON u.id = r.requested_by
     LEFT JOIN users ui ON ui.id = r.ietw_processed_by
     LEFT JOIN users ug ON ug.id = r.gsssr_approved_by
     $requestWhere
     ORDER BY r.created_at DESC
     LIMIT 200",
    $requestParams
);

$activityParams = [];
$activityWhere = 'WHERE u.role IN ("IETW", "DEPARTMENT")';
if ($selectedRole !== '') {
    $activityWhere .= ' AND u.role = ?';
    $activityParams[] = $selectedRole;
}
if ($selectedDept > 0) {
    $activityWhere .= ' AND u.department_id = ?';
    $activityParams[] = $selectedDept;
}
$activities = rows(
    "SELECT al.*, u.name user_name, u.role, d.code department_code, d.name department_name
     FROM activity_logs al
     JOIN users u ON u.id = al.user_id
     LEFT JOIN departments d ON d.id = u.department_id
     $activityWhere
     ORDER BY al.created_at DESC
     LIMIT 300",
    $activityParams
);

render_header('Department & IETW Activity');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-0">Department & IETW Activity <span class="badge bg-warning text-dark ms-2">GSSSR</span></h1>
    <div class="text-muted small">All department requests, IETW actions, and available stock in one admin view.</div>
  </div>
</div>

<form class="row g-2 mb-3">
  <div class="col-md-4">
    <select name="department_id" class="form-select" onchange="this.form.submit()">
      <option value="0">All departments</option>
      <?php foreach ($departments as $d): ?>
      <option value="<?= $d['id'] ?>" <?= $selectedDept === (int)$d['id'] ? 'selected' : '' ?>>
        <?= e($d['code'] . ' - ' . $d['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <select name="role" class="form-select" onchange="this.form.submit()">
      <option value="">IETW + Department activity</option>
      <option value="IETW" <?= $selectedRole === 'IETW' ? 'selected' : '' ?>>IETW only</option>
      <option value="DEPARTMENT" <?= $selectedRole === 'DEPARTMENT' ? 'selected' : '' ?>>Department only</option>
    </select>
  </div>
  <div class="col-md-4">
    <input class="form-control" data-filter-table=".gsssr-live-table" placeholder="Search visible tables">
  </div>
</form>

<div class="row g-3 mb-3">
  <div class="col-xl-7">
    <div class="card metric-card h-100">
      <div class="card-header bg-white fw-semibold">Department Summary</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 gsssr-live-table">
          <thead><tr><th>Dept</th><th>Users</th><th>Requests</th><th>Requested Qty</th><th>Issued Qty</th></tr></thead>
          <tbody>
            <?php foreach ($departmentSummary as $d): ?>
            <tr>
              <td><strong><?= e($d['code']) ?></strong><div class="small text-muted"><?= e($d['name']) ?></div></td>
              <td><?= e($d['user_count']) ?></td>
              <td><?= e($d['request_count']) ?></td>
              <td><?= e($d['requested_qty']) ?></td>
              <td><?= e($d['issued_qty']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card metric-card h-100">
      <div class="card-header bg-white fw-semibold">IETW / Department Activity</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 gsssr-live-table">
          <thead><tr><th>Time</th><th>User</th><th>Role</th><th>Action</th></tr></thead>
          <tbody>
            <?php foreach ($activities as $a): ?>
            <tr>
              <td><?= e($a['created_at']) ?></td>
              <td><?= e($a['user_name']) ?><div class="small text-muted"><?= e($a['department_code'] ?? '') ?></div></td>
              <td><span class="badge <?= $a['role'] === 'IETW' ? 'bg-info text-dark' : 'bg-secondary' ?>"><?= e($a['role']) ?></span></td>
              <td><?= e($a['action']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$activities): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">No activity found for the selected filter.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-6">
    <div class="card metric-card h-100">
      <div class="card-header bg-white fw-semibold">Central Available Stock</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 gsssr-live-table">
          <thead><tr><th>Code</th><th>Item</th><th>Category</th><th>Available</th><th>Location</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($centralStock as $i): ?>
            <tr>
              <td><code><?= e($i['item_code']) ?></code></td>
              <td><?= e($i['item_name']) ?></td>
              <td><?= e($i['category_name']) ?></td>
              <td><?= e(number_format((float)$i['quantity'], 0) . ' ' . $i['unit']) ?></td>
              <td><?= e($i['storage_location']) ?></td>
              <td><?= stock_badge($i) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="card metric-card h-100">
      <div class="card-header bg-white fw-semibold">Department Available Stock</div>
      <?php if (!$deptInventoryExists): ?>
      <div class="card-body">
        <div class="alert alert-warning mb-0">Department stock table is not available yet. Run the latest database script to enable this section.</div>
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 gsssr-live-table">
          <thead><tr><th>Dept</th><th>Code</th><th>Item</th><th>Available</th></tr></thead>
          <tbody>
            <?php foreach ($deptStock as $s): ?>
            <tr>
              <td><strong><?= e($s['department_code']) ?></strong><div class="small text-muted"><?= e($s['department_name']) ?></div></td>
              <td><code><?= e($s['item_code']) ?></code></td>
              <td><?= e($s['item_name']) ?></td>
              <td><?= e(number_format((float)$s['quantity'], 0) . ' ' . $s['unit']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$deptStock): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">No department stock found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card metric-card">
  <div class="card-header bg-white fw-semibold">All Department Requests</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 gsssr-live-table">
      <thead><tr><th>No</th><th>Date</th><th>Department</th><th>Requested By</th><th>Purpose</th><th>Status</th><th>IETW</th><th>GSSSR</th><th>PDF</th></tr></thead>
      <tbody>
        <?php foreach ($requests as $r): ?>
        <tr>
          <td><?= e($r['request_no']) ?></td>
          <td><?= e($r['created_at']) ?></td>
          <td><strong><?= e($r['department_code']) ?></strong><div class="small text-muted"><?= e($r['department_name']) ?></div></td>
          <td><?= e($r['requested_by_name']) ?></td>
          <td><?= e($r['purpose']) ?></td>
          <td><?= request_status_badge($r['status']) ?></td>
          <td><?= e($r['ietw_name'] ?: '-') ?></td>
          <td><?= e($r['gsssr_name'] ?: '-') ?></td>
          <td><a class="btn btn-sm btn-outline-danger" target="_blank" href="<?= BASE_URL ?>/download_pdf.php?type=request&id=<?= $r['id'] ?>"><i class="bi bi-file-pdf"></i></a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); ?>
