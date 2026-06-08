<?php
require_once __DIR__ . '/../includes/layout.php';
require_role(['DEPARTMENT']);
verify_csrf();
$pdo = Database::conn();

// ========== Generate default purpose text ==========
$currentMonth = (int)date('n');
$currentYear  = (int)date('Y');

if ($currentMonth >= 8 && $currentMonth <= 12) {
    $academicStart = $currentYear;
    $academicEnd   = $currentYear + 1;
    $semesterType  = 'Odd';
} elseif ($currentMonth >= 1 && $currentMonth <= 1) {
    $academicStart = $currentYear - 1;
    $academicEnd   = $currentYear;
    $semesterType  = 'Odd';
} else {
    $academicStart = $currentYear - 1;
    $academicEnd   = $currentYear;
    $semesterType  = 'Even';
}

$academicYear = $academicStart . '-' . substr($academicEnd, -2);
$defaultPurpose = "Stationery requirement for $semesterType Semester $academicYear";

// ========== Handle form submission ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = $_POST['items'] ?? [];
    if (!$selected) {
        flash('danger', 'Select at least one item.');
        redirect('/department/request.php');
    }
    try {
        $pdo->beginTransaction();
        $requestNo = next_request_number();
        $purpose = trim($_POST['purpose'] ?? '');
        if ($purpose === '') $purpose = $defaultPurpose;
        
        $pdo->prepare('INSERT INTO requests (request_no, department_id, requested_by, purpose, status) VALUES (?, ?, ?, ?, "PENDING_IETW")')
            ->execute([$requestNo, current_user()['department_id'], current_user()['id'], $purpose]);
        $requestId = (int)$pdo->lastInsertId();
        
        $stmt = $pdo->prepare('INSERT INTO request_items (request_id, item_id, requested_quantity, justification) VALUES (?, ?, ?, ?)');
        foreach ($selected as $itemId) {
            $qty = (int)($_POST['qty'][$itemId] ?? 0);
            if ($qty <= 0) throw new RuntimeException('Requested quantity must be positive integer.');
            $stmt->execute([$requestId, (int)$itemId, $qty, trim($_POST['justification'][$itemId] ?? '')]);
        }
        $pdo->commit();
        log_activity('Created request ' . $requestNo);
        flash('success', 'Request submitted to IETW.');
        redirect('/department/history.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $e->getMessage());
    }
}

$items = rows('SELECT i.*, c.name category_name FROM items i JOIN categories c ON c.id=i.category_id WHERE i.deleted_at IS NULL AND i.status="ACTIVE" ORDER BY c.name,i.item_name');
render_header('New Request');
?>
<h1 class="h3 mb-3">Department Indent Request</h1>
<form method="post" class="card metric-card">
  <?php csrf_field(); ?>
  <div class="card-body">
    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Your request first goes to IETW for consolidation and then to GSSSR for final approval and issuing.</div>
    
    <div class="mb-3">
      <label class="form-label">Subject / requirement purpose</label>
      <input type="text" name="purpose" class="form-control" value="<?= htmlspecialchars($defaultPurpose) ?>" required>
      <small class="text-muted">You can edit this if needed.</small>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <input class="form-control flex-grow-1 request-search" data-filter-table="#requestItems" placeholder="Search available items">
      <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit Request</button>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle" id="requestItems">
        <thead>
          <tr>
            <th>Select</th>
            <th>Item</th>
            <th>Category</th>
            <th>Available</th>
            <th>Required Qty (whole number)</th>
            <th>Justification</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($items as $item): ?>
          <tr>
            <td><input class="form-check-input request-check" type="checkbox" name="items[]" value="<?= $item['id'] ?>"></td>
            <td><?= e($item['item_name']) ?><div class="small text-muted"><?= e($item['item_code']) ?></div></td>
            <td><?= e($item['category_name']) ?></td>
            <td><?= e($item['quantity'].' '.$item['unit']) ?></td>
            <td><input class="form-control qty-input request-qty" type="number" min="1" step="1" name="qty[<?= $item['id'] ?>]" disabled></td>
            <td><input class="form-control request-justification" name="justification[<?= $item['id'] ?>]" placeholder="Reason / event / semester use" disabled></td>
            <td><?= stock_badge($item) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>
<?php render_footer(); ?>