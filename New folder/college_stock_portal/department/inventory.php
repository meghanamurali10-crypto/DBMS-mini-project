<?php
require_once __DIR__ . '/../includes/layout.php';
require_role(['DEPARTMENT']);

$pdo = Database::conn();
$deptId = current_user()['department_id'];

// Fetch all items currently in department inventory
$items = rows("
    SELECT di.item_id, di.quantity as dept_quantity, i.item_name, i.item_code, i.unit
    FROM department_inventory di
    JOIN items i ON i.id = di.item_id
    WHERE di.department_id = ?
    ORDER BY i.item_name
", [$deptId]);

// Fetch detailed history with date, quantity, remarks (optional – can be added later)
$history = rows("
    SELECT ri.issued_quantity, ri.gsssr_approved_qty, ri.ietw_recommended_qty, 
           i.item_name, i.item_code, i.unit, r.request_no, r.created_at, r.gsssr_remarks
    FROM request_items ri
    JOIN requests r ON r.id = ri.request_id
    JOIN items i ON i.id = ri.item_id
    WHERE r.department_id = ? AND ri.issued_quantity > 0
    ORDER BY r.created_at DESC
", [$deptId]);

render_header('My Department Inventory');
?>
<h1 class="h3 mb-3">My Department Stock</h1>

<div class="row g-3">
  <!-- Current Stock Card -->
  <div class="col-lg-6">
    <div class="card metric-card h-100">
      <div class="card-header bg-white fw-semibold">Current Stock</div>
      <div class="card-body">
        <?php if (empty($items)): ?>
          <div class="alert alert-info">No items have been issued to your department yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover">
              <thead>
                <tr><th>Item Code</th><th>Item Name</th><th>Quantity</th><th>Unit</th></tr>
              </thead>
              <tbody>
                <?php foreach ($items as $it): ?>
                <tr>
                  <td><?= e($it['item_code']) ?></td>
                  <td><?= e($it['item_name']) ?></td>
                  <td><?= e($it['dept_quantity']) ?></td>
                  <td><?= e($it['unit']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Issue History Card -->
  <div class="col-lg-6">
    <div class="card metric-card h-100">
      <div class="card-header bg-white fw-semibold">Issue History</div>
      <div class="card-body">
        <?php if (empty($history)): ?>
          <div class="alert alert-info">No issue history found.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm">
              <thead>
                <tr><th>Date</th><th>Request No</th><th>Item Code</th><th>Item</th><th>Qty</th><th>Unit</th><th>Remarks</th></tr>
              </thead>
              <tbody>
                <?php foreach ($history as $h): ?>
                <tr>
                  <td><?= date('d-m-Y', strtotime($h['created_at'])) ?></td>
                  <td><?= e($h['request_no']) ?></td>
                  <td><?= e($h['item_code']) ?></td>
                  <td><?= e($h['item_name']) ?></td>
                  <td><?= e($h['issued_quantity']) ?></td>
                  <td><?= e($h['unit']) ?></td>
                  <td><?= e($h['gsssr_remarks'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php render_footer(); ?>