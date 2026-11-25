<?php
$mode = $mode ?? 'verify';
?>
<div class="cp-login">
  <div class="cp-card cp-login__card">
    <?php if ($mode === 'enrol'): ?>
      <h2 class="cp-login__title">Set up two-factor authentication</h2>
      <p class="cp-muted">
        This dashboard must be protected by a second factor. Add CampusPay to an
        authenticator app (Google Authenticator, Authy, 1Password…) using the setup
        key below, then confirm with the 6-digit code it generates.
      </p>

      <div class="border rounded p-3 mb-3 text-center">
        <div class="cp-muted small">Setup key</div>
        <code class="fs-5 d-block text-break mt-1"><?= e($secret) ?></code>
      </div>

      <details class="mb-3">
        <summary class="cp-muted small">Can't type the key? Paste this URI instead</summary>
        <code class="d-block text-break mt-2 small"><?= e($otpauthUri) ?></code>
      </details>

      <form method="post" action="<?= e(url('/login/2fa')) ?>" novalidate>
        <?= App\Core\Csrf::field() ?>
        <div class="mb-3">
          <label class="form-label" for="code">6-digit code</label>
          <input class="form-control" id="code" name="code" type="text" inputmode="numeric"
                 autocomplete="one-time-code" maxlength="7" required autofocus>
        </div>
        <button class="btn cp-btn cp-btn--navy w-100" type="submit">Confirm and sign in</button>
      </form>
    <?php else: ?>
      <h2 class="cp-login__title">Two-factor verification</h2>
      <p class="cp-muted">Enter the 6-digit code from your authenticator app to finish signing in.</p>

      <form method="post" action="<?= e(url('/login/2fa')) ?>" novalidate>
        <?= App\Core\Csrf::field() ?>
        <div class="mb-3">
          <label class="form-label" for="code">6-digit code</label>
          <input class="form-control" id="code" name="code" type="text" inputmode="numeric"
                 autocomplete="one-time-code" maxlength="7" required autofocus>
        </div>
        <button class="btn cp-btn cp-btn--navy w-100" type="submit">Verify and sign in</button>
      </form>

      <p class="cp-muted small mt-3 mb-0">
        <a href="<?= e(url('/login')) ?>">Back to sign in</a>
      </p>
    <?php endif; ?>
  </div>
  <p class="cp-login__hint">Codes are checked on the server and cannot be reused. CampusPay never asks for your M-Pesa PIN.</p>
</div>
