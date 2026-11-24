<?php
$maxStatus = max(1, ...array_column(array_values($breakdown), 'count'));
$activityTotal = array_sum(array_column($activity, 'count'));
$firstName = explode(' ', (string) (App\Core\Auth::admin()['name'] ?? 'Admin'))[0];
?>
<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <h2 class="cp-page__title">Welcome back, <?= e($firstName) ?></h2>
      <p class="cp-page__sub">Every figure on this page is read from the database. Nothing is mocked.</p>
    </div>
    <span class="cp-chip cp-chip--ok"><i class="bi bi-database-check"></i> Live data</span>
  </div>

  <div class="cp-metrics cp-metrics--6">
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--navy"><i class="bi bi-inboxes"></i></span>
      <div>
        <div class="cp-metric__label">Payment requests</div>
        <div class="cp-metric__value"><?= (int) $metrics['total_requests'] ?></div>
      </div>
    </div>
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--green"><i class="bi bi-check2-circle"></i></span>
      <div>
        <div class="cp-metric__label">Successful payments</div>
        <div class="cp-metric__value"><?= (int) $metrics['succeeded'] ?></div>
      </div>
    </div>
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--amber"><i class="bi bi-hourglass-split"></i></span>
      <div>
        <div class="cp-metric__label">Pending</div>
        <div class="cp-metric__value"><?= (int) $metrics['awaiting'] ?></div>
      </div>
    </div>
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--red"><i class="bi bi-x-circle"></i></span>
      <div>
        <div class="cp-metric__label">Failed / expired</div>
        <div class="cp-metric__value"><?= (int) $metrics['failed'] ?></div>
      </div>
    </div>
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--red"><i class="bi bi-exclamation-triangle"></i></span>
      <div>
        <div class="cp-metric__label">Disputed payments</div>
        <div class="cp-metric__value"><?= (int) $metrics['disputed'] ?></div>
      </div>
    </div>
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--green"><i class="bi bi-cash-stack"></i></span>
      <div>
        <div class="cp-metric__label">Processed volume</div>
        <div class="cp-metric__value cp-metric__value--money"><?= e(money_ksh($metrics['processed_total'])) ?></div>
      </div>
    </div>
  </div>

  <div class="cp-grid cp-grid--2">
    <div class="cp-card">
      <div class="cp-card__head">
        <h3 class="cp-card__title">Payment activity</h3>
        <span class="cp-chip cp-chip--muted"><?= (int) $activityTotal ?> in 14 days</span>
      </div>
      <canvas class="cp-chart" id="cpActivityChart" role="img" aria-label="Bar chart of payment requests created per day over the last 14 days"
              data-series="<?= e(json_encode($activity, JSON_UNESCAPED_SLASHES)) ?>" width="720" height="240"></canvas>
      <table class="visually-hidden">
        <caption>Payment requests created per day</caption>
        <thead><tr><th scope="col">Day</th><th scope="col">Requests</th></tr></thead>
        <tbody>
          <?php foreach ($activity as $day): ?>
            <tr><td><?= e($day['label']) ?></td><td><?= (int) $day['count'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="cp-card">
      <div class="cp-card__head">
        <h3 class="cp-card__title">Payment status breakdown</h3>
        <span class="cp-chip cp-chip--muted">All time</span>
      </div>
      <?php if ((int) $metrics['total_requests'] === 0): ?>
        <div class="cp-empty cp-empty--compact">
          <span class="cp-empty__icon"><i class="bi bi-pie-chart"></i></span>
          <p class="cp-muted mb-0">No payment requests yet. Create an integration, then send your first request.</p>
        </div>
      <?php else: ?>
        <ul class="cp-breakdown">
          <?php foreach ($breakdown as $status => $row): ?>
            <li class="cp-breakdown__row">
              <div class="cp-breakdown__meta">
                <span><?= status_chip($status) ?></span>
                <span class="cp-muted"><?= e(money_ksh($row['total'])) ?></span>
              </div>
              <div class="cp-breakdown__bar">
                <progress class="cp-progress" value="<?= (int) $row['count'] ?>" max="<?= (int) $maxStatus ?>"><?= (int) $row['count'] ?></progress>
                <strong><?= (int) $row['count'] ?></strong>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <div class="cp-grid cp-grid--2">
    <div class="cp-card">
      <div class="cp-card__head">
        <h3 class="cp-card__title">Recent transactions</h3>
        <a class="cp-link" href="<?= e(url('/admin/transactions')) ?>">All transactions <i class="bi bi-arrow-right"></i></a>
      </div>
      <?php if ($recentTransactions === []): ?>
        <div class="cp-empty cp-empty--compact">
          <span class="cp-empty__icon"><i class="bi bi-receipt"></i></span>
          <p class="cp-muted mb-0">No transactions yet. A transaction appears when the provider confirms a payment or a cash agreement is recorded.</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table cp-table align-middle">
            <thead>
              <tr><th scope="col">Reference</th><th scope="col">Amount</th><th scope="col">Status</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recentTransactions as $tx): ?>
                <tr>
                  <td>
                    <a class="cp-table__ref" href="<?= e(url('/admin/transactions/' . (int) $tx['id'])) ?>"><code><?= e($tx['reference']) ?></code></a>
                    <span class="cp-table__sub"><?= e($tx['application']) ?> · <?= e($tx['description']) ?></span>
                  </td>
                  <td class="cp-nowrap"><?= e(money_ksh($tx['amount'])) ?></td>
                  <td><?= status_chip($tx['status']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="cp-stack">
      <div class="cp-card">
        <div class="cp-card__head">
          <h3 class="cp-card__title">Money</h3>
          <span class="cp-chip cp-chip--muted">Provider-confirmed only</span>
        </div>
        <div class="cp-kv">
          <div class="cp-kv__row">
            <span>Processed volume</span>
            <strong><?= e(money_ksh($metrics['processed_total'])) ?></strong>
          </div>
          <div class="cp-kv__row">
            <span>Success rate</span>
            <strong>
              <?php if ($metrics['success_rate'] === null): ?>
                <span class="cp-muted">No settled payments yet</span>
              <?php else: ?>
                <?= e((string) $metrics['success_rate']) ?>%
              <?php endif; ?>
            </strong>
          </div>
          <div class="cp-kv__row">
            <span>Open disputes</span>
            <strong><a class="cp-kv__link" href="<?= e(url('/admin/disputes')) ?>"><?= (int) $metrics['disputed'] ?></a></strong>
          </div>
          <div class="cp-kv__row">
            <span>Active administrators</span>
            <strong><?= (int) $metrics['administrators'] ?></strong>
          </div>
        </div>
      </div>

      <div class="cp-card">
        <div class="cp-card__head">
          <h3 class="cp-card__title">Environment readiness</h3>
          <a class="cp-link" href="<?= e(url('/admin/settings')) ?>">Settings <i class="bi bi-arrow-right"></i></a>
        </div>
        <ul class="cp-list">
          <?php foreach ($readiness as $label => $ready): ?>
            <li class="cp-list__item">
              <span><?= e($label) ?></span>
              <?php if ($ready): ?>
                <span class="cp-chip cp-chip--ok">Ready</span>
              <?php else: ?>
                <span class="cp-chip cp-chip--warn">Not configured</span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="cp-note">Checkout stays closed for M-Pesa until Daraja credentials and a callback token exist. There is no simulated success mode.</p>
      </div>
    </div>
  </div>

  <div class="cp-card cp-card--spaced">
    <div class="cp-card__head">
      <h3 class="cp-card__title">Recent activity</h3>
      <a class="cp-link" href="<?= e(url('/admin/audit-logs')) ?>">All audit logs <i class="bi bi-arrow-right"></i></a>
    </div>
    <?php if ($recentAudit === []): ?>
      <p class="cp-muted mb-0">No activity recorded yet.</p>
    <?php else: ?>
      <ul class="cp-list">
        <?php foreach ($recentAudit as $entry): ?>
          <li class="cp-list__item">
            <span class="cp-list__action"><code><?= e($entry['action']) ?></code></span>
            <span class="cp-muted cp-list__time"><?= e($entry['created_at']) ?> UTC</span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
