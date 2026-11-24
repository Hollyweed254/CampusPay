<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <h2 class="cp-page__title">Disputes</h2>
      <p class="cp-page__sub">Transactions whose recorded outcome is contested. Opening a dispute marks the transaction disputed without changing the money record; resolving or rejecting it restores a definite status with an audit trail.</p>
    </div>
  </div>

  <div class="cp-metrics">
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--amber"><i class="bi bi-exclamation-triangle"></i></span>
      <div>
        <div class="cp-metric__label">Active disputes</div>
        <div class="cp-metric__value"><?= (int) $metrics['active'] ?></div>
      </div>
    </div>
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--navy"><i class="bi bi-clipboard-pulse"></i></span>
      <div>
        <div class="cp-metric__label">Awaiting review</div>
        <div class="cp-metric__value"><?= (int) $metrics['awaiting_review'] ?></div>
      </div>
    </div>
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--green"><i class="bi bi-check2-circle"></i></span>
      <div>
        <div class="cp-metric__label">Resolved (7 days)</div>
        <div class="cp-metric__value"><?= (int) $metrics['resolved_7d'] ?></div>
      </div>
    </div>
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--navy"><i class="bi bi-clock-history"></i></span>
      <div>
        <div class="cp-metric__label">Oldest active (UTC)</div>
        <div class="cp-metric__value cp-metric__value--sm"><?= e($metrics['oldest_active'] ?? 'None') ?></div>
      </div>
    </div>
  </div>

  <div class="cp-card cp-card--spaced">
    <div class="cp-card__head">
      <h3 class="cp-card__title">Dispute log</h3>
      <span class="cp-chip cp-chip--muted"><?= (int) $pager['total'] ?> dispute(s)</span>
    </div>

    <form method="get" action="<?= e(url('/admin/disputes')) ?>" class="cp-filters">
      <div class="cp-filters__field">
        <label class="form-label" for="cpDisputeQ">Search</label>
        <input class="form-control form-control-sm" id="cpDisputeQ" name="q" type="search" value="<?= e($filters['q']) ?>" placeholder="Reference or reason">
      </div>
      <div class="cp-filters__field">
        <label class="form-label" for="cpDisputeStatus">Status</label>
        <select class="form-select form-select-sm" id="cpDisputeStatus" name="status">
          <option value="">All statuses</option>
          <?php foreach ($statuses as $value): ?>
            <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e(status_meta($value)[0]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cp-filters__field">
        <label class="form-label" for="cpDisputeApp">Integration</label>
        <select class="form-select form-select-sm" id="cpDisputeApp" name="app">
          <option value="">All integrations</option>
          <?php foreach ($applications as $application): ?>
            <option value="<?= (int) $application['id'] ?>" <?= $filters['app'] === (string) $application['id'] ? 'selected' : '' ?>><?= e($application['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cp-filters__actions">
        <button class="btn btn-sm cp-btn cp-btn--navy" type="submit"><i class="bi bi-funnel"></i> Apply</button>
        <a class="btn btn-sm cp-btn cp-btn--ghost" href="<?= e(url('/admin/disputes')) ?>">Clear</a>
      </div>
    </form>

    <?php if ($disputes === []): ?>
      <div class="cp-empty cp-empty--compact">
        <i class="bi bi-exclamation-triangle"></i>
        <p>No disputes match these filters. Open one from a confirmed, cash-recorded or failed transaction.</p>
      </div>
    <?php else: ?>
      <div class="cp-hook-cards d-md-none">
        <?php foreach ($disputes as $dispute): ?>
          <div class="cp-hook-card">
            <div class="cp-hook-card__top">
              <a class="cp-table__ref" href="<?= e(url('/admin/disputes/' . (int) $dispute['id'])) ?>">Dispute #<?= (int) $dispute['id'] ?></a>
              <?= status_chip($dispute['status']) ?>
            </div>
            <div class="cp-hook-card__meta">
              <a class="cp-table__ref" href="<?= e(url('/admin/payment-requests/' . $dispute['reference'])) ?>"><?= e($dispute['reference']) ?></a>
              · <?= e($dispute['application_name']) ?>
            </div>
            <div class="cp-hook-card__meta"><?= e(money_ksh($dispute['amount'])) ?> · <?= e(payment_method_label($dispute['provider'])) ?></div>
            <div class="cp-hook-card__meta"><?= e($dispute['reason']) ?></div>
            <div class="cp-hook-card__meta">Opened <?= e($dispute['created_at']) ?> UTC</div>
            <div class="cp-hook-card__actions">
              <a class="btn btn-sm cp-btn cp-btn--outline" href="<?= e(url('/admin/disputes/' . (int) $dispute['id'])) ?>"><i class="bi bi-arrow-right-circle"></i> Review</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="table-responsive d-none d-md-block">
        <table class="table cp-table align-middle cp-hook-table">
          <thead>
            <tr>
              <th scope="col">Opened (UTC)</th>
              <th scope="col">Dispute</th>
              <th scope="col">Integration</th>
              <th scope="col">Amount</th>
              <th scope="col">Reason</th>
              <th scope="col">Status</th>
              <th scope="col" class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($disputes as $dispute): ?>
              <tr>
                <td class="cp-muted cp-nowrap"><?= e($dispute['created_at']) ?></td>
                <td>
                  <a class="cp-table__ref" href="<?= e(url('/admin/disputes/' . (int) $dispute['id'])) ?>">#<?= (int) $dispute['id'] ?></a>
                  <span class="cp-table__sub">
                    <a class="cp-table__ref" href="<?= e(url('/admin/payment-requests/' . $dispute['reference'])) ?>"><?= e($dispute['reference']) ?></a>
                  </span>
                </td>
                <td><?= e($dispute['application_name']) ?></td>
                <td class="cp-nowrap">
                  <?= e(money_ksh($dispute['amount'])) ?>
                  <span class="cp-table__sub"><?= e(payment_method_label($dispute['provider'])) ?></span>
                </td>
                <td class="cp-muted"><?= e($dispute['reason']) ?></td>
                <td><?= status_chip($dispute['status']) ?></td>
                <td class="text-end">
                  <a class="btn btn-sm cp-btn cp-btn--outline" href="<?= e(url('/admin/disputes/' . (int) $dispute['id'])) ?>"><i class="bi bi-arrow-right-circle"></i> Review</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?php
    $path = '/admin/disputes';
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
  </div>
</section>
