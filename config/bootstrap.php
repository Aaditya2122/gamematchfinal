<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/mobile_catalog.php';
ensure_mobile_catalog_data($pdo);

function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function site_url(string $path=''): string {
    $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($base === '.' || $base === '\\') $base = '';
    return $base . '/' . ltrim($path, '/');
}
function redirect(string $url): never {
    header('Location: ' . (str_starts_with($url, '/') ? $url : site_url($url)));
    exit;
}
function is_logged_in(): bool { return isset($_SESSION['user_id']); }
function require_login(): void { if (!is_logged_in()) { $_SESSION['flash']=['type'=>'error','message'=>'Please log in to continue.']; redirect('login.php'); } }
function current_user(PDO $pdo): ?array { static $user=null; if (!isset($_SESSION['user_id'])) return null; if ($user===null) { $s=$pdo->prepare('SELECT id,name,email,created_at FROM users WHERE id=?'); $s->execute([$_SESSION['user_id']]); $user=$s->fetch()?:null; } return $user; }
function flash(?string $type=null, ?string $message=null): ?array { if ($type!==null && $message!==null) { $_SESSION['flash']=['type'=>$type,'message'=>$message]; return null; } $v=$_SESSION['flash']??null; unset($_SESSION['flash']); return $v; }
function csrf_token(): string { if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32)); return $_SESSION['csrf_token']; }
function verify_csrf(): void { if (!hash_equals($_SESSION['csrf_token']??'', $_POST['csrf_token']??'')) { http_response_code(419); die('Invalid CSRF token. Please go back and try again.'); } }
function money(float $price): string { return $price<=0?'Free':'₹'.number_format($price,0); }
function game_tags(?string $genres): array {
    $genres = (string)($genres ?? '');
    return array_values(array_filter(array_map('trim', explode(',', $genres))));
}
function first_tag(?string $value, string $fallback='—'): string {
    $tags = game_tags($value);
    return $tags[0] ?? $fallback;
}
function store_icon(string $store): string { return match(strtolower($store)) { 'steam'=>'🟦', 'epic games store'=>'⬛', 'playstation store'=>'🔵', 'xbox store'=>'🟩', 'google play'=>'▶️', 'google play store'=>'▶️', default=>'🛒' }; }
function best_offer(PDO $pdo, int $gameId): ?array {
    $s=$pdo->prepare("SELECT * FROM store_offers WHERE game_id=? AND is_available=1 ORDER BY price ASC, store_name ASC LIMIT 1");
    $s->execute([$gameId]);
    return $s->fetch() ?: null;
}

function steam_snapshot(array $game): array {
    $rating=$game['store_rating']??null; $count=(int)($game['rating_count']??0);
    if($rating===null || $count<=0) return [];
    $r=(float)$rating;
    $label=$r>=95?'Overwhelmingly Positive':($r>=80?'Very Positive':($r>=70?'Mostly Positive':'Mixed'));
    return ['percent'=>$r,'count'=>$count,'label'=>$label.' · Steam reviews'];
}
function external_review_sources(array $game, array $offers): array {
    $sources=[];
    if (!empty($game['steam_app_id'])) {
        $sources[]=[
            'name'=>'Steam Community','icon'=>'🟦','rating'=>$game['store_rating'],
            'count'=>(int)$game['rating_count'],'label'=>'Steam rating snapshot',
            'url'=>'https://steamcommunity.com/app/'.(int)$game['steam_app_id'].'/reviews/'
        ];
    }
    foreach($offers as $offer){
        if(strcasecmp($offer['store_name'],'Epic Games Store')===0){
            $sources[]=[
                'name'=>'Epic Games Store','icon'=>'⬛','rating'=>null,'count'=>0,
                'label'=>'Store rating not supplied in this catalog snapshot','url'=>$offer['url']
            ];
            break;
        }
    }
    return $sources;
}
function youtube_search_url(string $title): string {
    return 'https://www.youtube.com/results?search_query='.rawurlencode($title.' official trailer');
}

function ensure_personalization_table(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS played_games (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        game_id INT UNSIGNED NOT NULL,
        played_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_played_user_game (user_id, game_id),
        KEY idx_played_user (user_id),
        KEY idx_played_game (game_id)
    )");
    $ready = true;
}
function played_game_ids(PDO $pdo, int $userId): array {
    ensure_personalization_table($pdo);
    $s=$pdo->prepare("SELECT game_id FROM played_games WHERE user_id=?");
    $s->execute([$userId]);
    return array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
}
function personalized_games(PDO $pdo, int $userId, int $limit=6): array {
    ensure_personalization_table($pdo);
    $wishlistStmt=$pdo->prepare("SELECT g.platforms,g.genres,g.game_mode,g.developer FROM wishlists w JOIN games g ON g.id=w.game_id WHERE w.user_id=?");
    $wishlistStmt->execute([$userId]);
    $wishlist=$wishlistStmt->fetchAll();
    $playedStmt=$pdo->prepare("SELECT g.platforms,g.genres,g.game_mode,g.developer FROM played_games p JOIN games g ON g.id=p.game_id WHERE p.user_id=?");
    $playedStmt->execute([$userId]);
    $played=$playedStmt->fetchAll();
    $profile=array_merge($wishlist,$played);
    if(!$profile) return [];

    $wantedGenres=[];$wantedPlatforms=[];$wantedModes=[];$wantedDevelopers=[];
    foreach($profile as $g){
        foreach(game_tags($g['genres']) as $v) $wantedGenres[strtolower($v)]=($wantedGenres[strtolower($v)]??0)+1;
        foreach(game_tags($g['platforms']) as $v) $wantedPlatforms[strtolower($v)]=($wantedPlatforms[strtolower($v)]??0)+1;
        foreach(game_tags($g['game_mode']) as $v) $wantedModes[strtolower($v)]=($wantedModes[strtolower($v)]??0)+1;
        $dev=trim((string)$g['developer']); if($dev!=='') $wantedDevelopers[strtolower($dev)]=($wantedDevelopers[strtolower($dev)]??0)+1;
    }

    $excludedStmt=$pdo->prepare("(SELECT game_id FROM wishlists WHERE user_id=?) UNION (SELECT game_id FROM played_games WHERE user_id=?)");
    $excludedStmt->execute([$userId,$userId]);
    $excluded=array_map('intval',$excludedStmt->fetchAll(PDO::FETCH_COLUMN));

    $games=$pdo->query("SELECT g.* FROM games g ORDER BY g.featured DESC, g.store_rating DESC, g.title ASC LIMIT 500")->fetchAll();
    $result=[];
    foreach($games as $g){
        if(in_array((int)$g['id'],$excluded,true)) continue;
        $score=0;$reasons=[];
        foreach(game_tags($g['genres']) as $v){
            $k=strtolower($v);
            if(isset($wantedGenres[$k])) {$score += min(24, 8*$wantedGenres[$k]); $reasons[]=$v.' you like';}
        }
        foreach(game_tags($g['platforms']) as $v){
            $k=strtolower($v);
            if(isset($wantedPlatforms[$k])) $score += min(15, 5*$wantedPlatforms[$k]);
        }
        foreach(game_tags($g['game_mode']) as $v){
            $k=strtolower($v);
            if(isset($wantedModes[$k])) $score += min(10, 4*$wantedModes[$k]);
        }
        $dev=strtolower(trim((string)$g['developer']));
        if($dev!=='' && isset($wantedDevelopers[$dev])) {$score+=10;$reasons[]='same developer';}
        $score += min(10,(float)($g['store_rating']??0)*0.10);
        if($score<=0) continue;
        $g['personal_score']=min(100,(int)round($score));
        $g['personal_reason']=$reasons ? implode(', ',array_slice(array_unique($reasons),0,2)) : 'matches your gaming profile';
        $result[]=$g;
    }
    usort($result,fn($a,$b)=>($b['personal_score']<=>$a['personal_score'])?:strcmp($a['title'],$b['title']));
    return array_slice($result,0,$limit);
}

function game_rating(PDO $pdo, int $gameId): array { $s=$pdo->prepare('SELECT COALESCE(AVG(rating),0) avg_rating, COUNT(*) rating_count FROM ratings WHERE game_id=?'); $s->execute([$gameId]); $local=$s->fetch(); return $local?:['avg_rating'=>0,'rating_count'=>0]; }
?>
