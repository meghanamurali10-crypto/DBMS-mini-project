<?php
require_once __DIR__ . '/includes/layout.php';
require_login();

$pdo    = Database::conn();
$role   = current_user()['role'];
$deptId = (int)(current_user()['department_id'] ?? 0);

// --- Read date range filters ---
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');

// If dates are empty, default to current year (Jan 1 - Dec 31)
if ($from === '' || $to === '') {
    $currentYear = date('Y');
    $from = $currentYear . '-01-01';
    $to   = $currentYear . '-12-31';
}

$selectedDept = (int)($_GET['department_id'] ?? 0);
$effectiveDeptId = in_array($role, ['GSSSR', 'IETW'], true) ? $selectedDept : $deptId;

// --- Recent transactions (no date filter) ---
$recent = rows(
    'SELECT st.*, i.item_name, u.name
     FROM stock_transactions st
     JOIN items i ON i.id=st.item_id
     LEFT JOIN users u ON u.id=st.created_by
     ORDER BY st.id DESC LIMIT 8'
);

$departments = rows('SELECT id, code, name FROM departments WHERE code <> "ADMIN" ORDER BY code');

// --- Cards (summary) ---
$cards = [];
if ($role === 'DEPARTMENT') {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM requests WHERE department_id = ?');
    $stmt->execute([$deptId]);
    $myRequests = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE department_id = ? AND status IN ('PENDING_IETW','CONSOLIDATED_BY_IETW')");
    $stmt->execute([$deptId]);
    $myPending = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE department_id = ? AND status IN ('APPROVED_BY_GSSSR','PARTIALLY_APPROVED_BY_GSSSR','ISSUED','PARTIALLY_ISSUED')");
    $stmt->execute([$deptId]);
    $myApproved = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(ri.issued_quantity),0) FROM request_items ri JOIN requests r ON r.id=ri.request_id WHERE r.department_id = ?');
    $stmt->execute([$deptId]);
    $issuedItems = round((float)$stmt->fetchColumn()); // Rounded to whole number

    $cards = [
        'My Requests'         => $myRequests,
        'My Pending Requests' => $myPending,
        'My Approved Requests'=> $myApproved,
        'Items Issued to My Dept' => $issuedItems,
    ];
} else {
    // GSSSR and IETW roles
    $totalItems = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE deleted_at IS NULL AND status="ACTIVE"')->fetchColumn();
    $lowStock = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE deleted_at IS NULL AND status="ACTIVE" AND quantity < minimum_stock')->fetchColumn();
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM requests WHERE status IN ('PENDING_IETW','CONSOLIDATED_BY_IETW')")->fetchColumn();
    $issued = round((float)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM stock_transactions WHERE type='OUTWARD'")->fetchColumn()); // Rounded to whole number

    $cards = [
        'Total Items'      => $totalItems,
        'Low Stock Items'  => $lowStock,
        'Pending Requests' => $pending,
        'Issued Items'     => $issued,
    ];
}

// --- Department usage and trend data (using date range) ---
$periodCondition = 'DATE(r.created_at) BETWEEN ? AND ?';
$periodParams = [$from, $to];

$deptUsage = [];
$trendRows = [];

if (in_array($role, ['GSSSR', 'IETW'], true)) {
    // Department usage query
    $deptParams = [$from, $to];
    $deptWhere = '';
    if ($effectiveDeptId > 0) {
        $deptWhere = ' AND d.id = ?';
        $deptParams[] = $effectiveDeptId;
    }
    $deptUsage = rows(
        "SELECT d.code, d.name,
                COALESCE(SUM(ri.requested_quantity),0) requested_qty,
                COALESCE(SUM(ri.issued_quantity),0) issued_qty
         FROM departments d
         LEFT JOIN requests r ON r.department_id = d.id AND DATE(r.created_at) BETWEEN ? AND ?
         LEFT JOIN request_items ri ON ri.request_id = r.id
         WHERE d.code <> 'ADMIN' $deptWhere
         GROUP BY d.id, d.code, d.name
         ORDER BY requested_qty DESC, issued_qty DESC, d.code",
        $deptParams
    );

    // Trend query (monthly breakdown)
    $trendParams = [$from, $to];
    if ($effectiveDeptId > 0) {
        $trendWhere = ' AND r.department_id = ?';
        $trendParams[] = $effectiveDeptId;
    } else {
        $trendWhere = '';
    }
    $trendRows = rows(
        "SELECT DATE_FORMAT(r.created_at, '%Y-%m') bucket,
                COALESCE(SUM(ri.requested_quantity),0) requested_qty,
                COALESCE(SUM(ri.issued_quantity),0) issued_qty
         FROM requests r
         JOIN request_items ri ON ri.request_id = r.id
         WHERE DATE(r.created_at) BETWEEN ? AND ? $trendWhere
         GROUP BY bucket
         ORDER BY bucket",
        $trendParams
    );
} else {
    // DEPARTMENT role
    $trendRows = rows(
        "SELECT DATE_FORMAT(r.created_at, '%Y-%m') bucket,
                COALESCE(SUM(ri.requested_quantity),0) requested_qty,
                COALESCE(SUM(ri.issued_quantity),0) issued_qty
         FROM requests r
         JOIN request_items ri ON ri.request_id = r.id
         WHERE r.department_id = ? AND DATE(r.created_at) BETWEEN ? AND ?
         GROUP BY bucket
         ORDER BY bucket",
        [$deptId, $from, $to]
    );

    $deptUsage = rows(
        "SELECT d.code, d.name,
                COALESCE(SUM(ri.requested_quantity),0) requested_qty,
                COALESCE(SUM(ri.issued_quantity),0) issued_qty
         FROM departments d
         LEFT JOIN requests r ON r.department_id = d.id
         LEFT JOIN request_items ri ON ri.request_id = r.id
         WHERE d.id = ?
         GROUP BY d.id, d.code, d.name",
        [$deptId]
    );
}

// --- Max values for scaling ---
$maxDept = 1;
foreach ($deptUsage as $row) {
    $maxDept = max($maxDept, (float)$row['requested_qty'], (float)$row['issued_qty']);
}
$maxTrend = 1;
foreach ($trendRows as $row) {
    $maxTrend = max($maxTrend, (float)$row['requested_qty'], (float)$row['issued_qty']);
}

// --- My pending requests (department only) ---
$myPendingRequests = [];
if ($role === 'DEPARTMENT') {
    $myPendingRequests = rows(
        "SELECT request_no, purpose, status, created_at
         FROM requests
         WHERE department_id = ? AND status IN ('PENDING_IETW','CONSOLIDATED_BY_IETW')
         ORDER BY created_at DESC LIMIT 8",
        [$deptId]
    );
}

// --- Page label for display ---
$periodLabel = date('d-m-Y', strtotime($from)) . ' to ' . date('d-m-Y', strtotime($to));

render_header('Dashboard');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h3 mb-0">Dashboard</h1>
    <div class="text-muted small"><?= e(current_user()['department_name'] ?: $role) ?></div>
  </div>
  <?php if ($role === 'GSSSR'): ?>
  <span class="badge bg-warning text-dark fs-6 px-3 py-2"><i class="bi bi-shield-fill-check me-1"></i>GSSSR - Super Admin</span>
  <?php elseif ($role === 'IETW'): ?>
  <span class="badge bg-info text-dark fs-6 px-3 py-2"><i class="bi bi-layers me-1"></i>IETW - Consolidator</span>
  <?php endif; ?>
</div>

<div class="row g-3 mb-4">
  <?php foreach ($cards as $label => $value): ?>
  <div class="col-6 col-xl-3">
    <div class="card metric-card"><div class="card-body">
      <div class="text-muted small"><?= e($label) ?></div>
      <div class="display-6 fw-semibold"><?= e((string)$value) ?></div>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Custom Date Range Form -->
<div class="card metric-card mb-3">
  <div class="card-body">
    <form class="row g-2 align-items-end" method="GET">
      <div class="col-md-3">
        <label class="form-label">From Date</label>
        <input type="date" name="from" class="form-control" value="<?= e($from) ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">To Date</label>
        <input type="date" name="to" class="form-control" value="<?= e($to) ?>" required>
      </div>
      <?php if (in_array($role, ['GSSSR', 'IETW'], true)): ?>
      <div class="col-md-3">
        <label class="form-label">Department</label>
        <select name="department_id" class="form-select">
          <option value="0">All departments</option>
          <?php foreach ($departments as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $effectiveDeptId === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['code'] . ' - ' . $d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-md-3 d-grid">
        <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply Date Range</button>
      </div>
    </form>
    <div class="small text-muted mt-2">Showing data from <?= e($periodLabel) ?></div>
  </div>
</div>

<?php if (in_array($role, ['GSSSR', 'IETW'], true)): ?>
<div class="row g-3 mb-4">
  <div class="col-xl-7">
    <div class="card metric-card h-100">
      <div class="card-header bg-white fw-semibold">Department Request vs Issue - <?= e($periodLabel) ?></div>
      <div class="card-body">
        <?php foreach ($deptUsage as $d): ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between small fw-semibold">
            <span><?= e($d['code']) ?> - <?= e($d['name']) ?></span>
            <span>Req <?= e($d['requested_qty']) ?> | Issued <?= e($d['issued_qty']) ?></span>
          </div>
          <div class="usage-track mt-1">
            <div class="usage-bar requested" style="width:<?= max(2, ((float)$d['requested_qty'] / $maxDept) * 100) ?>%"></div>
            <div class="usage-bar issued" style="width:<?= max(2, ((float)$d['issued_qty'] / $maxDept) * 100) ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$deptUsage): ?><div class="text-muted">No department data available for the selected period.</div><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card metric-card h-100">
      <div class="card-header bg-white fw-semibold">Monthly Trend<?= $effectiveDeptId > 0 ? ' - Selected Department' : '' ?></div>
      <div class="card-body">
        <?php foreach ($trendRows as $row): ?>
        <div class="year-row">
          <div class="year-label"><?= e((string)$row['bucket']) ?></div>
          <div class="year-bars">
            <div class="usage-bar requested" style="width:<?= max(2, ((float)$row['requested_qty'] / $maxTrend) * 100) ?>%"></div>
            <div class="usage-bar issued" style="width:<?= max(2, ((float)$row['issued_qty'] / $maxTrend) * 100) ?>%"></div>
          </div>
          <div class="year-value"><?= e($row['issued_qty']) ?> issued</div>
        </div>
        <?php endforeach; ?>
        <?php if (!$trendRows): ?><div class="text-muted">No request history for the selected period.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php else: ?>
<div class="row g-3 mb-4">
  <div class="col-xl-7">
    <div class="card metric-card h-100">
      <div class="card-header bg-white fw-semibold">My Department Request vs Issue</div>
      <div class="card-body">
        <?php foreach ($deptUsage as $d): ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between small fw-semibold">
            <span><?= e($d['code']) ?> - <?= e($d['name']) ?></span>
            <span>Req <?= e($d['requested_qty']) ?> | Issued <?= e($d['issued_qty']) ?></span>
          </div>
          <div class="usage-track mt-1">
            <div class="usage-bar requested" style="width:<?= max(2, ((float)$d['requested_qty'] / $maxDept) * 100) ?>%"></div>
            <div class="usage-bar issued" style="width:<?= max(2, ((float)$d['issued_qty'] / $maxDept) * 100) ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card metric-card h-100">
      <div class="card-header bg-white fw-semibold">Monthly Trend - My Department</div>
      <div class="card-body">
        <?php foreach ($trendRows as $row): ?>
        <div class="year-row">
          <div class="year-label"><?= e((string)$row['bucket']) ?></div>
          <div class="year-bars">
            <div class="usage-bar requested" style="width:<?= max(2, ((float)$row['requested_qty'] / $maxTrend) * 100) ?>%"></div>
            <div class="usage-bar issued" style="width:<?= max(2, ((float)$row['issued_qty'] / $maxTrend) * 100) ?>%"></div>
          </div>
          <div class="year-value"><?= e($row['issued_qty']) ?> issued</div>
        </div>
        <?php endforeach; ?>
        <?php if (!$trendRows): ?><div class="text-muted">No department trend data for the selected period.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card metric-card mb-4">
  <div class="card-header bg-white fw-semibold">My Pending Requests</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Indent No</th><th>Date</th><th>Purpose</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($myPendingRequests as $request): ?>
        <tr>
          <td><?= e($request['request_no']) ?></td>
          <td><?= e($request['created_at']) ?></td>
          <td><?= e($request['purpose']) ?></td>
          <td><?= request_status_badge(visible_request_status($request)) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$myPendingRequests): ?>
        <tr><td colspan="4" class="text-muted text-center py-4">No pending requests for your department.<?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card metric-card">
  <div class="card-header bg-white fw-semibold">Recent Transactions</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Date</th><th>Item</th><th>Type</th><th>Qty</th><th>Balance</th><th>By</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
      <tr>
        <td><?= e($r['created_at']) ?></td>
        <td><?= e($r['item_name']) ?></td>
        <td><span class="badge text-bg-secondary"><?= e($r['type']) ?></span></td>
        <td><?= e($r['quantity']) ?></td>
        <td><?= e($r['new_quantity']) ?></td>
        <td><?= e($r['name']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); ?>