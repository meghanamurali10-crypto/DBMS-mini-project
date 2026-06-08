<?php
require_once __DIR__ . '/../includes/layout.php';
require_role(['GSSSR']);
verify_csrf();
$pdo = Database::conn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $invoice = upload_file($_FILES['invoice'] ?? [], 'invoices', ['pdf','jpg','jpeg','png']);
            $stmt = $pdo->prepare('INSERT INTO items (item_code,item_name,category_id,quantity,unit,unit_price,minimum_stock,storage_location,description,invoice_path,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                trim($_POST['item_code']),
                trim($_POST['item_name']),
                $_POST['category_id'],
                (int)$_POST['quantity'],
                trim($_POST['unit']),
                (int)$_POST['unit_price'],
                (int)$_POST['minimum_stock'],
                trim($_POST['storage_location']),
                trim($_POST['description']),
                $invoice,
                current_user()['id']
            ]);
            record_stock_transaction((int)$pdo->lastInsertId(), 'INWARD', (int)$_POST['quantity'], 'Opening stock');
            log_activity('Created item ' . $_POST['item_code']);
            flash('success', 'Item added.');
        }

        if ($action === 'update') {
            $item = one('SELECT * FROM items WHERE id=?', [(int)$_POST['id']]);
            if (!$item) throw new RuntimeException('Item not found.');
            $stmt = $pdo->prepare('UPDATE items SET item_code=?, item_name=?, category_id=?, unit=?, unit_price=?, minimum_stock=?, storage_location=?, description=? WHERE id=?');
            $stmt->execute([
                trim($_POST['item_code']),
                trim($_POST['item_name']),
                $_POST['category_id'],
                trim($_POST['unit']),
                (int)$_POST['unit_price'],
                (int)$_POST['minimum_stock'],
                trim($_POST['storage_location']),
                trim($_POST['description']),
                (int)$_POST['id']
            ]);
            log_activity('Updated item ' . $_POST['item_code']);
            flash('success', 'Item updated.');
        }

        if ($action === 'delete') {
            $reason = trim($_POST['delete_reason'] ?? '');
            if ($reason === '') throw new RuntimeException('Delete reason is required.');
            $itemId = (int)$_POST['id'];
            $item = one('SELECT * FROM items WHERE id=?', [$itemId]);
            if (!$item) throw new RuntimeException('Item not found.');
            $hasTransactions = (int)one('SELECT COUNT(*) cnt FROM stock_transactions WHERE item_id=?', [$itemId])['cnt'] > 0;
            $hasRequests = (int)one('SELECT COUNT(*) cnt FROM request_items WHERE item_id=?', [$itemId])['cnt'] > 0;
            if ($hasTransactions || $hasRequests) {
                $pdo->prepare('UPDATE items SET status="INACTIVE", deleted_at=NOW(), archived_at=NOW(), archived_by=?, archive_reason=?, deletion_approval_status="APPROVED_BY_GSSSR" WHERE id=?')
                    ->execute([current_user()['id'], $reason, $itemId]);
                log_activity('Deleted item from active list ID ' . $itemId . ' | Historical records preserved | Reason: ' . $reason);
                flash('warning', 'Item removed from active list. Historical transactions and requests were preserved.');
            } else {
                $pdo->prepare('DELETE FROM items WHERE id=?')->execute([$itemId]);
                log_activity('Permanently deleted item ID ' . $itemId . ' | Reason: ' . $reason);
                flash('success', 'Item deleted permanently.');
            }
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/admin/items.php');
}

$categories = rows('SELECT * FROM categories ORDER BY name');
$category = $_GET['category'] ?? '';
$low = isset($_GET['low']);
$params = [];
$where = 'WHERE i.deleted_at IS NULL AND i.status = "ACTIVE"';
if ($category !== '') { $where .= ' AND i.category_id=?'; $params[] = $category; }
if ($low) { $where .= ' AND i.quantity < i.minimum_stock'; }
$items = rows("SELECT i.*, c.name category_name FROM items i JOIN categories c ON c.id=i.category_id $where ORDER BY i.item_name", $params);

render_header('Item Management');
$itemModals = '';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0">Item Management</h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#itemModal"><i class="bi bi-plus-lg me-1"></i>Add Item</button>
</div>
<form class="row g-2 mb-3">
  <div class="col-md-4"><input class="form-control" data-filter-table="#itemsTable" placeholder="Search items"></div>
  <div class="col-md-3"><select name="category" class="form-select" onchange="this.form.submit()"><option value="">All categories</option><?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= $category == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3 form-check d-flex align-items-center ps-4"><input class="form-check-input me-2" type="checkbox" name="low" value="1" onchange="this.form.submit()" <?= $low ? 'checked' : '' ?>><label class="form-check-label">Low stock only</label></div>
</form>

<div class="card metric-card"><div class="table-responsive">
<table class="table table-hover align-middle mb-0" id="itemsTable">
  <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Qty</th><th>Unit</th><th>Price</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($items as $i): ?>
    <tr>
      <td><?= e($i['item_code']) ?></td>
      <td><?= e($i['item_name']) ?></td>
      <td><?= e($i['category_name']) ?></td>
      <td><?= e((int)$i['quantity']) ?></td>
      <td><?= e($i['unit']) ?></td>
      <td><?= (int)$i['unit_price'] > 0 ? e(number_format((int)$i['unit_price'], 0)) : '<span class="text-muted">-</span>' ?></td>
      <td><?= e($i['storage_location']) ?></td>
      <td><?= stock_badge($i) ?></td>
      <td class="d-flex gap-1 flex-wrap">
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#edit<?= $i['id'] ?>"><i class="bi bi-pencil"></i></button>
        <button class="btn btn-sm btn-delete-soft" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $i['id'] ?>"><i class="bi bi-trash me-1"></i>Delete</button>
      </td>
    </tr>
    <?php ob_start(); ?>
    <div class="modal fade" id="edit<?= $i['id'] ?>" tabindex="-1"><div class="modal-dialog modal-lg"><form method="post" class="modal-content"><?php csrf_field(); ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $i['id'] ?>"><?php include __DIR__ . '/../includes/item_form.php'; ?></form></div></div>
    
    <div class="modal fade" id="deleteModal<?= $i['id'] ?>" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
          <div class="modal-header border-0" style="background: linear-gradient(135deg, #f56565, #e53e3e);">
            <h5 class="modal-title text-white"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Deletion</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $i['id'] ?>">
            <div class="modal-body">
              <p>Are you sure you want to delete <strong><?= e($i['item_name']) ?></strong>?</p>
              <p class="text-secondary small">This action cannot be undone.</p>
              <label class="form-label">Reason for deletion <span class="text-danger">*</span></label>
              <textarea name="delete_reason" class="form-control" rows="3" placeholder="Why is this item being deleted?" required></textarea>
            </div>
            <div class="modal-footer border-0 bg-transparent">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-danger px-4"><i class="bi bi-trash3 me-1"></i>Delete Permanently</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php $itemModals .= ob_get_clean(); ?>
  <?php endforeach; ?>
  </tbody>
</table></div></div>

<?= $itemModals ?>

<div class="modal fade" id="itemModal" tabindex="-1"><div class="modal-dialog modal-lg"><form method="post" enctype="multipart/form-data" class="modal-content"><?php csrf_field(); ?><input type="hidden" name="action" value="create"><?php $i = []; include __DIR__ . '/../includes/item_form.php'; ?></form></div></div>

<script>
$(document).ready(function() {
    console.log("Document ready – script loaded");
    
    // When the Add Item modal is opened, attach event to the dropdown
    $('#itemModal').on('shown.bs.modal', function() {
        console.log("Modal opened");
        var $catSelect = $('#itemModal select[name="category_id"]');
        console.log("Found category select:", $catSelect.length);
        $catSelect.off('change').on('change', function() {
            var catId = $(this).val();
            console.log("Category changed to:", catId);
            var $codeField = $('#itemModal input[name="item_code"]');
            if (!catId) {
                $codeField.val('');
                return;
            }
            $.ajax({
                url: BASE_URL + '/api/get_next_item_code.php',
                data: { category_id: catId },
                dataType: 'json',
                success: function(res) {
                    console.log("API response:", res);
                    if (res.code) {
                        $codeField.val(res.code);
                    } else {
                        $codeField.val('');
                        alert("Error: " + (res.error || "Unknown"));
                    }
                },
                error: function(xhr, status, err) {
                    console.error("AJAX error:", status, err);
                    alert("AJAX failed: " + status);
                }
            });
        });
    });
});
</script>
<?php render_footer(); ?>