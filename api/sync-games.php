<?php
declare(strict_types=1);

// GameMatch catalog synchronizer.
// Steam is the first-class automatic public catalog source. Other stores can be
// connected through the optional normalized feed adapters below without changing the UI.
require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$secret = (string)(getenv('SYNC_SECRET') ?: (getenv('CRON_SECRET') ?: ''));
$given = (string)($_GET['key'] ?? ($_SERVER['HTTP_X_SYNC_KEY'] ?? ''));
$auth=(string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($given === '' && str_starts_with($auth, 'Bearer ')) $given=substr($auth, 7);
if ($secret !== '' && !hash_equals($secret, $given)) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}

$source = strtolower(trim((string)($_GET['source'] ?? 'steam')));
$batch = max(1, min(50, (int)($_GET['batch'] ?? (getenv('STEAM_SYNC_BATCH') ?: 25))));

function fetch_json_url(string $url, int $timeout=15): ?array {
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'GameMatch/3.0 catalog-sync',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
    }
    if ($body === false || $body === '') {
        $ctx = stream_context_create(['http'=>[
            'timeout'=>$timeout,
            'header'=>"User-Agent: GameMatch/3.0 catalog-sync\r\nAccept: application/json\r\n"
        ]]);
        $body = @file_get_contents($url, false, $ctx);
    }
    if (!is_string($body) || $body === '') return null;
    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

function slugify_game(string $title, int $appid): string {
    $s = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    if ($s === '') $s = 'steam-game';
    return substr($s, 0, 150) . '-' . $appid;
}

function clean_description(string $html): string {
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}

function steam_genres(array $data): string {
    $values=[];
    foreach (($data['genres'] ?? []) as $g) {
        $name=trim((string)($g['description'] ?? ''));
        if ($name !== '') $values[]=$name;
    }
    $map = [
        'Free To Play'=>'Free-to-Play', 'Indie'=>'Indie', 'RPG'=>'RPG', 'Action'=>'Action',
        'Adventure'=>'Adventure', 'Casual'=>'Casual', 'Strategy'=>'Strategy', 'Simulation'=>'Simulation',
        'Racing'=>'Racing', 'Sports'=>'Sports', 'Horror'=>'Horror', 'Massively Multiplayer'=>'MMO',
        'Early Access'=>'Early Access'
    ];
    $out=[];
    foreach ($values as $v) $out[]=$map[$v] ?? $v;
    $out=array_values(array_unique($out));
    return implode(', ', array_slice($out, 0, 8)) ?: 'Other';
}

function steam_mode(array $data): string {
    $cats = [];
    foreach (($data['categories'] ?? []) as $c) $cats[] = strtolower((string)($c['description'] ?? ''));
    $out=[];
    if (in_array('single-player',$cats,true)) $out[]='Single-player';
    if (in_array('multi-player',$cats,true) || in_array('online co-op',$cats,true) || in_array('co-op',$cats,true)) $out[]='Multiplayer';
    return implode(', ', $out) ?: 'Single-player';
}

function steam_platforms(array $data, ?string $existing = null): string {
    // Steam appdetails reliably establishes PC support. For games that were
    // already present in the cross-platform bootstrap catalog, preserve their
    // PlayStation/Xbox/Mobile/Switch metadata instead of collapsing them to PC.
    $p=$data['platforms'] ?? [];
    $out=[];
    if (!empty($p['windows']) || !empty($p['mac']) || !empty($p['linux'])) $out[]='PC';

    $existingParts=array_values(array_filter(array_map('trim', explode(',', (string)$existing))));
    foreach($existingParts as $part){
        if($part!=='' && !in_array($part,$out,true)) $out[]=$part;
    }
    return implode(', ', array_unique($out)) ?: 'PC';
}

function steam_review_snapshot(array $data, int $appid): array {
    $count=0; $rating=null;
    $reviews=fetch_json_url(
        'https://api.steampowered.com/IUserReviewsService/GetAppReviews/v1/?input_json=' .
        rawurlencode(json_encode([
            'appid'=>$appid,
            'filter'=>'all',
            'language'=>'all',
            'review_type'=>'all',
            'purchase_type'=>'all',
            'num_per_page'=>1
        ])),
        12
    );
    $summary=$reviews['query_summary']??null;
    if(is_array($summary)){
        $positive=(int)($summary['total_positive']??0);
        $negative=(int)($summary['total_negative']??0);
        $count=(int)($summary['total_reviews']??($positive+$negative));
        if(($positive+$negative)>0) $rating=round(($positive/($positive+$negative))*100,1);
    }
    return [$rating,$count];
}

function steam_age(array $data): string {
    $r=$data['ratings']['esrb']['rating'] ?? null;
    if (is_string($r) && $r !== '') return $r;
    $pegi=$data['ratings']['pegi']['rating'] ?? null;
    if (is_string($pegi) && $pegi !== '') return 'PEGI '.$pegi;
    return 'T';
}

function upsert_steam_game(PDO $pdo, int $appid, array $data): int {
    $title=trim((string)($data['name'] ?? ''));
    if ($title==='') throw new RuntimeException('Steam game has no name');

    // Reuse an existing row when the Steam App ID is already in the database.
    // This prevents the catalog sync from creating duplicate copies of the
    // original 20 games.
    $find=$pdo->prepare('SELECT * FROM games WHERE steam_app_id=? LIMIT 1');
    $find->execute([$appid]);
    $existing=$find->fetch() ?: null;

    $slug=slugify_game($title,$appid);
    $developer=trim((string)($data['developers'][0] ?? '')) ?: ($existing['developer'] ?? null);
    $publisher=trim((string)($data['publishers'][0] ?? '')) ?: ($existing['publisher'] ?? null);

    $date=null;
    if (!empty($data['release_date']['date'])) {
        $raw=(string)$data['release_date']['date'];
        if (preg_match('/(\d{4})/', $raw, $m)) $date=$m[1].'-01-01';
        if (preg_match('/(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})/', $raw, $m)) $date=sprintf('%04d-%02d-%02d',(int)$m[3],(int)$m[1],(int)$m[2]);
    }
    if ($date===null && !empty($existing['release_date'])) $date=$existing['release_date'];

    $desc=clean_description((string)($data['short_description'] ?? $data['detailed_description'] ?? $existing['description'] ?? $title));
    $cover=(string)($data['header_image'] ?? ($existing['cover_image'] ?? ''));
    if ($cover==='') $cover='https://cdn.cloudflare.steamstatic.com/steam/apps/'.$appid.'/header.jpg';

    $genres=steam_genres($data);
    $mode=steam_mode($data);
    $platforms=steam_platforms($data, $existing['platforms'] ?? null);
    $age=steam_age($data);

    $price=0.0; $currency='INR'; $initial=0.0;
    if (!empty($data['is_free'])) {
        $price=0; $initial=0;
    } elseif (!empty($data['price_overview'])) {
        $po=$data['price_overview'];
        $price=((float)($po['final'] ?? 0))/100;
        $initial=((float)($po['initial'] ?? $po['final'] ?? 0))/100;
        $currency=(string)($po['currency'] ?? 'INR');
    } elseif ($existing) {
        $price=(float)($existing['price'] ?? 0);
    }

    [$rating,$count]=steam_review_snapshot($data,$appid);
    $ratingSource='Steam';
    $trailer='https://www.youtube.com/results?search_query='.rawurlencode($title.' official trailer');

    if ($existing) {
        $sql="UPDATE games SET
            name=?, title=?, slug=?, developer=?, publisher=?, release_date=?,
            platforms=?, genres=?, game_mode=?, age_rating=?, price=?,
            description=?, cover_image=?, trailer_url=?, source='steam',
            external_id=?, last_synced_at=CURRENT_TIMESTAMP,
            store_rating=?, rating_count=?, rating_source=?
            WHERE id=?";
        $st=$pdo->prepare($sql);
        $st->execute([
            $title,$title,$slug,$developer,$publisher,$date,$platforms,$genres,$mode,
            $age,$price,$desc,$cover,$trailer,(string)$appid,$rating,$count,
            $ratingSource,(int)$existing['id']
        ]);
        $id=(int)$existing['id'];
    } else {
        $sql="INSERT INTO games
          (name,title,slug,genre,platform,mode,age_rating,price,description,image_url,cover_image,trailer_url,
           steam_app_id,source,external_id,last_synced_at,store_rating,rating_count,rating_source,featured)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'steam',?,CURRENT_TIMESTAMP,?,?,?,0)";
        $st=$pdo->prepare($sql);
        $st->execute([
            $title,$title,$slug,$genres,$platforms,$mode,$age,$price,$desc,$cover,$cover,$trailer,
            $appid,(string)$appid,$rating,$count,$ratingSource
        ]);
        $id=(int)$pdo->lastInsertId();
        if($id<=0){
            $q=$pdo->prepare('SELECT id FROM games WHERE steam_app_id=? LIMIT 1');
            $q->execute([$appid]);
            $id=(int)$q->fetchColumn();
        }
    }

    $url='https://store.steampowered.com/app/'.$appid.'/';
    $offer=$pdo->prepare("INSERT INTO store_offers
      (game_id,store_name,platform,price,original_price,currency,url,is_official,source,external_id,is_available,last_checked_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP)
      ON DUPLICATE KEY UPDATE
        price=VALUES(price), original_price=VALUES(original_price),
        currency=VALUES(currency), url=VALUES(url),
        is_available=1,last_checked_at=CURRENT_TIMESTAMP");
    try {
        $offer->execute([$id,'Steam','PC',$price,$initial,$currency,$url,1,'steam',(string)$appid]);
    } catch(Throwable $e) {
        // Existing installations may not yet have the optional unique key.
        // Avoid breaking catalog sync because of duplicate legacy offers.
        $offer2=$pdo->prepare("UPDATE store_offers
          SET price=?, original_price=?, currency=?, url=?, is_available=1, last_checked_at=CURRENT_TIMESTAMP
          WHERE game_id=? AND store_name='Steam' AND external_id=?");
        $offer2->execute([$price,$initial,$currency,$url,$id,(string)$appid]);
        if($offer2->rowCount()===0){
            $offer3=$pdo->prepare("INSERT INTO store_offers
              (game_id,store_name,platform,price,original_price,currency,url,is_official,source,external_id,is_available,last_checked_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP)");
            $offer3->execute([$id,'Steam','PC',$price,$initial,$currency,$url,1,'steam',(string)$appid]);
        }
    }
    return $id;
}

function sync_steam(PDO $pdo, int $batch): array {
    $pdo->prepare("INSERT INTO catalog_sync_state(source,status,last_run_at) VALUES('steam','running',CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE status='running',last_run_at=CURRENT_TIMESTAMP,last_error=NULL")->execute();
    $state=$pdo->prepare('SELECT cursor_value FROM catalog_sync_state WHERE source=?'); $state->execute(['steam']);
    $cursor=(string)($state->fetchColumn() ?: '');

    // Preferred: official Steam IStoreService when a Web API key is configured.
    $key=(string)(getenv('STEAM_WEB_API_KEY') ?: '');
    $apps=[]; $last=''; $more=false;
    if ($key !== '') {
        $url='https://partner.steam-api.com/IStoreService/GetAppList/v1/?key='.rawurlencode($key).'&max_results=1000&include_games=true&include_dlc=false&include_software=false&include_videos=false&include_hardware=false';
        if ($cursor!=='') $url.='&last_appid='.rawurlencode($cursor);
        $list=fetch_json_url($url,20);
        if (!$list || !isset($list['response']['apps'])) throw new RuntimeException('Steam official catalog API unavailable');
        $apps=$list['response']['apps'];
        $last=(string)($list['response']['last_appid'] ?? $cursor);
        $more=!empty($list['response']['have_more_results']);
    } else {
        // Public Steam Store search JSON fallback. It is useful for a demo without credentials,
        // but the official IStoreService API is preferred for a production deployment.
        $startOffset=max(0,(int)$cursor);
        $url='https://store.steampowered.com/search/results/?query=&start='.$startOffset.'&count=100&json=1&cc=in&l=english&category1=998';
        $list=fetch_json_url($url,20);
        if (!$list) throw new RuntimeException('Steam public catalog endpoint unavailable');
        $html=(string)($list['results_html'] ?? '');
        if ($html==='') throw new RuntimeException('Steam public catalog returned no results');
        preg_match_all('/href="https?:\\/\\/store\.steampowered\.com\\/app\\/(\\d+)[^"]*"[^>]*>.*?<span class="title">(.*?)<\\/span>/is',$html,$matches,PREG_SET_ORDER);
        foreach($matches as $m){
            $name=trim(html_entity_decode(strip_tags($m[2]),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            if($name!=='') $apps[]=['appid'=>(int)$m[1],'name'=>$name];
        }
        $last=(string)($startOffset+100); $total=(int)($list['total_count'] ?? 0); $more=($startOffset+100)<$total;
    }

    $ins=$pdo->prepare("INSERT INTO external_catalog(source,external_id,name,is_game,enriched,last_seen_at) VALUES('steam',?,?,NULL,0,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE name=VALUES(name),last_seen_at=CURRENT_TIMESTAMP");
    foreach ($apps as $app) {
        $appid=(string)($app['appid']??''); $name=trim((string)($app['name']??''));
        if ($appid!=='' && $name!=='') $ins->execute([$appid,$name]);
    }

    $q=$pdo->query("SELECT external_id,name FROM external_catalog WHERE source='steam' AND enriched=0 ORDER BY id ASC LIMIT ".(int)$batch);
    $rows=$q->fetchAll();
    $done=0; $failed=0; $errors=[];
    foreach($rows as $row){
        $appid=(int)$row['external_id'];
        $detail=fetch_json_url('https://store.steampowered.com/api/appdetails?appids='.$appid.'&cc=in&l=english',15);
        $app=$detail[$appid]['data'] ?? null;
        $success=!empty($detail[$appid]['success']) && is_array($app);
        $isGame=$success && (($app['type'] ?? '') === 'game');
        $up=$pdo->prepare("UPDATE external_catalog SET is_game=?, enriched=1, enriched_at=CURRENT_TIMESTAMP WHERE source='steam' AND external_id=?");
        $up->execute([$isGame?1:0,(string)$appid]);
        if (!$isGame) { if(!$success) $failed++; continue; }
        try { upsert_steam_game($pdo,$appid,$app); $done++; }
        catch(Throwable $e){ $failed++; $errors[]=$appid.': '.$e->getMessage(); }
    }
    if (!$more) $last='';
    $up=$pdo->prepare("UPDATE catalog_sync_state SET cursor_value=?,status='idle',last_run_at=CURRENT_TIMESTAMP,last_success_at=CURRENT_TIMESTAMP,last_error=? WHERE source='steam'");
    $up->execute([$last,$errors?substr(implode(' | ',$errors),0,2000):null]);
    return ['source'=>'steam','discovered'=>count($apps),'enriched'=>$done,'failed'=>$failed,'cursor'=>$last,'more'=>$more,'official_api'=>($key!==''),'errors'=>$errors];
}

try {
    if ($source !== 'steam') {
        // Explicitly do not fake public APIs for stores that do not expose an equivalent unrestricted catalog.
        $supported = ['steam'];
        throw new RuntimeException('Automatic catalog sync for this source is not configured. Supported automatic public source: '.implode(', ',$supported).'. Add a verified partner/catalog adapter before enabling another store.');
    }
    $result=sync_steam($pdo,$batch);
    echo json_encode(['ok'=>true,'result'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) {
    try { $pdo->prepare("INSERT INTO catalog_sync_state(source,status,last_run_at,last_error) VALUES(?, 'error', CURRENT_TIMESTAMP, ?) ON DUPLICATE KEY UPDATE status='error',last_run_at=CURRENT_TIMESTAMP,last_error=?")->execute([$source,$e->getMessage(),$e->getMessage()]); } catch(Throwable $ignored) {}
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
}
