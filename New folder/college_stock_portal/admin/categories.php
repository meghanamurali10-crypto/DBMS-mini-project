<?php
require_once __DIR__ . '/../includes/layout.php';
require_role(['GSSSR']);
verify_csrf();
$pdo = Database::conn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'save') {
        $pdo->prepare(
            'INSERT INTO categories (name, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description=VALUES(description)'
        )->execute([trim($_POST['name']), trim($_POST['description'])]);
        log_activity('GSSSR saved category ' . $_POST['name']);
        flash('success', 'Category saved.');
    }
    redirect('/admin/categories.php');
}

$categories = rows(
    'SELECT c.*, COUNT(i.id) item_count FROM categories c
     LEFT JOIN items i ON i.category_id=c.id AND i.deleted_at IS NULL
     GROUP BY c.id ORDER BY c.name'
);
render_header('Categories — GSSSR');
?>
<h1 class="h3 mb-3">Categories <span class="badge bg-warning text-dark ms-2">GSSSR</span></h1>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card metric-card"><div class="card-body">
      <form method="post"><?php csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <div class="mb-3"><label class="form-label">Name</label><input name="name" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control"></textarea></div>
        <button class="btn btn-primary">Save Category</button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card metric-card"><div class="table-responsive">
      <table class="table mb-0">
        <thead><tr><th>Name</th><th>Description</th><th>Items</th></tr></thead>
        <tbody>
          <?php foreach ($categories as $c): ?>
          <tr><td><?= e($c['name']) ?></td><td><?= e($c['description']) ?></td><td><?= e($c['item_count']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div>
<?php render_footer(); ?>
