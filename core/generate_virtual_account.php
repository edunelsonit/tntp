<?php
require_once '../config/config.php';

checkRouteAccess('user');

$dashboard_url = APP_BASE_URL . '/users/index.php';
header('Location: ' . $dashboard_url . '?err=payment_flow_disabled');
exit;
