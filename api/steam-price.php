<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, s-maxage=300');

$appId = filter_input(INPUT_GET, 'appid', FILTER_VALIDATE_INT);
if (!$appId || $appId < 1) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Invalid Steam App ID']);
    exit;
}

$url = 'https://store.steampowered.com/api/appdetails?appids=' . $appId . '&cc=in&l=english';

$body = false;
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'GameMatch/3.0 (+https://gamematch-taupe.vercel.app)',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
}

if ($body === false || $body === '') {
    $context = stream_context_create(['http'=>[
        'timeout'=>12,
        'header'=>"User-Agent: GameMatch/2.3\r\nAccept: application/json\r\n"
    ]]);
    $body = @file_get_contents($url, false, $context);
}

$data = is_string($body) ? json_decode($body, true) : null;
$app = $data[$appId] ?? null;

if (!is_array($app) || empty($app['success'])) {
    echo json_encode(['ok'=>false,'available'=>false,'error'=>'Steam price is currently unavailable.']);
    exit;
}

$details = $app['data'] ?? [];
$price = $details['price_overview'] ?? null;

if (!$price) {
    try {
        $gameStmt=$pdo->prepare('SELECT id FROM games WHERE steam_app_id=? LIMIT 1'); $gameStmt->execute([$appId]); $gameId=(int)($gameStmt->fetchColumn() ?: 0);
        if($gameId>0){
            $offer=$pdo->prepare("INSERT INTO store_offers (game_id,store_name,platform,price,original_price,currency,url,is_official,source,external_id,is_available,last_checked_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE price=VALUES(price),original_price=VALUES(original_price),currency=VALUES(currency),url=VALUES(url),is_available=1,last_checked_at=CURRENT_TIMESTAMP");
            $offer->execute([$gameId,'Steam','PC',0,0,'INR','https://store.steampowered.com/app/'.$appId.'/',1,'steam',(string)$appId]);
        }
    } catch(Throwable $ignored) {}
    echo json_encode([
        'ok'=>true,
        'available'=>true,
        'free'=>!empty($details['is_free']),
        'currency'=>'INR',
        'price'=>0,
        'final_formatted'=>!empty($details['is_free']) ? 'Free' : 'Price unavailable',
        'url'=>'https://store.steampowered.com/app/'.$appId.'/'
    ]);
    exit;
}

$final = isset($price['final']) ? ((float)$price['final'] / 100) : 0;
$initial = isset($price['initial']) ? ((float)$price['initial'] / 100) : $final;

// Keep the normalized offer cache fresh for the price-comparison page.
try {
    $gameStmt = $pdo->prepare('SELECT id FROM games WHERE steam_app_id=? LIMIT 1');
    $gameStmt->execute([$appId]);
    $gameId = (int)($gameStmt->fetchColumn() ?: 0);
    if ($gameId > 0) {
        $offer = $pdo->prepare("INSERT INTO store_offers (game_id,store_name,platform,price,original_price,currency,url,is_official,source,external_id,is_available,last_checked_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP)
          ON DUPLICATE KEY UPDATE price=VALUES(price),original_price=VALUES(original_price),currency=VALUES(currency),url=VALUES(url),is_available=1,last_checked_at=CURRENT_TIMESTAMP");
        $offer->execute([$gameId,'Steam','PC',$final,$initial,$price['currency'] ?? 'INR','https://store.steampowered.com/app/'.$appId.'/',1,'steam',(string)$appId]);
    }
} catch (Throwable $ignored) {}

echo json_encode([
    'ok'=>true,
    'available'=>true,
    'free'=>false,
    'currency'=>$price['currency'] ?? 'INR',
    'price'=>$final,
    'initial_price'=>$initial,
    'discount_percent'=>(int)($price['discount_percent'] ?? 0),
    'final_formatted'=>$price['final_formatted'] ?? ('₹'.number_format($final,0)),
    'initial_formatted'=>$price['initial_formatted'] ?? null,
    'url'=>'https://store.steampowered.com/app/'.$appId.'/'
]);
