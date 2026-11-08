<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';
$slRole = 'admin';      // 'manager' in app/manager, 'user' in app/responder
require_once __DIR__ . '/../../config/security_logs_page.php';