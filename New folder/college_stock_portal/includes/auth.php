<?php
require_once __DIR__ . '/functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        redirect('/login.php');
    }
}

function require_role(array $roles): void
{
    require_login();
    if (!in_array(current_user()['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function login_user(string $email, string $password): bool
{
    $stmt = Database::conn()->prepare('SELECT u.*, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.email = ? AND u.status = "ACTIVE"');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $ok = $user && password_verify($password, $user['password_hash']);
    Database::conn()->prepare('INSERT INTO login_history (user_id, email, success, ip_address) VALUES (?, ?, ?, ?)')
        ->execute([$user['id'] ?? null, $email, $ok ? 1 : 0, $_SERVER['REMOTE_ADDR'] ?? 'CLI']);
    if (!$ok) {
        return false;
    }
    // Only allow GSSSR, IETW, and DEPARTMENT roles
    if (!in_array($user['role'], ['GSSSR', 'IETW', 'DEPARTMENT'], true)) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'              => (int)$user['id'],
        'name'            => $user['name'],
        'email'           => $user['email'],
        'role'            => $user['role'],
        'department_id'   => $user['department_id'],
        'department_name' => $user['department_name'],
    ];
    log_activity('Logged in');
    return true;
}