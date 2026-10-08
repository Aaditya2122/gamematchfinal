<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid game id']); exit; }

$s = $pdo->prepare('SELECT * FROM games WHERE id=? LIMIT 1');
$s->execute([$id]);
$game = $s->fetch();
if (!$game) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Game not found']); exit; }

function gm_http_json(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'GameMatch/4.4 metadata-enricher',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!is_string($body) || $body === '') return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function gm_norm(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
    return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
}

$appid = (int)($game['steam_app_id'] ?? 0);
if ($appid <= 0) {
    $q = rawurlencode((string)$game['title']);
    $search = gm_http_json('https://store.steampowered.com/api/storesearch/?term='.$q.'&cc=in&l=en&start=0&count=10');
    $best = null; $bestScore = 0.0;
    foreach (($search['items'] ?? []) as $item) {
        $name = (string)($item['name'] ?? '');
        $score = 0.0;
        similar_text(gm_norm((string)$game['title']), gm_norm($name), $score);
        if ($score > $bestScore) { $bestScore = $score; $best = $item; }
    }
    if ($best && $bestScore >= 82 && !empty($best['id'])) $appid = (int)$best['id'];
}

if ($appid <= 0) {
    echo json_encode(['ok'=>true,'found'=>false,'message'=>'No reliable Steam match found']);
    exit;
}

$data = gm_http_json('https://store.steampowered.com/api/appdetails?appids='.$appid.'&cc=in&l=en');
$details = $data[(string)$appid]['data'] ?? null;
if (!is_array($details)) {
    echo json_encode(['ok'=>true,'found'=>false,'steam_app_id'=>$appid,'message'=>'Steam metadata unavailable']);
    exit;
}

$reviews = gm_http_json('https://store.steampowered.com/appreviews/'.$appid.'?json=1&language=all&purchase_type=all&filter=summary');
$summary = $reviews['query_summary'] ?? [];
$totalReviews = (int)($summary['total_reviews'] ?? 0);
$positive = (int)($summary['total_positive'] ?? 0);
$percent = $totalReviews > 0 ? round(($positive / $totalReviews) * 100, 1) : null;

$genres = [];
foreach (($details['genres'] ?? []) as $g) if (!empty($g['description'])) $genres[] = (string)$g['description'];
$gameMode = 'Single-player';
$categories = array_map(static fn($c)=>(string)($c['description'] ?? ''), $details['categories'] ?? []);
if (in_array('Multi-player', $categories, true) || in_array('Online Co-op', $categories, true) || in_array('Online PvP', $categories, true)) $gameMode = 'Multiplayer';
$release = (string)($details['release_date']['date'] ?? '');
$releaseDate = null;
if (preg_match('/(\d{1,2})\s+([A-Za-z]+),?\s+(\d{4})/', $release, $m)) {
    $ts = strtotime($release); if ($ts) $releaseDate = date('Y-m-d', $ts);
} elseif (preg_match('/(\d{4})/', $release, $m)) {
    $releaseDate = $m[1].'-01-01';
}

$cover = (string)($details['header_image'] ?? '');
$developer = (string)($details['developers'][0] ?? '');
$publisher = (string)($details['publishers'][0] ?? '');
$description = trim(strip_tags((string)($details['short_description'] ?? $game['description'] ?? '')));

// Update only missing/placeholder values so existing curated data is preserved.
$up = $pdo->prepare("UPDATE games SET
    steam_app_id = CASE WHEN steam_app_id IS NULL OR steam_app_id=0 THEN ? ELSE steam_app_id END,
    developer = CASE WHEN developer IS NULL OR developer='' OR developer='Unknown' THEN ? ELSE developer END,
    publisher = CASE WHEN publisher IS NULL OR publisher='' OR publisher='Unknown' THEN ? ELSE publisher END,
    release_date = CASE WHEN release_date IS NULL THEN ? ELSE release_date END,
    genres = CASE WHEN genres IS NULL OR genres='' THEN ? ELSE genres END,
    game_mode = CASE WHEN game_mode IS NULL OR game_mode='' THEN ? ELSE game_mode END,
    description = CASE WHEN description IS NULL OR description='' OR description LIKE '%GameMatch catalog entry%' THEN ? ELSE description END,
    cover_image = CASE WHEN cover_image IS NULL OR cover_image='' OR cover_image LIKE '%game-placeholder.svg%' THEN ? ELSE cover_image END,
    trailer_url = CASE WHEN trailer_url IS NULL OR trailer_url='' THEN ? ELSE trailer_url END,
    store_rating = CASE WHEN (store_rating IS NULL OR store_rating=0) AND ? IS NOT NULL THEN ? ELSE store_rating END,
    rating_count = CASE WHEN (rating_count IS NULL OR rating_count=0) AND ? IS NOT NULL THEN ? ELSE rating_count END,
    rating_source = CASE WHEN (rating_source IS NULL OR rating_source='') AND ? IS NOT NULL THEN 'Steam' ELSE rating_source END
    WHERE id=?");
$trailer = 'https://www.youtube.com/results?search_query='.rawurlencode((string)$game['title'].' official trailer');
$up->execute([$appid,$developer,$publisher,$releaseDate,implode(', ',$genres),$gameMode,$description,$cover,$trailer,$percent,$percent,$totalReviews,$totalReviews,$percent,$id]);

// Ensure an official Steam store offer exists without inventing a price.
if ($appid > 0) {
    $offer = $pdo->prepare("INSERT INTO store_offers (game_id,store_name,platform,price,original_price,currency,url,is_official,source,external_id,is_available,last_checked_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE url=VALUES(url),is_available=1,last_checked_at=CURRENT_TIMESTAMP");
    try { $offer->execute([$id,'Steam','PC',0,0,'INR','https://store.steampowered.com/app/'.$appid.'/',1,'steam',$appid]); } catch (Throwable $e) {}
}

echo json_encode([
    'ok'=>true,'found'=>true,'steam_app_id'=>$appid,
    'title'=>(string)($details['name'] ?? $game['title']),
    'developer'=>$developer,'publisher'=>$publisher,'release_date'=>$releaseDate,
    'genres'=>implode(', ',$genres),'game_mode'=>$gameMode,'description'=>$description,
    'cover_image'=>$cover,'rating'=>$percent,'rating_count'=>$totalReviews,
    'rating_source'=>'Steam','store_url'=>'https://store.steampowered.com/app/'.$appid.'/'
], JSON_UNESCAPED_SLASHES);
