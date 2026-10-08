<?php
$pageTitle = 'Login';
require_once 'config/bootstrap.php';

if (is_logged_in()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT id, name, email, password FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        flash('success', 'Welcome back, ' . $user['name'] . '!');
        redirect('index.php');
    }
    $error = 'Invalid email or password.';
}

require 'partials/header.php';
?>
<div class="auth-shell">
    <div class="auth-card">
        <p class="eyebrow">Welcome back</p>
        <h1>Log in to GameMatch</h1>
        <p>Access your wishlist and rate the games you play.</p>
        <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?=e(site_url('login.php'))?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" required></div>
            <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required></div>
            <button class="btn btn-full" type="submit">Login</button>
        </form>
        <p class="auth-bottom">New here? <a href="<?=e(site_url('register.php'))?>">Create an account</a></p>
    </div>
</div>
<?php require 'partials/footer.php'; ?>
