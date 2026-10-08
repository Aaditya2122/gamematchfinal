
<?php
function gm_card_price(PDO $pdo, int $gameId, $catalogPrice = null): array {
    $s = $pdo->prepare("
        SELECT store_name, platform, price, original_price, currency, url
        FROM store_offers
        WHERE game_id = ? AND is_available = 1 AND price >= 0
        ORDER BY price ASC, store_name ASC
        LIMIT 1
    ");
    $s->execute([$gameId]);
    $offer = $s->fetch(PDO::FETCH_ASSOC);
    if ($offer) {
        return ['label' => ((float)$offer['price'] <= 0 ? 'Free' : money((float)$offer['price'])), 'offer' => $offer];
    }
    // A zero catalog price alone is NOT enough to claim a game is free.
    if ($catalogPrice !== null && is_numeric($catalogPrice) && (float)$catalogPrice > 0) {
        return ['label' => money((float)$catalogPrice), 'offer' => null];
    }
    return ['label' => 'Price unavailable', 'offer' => null];
}
?>

<?php
$pageTitle='Browse Games'; require_once 'config/bootstrap.php';
$user=current_user($pdo); $playedIds=$user?played_game_ids($pdo,(int)$user['id']):[];
$q=trim($_GET['q']??''); $platform=trim($_GET['platform']??''); $genre=trim($_GET['genre']??''); $mode=trim($_GET['mode']??''); $store=trim($_GET['store']??''); $maxPrice=trim($_GET['max_price']??'');
$where=[];$params=[];
if($q!==''){ $where[]='(g.title LIKE ? OR g.genres LIKE ? OR g.developer LIKE ? OR g.description LIKE ?)'; array_push($params,"%$q%","%$q%","%$q%","%$q%"); }
if($platform!==''){ $where[]='FIND_IN_SET(?, REPLACE(g.platforms, ", ", ",")) > 0'; $params[]=$platform; }
if($genre!==''){ $where[]='FIND_IN_SET(?, REPLACE(g.genres, ", ", ",")) > 0'; $params[]=$genre; }
if($mode!==''){ $where[]='FIND_IN_SET(?, REPLACE(g.game_mode, ", ", ",")) > 0'; $params[]=$mode; }
if($store!==''){ $where[]='EXISTS (SELECT 1 FROM store_offers so WHERE so.game_id=g.id AND LOWER(TRIM(so.store_name))=LOWER(TRIM(?)))'; $params[]=$store; }
if($maxPrice!==''&&is_numeric($maxPrice)){ $where[]='(g.price IS NOT NULL AND g.price>0 AND g.price<=? OR EXISTS (SELECT 1 FROM store_offers so WHERE so.game_id=g.id AND so.is_available=1 AND so.price>=0 AND so.price<=?))'; $limit=(float)$maxPrice; $params[]=$limit; $params[]=$limit; }
$sql='SELECT g.* FROM games g'; if($where)$sql.=' WHERE '.implode(' AND ',$where); $sql.=' ORDER BY g.featured DESC, g.store_rating DESC, g.title ASC';
$s=$pdo->prepare($sql);$s->execute($params);$games=$s->fetchAll();
$genres=['Action','Adventure','Horror','Racing','RPG','Sports','Strategy','Simulation','FPS','Open World','Co-op','Roguelike'];
require 'partials/header.php';
?>
<div class="page-heading"><div><p class="eyebrow">Game library</p><h1>Browse games</h1><p>Search the catalog and compare platforms, stores, ratings and prices.</p></div></div>
<form class="filters" method="get"><div class="field wide"><label>Search</label><input name="q" value="<?=e($q)?>" placeholder="Elden Ring, horror, racing..."></div><div class="field"><label>Platform</label><select name="platform"><option value="">Any</option><?php foreach(['PC','PlayStation','Xbox','Mobile','Nintendo Switch'] as $v):?><option <?=$platform===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></div><div class="field"><label>Genre</label><select name="genre"><option value="">Any</option><?php foreach($genres as $v):?><option <?=$genre===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></div><div class="field"><label>Mode</label><select name="mode"><option value="">Any</option><option <?=$mode==='Single-player'?'selected':''?>>Single-player</option><option <?=$mode==='Multiplayer'?'selected':''?>>Multiplayer</option></select></div><div class="field"><label>Store</label><select name="store"><option value="">Any</option><?php foreach(['Steam','Epic Games Store','PlayStation Store','Xbox Store','Google Play','App Store'] as $v):?><option <?=$store===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></div><div class="field"><label>Cheapest ≤ ₹</label><input type="number" min="0" step="100" name="max_price" value="<?=e($maxPrice)?>" placeholder="Any"></div><div class="filter-actions"><button class="btn" type="submit">Search</button><a class="btn btn-ghost" href="games.php">Reset</a></div></form>
<p class="result-count"><?=count($games)?> game(s) found</p><div class="game-grid"><?php foreach($games as $game): $offer=best_offer($pdo,(int)$game['id']); ?><article class="game-card"><a href="game-details.php?id=<?=(int)$game['id']?>"><img src="<?=e($game['cover_image'])?>" data-fallback="<?=e(site_url('images/game-placeholder.svg'))?>" alt="<?=e($game['title'])?>" loading="lazy"></a><div class="game-card-body"><div class="card-top"><span class="badge"><?=e(first_tag($game['platforms'],'—'))?></span><span class="rating">⭐ <?= $game['store_rating'] ? number_format((float)$game['store_rating'],1) : '—' ?></span></div><h3><a href="game-details.php?id=<?=(int)$game['id']?>"><?=e($game['title'])?></a></h3><p><?=e($game['genres'])?></p><div class="card-bottom"><strong><?= e(gm_card_price($pdo, (int)$game['id'], $game['price'] ?? null)['label']) ?></strong><a href="game-details.php?id=<?=(int)$game['id']?>">Details →</a></div><?php if($user && !empty($playedIds) && in_array((int)$game['id'], $playedIds, true)): ?><form method="post" action="<?=e(site_url('played-toggle.php'))?>" class="played-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="game_id" value="<?=(int)$game['id']?>"><input type="hidden" name="redirect_to" value="<?=e(basename($_SERVER['PHP_SELF']).(!empty($_SERVER['QUERY_STRING'])?'?'.$_SERVER['QUERY_STRING']:''))?>"><button class="played-button is-played" type="submit">✓ Played</button></form><?php elseif($user): ?><form method="post" action="<?=e(site_url('played-toggle.php'))?>" class="played-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="game_id" value="<?=(int)$game['id']?>"><input type="hidden" name="redirect_to" value="<?=e(basename($_SERVER['PHP_SELF']).(!empty($_SERVER['QUERY_STRING'])?'?'.$_SERVER['QUERY_STRING']:''))?>"><button class="played-button" type="submit">Mark as Played</button></form><?php endif; ?></div></article><?php endforeach;?></div>
<?php if(!$games):?><div class="empty"><div class="empty-icon">🔎</div><h2>No games found</h2><p>Try a broader search or remove a filter.</p></div><?php endif;?>
<?php require 'partials/footer.php'; ?>
