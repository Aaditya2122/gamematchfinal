<?php
require_once 'config/bootstrap.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
verify_csrf();

$gameId = (int)($_POST['game_id'] ?? 0);
if ($gameId < 1) {
    flash('error', 'Invalid game.');
    redirect('games.php');
}

$check = $pdo->prepare('SELECT 1 FROM wishlists WHERE user_id = ? AND game_id = ?');
$check->execute([$_SESSION['user_id'], $gameId]);

if ($check->fetchColumn()) {
    $stmt = $pdo->prepare('DELETE FROM wishlists WHERE user_id = ? AND game_id = ?');
    $stmt->execute([$_SESSION['user_id'], $gameId]);
    flash('success', 'Removed from wishlist.');
} else {
    try {
        $stmt = $pdo->prepare('INSERT INTO wishlists (user_id, game_id) VALUES (?, ?)');
        $stmt->execute([$_SESSION['user_id'], $gameId]);
    } catch (PDOException $e) {
        // Some existing TiDB databases have wishlists.id as a required
        // non-auto-increment column. Generate the next id without changing
        // or deleting existing wishlist rows.
        $driverCode = (string)$e->getCode();
        $message = $e->getMessage();
        if ($driverCode === '1364' || str_contains($message, "Field 'id' doesn't have a default value")) {
            $fallback = $pdo->prepare("
                INSERT INTO wishlists (id, user_id, game_id)
                SELECT COALESCE(MAX(id), 0) + 1, ?, ?
                FROM wishlists
            ");
            $fallback->execute([$_SESSION['user_id'], $gameId]);
        } else {
            throw $e;
        }
    }
    flash('success', 'Added to wishlist.');
}

redirect('game-details.php?id=' . $gameId);
?>
