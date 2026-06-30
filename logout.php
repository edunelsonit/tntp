<?php
require_once 'config/config.php';
$_SESSION = [];
session_destroy();
header("Location: " . APP_BASE_URL . "/index.php");
exit;
