<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

/*
 * One-time safe catalog seeder.
 * It only INSERTs games that do not already exist by title or Steam App ID.
 * It does not delete or update users, ratings, wishlists, or existing games.
 *
 * Optional protection:
 * Set SEED_SECRET in Vercel and call:
 * /api/seed-catalog.php?secret=YOUR_SECRET
 */
$secret = getenv('SEED_SECRET') ?: '';
if ($secret !== '') {
    $provided = (string)($_GET['secret'] ?? '');
    if (!hash_equals($secret, $provided)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

header('Content-Type: application/json; charset=utf-8');

$file = __DIR__ . '/../data/catalog_seed.json';
if (!is_file($file)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'catalog_seed.json not found']);
    exit;
}
$catalog = json_decode((string)file_get_contents($file), true);
if (!is_array($catalog)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Invalid catalog seed']);
    exit;
}

$inserted=0; $skipped=0; $errors=[];
$sql="INSERT INTO games
(title,slug,developer,publisher,release_date,platforms,genres,game_mode,age_rating,price,
description,cover_image,trailer_url,steam_app_id,source,external_id,
store_rating,rating_count,rating_source,featured)
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
$ins=$pdo->prepare($sql);
$mobileOffer = $pdo->prepare("INSERT INTO store_offers (game_id,store_name,platform,price,original_price,currency,url,is_official,source,external_id,is_available,last_checked_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE url=VALUES(url),is_available=1,last_checked_at=CURRENT_TIMESTAMP");
$findTitle=$pdo->prepare("SELECT id FROM games WHERE LOWER(title)=LOWER(?) LIMIT 1");
$findSteam=$pdo->prepare("SELECT id FROM games WHERE steam_app_id=? LIMIT 1");

foreach($catalog as $g){
    try{
        $title=trim((string)($g['title']??''));
        if($title===''){ $skipped++; continue; }

        $exists=false;
        $findTitle->execute([$title]);
        if($findTitle->fetchColumn()) $exists=true;
        if(!$exists && !empty($g['steam_app_id'])){
            $findSteam->execute([(int)$g['steam_app_id']]);
            if($findSteam->fetchColumn()) $exists=true;
        }
        if($exists){ $skipped++; continue; }

        $platforms=(string)($g['platforms']??'PC');
        $genres=(string)($g['genres']??'');
        $mode=(string)($g['game_mode']??'Single-player');
        $cover=(string)($g['cover_image']??''); if($cover==='' || stripos($platforms,'Mobile')!==false) $cover=mobile_asset_path((string)($g['slug']??''));
        $ins->execute([
            $title,(string)($g['slug']??''),
            $g['developer']??null,$g['publisher']??null,$g['release_date']??null,
            $platforms,$genres,$mode,$g['age_rating']??'T',(float)($g['price']??0),
            (string)($g['description']??$title),$cover,$g['trailer_url']??null,
            !empty($g['steam_app_id'])?(int)$g['steam_app_id']:null,
            'seed',!empty($g['steam_app_id'])?(string)$g['steam_app_id']:(string)($g['slug']??''),
            $g['store_rating']??null,(int)($g['rating_count']??0),$g['rating_source']??null,
            (int)($g['featured']??0)
        ]);
        $newId=(int)$pdo->lastInsertId();
        if(stripos($platforms,'Mobile')!==false && $newId>0){
            $slug=(string)($g['slug']??'');
            $meta=mobile_known_meta($slug); $play=$meta['play'] ?? ('https://play.google.com/store/search?q='.rawurlencode($title).'&c=apps'); $mobileOffer->execute([$newId,'Google Play','Mobile',0,0,'INR',$play,1,'mobile-store',$slug.'-android']);
            $mobileOffer->execute([$newId,'App Store','Mobile',0,0,'INR','https://apps.apple.com/in/search?term='.rawurlencode($title),1,'mobile-store',$slug.'-ios']);
        }
        $inserted++;
    }catch(Throwable $e){
        $errors[]=$title.': '.$e->getMessage();
    }
}

// Backfill official store search links for existing mobile rows too.
try{
  $mobileRows=$pdo->query("SELECT id,title,slug FROM games WHERE FIND_IN_SET('Mobile', REPLACE(platforms, ', ', ',')) > 0")->fetchAll();
  foreach($mobileRows as $mr){
    $slug=(string)$mr['slug']; $title=(string)$mr['title']; $gid=(int)$mr['id'];
    $mobileOffer->execute([$gid,'Google Play','Mobile',0,0,'INR','https://play.google.com/store/search?q='.rawurlencode($title).'&c=apps',1,'mobile-store',$slug.'-android']);
    $mobileOffer->execute([$gid,'App Store','Mobile',0,0,'INR','https://apps.apple.com/in/search?term='.rawurlencode($title),1,'mobile-store',$slug.'-ios']);
  }
}catch(Throwable $e){}

echo json_encode([
    'ok'=>count($errors)===0,
    'catalog_entries'=>count($catalog),
    'inserted'=>$inserted,
    'skipped_existing'=>$skipped,
    'errors'=>$errors
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
?>
