<?php
require_once 'config/bootstrap.php';
require_login();
verify_csrf();
ensure_personalization_table($pdo);

$gameId=(int)($_POST['game_id']??0);
$back=$_POST['redirect_to']??'';
if($gameId<=0){ flash('error','Invalid game.'); redirect('games.php'); }

$check=$pdo->prepare("SELECT id FROM games WHERE id=?");
$check->execute([$gameId]);
if(!$check->fetchColumn()){ flash('error','Game not found.'); redirect('games.php'); }

$find=$pdo->prepare("SELECT id FROM played_games WHERE user_id=? AND game_id=?");
$find->execute([$_SESSION['user_id'],$gameId]);
$existing=$find->fetchColumn();

if($existing){
    $del=$pdo->prepare("DELETE FROM played_games WHERE id=?");
    $del->execute([$existing]);
    flash('success','Removed from Played Games.');
}else{
    $ins=$pdo->prepare("INSERT INTO played_games (user_id,game_id) VALUES (?,?)");
    $ins->execute([$_SESSION['user_id'],$gameId]);
    flash('success','Marked as Played. Your recommendations will now learn from it.');
}

if($back!=='' && preg_match('/^(?:[A-Za-z0-9_.-]+)(?:\?[^#]*)?$/',$back)) redirect($back);
redirect('games.php');
