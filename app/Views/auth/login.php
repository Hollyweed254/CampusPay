<div class="cp-login">
  <div class="cp-card cp-login__card">
    <h2 class="cp-login__title">Administrator sign in</h2>
    <p class="cp-muted">Use your CampusPay administrator account.</p>

    <?php if (!empty($throttled)): ?>
      <div class="alert alert-danger" role="alert">
        Too many failed attempts. Wait 15 minutes before trying again.
      </div>
    <?php else: ?>
      <form method="post" action="<?= e(url('/login')) ?>" novalidate>
        <?= App\Core\Csrf::field() ?>
        <div class="mb-3">
          <label class="form-label" for="email">Email</label>
          <input class="form-control" id="email" name="email" type="email" autocomplete="username" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn cp-btn cp-btn--navy w-100" type="submit">Sign in</button>
      </form>
    <?php endif; ?>
  </div>
  <p class="cp-login__hint">Payments are confirmed by the provider, never by this browser. CampusPay never asks for your M-Pesa PIN.</p>
</div>
