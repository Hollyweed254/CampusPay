<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'CampusPay') ?> · CampusPay</title>
  <link rel="icon" href="<?= e(url('/assets/images/favicon.svg')) ?>" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body class="cp-auth-body">
<header class="cp-auth-header">
  <a class="cp-brand" href="<?= e(url('/')) ?>">
    <span class="cp-brand__mark"><i class="bi bi-wallet2"></i></span>
    <span class="cp-brand__text">Campus<span>Pay</span></span>
  </a>
</header>
<main class="cp-auth-main">
  <div class="cp-auth-flashes">
    <?php require BASE_PATH . '/app/Views/partials/flashes.php'; ?>
  </div>
  <?= $content ?>
</main>
<footer class="cp-auth-footer">Independent payment platform · provider-verified M-Pesa</footer>
<script src="<?= e(url('/assets/vendor/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('/assets/js/app.js')) ?>"></script>
</body>
</html>
