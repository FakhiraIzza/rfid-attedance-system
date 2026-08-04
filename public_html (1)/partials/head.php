<?php $cfg = app_cfg(); ?>
<!doctype html>
<html lang="id">
<head>
 <meta charset="utf-8">
 <meta name="viewport" content="width=device-width, initial-scale=1">
 <title><?= htmlspecialchars($cfg['school_name']) ?> - Website Presensi</title>

 <!-- Bootstrap 5 (CDN) -->
 <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

 <!-- Favicon -->
 <link rel="icon" type="image/png" href="/assets/img/logo.png?v=20260109">




 <?php $cssVer = file_exists(__DIR__ . '/../assets/css/app.css') ? filemtime(__DIR__ . '/../assets/css/app.css') : time(); ?>
 <link rel="stylesheet" href="<?= base_url('/assets/css/app.css?v=' . $cssVer) ?>">
</head>
<body class="<?= isset($body_class) ? htmlspecialchars($body_class) : '' ?>">
