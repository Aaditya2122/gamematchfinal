<?php
require_once 'config/bootstrap.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
verify_csrf();

$gameId = (int)($_POST['game_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 0);
$review = trim($_POST['review'] ?? '');

if ($rating < 1 || $rating > 5) {
    flash('error', 'Please select a rating from 1 to 5.');
    redirect('game-details.php?id=' . $gameId);
}

$check = $pdo->prepare('SELECT id FROM ratings WHERE user_id = ? AND game_id = ?');
$check->execute([$_SESSION['user_id'], $gameId]);
$existing = $check->fetchColumn();

if ($existing) {
    $stmt = $pdo->prepare('UPDATE ratings SET rating = ?, review = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$rating, $review, $existing]);
    flash('success', 'Your rating was updated.');
} else {
    $stmt = $pdo->prepare('INSERT INTO ratings (user_id, game_id, rating, review) VALUES (?, ?, ?, ?)');
    $stmt->execute([$_SESSION['user_id'], $gameId, $rating, $review]);
    flash('success', 'Thanks for rating this game!');
}

redirect('game-details.php?id=' . $gameId);
?>