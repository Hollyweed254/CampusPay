<?php
$enabled = (bool) ($totpEnabled ?? false);
?>
<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <h2 class="cp-page__title">Security</h2>
      <p class="cp-page__sub">Protect your administrator account. Passwords alone are thin for a panel that moves money.</p>
    </div>
  </div>

  <div class="cp-detail">
    <div class="cp-detail__main">
      <div class="cp-card">
        <div class="cp-card__head">
          <h3 class="cp-card__title">Two-factor authentication</h3>
          <span class="cp-chip <?= $enabled ? 'cp-chip--ok' : 'cp-chip--warn' ?>"><?= $enabled ? 'On' : 'Off' ?></span>
        </div>

        <?php if ($enabled): ?>
          <p class="cp-muted">
            This account signs in with a password plus a 6-digit code from your authenticator app.
            <?php if (!empty($totpEnabledAt)): ?>
              Enabled <?= e((string) $totpEnabledAt) ?> UTC.
            <?php endif; ?>
          </p>

          <?php if ($enforced): ?>
            <p class="cp-note mb-0">
              Two-factor authentication is required by policy for all administrators, so it cannot be turned off here.
            </p>
          <?php else: ?>
            <form method="post" action="<?= e(url('/admin/security/totp/disable')) ?>" class="cp-form" novalidate>
              <?= App\Core\Csrf::field() ?>
              <div class="mb-3">
                <label class="form-label" for="disable_password">Confirm your password to turn it off</label>
                <input class="form-control" id="disable_password" name="password" type="password"
                       autocomplete="current-password" required>
              </div>
              <button class="btn cp-btn cp-btn--outline-danger" type="submit">
                <i class="bi bi-shield-slash"></i> Turn off two-factor
              </button>
            </form>
          <?php endif; ?>

        <?php elseif (!empty($pendingSecret)): ?>
          <p class="cp-muted">
            Add CampusPay to your authenticator app (Google Authenticator, Authy, 1Password…)
            using the setup key below, then confirm with the 6-digit code it generates.
          </p>

          <div class="border rounded p-3 mb-3 text-center">
            <div class="cp-muted small">Setup key</div>
            <code class="fs-5 d-block text-break mt-1"><?= e((string) $pendingSecret) ?></code>
          </div>

          <details class="mb-3">
            <summary class="cp-muted small">Can't type the key? Paste this URI instead</summary>
            <code class="d-block text-break mt-2 small"><?= e((string) $otpauthUri) ?></code>
          </details>

          <form method="post" action="<?= e(url('/admin/security/totp/enable')) ?>" novalidate>
            <?= App\Core\Csrf::field() ?>
            <div class="mb-3">
              <label class="form-label" for="enable_code">6-digit code</label>
              <input class="form-control" id="enable_code" name="code" type="text" inputmode="numeric"
                     autocomplete="one-time-code" maxlength="7" required>
            </div>
            <div class="d-flex gap-2">
              <button class="btn cp-btn cp-btn--navy" type="submit"><i class="bi bi-check2"></i> Confirm and enable</button>
              <a class="btn cp-btn cp-btn--ghost" href="<?= e(url('/admin/security')) ?>">Cancel</a>
            </div>
          </form>

        <?php else: ?>
          <p class="cp-muted">
            Two-factor authentication is off. Turn it on to require a code from your authenticator app
            at every sign-in — the strongest single step you can take to protect this panel.
          </p>
          <form method="post" action="<?= e(url('/admin/security/totp/start')) ?>">
            <?= App\Core\Csrf::field() ?>
            <button class="btn cp-btn cp-btn--navy" type="submit">
              <i class="bi bi-shield-lock"></i> Set up two-factor authentication
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <aside class="cp-detail__side">
      <?php if (!empty($isSuperadmin)): ?>
        <div class="cp-card">
          <div class="cp-card__head"><h3 class="cp-card__title">Enforcement</h3></div>
          <p class="cp-muted">
            Require every administrator to enrol in two-factor authentication.
            Administrators without it are asked to set it up at their next sign-in.
          </p>
          <form method="post" action="<?= e(url('/admin/security/policy')) ?>">
            <?= App\Core\Csrf::field() ?>
            <input type="hidden" name="require_admin_totp" value="<?= $enforced ? '0' : '1' ?>">
            <button class="btn cp-btn <?= $enforced ? 'cp-btn--ghost' : 'cp-btn--navy' ?> w-100" type="submit">
              <?= $enforced ? 'Stop requiring two-factor' : 'Require two-factor for all admins' ?>
            </button>
          </form>
        </div>
      <?php endif; ?>

      <div class="cp-card<?= !empty($isSuperadmin) ? ' cp-card--spaced' : '' ?>">
        <div class="cp-card__head"><h3 class="cp-card__title">Your account</h3></div>
        <dl class="cp-kv">
          <div><dt>Name</dt><dd><?= e((string) $admin['name']) ?></dd></div>
          <div><dt>Email</dt><dd><?= e((string) $admin['email']) ?></dd></div>
          <div><dt>Role</dt><dd><?= e(ucfirst((string) $admin['role'])) ?></dd></div>
          <div><dt>Last sign-in</dt><dd><?= e((string) ($admin['last_login_at'] ?? 'Never')) ?></dd></div>
        </dl>
        <p class="cp-note mb-0">
          Lost your authenticator? An operator can reset it with
          <code class="cp-code">php bin/campuspay-admin-2fa.php --email=you@example.com --disable</code>.
        </p>
      </div>
    </aside>
  </div>
</section>
