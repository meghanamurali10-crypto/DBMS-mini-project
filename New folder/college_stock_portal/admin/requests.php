<?php
/**
 * GSSSR - Approval Desk (Approval only – no auto-issue)
 * After approval, IETW will distribute and issue stock to individual departments.
 */
require_once __DIR__ . '/../includes/layout.php';
require_role(['GSSSR']);
verify_csrf();
$pdo = Database::conn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'decision') {
        try {
            $pdo->beginTransaction();
            $id = (int)$_POST['id'];
            $decision = $_POST['decision'] ?? 'approve';

            $defaultRemark = match ($decision) {
                'partial' => 'The request has been partially approved. IETW must now distribute the approved quantities to the respective departments.',
                'reject'  => 'After careful review, the request has not been approved for the current semester.',
                default   => 'The request has been fully approved. IETW may now distribute and issue the stock.',
            };

            $status = match ($decision) {
                'partial' => 'PARTIALLY_APPROVED_BY_GSSSR',
                'reject'  => 'REJECTED_BY_GSSSR',
                default   => 'APPROVED_BY_GSSSR',
            };

            $request = one('SELECT * FROM requests WHERE id=? AND status="CONSOLIDATED_BY_IETW"', [$id]);
            if (!$request) throw new RuntimeException('Request is not available for GSSSR review.');

            $items = rows('SELECT ri.*, i.item_name FROM request_items ri JOIN items i ON i.id=ri.item_id WHERE ri.request_id=?', [$id]);
            foreach ($items as $ri) {
                if ($decision === 'reject') {
                    $approvedQty = 0;
                } elseif ($decision === 'partial') {
                    $approvedQty = (int)($_POST['approved_qty'][$ri['id']] ?? 0);
                    if ($approvedQty < 0 || $approvedQty > (int)$ri['ietw_recommended_qty']) {
                        throw new RuntimeException('Approved qty cannot exceed IETW recommendation for ' . $ri['item_name']);
                    }
                } else {
                    $approvedQty = (int)$ri['ietw_recommended_qty'];
                }
                $pdo->prepare('UPDATE request_items SET gsssr_approved_qty = ? WHERE id = ?')->execute([$approvedQty, $ri['id']]);
            }

            $userRemarks = trim($_POST['remarks'] ?? '');
            $finalMessage = $userRemarks !== '' ? $userRemarks . "\n\n" . $defaultRemark : $defaultRemark;

            $pdo->prepare('UPDATE requests SET status=?, gsssr_remarks=?, gsssr_approved_by=?, gsssr_approved_at=NOW() WHERE id=?')
                ->execute([$status, $finalMessage, current_user()['id'], $id]);

            $pdo->commit();

            notify_request_stakeholders($id, $finalMessage, 'GSSSR Decision');
            log_activity('GSSSR ' . $status . ' request ID ' . $id);
            flash('success', $decision === 'reject' ? 'Request rejected.' : 'Request approved. IETW will now distribute the stock.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('danger', $e->getMessage());
        }
        redirect('/admin/requests.php');
    }
}

$requests = rows('
    SELECT r.*, d.name department_name, u.name requested_by_name, ui.name ietw_name
    FROM requests r
    JOIN departments d ON d.id=r.department_id
    JOIN users u ON u.id=r.requested_by
    LEFT JOIN users ui ON ui.id=r.ietw_processed_by
    WHERE r.status IN ("CONSOLIDATED_BY_IETW", "APPROVED_BY_GSSSR", "PARTIALLY_APPROVED_BY_GSSSR", "ISSUED", "PARTIALLY_ISSUED", "REJECTED_BY_GSSSR")
    ORDER BY FIELD(r.status, "CONSOLIDATED_BY_IETW", "APPROVED_BY_GSSSR", "PARTIALLY_APPROVED_BY_GSSSR", "PARTIALLY_ISSUED", "ISSUED", "REJECTED_BY_GSSSR"), r.id DESC
');

render_header('GSSSR Approval Desk');
$requestModals = '';
?>
<h1 class="h3 mb-3">GSSSR Approval Desk <span class="badge bg-warning text-dark ms-2">Super Admin</span></h1>
<p class="text-muted">Consolidated requests from IETW are shown below. Approve (full or partial) to send them back to IETW for distribution and issuance.</p>

<div class="card metric-card"><div class="table-responsive">
<table class="table align-middle mb-0">
  <thead>
    <tr>
      <th>Indent No</th>
      <th>Department / Consolidated By</th>
      <th>Subject</th>
      <th>Status</th>
      <th>IETW Remarks</th>
      <th>GSSSR Remarks</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($requests as $r):
    $items = rows('SELECT ri.*, i.item_name, i.quantity avail_qty, i.unit FROM request_items ri JOIN items i ON i.id=ri.item_id WHERE ri.request_id=?', [$r['id']]);
    $displayDepartment = $r['is_consolidated'] ? 'IETW (Consolidated)' : e($r['department_name']);
  ?>
  <tr>
    <td><?= e($r['request_no']) ?></td>
    <td><?= $displayDepartment ?> <?= $r['is_consolidated'] ? '<span class="badge bg-secondary ms-1">Merged</span>' : '' ?></td>
    <td><?= e($r['purpose']) ?></td>
    <td><?= request_status_badge($r['status']) ?></td>
    <td><?= e($r['ietw_remarks']) ?></td>
    <td><?= e($r['gsssr_remarks']) ?></td>
    <td class="d-flex gap-1 flex-wrap">
      <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#items<?= $r['id'] ?>"><i class="bi bi-table me-1"></i>View Items</button>
      <a class="btn btn-sm btn-outline-danger" target="_blank" href="<?= BASE_URL ?>/download_pdf.php?type=request&id=<?= $r['id'] ?>"><i class="bi bi-file-pdf me-1"></i>PDF</a>
      <?php if ($r['status'] === 'CONSOLIDATED_BY_IETW'): ?>
      <button class="btn btn-sm btn-success gsssr-decision-trigger" data-decision-mode="approve" data-bs-toggle="modal" data-bs-target="#decisionModal<?= $r['id'] ?>"><i class="bi bi-check-lg me-1"></i>Approve</button>
      <button class="btn btn-sm btn-warning gsssr-decision-trigger" data-decision-mode="partial" data-bs-toggle="modal" data-bs-target="#decisionModal<?= $r['id'] ?>"><i class="bi bi-pie-chart me-1"></i>Partial Approve</button>
      <button class="btn btn-sm btn-danger gsssr-decision-trigger" data-decision-mode="reject" data-bs-toggle="modal" data-bs-target="#decisionModal<?= $r['id'] ?>"><i class="bi bi-x-circle me-1"></i>Reject</button>
      <?php endif; ?>
    </td>
  </tr>
  <tr class="request-detail-row"><td colspan="7" class="p-0 border-0"><div class="collapse" id="items<?= $r['id'] ?>"><div class="request-detail-wrap"><div class="table-responsive"><table class="table table-sm request-items-table mb-0"><thead><tr><th>Item</th><th>Available</th><th>Requested</th><th>Justification</th><th>IETW Rec</th><th>GSSSR Approved</th><th>Issued</th></tr></thead><tbody><?php foreach ($items as $it): ?><tr><td><?= e($it['item_name']) ?> (<?= e($it['unit']) ?>)<\/span></td><td><?= e($it['avail_qty']) ?></td><td><?= e($it['requested_quantity']) ?></td><td><?= e($it['justification'] ?: '-') ?></td><td><?= e($it['ietw_recommended_qty']) ?></td><td><?= e($it['gsssr_approved_qty']) ?></td><td><?= e($it['issued_quantity']) ?></td></tr><?php endforeach; ?></tbody></table></div></div></div></td></tr>

  <?php ob_start(); ?>
  <div class="modal fade" id="decisionModal<?= $r['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-xl">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">GSSSR Decision - <?= e($r['request_no']) ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form method="post">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="decision">
          <input type="hidden" name="id" value="<?= $r['id'] ?>">
          <input type="hidden" name="preferred_decision" value="approve">
          <div class="modal-body">
            <div class="alert alert-info">
              <strong>Note:</strong> Approving (full or partial) will send the request back to IETW for distribution to individual departments. Stock will not be issued now.
            </div>
            <div class="table-responsive">
              <table class="table table-bordered">
                <thead class="table-light">
                  <tr><th>Item</th><th>Requested Qty</th><th>IETW Recommended</th><th>Available Stock</th><th>GSSSR Approved Qty</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($items as $it): ?>
                  <tr>
                    <td><strong><?= e($it['item_name']) ?></strong><br><small><?= e($it['unit']) ?></small></td>
                    <td class="text-center"><?= e($it['requested_quantity']) ?></td>
                    <td class="text-center"><?= e($it['ietw_recommended_qty']) ?></td>
                    <td class="text-center"><?= e($it['avail_qty']) ?></td>
                    <td>
                      <input type="number" class="form-control form-control-sm text-center" name="approved_qty[<?= $it['id'] ?>]" min="0" max="<?= e($it['ietw_recommended_qty']) ?>" step="1" value="<?= e((int)$it['ietw_recommended_qty']) ?>">
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="mt-3">
              <label class="form-label">GSSSR Remarks (optional)</label>
              <textarea name="remarks" class="form-control" rows="2" placeholder="Add any remarks..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" name="decision" value="reject" class="btn btn-danger">Reject</button>
            <button type="submit" name="decision" value="partial" class="btn btn-warning">Partial Approve</button>
            <button type="submit" name="decision" value="approve" class="btn btn-success">Approve Fully</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php $requestModals .= ob_get_clean(); ?>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?= $requestModals ?>
<?php render_footer(); ?>