<?php
require_once __DIR__ . '/../includes/layout.php';
require_role(['IETW']);
verify_csrf();
$pdo = Database::conn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        record_stock_transaction((int)$_POST['item_id'], $_POST['type'], (float)$_POST['quantity'], trim($_POST['remarks']));
        $pdo->commit();
        log_activity('IETW recorded stock transaction');
        flash('success', 'Stock transaction recorded.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $e->getMessage());
    }
    redirect('/IETW/transactions.php');
}

$items = rows('SELECT id,item_code,item_name,quantity FROM items WHERE deleted_at IS NULL AND status="ACTIVE" ORDER BY item_name');
$type  = $_GET['type'] ?? '';
$params = [];
$where  = 'WHERE 1=1';
if ($type) { $where .= ' AND st.type=?'; $params[] = $type; }
$transactions = rows(
    "SELECT st.*, i.item_name, u.name user_name
     FROM stock_transactions st JOIN items i ON i.id=st.item_id
     LEFT JOIN users u ON u.id=st.created_by
     $where ORDER BY st.id DESC LIMIT 200",
    $params
);

render_header('Transactions — IETW');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3 mb-0">Stock Transactions <span class="badge bg-info text-dark ms-2">IETW</span></h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#txnModal">New Transaction</button>
</div>
<div class="alert alert-info small">
  IETW records inward stock (new deliveries, returns) and adjustments.
  Outward stock is only issued by GSSSR after approval.
</div>

<form class="row g-2 mb-3">
  <div class="col-md-3">
    <select name="type" class="form-select" onchange="this.form.submit()">
      <option value="">All types</option>
      <?php foreach (['INWARD','OUTWARD','RETURN','ADJUSTMENT'] as $t): ?>
      <option <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4"><input class="form-control" data-filter-table="#txnTable" placeholder="Search"></div>
</form>

<div class="card metric-card"><div class="table-responsive">
<table class="table table-hover mb-0" id="txnTable">
  <thead><tr><th>No</th><th>Date</th><th>Item</th><th>Type</th><th>Qty</th><th>Before</th><th>After</th><th>Remarks</th><th>User</th></tr></thead>
  <tbody>
    <?php foreach ($transactions as $t): ?>
    <tr>
      <td><?= e($t['transaction_no']) ?></td>
      <td><?= e($t['created_at']) ?></td>
      <td><?= e($t['item_name']) ?></td>
      <td><?= e($t['type']) ?></td>
      <td><?= e($t['quantity']) ?></td>
      <td><?= e($t['previous_quantity']) ?></td>
      <td><?= e($t['new_quantity']) ?></td>
      <td><?= e($t['remarks']) ?></td>
      <td><?= e($t['user_name']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div></div>

<div class="modal fade" id="txnModal" tabindex="-1"><div class="modal-dialog">
  <form method="post" class="modal-content"><?php csrf_field(); ?>
    <div class="modal-header"><h5 class="modal-title">New Stock Transaction</h5><button class="btn-close" data-bs-dismiss="modal" type="button"></button></div>
    <div class="modal-body">
      <div class="mb-3"><label class="form-label">Item</label>
        <select name="item_id" class="form-select" required>
          <?php foreach ($items as $i): ?>
          <option value="<?= $i['id'] ?>"><?= e($i['item_code'] . ' — ' . $i['item_name'] . ' (' . $i['quantity'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label">Type</label>
        <select name="type" class="form-select" required>
          <option>INWARD</option><option>RETURN</option><option>ADJUSTMENT</option>
        </select>
        <div class="small text-muted mt-1">OUTWARD transactions are created automatically during GSSSR stock issuance.</div>
      </div>
      <div class="mb-3"><label class="form-label">Quantity</label>
        <input name="quantity" type="number" min="0.01" step="0.01" class="form-control" required>
      </div>
      <div class="mb-3"><label class="form-label">Remarks</label>
        <textarea name="remarks" class="form-control" required></textarea>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Record</button></div>
  </form>
</div></div>
<?php render_footer(); ?>
