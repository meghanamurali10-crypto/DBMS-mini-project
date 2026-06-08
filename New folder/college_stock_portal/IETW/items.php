<?php
require_once __DIR__ . '/../includes/layout.php';
require_role(['IETW']);
$pdo = Database::conn();

$categories = rows('SELECT * FROM categories ORDER BY name');
$category = $_GET['category'] ?? '';
$low = isset($_GET['low']);
$params = [];
$where = 'WHERE i.deleted_at IS NULL AND i.status = "ACTIVE"';
if ($category !== '') { $where .= ' AND i.category_id=?'; $params[] = $category; }
if ($low) { $where .= ' AND i.quantity < i.minimum_stock'; }
$items = rows("SELECT i.*, c.name category_name FROM items i JOIN categories c ON c.id=i.category_id $where ORDER BY i.item_name", $params);

render_header('View Items — IETW');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0">View Items <span class="badge bg-info text-dark ms-2">Read-Only</span></h1>
</div>
<div class="alert alert-info small">IETW can view all items but cannot add, edit, or delete. Stock adjustments are done via Transactions.</div>

<form class="row g-2 mb-3">
  <div class="col-md-4">
    <input class="form-control" data-filter-table="#itemsTable" placeholder="Search items">
  </div>
  <div class="col-md-3">
    <select name="category" class="form-select" onchange="this.form.submit()">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?>
      <option value="<?= $c['id'] ?>" <?= $category == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3 form-check d-flex align-items-center ps-4">
    <input class="form-check-input me-2" type="checkbox" name="low" value="1" onchange="this.form.submit()" <?= $low ? 'checked' : '' ?>>
    <label class="form-check-label">Low stock only</label>
  </div>
</form>

<div class="card metric-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="itemsTable">
        <thead class="table-light">
          <tr>
            <th>Code</th>
            <th>Name</th>
            <th>Category</th>
            <th class="text-end">Qty</th>
            <th>Unit</th>
            <th class="text-end">Price</th>
            <th>Location</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($items)): ?>
          <tr>
            <td colspan="8" class="text-center text-muted py-4">No items found.</td>
          </tr>
          <?php else: ?>
            <?php foreach ($items as $i): ?>
            <tr>
              <td><code><?= e($i['item_code']) ?></code></td>
              <td><?= e($i['item_name']) ?></td>
              <td><?= e($i['category_name']) ?></td>
              <td class="text-end"><?= e(number_format((float)$i['quantity'], 0)) ?></td>
              <td><?= e($i['unit']) ?></td>
              <td class="text-end"><?= (float)$i['unit_price'] > 0 ? '₹ ' . number_format((float)$i['unit_price'], 0) : '-' ?></td>
              <td><?= e($i['storage_location']) ?></td>
              <td><?= stock_badge($i) ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php render_footer(); ?>