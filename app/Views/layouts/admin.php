<?php
$admin = App\Core\Auth::admin();
$activeNav = $activeNav ?? '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'Admin') ?> · CampusPay</title>
  <link rel="icon" href="<?= e(url('/assets/images/favicon.svg')) ?>" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/app-stage4.css')) ?>">
</head>
<body class="cp-admin-body">
<div class="cp-shell">
  <aside class="cp-sidebar" id="cpSidebar">
    <a class="cp-brand cp-brand--sidebar" href="<?= e(url('/admin')) ?>">
      <span class="cp-brand__mark"><i class="bi bi-wallet2"></i></span>
      <span class="cp-brand__text">Campus<span>Pay</span></span>
    </a>
    <nav class="cp-nav" aria-label="Administration">
      <?php foreach (App\Controllers\AdminController::navFor((string) ($admin['role'] ?? 'admin')) as $key => $item): ?>
        <a class="cp-nav__item <?= $activeNav === $key ? 'is-active' : '' ?>"
           href="<?= e(url($key === 'overview' ? '/admin' : '/admin/' . $key)) ?>">
          <i class="bi <?= e($item['icon']) ?>"></i><span><?= e($item['label']) ?></span>
          <?php if ($item['stage'] !== null): ?><span class="cp-nav__stage" title="Scheduled for stage <?= (int) $item['stage'] ?>">S<?= (int) $item['stage'] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="cp-sidebar__foot">
      <form method="post" action="<?= e(url('/logout')) ?>">
        <?= App\Core\Csrf::field() ?>
        <button type="submit" class="btn cp-btn cp-btn--ghost-light w-100 btn-sm">
          <i class="bi bi-box-arrow-right"></i> Sign out
        </button>
      </form>
    </div>
  </aside>

  <div class="cp-main">
    <header class="cp-topbar">
      <button class="cp-burger" id="cpBurger" aria-label="Toggle navigation" aria-controls="cpSidebar">
        <i class="bi bi-list"></i>
      </button>
      <h1 class="cp-topbar__title"><?= e($title ?? '') ?></h1>
      <div class="cp-topbar__user">
        <div class="cp-avatar"><?= e(mb_strtoupper(mb_substr((string) ($admin['name'] ?? 'A'), 0, 1))) ?></div>
        <div class="cp-topbar__meta">
          <span class="cp-topbar__name"><?= e((string) ($admin['name'] ?? '')) ?></span>
          <span class="cp-topbar__role"><?= e(ucfirst((string) ($admin['role'] ?? ''))) ?></span>
        </div>
      </div>
    </header>
    <main class="cp-content">
      <div class="cp-content__flashes">
        <?php require BASE_PATH . '/app/Views/partials/flashes.php'; ?>
      </div>
      <?= $content ?>
    </main>
  </div>
</div>
<script src="<?= e(url('/assets/vendor/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('/assets/js/app.js')) ?>"></script>
</body>
</html>
