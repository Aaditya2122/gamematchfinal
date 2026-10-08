<?php
$pageTitle='Played Games';
require_once 'config/bootstrap.php';
require_login();
$user=current_user($pdo); ensure_personalization_table($pdo);
$stmt=$pdo->prepare("SELECT g.*, p.played_at FROM played_games p JOIN games g ON g.id=p.game_id WHERE p.user_id=? ORDER BY p.played_at DESC, g.title ASC");
$stmt->execute([$user['id']]); $games=$stmt->fetchAll();
$playedIds=array_map('intval',array_column($games,'id'));
require 'partials/header.php';
?>
<section class="page-heading"><p class="eyebrow">Your gaming history</p><h1>Played Games 🎮</h1><p>Games you have marked as played. They also help GameMatch improve your personalized recommendations.</p></section>
<?php if(!$games): ?>
<div class="empty-state"><h2>No played games yet</h2><p>Mark games as played while browsing and they will appear here.</p><a class="btn" href="<?=e(site_url('games.php'))?>">Browse Games</a></div>
<?php else: ?>
<p class="result-count"><?=count($games)?> played game(s)</p>
<div class="game-grid played-grid">
<?php foreach($games as $game): ?>
<article class="game-card"><a href="game-details.php?id=<?=(int)$game['id']?>"><img src="<?=e($game['cover_image'])?>" data-fallback="<?=e(site_url('images/game-placeholder.svg'))?>" alt="<?=e($game['title'])?>" loading="lazy"></a><div class="game-card-body"><div class="card-top"><span class="badge"><?=e(first_tag($game['platforms'],'—'))?></span><span class="rating">⭐ <?= $game['store_rating'] ? number_format((float)$game['store_rating'],1) : '—' ?></span></div><h3><a href="game-details.php?id=<?=(int)$game['id']?>"><?=e($game['title'])?></a></h3><p><?=e($game['genres'])?></p><div class="card-bottom"><small>Played <?=e(date('d M Y',strtotime($game['played_at'])))?></small><a href="game-details.php?id=<?=(int)$game['id']?>">Details →</a></div><form method="post" action="<?=e(site_url('played-toggle.php'))?>" class="played-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="game_id" value="<?=(int)$game['id']?>"><input type="hidden" name="redirect_to" value="played.php"><button class="played-button is-played" type="submit">✓ Played</button></form></div></article>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php require 'partials/footer.php'; ?>
