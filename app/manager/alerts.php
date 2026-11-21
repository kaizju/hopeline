<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';
requireRole('manager');
require __DIR__ . '/../shared/alerts-inbox.php';
