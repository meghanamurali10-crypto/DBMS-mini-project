<?php
/**
 * IETW - Distribution Desk
 * After GSSSR approval, IETW distributes the approved quantities (whole numbers)
 * to the original departments and issues stock to each department individually.
 */
require_once __DIR__ . '/../includes/layout.php';
require_role(['IETW']);
verify_csrf();
$pdo = Database::conn();

// Helper: distribute integer total among departments proportionally to requested amounts
function distributeIntegers(array $requested, int $total): array {
    $n = count($requested);
    if ($n == 0 || $total <= 0) return array_fill(0, $n, 0);
    $sumReq = array_sum($requested);
    if ($sumReq == 0) return array_fill(0, $n, 0);
    
    $allocations = [];
    $fractions = [];
    $floorSum = 0;
    foreach ($requested as $i => $req) {
        $exact = ($req / $sumReq) * $total;
        $floor = (int)floor($exact);
        $allocations[$i] = $floor;
        $floorSum += $floor;
        $fractions[$i] = $exact - $floor;
    }
    $remaining = $total - $floorSum;
    if ($remaining > 0) {
        arsort($fractions);
        foreach (array_keys($fractions) as $idx) {
            if ($remaining <= 0) break;
            $allocations[$idx]++;
            $remaining--;
        }
    }
    return $allocations;
}

// Handle distribution form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'distribute') {
    try {
        $pdo->beginTransaction();
        $consolidatedId = (int)$_POST['consolidated_id'];
        $request = one('SELECT * FROM requests WHERE id=? AND status IN ("APPROVED_BY_GSSSR","PARTIALLY_APPROVED_BY_GSSSR")', [$consolidatedId]);
        if (!$request) throw new RuntimeException('Request not found or not approved for distribution.');

        // Parse source request IDs
        $sourceIds = [];
        if (!empty($request['source_request_ids'])) {
            $sourceIds = explode(',', $request['source_request_ids']);
            $sourceIds = array_map('intval', $sourceIds);
        } else {
            // Fallback: find all requests that were consolidated? This shouldn't happen.
            // We'll try to find requests with status 'CONSOLIDATED' that have the same purpose? Not reliable.
            throw new RuntimeException('No source request IDs found for this consolidated request. Please contact admin.');
        }
        
        $sourceRequests = [];
        foreach ($sourceIds as $sid) {
            $src = one('SELECT r.*, d.name dept_name FROM requests r JOIN departments d ON d.id=r.department_id WHERE r.id=?', [$sid]);
            if ($src) $sourceRequests[$sid] = $src;
        }
        if (empty($sourceRequests)) throw new RuntimeException('No source requests found.');

        // Get consolidated items with approved quantities
        $consolidatedItems = rows('SELECT ri.*, i.item_name, i.unit FROM request_items ri JOIN items i ON i.id=ri.item_id WHERE ri.request_id=?', [$consolidatedId]);

        // For each source request, fetch its original requested quantities
        $sourceItems = [];
        foreach ($sourceRequests as $sid => $src) {
            $items = rows('SELECT item_id, requested_quantity FROM request_items WHERE request_id=?', [$sid]);
            foreach ($items as $it) {
                $sourceItems[$sid][$it['item_id']] = (int)$it['requested_quantity'];
            }
        }

        // Prepare allocation array from POST (already integers)
        $allocation = [];
        $errors = [];

        foreach ($consolidatedItems as $ci) {
            $itemId = $ci['item_id'];
            $approvedTotal = (int)$ci['gsssr_approved_qty'];
            if ($approvedTotal <= 0) continue;

            $allocatedTotal = 0;
            foreach ($sourceRequests as $sid => $src) {
                $qty = (int)($_POST['qty'][$itemId][$sid] ?? 0);
                if ($qty < 0) $errors[] = "Negative quantity for {$ci['item_name']} for department {$src['dept_name']}";
                $allocation[$sid][$itemId] = $qty;
                $allocatedTotal += $qty;
            }
            if ($allocatedTotal != $approvedTotal) {
                $errors[] = "Total allocated quantity for {$ci['item_name']} ($allocatedTotal) does not match approved total ($approvedTotal)";
            }
        }

        if (!empty($errors)) {
            throw new RuntimeException(implode('<br>', $errors));
        }

        // Now issue stock to each department based on allocation
        foreach ($allocation as $sid => $itemsAlloc) {
            foreach ($itemsAlloc as $itemId => $qty) {
                if ($qty <= 0) continue;
                // Check if enough stock in central warehouse
                $item = one('SELECT quantity FROM items WHERE id=?', [$itemId]);
                if (!$item || (int)$item['quantity'] < $qty) {
                    throw new RuntimeException("Insufficient stock for item ID $itemId. Available: " . ($item['quantity'] ?? 0));
                }
                // Record OUTWARD transaction
                record_stock_transaction(
                    $itemId,
                    'OUTWARD',
                    $qty,
                    "Issued to department via distribution of consolidated request #{$request['request_no']}",
                    null
                );
                // Add to department inventory
                $stmt = $pdo->prepare("INSERT INTO department_inventory (department_id, item_id, quantity) VALUES (?, ?, ?) AS new ON DUPLICATE KEY UPDATE quantity = quantity + new.quantity");
                $stmt->execute([$sourceRequests[$sid]['department_id'], $itemId, $qty]);
            }
            // Mark source request as issued
            $pdo->prepare("UPDATE requests SET status='ISSUED' WHERE id=?")->execute([$sid]);
        }

        // Mark consolidated request as fully issued
        $pdo->prepare("UPDATE requests SET status='ISSUED', admin_issued_by=?, admin_issued_at=NOW() WHERE id=?")->execute([current_user()['id'], $consolidatedId]);

        $pdo->commit();
        log_activity('IETW distributed and issued stock for consolidated request ID ' . $consolidatedId);
        flash('success', 'Stock distributed and issued to departments successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $e->getMessage());
    }
    redirect('/IETW/allocate.php');
}

// Fetch all consolidated requests that are approved by GSSSR but not yet distributed
$pendingDistribution = rows('
    SELECT r.*, d.name department_name
    FROM requests r
    JOIN departments d ON d.id=r.department_id
    WHERE r.status IN ("APPROVED_BY_GSSSR","PARTIALLY_APPROVED_BY_GSSSR") AND r.is_consolidated = 1
    ORDER BY r.created_at ASC
');

render_header('IETW Distribution Desk');
?>
<h1 class="h3 mb-3">Distribution Desk <span class="badge bg-info text-dark ms-2">IETW</span></h1>
<p class="text-muted">Approved consolidated requests are listed below. Click "Distribute" to allocate the approved whole‑number quantities to the original departments and issue stock.</p>

<?php if (empty($pendingDistribution)): ?>
<div class="alert alert-info">No approved consolidated requests waiting for distribution.</div>
<?php else: ?>
  <?php foreach ($pendingDistribution as $req):
    // Parse source request IDs
    $sourceIds = [];
    if (!empty($req['source_request_ids'])) {
        $sourceIds = explode(',', $req['source_request_ids']);
        $sourceIds = array_map('intval', $sourceIds);
    } else {
        // If missing, show error
        echo '<div class="alert alert-danger">Consolidated request #' . e($req['request_no']) . ' has no source request IDs. Cannot distribute.</div>';
        continue;
    }
    $sourceRequests = [];
    foreach ($sourceIds as $sid) {
        $src = one('SELECT r.*, d.name dept_name FROM requests r JOIN departments d ON d.id=r.department_id WHERE r.id=?', [$sid]);
        if ($src) $sourceRequests[] = $src;
    }
    if (empty($sourceRequests)) {
        echo '<div class="alert alert-warning">No valid source requests found for #' . e($req['request_no']) . '</div>';
        continue;
    }
    $consolidatedItems = rows('SELECT ri.*, i.item_name, i.unit FROM request_items ri JOIN items i ON i.id=ri.item_id WHERE ri.request_id=?', [$req['id']]);
  ?>
  <div class="card metric-card mb-4">
    <div class="card-header bg-white fw-semibold">
      Consolidated Request #<?= e($req['request_no']) ?> (Approved on <?= date('d-m-Y', strtotime($req['gsssr_approved_at'])) ?>)
    </div>
    <div class="card-body">
      <form method="post" onsubmit="return confirm('Confirm distribution? Stock will be issued to each department.');">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="distribute">
        <input type="hidden" name="consolidated_id" value="<?= $req['id'] ?>">
        <div class="table-responsive">
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Item</th>
                <?php foreach ($sourceRequests as $src): ?>
                <th class="text-center"><?= e($src['dept_name']) ?><br><small>Original Request #<?= e($src['request_no']) ?></small></th>
                <?php endforeach; ?>
                <th class="text-center bg-light">Total Approved</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($consolidatedItems as $ci):
                $approvedTotal = (int)$ci['gsssr_approved_qty'];
                if ($approvedTotal == 0) continue;
                
                // Get original requested quantities for this item from each source request
                $requestedQuantities = [];
                foreach ($sourceRequests as $src) {
                    $orig = (int)one('SELECT requested_quantity FROM request_items WHERE request_id=? AND item_id=?', [$src['id'], $ci['item_id']])['requested_quantity'] ?? 0;
                    $requestedQuantities[] = $orig;
                }
                // Compute proportional integer allocation
                $allocations = distributeIntegers($requestedQuantities, $approvedTotal);
              ?>
              <tr>
                <td><strong><?= e($ci['item_name']) ?></strong><br><small><?= e($ci['unit']) ?></small></td>
                <?php foreach ($sourceRequests as $idx => $src):
                  $defaultQty = $allocations[$idx] ?? 0;
                ?>
                <td class="text-center">
                  <input type="number" class="form-control form-control-sm text-center" name="qty[<?= $ci['item_id'] ?>][<?= $src['id'] ?>]" min="0" step="1" value="<?= e($defaultQty) ?>" style="width: 100px; margin: 0 auto;" required>
                  <div class="small text-muted">Requested: <?= e($requestedQuantities[$idx]) ?></div>
                </td>
                <?php endforeach; ?>
                <td class="text-center bg-light fw-bold"><?= e($approvedTotal) ?> <?= e($ci['unit']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="mt-3">
          <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Distribute & Issue Stock</button>
        </div>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php render_footer(); ?>