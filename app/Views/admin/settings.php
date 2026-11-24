<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <h2 class="cp-page__title">Settings</h2>
      <p class="cp-page__sub">Operational limits applied by the API, checkout and upkeep cron. Credentials stay in <code class="cp-code">.env</code> and are never shown here.</p>
    </div>
  </div>

  <div class="cp-detail">
    <div class="cp-detail__main">
      <form method="post" action="<?= e(url('/admin/settings')) ?>" class="cp-card cp-form">
        <?= App\Core\Csrf::field() ?>
        <h3 class="cp-card__title">Operational limits</h3>
        <div class="cp-form__grid">
          <?php foreach ($fields as $key => [$min, $max, $label, $hint]): ?>
            <div>
              <label class="form-label" for="set_<?= e($key) ?>"><?= e($label) ?></label>
              <input class="form-control" id="set_<?= e($key) ?>" name="<?= e($key) ?>" type="number"
                     min="<?= (int) $min ?>" max="<?= (int) $max ?>" step="1" required
                     value="<?= (int) $values[$key] ?>">
              <p class="cp-note mt-1 mb-0"><?= e($hint) ?> Range <?= (int) $min ?>–<?= (int) $max ?>.</p>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="cp-form__foot">
          <p class="cp-note mb-0">
            <?php if (isset($settings['last_cron_run_at'])): ?>
              Last upkeep run <?= e($settings['last_cron_run_at']['value']) ?> UTC.
            <?php else: ?>
              The upkeep cron has not run yet.
            <?php endif; ?>
          </p>
          <button class="btn cp-btn cp-btn--navy" type="submit"><i class="bi bi-check2"></i> Save settings</button>
        </div>
      </form>
    </div>

    <aside class="cp-detail__side">
      <div class="cp-card">
        <div class="cp-card__head"><h3 class="cp-card__title">Readiness</h3></div>
        <ul class="cp-list">
          <?php foreach ($readiness as $label => $ok): ?>
            <li>
              <span><?= e($label) ?></span>
              <span class="cp-chip <?= $ok ? 'cp-chip--ok' : 'cp-chip--warn' ?>"><?= $ok ? 'Configured' : 'Not set' ?></span>
            </li>
          <?php endforeach; ?>
          <li>
            <span>Daraja environment</span>
            <span class="cp-chip cp-chip--info"><?= e($darajaEnv) ?></span>
          </li>
        </ul>
        <p class="cp-note">The callback token and API secrets are intentionally not displayed. Rotate them in <code class="cp-code">.env</code> or API Credentials.</p>
      </div>

      <div class="cp-card cp-card--spaced">
        <div class="cp-card__head"><h3 class="cp-card__title">System</h3></div>
        <dl class="cp-kv">
          <div><dt>Database time (UTC)</dt><dd><?= e($dbTime) ?></dd></div>
          <div><dt>Last cron run</dt><dd><?= e($lastCron ?? 'Never') ?></dd></div>
          <div><dt>Application</dt><dd><?= e(env('APP_NAME', 'CampusPay')) ?></dd></div>
        </dl>
      </div>
    </aside>
  </div>
</section>
