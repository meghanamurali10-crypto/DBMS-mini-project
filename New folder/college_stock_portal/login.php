<?php
require_once __DIR__ . '/includes/auth.php';
if (current_user()) {
    redirect('/dashboard.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (login_user(trim($_POST['email'] ?? ''), $_POST['password'] ?? '')) {
        redirect('/dashboard.php');
    }
    flash('danger', 'Invalid email or password.');
}
require_once __DIR__ . '/includes/layout.php';
render_header('Login');
?>
<div class="auth-shell">
  <div class="card auth-card">
    <div class="card-body p-4">
      <div class="d-flex align-items-center gap-2 mb-3">
        <img src="<?= APP_LOGO ?>" alt="<?= e(APP_SHORT_NAME) ?> logo" width="44" height="44" class="brand-logo">
        <h1 class="h4 mb-0"><?= e(APP_SHORT_NAME) ?> Login</h1>
      </div>
      <p class="text-muted mb-4">Secure inventory access for college departments.</p>
      <form method="post">
        <?php csrf_field(); ?>
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button class="btn btn-primary w-100"><i class="bi bi-shield-lock me-1"></i> Login</button>
      </form>
      <div class="small text-muted mt-3">Demo password for seeded users: <strong>password</strong></div>
    </div>
  </div>
</div>
<?php render_footer(); ?>
