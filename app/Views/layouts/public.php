<?php
$current = $current ?? '/';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="CampusPay collects M-Pesa payments for campus marketplaces and verifies every payment with the provider before it counts.">
  <title><?= e($title ?? 'CampusPay') ?> · CampusPay</title>
  <link rel="icon" href="<?= e(url('/assets/images/favicon.svg')) ?>" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body class="cp-public-body">
<a class="cp-skip" href="#main">Skip to content</a>

<header class="cp-pub-header">
  <nav class="navbar navbar-expand-lg cp-pub-nav" aria-label="Main navigation">
    <div class="container">
      <a class="cp-brand" href="<?= e(url('/')) ?>" aria-label="CampusPay home">
        <span class="cp-brand__mark"><i class="bi bi-wallet2"></i></span>
        <span class="cp-brand__text">Campus<span>Pay</span></span>
      </a>
      <button class="navbar-toggler cp-navbar-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#cpPublicNav"
              aria-controls="cpPublicNav" aria-expanded="false" aria-label="Toggle navigation">
        <i class="bi bi-list"></i>
      </button>
      <div class="collapse navbar-collapse" id="cpPublicNav">
        <ul class="navbar-nav ms-lg-auto cp-pub-nav__links">
          <li class="nav-item"><a class="nav-link" href="<?= e(url('/#how-it-works')) ?>">How it works</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= e(url('/#security')) ?>">Security</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= e(url('/#methods')) ?>">Payments</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= e(url('/#integrations')) ?>">Integrations</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= e(url('/#faq')) ?>">FAQ</a></li>
        </ul>
        <div class="cp-pub-nav__actions">
          <a class="btn cp-btn cp-btn--navy" href="<?= e(url('/login')) ?>">
            <i class="bi bi-box-arrow-in-right"></i> Sign in
          </a>
        </div>
      </div>
    </div>
  </nav>
</header>

<main id="main">
  <?= $content ?>
</main>

<footer class="cp-footer">
  <div class="container">
    <div class="cp-footer__grid">
      <div class="cp-footer__brand">
        <a class="cp-brand" href="<?= e(url('/')) ?>">
          <span class="cp-brand__mark"><i class="bi bi-wallet2"></i></span>
          <span class="cp-brand__text">Campus<span>Pay</span></span>
        </a>
        <p>An independent payment platform for campus marketplaces. Every payment is verified with the provider before it counts.</p>
      </div>
      <div>
        <h3 class="cp-footer__title">Product</h3>
        <ul class="cp-footer__links">
          <li><a href="<?= e(url('/#how-it-works')) ?>">How it works</a></li>
          <li><a href="<?= e(url('/#security')) ?>">Security</a></li>
          <li><a href="<?= e(url('/#methods')) ?>">Payment methods</a></li>
          <li><a href="<?= e(url('/#integrations')) ?>">For developers</a></li>
        </ul>
      </div>
      <div>
        <h3 class="cp-footer__title">Legal</h3>
        <ul class="cp-footer__links">
          <li><a href="<?= e(url('/legal/privacy')) ?>">Privacy notice</a></li>
          <li><a href="<?= e(url('/legal/terms')) ?>">Terms of use</a></li>
        </ul>
      </div>
      <div>
        <h3 class="cp-footer__title">Contact</h3>
        <ul class="cp-footer__links">
          <li><a href="mailto:admin@campuspay.local">admin@campuspay.local</a></li>
          <li><span class="cp-muted">Nairobi, Kenya</span></li>
        </ul>
      </div>
    </div>
    <div class="cp-footer__bottom">
      <span>&copy; <?= date('Y') ?> CampusPay</span>
      <span class="cp-footer__note">
        M-Pesa is a trademark of Safaricom PLC. CampusPay is an independent platform and is not
        affiliated with, certified by or endorsed by Safaricom.
      </span>
    </div>
  </div>
</footer>

<script src="<?= e(url('/assets/vendor/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('/assets/js/app.js')) ?>"></script>
</body>
</html>
