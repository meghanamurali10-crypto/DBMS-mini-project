<?php
/**
 * IETW - Consolidation Desk
 * Aggregates all pending department requests into one consolidated request
 * and forwards it to GSSSR. Original requests are set to status 'CONSOLIDATED'
 * so they no longer appear in GSSSR's pending list. All quantities are integers.
 */
require_once __DIR__ . '/../includes/layout.php';
require_role(['IETW']);
verify_csrf();
$pdo = Database::conn();

// Handle consolidation of a single request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'consolidate_single') {
    try {
        $id = (int)$_POST['id'];
        $request = one('SELECT * FROM requests WHERE id=? AND status="PENDING_IETW"', [$id]);
        if (!$request) throw new RuntimeException('Request is not in PENDING_IETW status.');
        $items = rows('SELECT ri.*, i.quantity avail FROM request_items ri JOIN items i ON i.id=ri.item_id WHERE ri.request_id=?', [$id]);
        foreach ($items as $ri) {
            $recQty = (int)($_POST['rec_qty'][$ri['id']] ?? 0);
            if ($recQty < 0) throw new RuntimeException('Recommended quantity cannot be negative.');
            if ($recQty > (int)$ri['requested_quantity']) throw new RuntimeException('Recommended qty cannot exceed department requested qty.');
            $pdo->prepare('UPDATE request_items SET ietw_recommended_qty=? WHERE id=?')->execute([$recQty, $ri['id']]);
        }
        $pdo->prepare('UPDATE requests SET status="CONSOLIDATED_BY_IETW", ietw_remarks=?, ietw_processed_by=?, ietw_processed_at=NOW() WHERE id=?')
            ->execute([trim($_POST['ietw_remarks']), current_user()['id'], $id]);
        notify_request_stakeholders($id, 'IETW consolidated request ' . $request['request_no'] . ' and forwarded to GSSSR.', 'Request Forwarded');
        log_activity('IETW consolidated request ID ' . $id);
        flash('success', 'Request consolidated and sent to GSSSR.');
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/IETW/consolidate.php');
}

// Handle consolidation of ALL pending requests into ONE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'consolidate_all') {
    try {
        $pdo->beginTransaction();
        $pending = rows('SELECT id, request_no, department_id, requested_by, purpose FROM requests WHERE status="PENDING_IETW" AND (is_consolidated = 0 OR is_consolidated IS NULL)');
        if (empty($pending)) throw new RuntimeException('No pending requests to consolidate.');

        $aggregated = [];
        $sourceIds = [];
        foreach ($pending as $req) {
            $sourceIds[] = $req['id'];
            $items = rows('SELECT item_id, requested_quantity FROM request_items WHERE request_id=?', [$req['id']]);
            foreach ($items as $it) {
                $itemId = $it['item_id'];
                $aggregated[$itemId] = ($aggregated[$itemId] ?? 0) + (int)$it['requested_quantity'];
            }
        }

        // Create consolidated request (CAC department ID 7, adjust if needed)
        $consolidatedDeptId = 7;
        $consolidatedRequestNo = next_request_number();
        $purpose = 'Consolidated request from multiple departments – ' . date('Y-m-d H:i:s');
        $ietwRemarks = trim($_POST['consolidated_remarks'] ?? '');
        
        $stmt = $pdo->prepare("
            INSERT INTO requests 
            (request_no, department_id, requested_by, purpose, status, ietw_remarks, ietw_processed_by, ietw_processed_at, is_consolidated, source_request_ids, consolidated_by, consolidated_at)
            VALUES (?, ?, ?, ?, 'CONSOLIDATED_BY_IETW', ?, ?, NOW(), 1, ?, ?, NOW())
        ");
        $stmt->execute([
            $consolidatedRequestNo,
            $consolidatedDeptId,
            current_user()['id'],
            $purpose,
            $ietwRemarks,
            current_user()['id'],
            implode(',', $sourceIds),
            current_user()['id']
        ]);
        $newId = $pdo->lastInsertId();

        $stmtItem = $pdo->prepare("INSERT INTO request_items (request_id, item_id, requested_quantity, ietw_recommended_qty, justification) VALUES (?, ?, ?, ?, ?)");
        foreach ($aggregated as $itemId => $totalQty) {
            $stmtItem->execute([$newId, $itemId, $totalQty, $totalQty, 'Aggregated from multiple department requests']);
        }

        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
        $pdo->prepare("UPDATE requests SET status='CONSOLIDATED', ietw_processed_by=?, ietw_processed_at=NOW(), is_consolidated=1 WHERE id IN ($placeholders)")
            ->execute(array_merge([current_user()['id']], $sourceIds));

        $pdo->commit();
        notify_request_stakeholders($newId, 'All pending requests consolidated into one request #' . $consolidatedRequestNo, 'All Requests Consolidated');
        log_activity('Consolidated all pending requests into ID ' . $newId);
        flash('success', 'All pending requests consolidated into a single request and sent to GSSSR.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $e->getMessage());
    }
    redirect('/IETW/consolidate.php');
}

// Fetch all pending requests (PENDING_IETW) that are not yet consolidated
$requests = rows('
    SELECT r.*, d.name department_name, u.name requested_by_name
    FROM requests r
    JOIN departments d ON d.id=r.department_id
    JOIN users u ON u.id=r.requested_by
    WHERE r.status = "PENDING_IETW" AND (r.is_consolidated = 0 OR r.is_consolidated IS NULL)
    ORDER BY r.created_at ASC
');

render_header('IETW Consolidation Desk');
?>
<h1 class="h3 mb-3">Consolidation Desk <span class="badge bg-info text-dark ms-2">IETW</span></h1>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-2"></i>
  <strong>How Consolidation Works:</strong> All pending department requests (status <code>PENDING_IETW</code>) will be merged into a single consolidated request. 
  Quantities of the same item from different departments are added together (whole numbers). The original requests become <code>CONSOLIDATED</code> and are hidden from GSSSR. 
  The new consolidated request is sent to GSSSR for final approval.
</div>

<?php if (!empty($requests)): ?>
<div class="card metric-card mb-4">
  <div class="card-body">
    <form method="post" onsubmit="return confirm('Consolidate ALL pending requests into one? This action cannot be undone.');">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="consolidate_all">
      <div class="mb-3">
        <label class="form-label">IETW Remarks for Consolidated Request</label>
        <textarea name="consolidated_remarks" class="form-control" rows="2" placeholder="Add any notes about this consolidation (will appear in the consolidated request)"></textarea>
      </div>
      <button class="btn btn-primary btn-lg"><i class="bi bi-layers-fill me-2"></i>Consolidate All Pending Requests</button>
    </form>
  </div>
</div>

<div class="card metric-card">
  <div class="card-header bg-white fw-semibold">Pending Department Requests (<?= count($requests) ?>)</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Indent No</th>
          <th>Department</th>
          <th>Requested By</th>
          <th>Purpose</th>
          <th>Date</th>
          <th>Items</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $r):
          $items = rows('SELECT ri.*, i.item_name, i.unit, i.quantity avail_qty FROM request_items ri JOIN items i ON i.id=ri.item_id WHERE ri.request_id = ?', [$r['id']]);
        ?>
        <tr>
          <td><?= e($r['request_no']) ?></td>
          <td><?= e($r['department_name']) ?></td>
          <td><?= e($r['requested_by_name']) ?></td>
          <td><?= e($r['purpose']) ?></td>
          <td><?= date('d-m-Y', strtotime($r['created_at'])) ?></td>
          <td>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#items<?= $r['id'] ?>">
              View Items (<?= count($items) ?>)
            </button>
          </td>
        </tr>
        <tr class="collapse" id="items<?= $r['id'] ?>">
          <td colspan="6" class="p-0">
            <div class="p-3 bg-light">
              <table class="table table-sm mb-0">
                <thead><tr><th>Item</th><th>Requested Qty</th><th>Available Stock</th><th>Justification</th></tr></thead>
                <tbody>
                  <?php foreach ($items as $it): ?>
                  <tr>
                    <td><?= e($it['item_name']) ?> (<?= e($it['unit']) ?>)<\/span></td>
                    <td><?= e($it['requested_quantity']) ?></td>
                    <td><?= e($it['avail_qty']) ?></td>
                    <td><?= e($it['justification'] ?: '-') ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Single consolidation modals (optional) -->
<?php foreach ($requests as $r): 
  $items = rows('SELECT ri.*, i.item_name, i.quantity avail_qty, i.unit FROM request_items ri JOIN items i ON i.id=ri.item_id WHERE ri.request_id=?', [$r['id']]);
?>
<div class="modal fade" id="consolidateModal<?= $r['id'] ?>" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">Consolidate Request #<?= e($r['request_no']) ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="consolidate_single">
        <input type="hidden" name="id" value="<?= $r['id'] ?>">
        <div class="modal-body">
          <div class="alert alert-light"><?= e($r['department_name']) ?> | <?= e($r['requested_by_name']) ?></div>
          <p><strong>Purpose:</strong> <?= e($r['purpose']) ?></p>
          <div class="table-responsive">
            <table class="table table-bordered">
              <thead class="table-light">
                <tr><th>Item</th><th>Requested Qty</th><th>Available Stock</th><th>IETW Recommended</th></tr>
              </thead>
              <tbody>
                <?php foreach ($items as $it): ?>
                <tr>
                  <td><strong><?= e($it['item_name']) ?></strong><br><small><?= e($it['unit']) ?></small></td>
                  <td class="text-center"><?= e($it['requested_quantity']) ?></td>
                  <td class="text-center"><?= e($it['avail_qty']) ?></td>
                  <td>
                    <input type="number" class="form-control form-control-sm text-center" name="rec_qty[<?= $it['id'] ?>]" min="0" max="<?= e($it['requested_quantity']) ?>" step="1" value="<?= e(min($it['requested_quantity'], $it['avail_qty'])) ?>" style="width: 140px; margin: 0 auto;">
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="mt-3">
            <label class="form-label">IETW Remarks (Consolidation Notes)</label>
            <textarea name="ietw_remarks" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Forward to GSSSR</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php else: ?>
<div class="alert alert-warning">No pending requests to consolidate. All department requests have already been processed.</div>
<?php endif; ?>
<?php render_footer(); ?>