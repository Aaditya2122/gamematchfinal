<?php
$pageTitle = 'Register';
require_once 'config/bootstrap.php';

if (is_logged_in()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (mb_strlen($name) < 2) $error = 'Name must contain at least 2 characters.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid email address.';
    elseif (strlen($password) < 6) $error = 'Password must be at least 6 characters.';
    else {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) $error = 'An account already exists with that email.';
        else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $pdo->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)');
            $ins->execute([$name, $email, $hash]);
            $_SESSION['user_id'] = (int)$pdo->lastInsertId();
            flash('success', 'Welcome to GameMatch!');
            redirect('index.php');
        }
    }
}

require 'partials/header.php';
?>
<div class="auth-shell">
    <div class="auth-card">
        <p class="eyebrow">Create your account</p>
        <h1>Join GameMatch</h1>
        <p>Save wishlists and share ratings with the community.</p>
        <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?=e(site_url('register.php'))?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="field"><label for="name">Name</label><input id="name" name="name" required></div>
            <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" required></div>
            <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" minlength="6" required></div>
            <button class="btn btn-full" type="submit">Create Account</button>
        </form>
        <p class="auth-bottom">Already registered? <a href="<?=e(site_url('login.php'))?>">Log in</a></p>
    </div>
</div>
<?php require 'partials/footer.php'; ?>
