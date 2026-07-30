<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manager Dashboard — HopeLine</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0c2334;
            display: flex;
            min-height: 100vh;
        }
        .main {
            flex: 1;
            padding: 28px 32px;
            color: #fbf0d8;
        }
        .main h1 { font-size: 20px; margin-bottom: 6px; }
        .main p { color: #739ab9; font-size: 13px; }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>

    <main class="main">
        <h1>Manager Dashboard</h1>
        <p>Active incidents, live unit map, and delay alerts go here.</p>
    </main>

</body>
</html>