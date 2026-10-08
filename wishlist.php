<?php
$pageTitle = 'My Wishlist';
require_once 'config/bootstrap.php';
require_login();

$user=current_user($pdo); $playedIds=played_game_ids($pdo,(int)$user['id']);

$stmt = $pdo->prepare("
    SELECT
        g.*,
        COALESCE(
            (SELECT AVG(r.rating) FROM ratings r WHERE r.game_id = g.id),
            g.store_rating,
            0
        ) AS avg_rating,
        w.created_at AS wishlist_created_at
    FROM wishlists w
    JOIN games g ON g.id = w.game_id
    WHERE w.user_id = ?
    ORDER BY w.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$games = $stmt->fetchAll();

require 'partials/header.php';
?>
<div class="page-heading">
    <div>
        <p class="eyebrow">Saved games</p>
        <h1>My Wishlist ❤️</h1>
        <p>Keep your must-play games in one place.</p>
    </div>
</div>

<?php if ($games): ?>
<div class="game-grid">
<?php foreach ($games as $game): ?>
    <?php $offer = best_offer($pdo, (int)$game['id']); ?>
    <article class="game-card">
        <a href="game-details.php?id=<?= (int)$game['id'] ?>"><img src="<?= e($game['cover_image']) ?>" data-fallback="<?=e(site_url('images/game-placeholder.svg'))?>" alt="<?= e($game['title']) ?>"></a>
        <div class="game-card-body">
            <div class="card-top"><span class="badge"><?= e(first_tag($game['platforms'],'—')) ?></span><span class="rating">⭐ <?= number_format((float)$game['avg_rating'],1) ?></span></div>
            <h3><a href="game-details.php?id=<?= (int)$game['id'] ?>"><?= e($game['title']) ?></a></h3>
            <p><?= e($game['genres']) ?></p>
            <div class="card-bottom"><strong><?= $offer ? ((float)$offer['price'] <= 0 ? 'Free' : money((float)$offer['price'])) : 'Price unavailable' ?></strong>
                <form method="post" action="wishlist-toggle.php" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="game_id" value="<?= (int)$game['id'] ?>">
                    <button class="link-button" type="submit">Remove</button>
                </form>
            </div><?php if($user && !empty($playedIds) && in_array((int)$game['id'], $playedIds, true)): ?><form method="post" action="<?=e(site_url('played-toggle.php'))?>" class="played-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="game_id" value="<?=(int)$game['id']?>"><input type="hidden" name="redirect_to" value="<?=e(basename($_SERVER['PHP_SELF']).(!empty($_SERVER['QUERY_STRING'])?'?'.$_SERVER['QUERY_STRING']:''))?>"><button class="played-button is-played" type="submit">✓ Played</button></form><?php elseif($user): ?><form method="post" action="<?=e(site_url('played-toggle.php'))?>" class="played-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="game_id" value="<?=(int)$game['id']?>"><input type="hidden" name="redirect_to" value="<?=e(basename($_SERVER['PHP_SELF']).(!empty($_SERVER['QUERY_STRING'])?'?'.$_SERVER['QUERY_STRING']:''))?>"><button class="played-button" type="submit">Mark as Played</button></form><?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty">
    <div class="empty-icon">♡</div>
    <h2>Your wishlist is empty</h2>
    <p>Browse games and save the ones you want to play later.</p>
    <a class="btn" href="games.php">Browse Games</a>
</div>
<?php endif; ?>
<?php require 'partials/footer.php'; ?>
