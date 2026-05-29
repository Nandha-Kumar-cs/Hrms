<?php
$page_title = '403 — Access Denied';
// Minimal header without full nav for error pages
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 — Access Denied | HRMS</title>
    <link rel="stylesheet" href="<?= (defined('BASE_URL') ? BASE_URL : '') ?>/assets/css/magdyn-base.css">
    <link rel="stylesheet" href="<?= (defined('BASE_URL') ? BASE_URL : '') ?>/assets/css/hrms.css">
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--bg-primary); }
        .error-box { text-align:center; max-width:400px; }
        .error-code { font-size:6rem; font-weight:900; color:var(--primary); line-height:1; }
        .error-msg { font-size:1.25rem; color:var(--text-primary); margin:1rem 0; }
        .error-sub { color:var(--text-muted); margin-bottom:2rem; }
    </style>
</head>
<body>
    <div class="error-box">
        <div class="error-code">403</div>
        <div class="error-msg">Access Denied</div>
        <div class="error-sub">You don't have permission to access this page. Contact your administrator if you believe this is an error.</div>
        <a href="<?= (defined('BASE_URL') ? BASE_URL : '/') ?>/index.php" class="btn btn-primary">Back to Dashboard</a>
    </div>
</body>
</html>
