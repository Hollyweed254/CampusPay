<?php
$title = $title ?? 'Checkout';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> · CampusPay</title>
  <link rel="icon" href="<?= e(url('/assets/images/favicon.svg')) ?>" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/app-stage4.css')) ?>">
</head>
<body class="cp-checkout-body">
  <header class="cp-checkout-top">
    <a class="cp-brand" href="<?= e(url('/')) ?>">
      <span class="cp-brand__mark"><i class="bi bi-wallet2"></i></span>
      <span class="cp-brand__text">Campus<span>Pay</span></span>
    </a>
    <span class="cp-chip cp-chip--info"><i class="bi bi-shield-lock"></i> Secure checkout</span>
  </header>

  <main class="cp-checkout-main">
    <?php $flashScope = 'checkout'; require BASE_PATH . '/app/Views/partials/flashes.php'; ?>
    <?= $content ?>
  </main>

  <footer class="cp-checkout-foot">
    <span>CampusPay is an independent payment platform. M-Pesa is a trademark of Safaricom PLC.</span>
    <span><a href="<?= e(url('/legal/privacy')) ?>">Privacy</a> · <a href="<?= e(url('/legal/terms')) ?>">Terms</a></span>
  </footer>

  <script src="<?= e(url('/assets/vendor/bootstrap.bundle.min.js')) ?>"></script>
  <script src="<?= e(url('/assets/js/app.js')) ?>"></script>
  <script src="<?= e(url('/assets/js/checkout.js')) ?>"></script>
</body>
</html>
