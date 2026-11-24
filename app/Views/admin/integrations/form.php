<?php
$isEdit = $application !== null;
$action = $isEdit ? url('/admin/integrations/' . (int) $application['id']) : url('/admin/integrations');
$name = $isEdit ? $application['name'] : ($old['name'] ?? '');
$settlement = $isEdit ? $application['settlement_model'] : ($old['settlement_model'] ?? 'undecided');
$webhook = $isEdit ? (string) ($application['webhook_url'] ?? '') : ($old['webhook_url'] ?? '');
$cash = $isEdit ? (int) $application['cash_on_meeting_enabled'] === 1 : false;
$status = $isEdit ? $application['status'] : 'active';
?>
<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <div class="cp-page__eyebrow">
        <a href="<?= e(url('/admin/integrations')) ?>">Integrations</a>
        <span>/</span>
        <span><?= $isEdit ? e($application['name']) : 'New' ?></span>
      </div>
      <h2 class="cp-page__title"><?= $isEdit ? 'Edit integration' : 'New integration' ?></h2>
      <p class="cp-page__sub">The settlement model decides what CampusPay is allowed to confirm. It is never inferred from a payment.</p>
    </div>
  </div>

  <?php if ($isEdit): ?>
    <div class="cp-metrics cp-metrics--3">
      <div class="cp-metric">
        <span class="cp-metric__icon cp-metric__icon--navy"><i class="bi bi-inboxes"></i></span>
        <div>
          <span class="cp-metric__label">Payment requests</span>
          <div class="cp-metric__value"><?= number_format((int) $stats['requests']) ?></div>
          <span class="cp-metric__foot"><?= number_format((int) $stats['succeeded']) ?> successful</span>
        </div>
      </div>
      <div class="cp-metric">
        <span class="cp-metric__icon cp-metric__icon--green"><i class="bi bi-patch-check"></i></span>
        <div>
          <span class="cp-metric__label">Verified volume</span>
          <div class="cp-metric__value cp-metric__value--money"><?= e(money_ksh($stats['volume'])) ?></div>
          <span class="cp-metric__foot">Provider-confirmed only</span>
        </div>
      </div>
      <div class="cp-metric">
        <span class="cp-metric__icon cp-metric__icon--amber"><i class="bi bi-key"></i></span>
        <div>
          <span class="cp-metric__label">API credentials</span>
          <div class="cp-metric__value"><?= count($credentials) ?></div>
          <span class="cp-metric__foot">Issued in API Credentials</span>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= e($action) ?>" class="cp-card cp-form<?= $isEdit ? ' cp-card--spaced' : '' ?>" novalidate>
    <?= App\Core\Csrf::field() ?>
    <div class="cp-form__grid">
      <div class="cp-form__span">
        <label class="form-label" for="intgName">Application name</label>
        <input class="form-control" id="intgName" name="name" type="text" minlength="2" maxlength="120" required value="<?= e($name) ?>">
        <?php if ($isEdit): ?>
          <p class="cp-note mt-1 mb-0">Slug <code class="cp-code"><?= e($application['slug']) ?></code> is permanent; it identifies the application in the API.</p>
        <?php endif; ?>
      </div>
      <div class="cp-form__span">
        <label class="form-label" for="intgSettlement">Settlement model</label>
        <select class="form-select" id="intgSettlement" name="settlement_model">
          <?php foreach ($settlementLabels as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $settlement === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($isEdit): ?>
        <div>
          <label class="form-label" for="intgStatus">Status</label>
          <select class="form-select" id="intgStatus" name="status">
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active — API and checkout allowed</option>
            <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended — new requests refused</option>
          </select>
        </div>
      <?php endif; ?>
      <div class="cp-form__span">
        <label class="form-label" for="intgWebhook">Webhook endpoint <span class="cp-muted">(optional)</span></label>
        <input class="form-control" id="intgWebhook" name="webhook_url" type="url" maxlength="500" placeholder="https://app.example.com/campuspay/webhook" value="<?= e($webhook) ?>">
        <p class="cp-note mt-1 mb-0">Must be a full http(s) URL. Deliveries are signed and retried automatically; production requires HTTPS.</p>
      </div>
      <div class="cp-form__span">
        <div class="form-check cp-check">
          <input class="form-check-input" id="intgCash" name="cash_on_meeting_enabled" type="checkbox" value="1" <?= $cash ? 'checked' : '' ?>>
          <label class="form-check-label" for="intgCash">
            Allow cash on meeting
            <span class="cp-note d-block">Records the agreement without moving money. Not available while the settlement model is undecided.</span>
          </label>
        </div>
      </div>
    </div>
    <div class="cp-form__foot">
      <p class="cp-note mb-0">CampusPay never handles payouts. Settlement between the application and its merchants stays outside this platform.</p>
      <div class="d-flex gap-2">
        <a class="btn cp-btn cp-btn--ghost" href="<?= e(url('/admin/integrations')) ?>">Cancel</a>
        <button class="btn cp-btn cp-btn--navy" type="submit">
          <i class="bi bi-check2"></i> <?= $isEdit ? 'Save changes' : 'Create integration' ?>
        </button>
      </div>
    </div>
  </form>

  <?php if ($isEdit): ?>
    <div class="cp-card cp-card--spaced">
      <div class="cp-card__head">
        <h3 class="cp-card__title">Recent payment requests</h3>
        <a class="cp-link" href="<?= e(url('/admin/payment-requests') . '?app=' . (int) $application['id']) ?>">See all <i class="bi bi-arrow-right"></i></a>
      </div>
      <?php if ($recentRequests === []): ?>
        <div class="cp-empty cp-empty--compact"><i class="bi bi-inboxes"></i><p>No payment requests yet.</p></div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table cp-table align-middle">
            <thead><tr><th>Reference</th><th>Description</th><th>Amount</th><th>Status</th><th>Created (UTC)</th></tr></thead>
            <tbody>
              <?php foreach ($recentRequests as $request): ?>
                <tr>
                  <td><a class="cp-table__ref" href="<?= e(url('/admin/payment-requests/' . $request['reference'])) ?>"><?= e($request['reference']) ?></a></td>
                  <td><?= e($request['description']) ?></td>
                  <td class="cp-nowrap"><?= e(money_ksh($request['amount'])) ?></td>
                  <td><?= status_chip($request['status']) ?></td>
                  <td class="cp-muted cp-nowrap"><?= e($request['created_at']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="cp-card cp-card--spaced">
      <div class="cp-card__head">
        <h3 class="cp-card__title">API credentials</h3>
        <a class="cp-link" href="<?= e(url('/admin/api-credentials')) ?>">Open API Credentials <i class="bi bi-arrow-right"></i></a>
      </div>
      <?php if ($credentials === []): ?>
        <div class="cp-empty cp-empty--compact">
          <i class="bi bi-key"></i>
          <p>No API credentials yet. Create one in API Credentials to let this integration call the API.</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table cp-table align-middle">
            <thead><tr><th>Key ID</th><th>Status</th><th>Expires (UTC)</th><th>Last used (UTC)</th><th>Created (UTC)</th></tr></thead>
            <tbody>
              <?php foreach ($credentials as $credential): ?>
                <tr>
                  <td><code class="cp-code"><?= e($credential['key_id']) ?></code></td>
                  <td><?= status_chip($credential['status']) ?></td>
                  <td class="cp-muted"><?= e($credential['expires_at'] ?? 'Never') ?></td>
                  <td class="cp-muted"><?= e($credential['last_used_at'] ?? '—') ?></td>
                  <td class="cp-muted"><?= e($credential['created_at']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
