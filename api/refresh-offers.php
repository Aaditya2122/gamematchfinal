<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, s-maxage=300');

$id=(int)($_GET['game_id'] ?? 0);
if($id<1){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Invalid game id']);exit;}
$s=$pdo->prepare('SELECT * FROM games WHERE id=?');$s->execute([$id]);$game=$s->fetch();
if(!$game){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Game not found']);exit;}

function get_json(string $url,int $timeout=12):?array{
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>$timeout,CURLOPT_USERAGENT=>'GameMatch/3.0',CURLOPT_HTTPHEADER=>['Accept: application/json']]);$body=curl_exec($ch);curl_close($ch);if(!is_string($body)||$body==='')return null;$j=json_decode($body,true);return is_array($j)?$j:null;
}
function post_json(string $url,array $payload,string $key):?array{
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>15,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_USERAGENT=>'GameMatch/3.0',CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','ITAD-API-Key: '.$key]]);$body=curl_exec($ch);curl_close($ch);if(!is_string($body)||$body==='')return null;$j=json_decode($body,true);return is_array($j)?$j:null;
}

$updated=[];
// 1) Live Steam India price.
if(!empty($game['steam_app_id'])){
 $appid=(int)$game['steam_app_id'];
 $d=get_json('https://store.steampowered.com/api/appdetails?appids='.$appid.'&cc=in&l=english');
 $app=$d[$appid]['data']??null;
 if(!empty($d[$appid]['success'])&&is_array($app)){
  $po=$app['price_overview']??null;$price=0;$initial=0;$currency='INR';
  if($po){$price=((float)($po['final']??0))/100;$initial=((float)($po['initial']??$po['final']??0))/100;$currency=(string)($po['currency']??'INR');}
  $st=$pdo->prepare("INSERT INTO store_offers (game_id,store_name,platform,price,original_price,currency,url,is_official,source,external_id,is_available,last_checked_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE price=VALUES(price),original_price=VALUES(original_price),currency=VALUES(currency),url=VALUES(url),is_available=1,last_checked_at=CURRENT_TIMESTAMP");
  $st->execute([$id,'Steam','PC',$price,$initial,$currency,'https://store.steampowered.com/app/'.$appid.'/',1,'steam',(string)$appid]);
  $updated[]='Steam';
 }
}

// 2) Optional IsThereAnyDeal adapter. It requires the user's own API key.
$itad=(string)(getenv('ITAD_API_KEY')?:'');
if($itad!==''){
 try{
  $search=get_json('https://api.isthereanydeal.com/games/search/v1?title='.rawurlencode((string)$game['title']).'&results=5&key='.rawurlencode($itad));
  $gid=null;
  foreach(($search??[]) as $item){
   $gid=$item['id']??null;if($gid)break;
  }
  if($gid){
   $prices=post_json('https://api.isthereanydeal.com/games/prices/v3/?country=IN&capacity=50',[$gid],$itad);
   foreach(($prices[0]['deals']??[]) as $deal){
    $shop=$deal['shop']['name']??'Unknown Store';$amount=$deal['price']['amount']??null;$regular=$deal['regular']['amount']??$amount;$currency=$deal['price']['currency']??'INR';$url=$deal['url']??'';
    if($amount===null||$url==='')continue;
    $name=(string)$shop;$platform='PC';
    $isKnown=in_array(strtolower($name),['steam','epic games store','gog','humble store','green man gaming','fanatical'],true);
    if(!$isKnown)continue;
    $st=$pdo->prepare("INSERT INTO store_offers (game_id,store_name,platform,price,original_price,currency,url,is_official,source,external_id,is_available,last_checked_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE price=VALUES(price),original_price=VALUES(original_price),currency=VALUES(currency),url=VALUES(url),is_available=1,last_checked_at=CURRENT_TIMESTAMP");
    $st->execute([$id,$name,$platform,(float)$amount,(float)$regular,$currency,$url,0,'itad',(string)$gid]);$updated[]=$name;
   }
  }
 }catch(Throwable $e){ /* Optional adapter must never break the main site. */ }
}

$q=$pdo->prepare('SELECT * FROM store_offers WHERE game_id=? AND is_available=1 ORDER BY price ASC,store_name ASC');$q->execute([$id]);$offers=$q->fetchAll();
foreach($offers as &$o){$o['price']=(float)$o['price'];$o['original_price']=$o['original_price']!==null?(float)$o['original_price']:null;}
unset($o);
echo json_encode(['ok'=>true,'game_id'=>$id,'updated_sources'=>array_values(array_unique($updated)),'offers'=>$offers],JSON_UNESCAPED_SLASHES);
