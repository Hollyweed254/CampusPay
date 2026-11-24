<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <h2 class="cp-page__title">API Credentials</h2>
      <p class="cp-page__sub">Key pairs that let an integration sign requests to <code class="cp-code">/api/v1</code>. Secrets are encrypted at rest and shown in full only once, right after creation.</p>
    </div>
  </div>

  <?php if ($newKey !== null): ?>
    <div class="cp-card cp-card--spaced cp-credential">
      <div class="cp-card__head">
        <h3 class="cp-card__title">Copy this secret now</h3>
        <span class="cp-chip cp-chip--warn">Shown once</span>
      </div>
      <div class="cp-credential__body">
        <p class="cp-note mt-0">Credential for <strong><?= e($newKey['application']) ?></strong>. CampusPay stores only an encrypted copy; if it is lost, revoke the key and create a new one.</p>
        <label class="form-label" for="cpNewKeyId">Key ID</label>
        <div class="cp-copy">
          <input class="form-control cp-code" id="cpNewKeyId" type="text" readonly value="<?= e($newKey['key_id']) ?>">
          <button class="btn btn-sm cp-btn cp-btn--outline" type="button" data-copy-target="#cpNewKeyId" data-copy-label="Copy">
            <i class="bi bi-clipboard"></i> Copy
          </button>
        </div>
        <label class="form-label mt-3" for="cpNewSecret">Secret</label>
        <div class="cp-copy">
          <input class="form-control cp-code" id="cpNewSecret" type="text" readonly value="<?= e($newKey['secret']) ?>">
          <button class="btn btn-sm cp-btn cp-btn--outline" type="button" data-copy-target="#cpNewSecret" data-copy-label="Copy">
            <i class="bi bi-clipboard"></i> Copy
          </button>
        </div>
        <p class="cp-note mb-0">Send both values over a secure channel. The secret signs every request with HMAC-SHA256; see <code class="cp-code">docs/API.md</code>.</p>
      </div>
    </div>
  <?php endif; ?>

  <div class="cp-card cp-card--spaced">
    <div class="cp-card__head">
      <h3 class="cp-card__title">Create a credential</h3>
    </div>
    <?php if ($applications === []): ?>
      <div class="cp-empty cp-empty--compact">
        <i class="bi bi-key"></i>
        <p>Create an integration first, then issue it an API credential here.</p>
      </div>
    <?php else: ?>
      <form method="post" action="<?= e(url('/admin/api-credentials')) ?>" class="cp-form" novalidate>
        <?= App\Core\Csrf::field() ?>
        <div class="cp-form__grid">
          <div>
            <label class="form-label" for="cpCredApp">Integration</label>
            <select class="form-select" id="cpCredApp" name="application_id" required>
              <?php foreach ($applications as $application): ?>
                <option value="<?= (int) $application['id'] ?>">
                  <?= e($application['name']) ?> · <?= e($application['slug']) ?>
                  <?= (int) $application['active_keys'] === 0 ? ' — no active key' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label" for="cpCredExpiry">Expires after <span class="cp-muted">(optional, days)</span></label>
            <input class="form-control" id="cpCredExpiry" name="expires_days" type="number" min="1" max="3650" inputmode="numeric" placeholder="Never">
            <p class="cp-note mt-1 mb-0">Rotate keys by creating a new one, switching the integration over, then revoking the old one.</p>
          </div>
        </div>
        <div class="cp-form__foot">
          <p class="cp-note mb-0">Revoking a key takes effect immediately for API requests. Webhooks start signing with the newest active key.</p>
          <button class="btn cp-btn cp-btn--navy" type="submit"><i class="bi bi-plus-lg"></i> Create credential</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <div class="cp-card cp-card--spaced">
    <div class="cp-card__head">
      <h3 class="cp-card__title">All credentials</h3>
      <span class="cp-chip cp-chip--muted"><?= count($credentials) ?> key(s)</span>
    </div>
    <?php if ($credentials === []): ?>
      <div class="cp-empty cp-empty--compact">
        <i class="bi bi-key"></i>
        <p>No API credentials yet. Integrations cannot call the API until one is created.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table cp-table align-middle">
          <thead>
            <tr>
              <th scope="col">Key ID</th>
              <th scope="col">Integration</th>
              <th scope="col">Status</th>
              <th scope="col">Expires (UTC)</th>
              <th scope="col">Last used (UTC)</th>
              <th scope="col">Created (UTC)</th>
              <th scope="col" class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($credentials as $credential): ?>
              <tr>
                <td><code class="cp-code"><?= e($credential['key_id']) ?></code></td>
                <td>
                  <a class="cp-table__ref" href="<?= e(url('/admin/integrations/' . (int) $credential['application_id'])) ?>"><?= e($credential['application_name']) ?></a>
                </td>
                <td><?= status_chip($credential['status']) ?></td>
                <td class="cp-muted cp-nowrap"><?= e($credential['expires_at'] ?? 'Never') ?></td>
                <td class="cp-muted cp-nowrap"><?= e($credential['last_used_at'] ?? '—') ?></td>
                <td class="cp-muted cp-nowrap"><?= e($credential['created_at']) ?></td>
                <td class="text-end">
                  <?php if ($credential['status'] === 'active'): ?>
                    <form method="post" action="<?= e(url('/admin/api-credentials/' . (int) $credential['id'] . '/revoke')) ?>" class="d-inline" data-confirm="Revoke <?= e($credential['key_id']) ?>? Requests signed with it will be rejected immediately.">
                      <?= App\Core\Csrf::field() ?>
                      <button class="btn btn-sm cp-btn cp-btn--outline-danger" type="submit"><i class="bi bi-slash-circle"></i> Revoke</button>
                    </form>
                  <?php else: ?>
                    <span class="cp-muted">Revoked</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
