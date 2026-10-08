<?php
require_once 'config/bootstrap.php';
session_unset();
session_destroy();
session_start();
flash('success', 'You have been logged out.');
redirect('index.php');
?>