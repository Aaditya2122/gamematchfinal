<?php
$pageTitle = 'Profile';
require_once 'config/bootstrap.php';
require_login();

$user = current_user($pdo);
$wishlistCount = (int)$pdo->query("SELECT COUNT(*) FROM wishlists WHERE user_id = " . (int)$user['id'])->fetchColumn();
$ratingCount = (int)$pdo->query("SELECT COUNT(*) FROM ratings WHERE user_id = " . (int)$user['id'])->fetchColumn();

require 'partials/header.php';
?>
<div class="profile-card">
    <div class="avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div>
    <div>
        <p class="eyebrow">Your GameMatch profile</p>
        <h1><?= e($user['name']) ?></h1>
        <p><?= e($user['email']) ?></p>
        <small>Member since <?= e(date('d M Y', strtotime($user['created_at']))) ?></small>
    </div>
</div>

<div class="stats-grid">
    <div class="stat"><strong><?= $wishlistCount ?></strong><span>Wishlist games</span></div>
    <div class="stat"><strong><?= $ratingCount ?></strong><span>Ratings given</span></div>
    <div class="stat"><strong>5</strong><span>Preference types</span></div>
</div>
<?php require 'partials/footer.php'; ?>
