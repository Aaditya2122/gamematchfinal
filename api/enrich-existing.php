<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$secret=(string)(getenv('SYNC_SECRET') ?: (getenv('CRON_SECRET') ?: ''));
$given=(string)($_GET['key'] ?? ($_SERVER['HTTP_X_SYNC_KEY'] ?? ''));
$auth=(string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if($given==='' && str_starts_with($auth,'Bearer ')) $given=substr($auth,7);
if($secret!=='' && !hash_equals($secret,$given)){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Unauthorized']);exit;}
$limit=max(1,min(50,(int)($_GET['limit']??20)));

function gm_fetch_json(string $url,int $timeout=15):?array{
  $body=false;
  if(function_exists('curl_init')){
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>$timeout,CURLOPT_USERAGENT=>'GameMatch/3.0',CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $body=curl_exec($ch);curl_close($ch);
  }
  if($body===false||$body===''){
    $ctx=stream_context_create(['http'=>['timeout'=>$timeout,'header'=>"User-Agent: GameMatch/3.0\r\nAccept: application/json\r\n"]]);
    $body=@file_get_contents($url,false,$ctx);
  }
  if(!is_string($body)||$body==='')return null;
  $j=json_decode($body,true);return is_array($j)?$j:null;
}
function gm_slug(string $title,int $appid):string{
  $s=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$title),'-'));
  if($s==='')$s='steam-game';return substr($s,0,150).'-'.$appid;
}
function gm_desc(string $html):string{
  $t=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
  $t=preg_replace('/\s+/',' ',$t)??$t;return trim($t);
}
function gm_genres(array $d):string{
  $a=[];foreach(($d['genres']??[]) as $g){$n=trim((string)($g['description']??''));if($n!=='')$a[]=$n;}
  return implode(', ',array_values(array_unique(array_slice($a,0,8))))?:'Other';
}
function gm_mode(array $d):string{
  $a=[];foreach(($d['categories']??[]) as $c)$a[]=strtolower((string)($c['description']??''));$o=[];
  if(in_array('single-player',$a,true))$o[]='Single-player';
  if(in_array('multi-player',$a,true)||in_array('online co-op',$a,true)||in_array('co-op',$a,true))$o[]='Multiplayer';
  return implode(', ',$o)?:'Single-player';
}
function gm_platforms(array $d, ?string $existing=null):string{
  $p=$d['platforms']??[];$o=[];
  if(!empty($p['windows'])||!empty($p['mac'])||!empty($p['linux']))$o[]='PC';
  foreach(array_values(array_filter(array_map('trim',explode(',',(string)$existing)))) as $part){
    if($part!==''&&!in_array($part,$o,true))$o[]=$part;
  }
  return implode(', ',array_unique($o))?:'PC';
}
function gm_review_snapshot(int $appid):array{
  $reviews=gm_fetch_json('https://api.steampowered.com/IUserReviewsService/GetAppReviews/v1/?input_json='.rawurlencode(json_encode([
    'appid'=>$appid,'filter'=>'all','language'=>'all','review_type'=>'all','purchase_type'=>'all','num_per_page'=>1
  ])));
  $qs=$reviews['query_summary']??null;$rating=null;$count=0;
  if(is_array($qs)){
    $pos=(int)($qs['total_positive']??0);$neg=(int)($qs['total_negative']??0);
    $count=(int)($qs['total_reviews']??($pos+$neg));
    if(($pos+$neg)>0)$rating=round(($pos/($pos+$neg))*100,1);
  }
  return [$rating,$count];
}
function gm_age(array $d):string{
  $r=$d['ratings']['esrb']['rating']??null;if(is_string($r)&&$r!=='')return $r;
  $p=$d['ratings']['pegi']['rating']??null;if(is_string($p)&&$p!=='')return 'PEGI '.$p;return 'T';
}

$s=$pdo->prepare("SELECT * FROM games WHERE steam_app_id IS NOT NULL AND (source='legacy' OR developer IS NULL OR publisher IS NULL OR release_date IS NULL OR store_rating IS NULL) ORDER BY id ASC LIMIT ".(int)$limit);
$s->execute();$rows=$s->fetchAll();$updated=0;$failed=0;$errors=[];
foreach($rows as $g){
  $appid=(int)$g['steam_app_id'];
  $detail=gm_fetch_json('https://store.steampowered.com/api/appdetails?appids='.$appid.'&cc=in&l=english');
  $app=$detail[$appid]['data']??null;
  if(empty($detail[$appid]['success'])||!is_array($app)||($app['type']??'')!=='game'){$failed++;$errors[]=$appid.': appdetails unavailable';continue;}
  $title=trim((string)($app['name']??$g['title']??$g['name']));
  $developer=trim((string)($app['developers'][0]??''))?:null;
  $publisher=trim((string)($app['publishers'][0]??''))?:null;
  $release=null;if(!empty($app['release_date']['date'])){if(preg_match('/(\d{4})/',(string)$app['release_date']['date'],$m))$release=$m[1].'-01-01';}
  $price=$g['price'];$initial=$g['price'];$currency='INR';if(!empty($app['price_overview'])){$po=$app['price_overview'];$price=((float)($po['final']??0))/100;$initial=((float)($po['initial']??$po['final']??0))/100;$currency=(string)($po['currency']??'INR');}elseif(!empty($app['is_free'])){$price=0;$initial=0;}
  $cover=(string)($app['header_image']??$g['cover_image']??'');
  $rating=null;$count=0;
  [$rating,$count]=gm_review_snapshot($appid);
  $url='https://store.steampowered.com/app/'.$appid.'/';
  $st=$pdo->prepare("UPDATE games SET name=?,title=?,slug=?,developer=?,publisher=?,release_date=?,platforms=?,genres=?,game_mode=?,age_rating=?,price=?,description=?,cover_image=?,trailer_url=?,source='steam',external_id=?,last_synced_at=CURRENT_TIMESTAMP,store_rating=?,rating_count=?,rating_source='Steam' WHERE id=?");
  $st->execute([$title,$title,gm_slug($title,$appid),$developer,$publisher,$release,gm_platforms($app, $g['platforms'] ?? null),gm_genres($app),gm_mode($app),gm_age($app),$price,gm_desc((string)($app['short_description']??$app['detailed_description']??$title)),$cover,'https://www.youtube.com/results?search_query='.rawurlencode($title.' official trailer'),(string)$appid,$rating,$count,(int)$g['id']]);
  $offer=$pdo->prepare("INSERT INTO store_offers (game_id,store_name,platform,price,original_price,currency,url,is_official,source,external_id,is_available,last_checked_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE price=VALUES(price),original_price=VALUES(original_price),currency=VALUES(currency),url=VALUES(url),is_available=1,last_checked_at=CURRENT_TIMESTAMP");
  $offer->execute([(int)$g['id'],'Steam','PC',$price,$initial,$currency,$url,1,'steam',(string)$appid]);
  $src=$pdo->prepare("INSERT INTO game_sources (game_id,source,external_id,store_url,region) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE game_id=VALUES(game_id),store_url=VALUES(store_url),region=VALUES(region),updated_at=CURRENT_TIMESTAMP");
  $src->execute([(int)$g['id'],'steam',(string)$appid,$url,'IN']);
  $updated++;
}
echo json_encode(['ok'=>true,'updated'=>$updated,'failed'=>$failed,'errors'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
