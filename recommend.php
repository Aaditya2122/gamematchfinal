<?php
$pageTitle = 'Recommend a Game';
require_once 'config/bootstrap.php';

$platform = '';
$genre = '';
$mode = '';
$age = '';
$maxPrice = 5000;
$recommendations = [];
$submitted = ($_SERVER['REQUEST_METHOD'] === 'POST');

if ($submitted) {
    $platform = trim($_POST['platform'] ?? '');
    $genre = trim($_POST['genre'] ?? '');
    $mode = trim($_POST['mode'] ?? '');
    $age = trim($_POST['age_rating'] ?? '');
    $maxPrice = is_numeric($_POST['max_price'] ?? '') ? (float)$_POST['max_price'] : 999999;

    $where = [];
    $params = [];

    if ($platform !== '') {
        $where[] = 'g.platforms LIKE ?';
        $params[] = '%' . $platform . '%';
    }
    if ($genre !== '') {
        $where[] = 'g.genres LIKE ?';
        $params[] = '%' . $genre . '%';
    }
    if ($mode !== '') {
        $where[] = 'g.game_mode LIKE ?';
        $params[] = '%' . $mode . '%';
    }
    if ($age !== '') {
        $where[] = 'g.age_rating = ?';
        $params[] = $age;
    }

    // A budget filter uses the cheapest currently available store offer.
    // If there is no offer yet, fall back to the catalog price only when it
    // is explicitly greater than zero.
    $where[] = "(
        CASE
            WHEN EXISTS (
                SELECT 1 FROM store_offers so0
                WHERE so0.game_id = g.id AND so0.is_available = 1
            )
            THEN (
                SELECT MIN(so1.price) FROM store_offers so1
                WHERE so1.game_id = g.id AND so1.is_available = 1
            )
            ELSE g.price
        END
    ) <= ?";
    $params[] = $maxPrice;

    $sql = "
        SELECT
            g.*,
            COALESCE((SELECT AVG(r.rating) FROM ratings r WHERE r.game_id = g.id), 0) AS avg_rating,
            COALESCE((SELECT COUNT(*) FROM ratings r2 WHERE r2.game_id = g.id), 0) AS rating_count,
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM store_offers so3
                    WHERE so3.game_id = g.id AND so3.is_available = 1
                )
                THEN (
                    SELECT MIN(so4.price) FROM store_offers so4
                    WHERE so4.game_id = g.id AND so4.is_available = 1
                )
                ELSE g.price
            END AS effective_price
        FROM games g
    ";

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY g.featured DESC, g.store_rating DESC, g.title ASC LIMIT 100';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allGames = $stmt->fetchAll();

    foreach ($allGames as &$game) {
        $score = 0;
        $reasons = [];
        $platforms = game_tags((string)$game['platforms']);
        $genres = game_tags((string)$game['genres']);
        $modes = game_tags((string)$game['game_mode']);

        if ($platform && in_array($platform, $platforms, true)) {
            $score += 35;
            $reasons[] = 'platform match';
        }
        if ($genre && in_array($genre, $genres, true)) {
            $score += 30;
            $reasons[] = 'genre match';
        }
        if ($mode && in_array($mode, $modes, true)) {
            $score += 20;
            $reasons[] = 'play-style match';
        }
        if ($age && (string)$game['age_rating'] === $age) {
            $score += 10;
            $reasons[] = 'age rating match';
        }

        $score += 5;
        $reasons[] = 'within budget';
        $score += min(10, (float)$game['avg_rating'] * 0.10);

        $game['match_score'] = min(100, (int)round($score));
        $game['match_reason'] = $reasons ? implode(', ', $reasons) : 'popular community pick';
        $game['price'] = (float)$game['effective_price'];
        $game['platform'] = $platform ?: (first_tag($game['platforms'],'PC'));
    }
    unset($game);

    usort($allGames, fn($a, $b) => ($b['match_score'] <=> $a['match_score']) ?: strcmp($a['title'], $b['title']));
    $recommendations = array_slice($allGames, 0, 6);
}

$user=current_user($pdo);
$playedIds=$user?played_game_ids($pdo,(int)$user['id']):[];
$personalRecommendations=$user?personalized_games($pdo,(int)$user['id'],6):[];
require 'partials/header.php';
?>
<?php if($user && $personalRecommendations): ?>
<section class="personal-section">
    <div class="section-head"><div><p class="eyebrow">Personalized for you</p><h2>Based on your wishlist & played games</h2><p class="section-note">The more games you save or mark as played, the smarter these suggestions become.</p></div></div>
    <div class="game-grid">
    <?php foreach($personalRecommendations as $game): $offer=best_offer($pdo,(int)$game['id']); ?>
    <article class="game-card">
        <a href="game-details.php?id=<?=(int)$game['id']?>"><img src="<?=e($game['cover_image'])?>" data-fallback="<?=e(site_url('images/game-placeholder.svg'))?>" alt="<?=e($game['title'])?>" loading="lazy"></a>
        <div class="game-card-body">
            <div class="card-top"><span class="badge"><?=e(first_tag($game['platforms'],'—'))?></span><span class="rating">⭐ <?= $game['store_rating'] ? number_format((float)$game['store_rating'],1) : '—' ?></span></div>
            <h3><a href="game-details.php?id=<?=(int)$game['id']?>"><?=e($game['title'])?></a></h3>
            <p><?=e($game['personal_reason'])?></p>
            <div class="card-bottom"><strong><?=$offer?money((float)$offer['price']):'Price unavailable'?></strong><a href="game-details.php?id=<?=(int)$game['id']?>">Details →</a></div>
            <form method="post" action="<?=e(site_url('played-toggle.php'))?>" class="played-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="game_id" value="<?=(int)$game['id']?>"><input type="hidden" name="redirect_to" value="recommend.php"><button class="played-button" type="submit">Mark as Played</button></form>
        </div>
    </article>
    <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<div class="page-heading">
    <div>
        <p class="eyebrow">Recommendation engine</p>
        <h1>Find your game match</h1>
        <p>Choose what matters to you. GameMatch scores the library and ranks the best fits.</p>
    </div>
</div>

<form class="recommend-box" method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="pref-grid">
        <div class="field">
            <label for="platform">Platform</label>
            <select id="platform" name="platform">
                <option value="">Any platform</option>
                <?php foreach (['PC','PlayStation','Xbox','Mobile'] as $p): ?>
                    <option value="<?= e($p) ?>" <?= (($platform ?? '') === $p) ? 'selected' : '' ?>><?= e($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="genre">Genre</label>
            <select id="genre" name="genre">
                <option value="">Any genre</option>
                <?php foreach (['Action','Adventure','Horror','Racing','RPG','Sports','Strategy','Simulation'] as $g): ?>
                    <option value="<?= e($g) ?>" <?= $genre === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="mode">Gameplay</label>
            <select id="mode" name="mode">
                <option value="">Any</option>
                <option value="Single-player" <?= $mode === 'Single-player' ? 'selected' : '' ?>>Single-player</option>
                <option value="Multiplayer" <?= $mode === 'Multiplayer' ? 'selected' : '' ?>>Multiplayer</option>
            </select>
        </div>
        <div class="field">
            <label for="age_rating">Age Rating</label>
            <select id="age_rating" name="age_rating">
                <option value="">Any</option>
                <option value="E" <?= $age === 'E' ? 'selected' : '' ?>>E</option>
                <option value="E10+" <?= $age === 'E10+' ? 'selected' : '' ?>>E10+</option>
                <option value="T" <?= $age === 'T' ? 'selected' : '' ?>>T</option>
                <option value="M" <?= $age === 'M' ? 'selected' : '' ?>>M</option>
            </select>
        </div>
        <div class="field">
            <label for="max_price">Maximum Budget (₹)</label>
            <input id="max_price" name="max_price" type="number" min="0" step="100" value="<?= e((string)($_POST['max_price'] ?? 5000)) ?>">
        </div>
    </div>
    <button class="btn btn-large" type="submit">✨ Generate My Matches</button>
</form>

<?php if ($submitted): ?>
<section class="section-head">
    <div>
        <p class="eyebrow">Your results</p>
        <h2>Top matches</h2>
    </div>
</section>
<div class="game-grid">
<?php foreach ($recommendations as $game): ?>
    <?php $offer = best_offer($pdo, (int)$game['id']); ?>
    <article class="game-card recommendation-card">
        <div class="match-score"><?= (int)$game['match_score'] ?>% match</div>
        <a href="game-details.php?id=<?= (int)$game['id'] ?>"><img src="<?= e($game['cover_image']) ?>" data-fallback="<?=e(site_url('images/game-placeholder.svg'))?>" alt="<?= e($game['title']) ?>"></a>
        <div class="game-card-body">
            <div class="card-top">
                <span class="badge"><?= e($game['platform']) ?></span>
                <span class="rating">⭐ <?= $game['store_rating'] ? number_format((float)$game['store_rating'],1) : '—' ?></span>
            </div>
            <h3><a href="game-details.php?id=<?= (int)$game['id'] ?>"><?= e($game['title']) ?></a></h3>
            <p><?= e($game['match_reason']) ?></p>
            <div class="card-bottom">
                <strong><?= $offer ? ((float)$offer['price'] <= 0 ? 'Free' : money((float)$offer['price'])) : ((float)$game['effective_price'] > 0 ? money((float)$game['effective_price']) : 'Price unavailable') ?></strong>
                <a href="game-details.php?id=<?= (int)$game['id'] ?>">View →</a>
            </div>
        </div>
    </article>
<?php endforeach; ?>
</div>
<?php if (!$recommendations): ?>
<div class="empty">
    <div class="empty-icon">🎮</div>
    <h2>No exact matches found</h2>
    <p>Try increasing your budget or choosing fewer filters.</p>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require 'partials/footer.php'; ?>
