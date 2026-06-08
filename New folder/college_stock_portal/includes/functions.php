<?php
require_once __DIR__ . '/db.php';

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }
    }
}

function log_activity(string $action): void
{
    if (empty($_SESSION['user'])) {
        return;
    }
    $stmt = Database::conn()->prepare(
        'INSERT INTO activity_logs (user_id, action, ip_address) VALUES (?, ?, ?)'
    );
    $stmt->execute([$_SESSION['user']['id'], $action, $_SERVER['REMOTE_ADDR'] ?? 'CLI']);
}

function upload_file(array $file, string $targetFolder, array $allowedExtensions): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions, true)) {
        throw new RuntimeException('Invalid file type.');
    }
    $dangerous = ['php', 'phtml', 'exe', 'js', 'bat', 'cmd', 'sh'];
    if (in_array($ext, $dangerous, true)) {
        throw new RuntimeException('Blocked unsafe file type.');
    }
    $dir = UPLOAD_DIR . '/' . trim($targetFolder, '/');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $path = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        throw new RuntimeException('Could not save uploaded file.');
    }
    return 'uploads/' . trim($targetFolder, '/') . '/' . $name;
}

function next_request_number(): string
{
    return 'REQ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function next_transaction_number(): string
{
    return 'TRX-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function can_edit_item(string $createdAt): bool
{
    return (time() - strtotime($createdAt)) <= (EDIT_LOCK_MINUTES * 60);
}

function stock_badge(array $item): string
{
    if ((float)$item['quantity'] < (float)$item['minimum_stock']) {
        return '<span class="badge text-bg-danger">Low stock</span>';
    }
    return '<span class="badge text-bg-success">OK</span>';
}

function record_stock_transaction(
    int $itemId,
    string $type,
    float $quantity,
    string $remarks,
    ?int $requestItemId = null
): void {
    $pdo  = Database::conn();
    $stmt = $pdo->prepare('SELECT quantity FROM items WHERE id = ? FOR UPDATE');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        throw new RuntimeException('Item not found.');
    }
    $old   = (float)$item['quantity'];
    $delta = match ($type) {
        'INWARD', 'RETURN' => $quantity,
        'OUTWARD'          => -$quantity,
        'ADJUSTMENT'       => $quantity,
        default            => throw new RuntimeException('Invalid transaction type.'),
    };
    $new = $old + $delta;
    if ($new < 0) {
        throw new RuntimeException('Stock cannot go negative.');
    }
    $pdo->prepare('UPDATE items SET quantity = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$new, $itemId]);
    $pdo->prepare(
        'INSERT INTO stock_transactions (transaction_no, item_id, request_item_id, type, quantity, previous_quantity, new_quantity, remarks, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        next_transaction_number(), $itemId, $requestItemId, $type,
        $quantity, $old, $new, $remarks, $_SESSION['user']['id'] ?? null,
    ]);
    $pdo->prepare(
        'INSERT INTO stock_book (item_id, transaction_type, inward_qty, outward_qty, balance_qty, remarks, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $itemId,
        $type,
        in_array($type, ['INWARD', 'RETURN'], true) ? $quantity : 0,
        $type === 'OUTWARD' ? $quantity : 0,
        $new,
        $remarks,
        $_SESSION['user']['id'] ?? null,
    ]);
}

function rows(string $sql, array $params = []): array
{
    $stmt = Database::conn()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function one(string $sql, array $params = []): ?array
{
    $stmt = Database::conn()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function visible_request_status(array $request, ?array $viewer = null): string
{
    $viewer = $viewer ?? current_user();
    $role = $viewer['role'] ?? '';
    $status = (string)($request['status'] ?? '');

    if ($role === 'DEPARTMENT') {
        return match ($status) {
            'PENDING_IETW', 'CONSOLIDATED_BY_IETW', 'REJECTED_BY_GSSSR' => 'Pending',
            'APPROVED_BY_GSSSR', 'PARTIALLY_APPROVED_BY_GSSSR' => 'Approved',
            'PARTIALLY_ISSUED' => 'Partially Issued',
            'ISSUED' => 'Issued',
            'CONSOLIDATED' => 'Consolidated (Archived)',
            default => $status,
        };
    }

    return $status;
}

function request_status_badge(string $status): string
{
    $class = match ($status) {
        'Pending' => 'text-bg-secondary',
        'Approved' => 'text-bg-success',
        'Issued' => 'text-bg-primary',
        'Partially Issued' => 'text-bg-info',
        'PARTIALLY_APPROVED_BY_GSSSR' => 'text-bg-warning',
        'REJECTED_BY_GSSSR' => 'text-bg-danger',
        'CONSOLIDATED' => 'text-bg-secondary',
        default => 'text-bg-secondary',
    };
    return '<span class="badge ' . $class . '">' . e($status) . '</span>';
}

function create_notification(int $userId, string $title, string $message): void
{
    Database::conn()->prepare(
        'INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)'
    )->execute([$userId, $title, $message]);
}

function request_stakeholder_ids(int $requestId): array
{
    $request = one(
        'SELECT requested_by FROM requests WHERE id = ?',
        [$requestId]
    );
    if (!$request) {
        return [];
    }
    $stakeholders = [(int)$request['requested_by']];
    foreach (rows('SELECT id FROM users WHERE role = "IETW" AND status = "ACTIVE"') as $user) {
        $stakeholders[] = (int)$user['id'];
    }
    return array_values(array_unique($stakeholders));
}

function notify_request_stakeholders(int $requestId, string $message, string $title = 'Request Update'): void
{
    foreach (request_stakeholder_ids($requestId) as $userId) {
        create_notification($userId, $title, $message);
    }
}
