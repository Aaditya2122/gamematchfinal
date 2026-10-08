<?php
require_once __DIR__ . '/../config/bootstrap.php';
$user=current_user($pdo); $flashMessage=flash(); $pageTitle=$pageTitle??'GameMatch';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?=e($pageTitle)?> | GameMatch</title>
<meta name="description" content="GameMatch - discover, compare and find your next game.">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=42"><script>(function(){try{if(localStorage.getItem('gamematch-theme')==='light')document.documentElement.classList.add('light-theme')}catch(e){}})();</script></head><body>
<header class="topbar"><a class="brand" href="<?=e(site_url('index.php'))?>"><img src="images/gamematch-logo.png" alt="GameMatch logo"><span class="brand-name">Game<span>Match</span></span></a>
<nav class="nav"><a href="<?=e(site_url('index.php'))?>">Home</a><a href="<?=e(site_url('games.php'))?>">Browse</a><a href="<?=e(site_url('recommend.php'))?>">Recommend</a><?php if($user): ?><a href="<?=e(site_url('wishlist.php'))?>">Wishlist</a><a href="<?=e(site_url('played.php'))?>">Played</a><button type="button" class="theme-toggle" id="themeToggle" aria-label="Switch to light theme" title="Switch to light theme">☀️</button><a class="nav-user" href="<?=e(site_url('profile.php'))?>"><?=e($user['name'])?></a><a class="btn btn-small btn-outline" href="<?=e(site_url('logout.php'))?>">Logout</a><?php else: ?><a href="<?=e(site_url('login.php'))?>">Login</a><a class="btn btn-small" href="<?=e(site_url('register.php'))?>">Register</a><?php endif; ?></nav></header>
<main class="container"><?php if($flashMessage): ?><div class="flash <?=e($flashMessage['type'])?>"><?=e($flashMessage['message'])?></div><?php endif; ?>
