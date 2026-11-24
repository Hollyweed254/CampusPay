<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <h2 class="cp-page__title">Reconciliation</h2>
      <p class="cp-page__sub">Every row is an M-Pesa callback or STK Push Query answer compared with the local record. Provider answers can confirm a payment, never invent one.</p>
    </div>
    <form method="post" action="<?= e(url('/admin/reconciliation/run')) ?>" data-confirm="Query M-Pesa for up to 5 open attempts now?">
      <?= App\Core\Csrf::field() ?>
      <button class="btn cp-btn cp-btn--navy" type="submit" <?= $darajaConfigured ? '' : 'disabled' ?>>
        <i class="bi bi-arrow-repeat"></i> Check open attempts
      </button>
    </form>
  </div>

  <?php if (!$darajaConfigured): ?>
    <div class="cp-alert cp-alert--warn">
      <i class="bi bi-exclamation-triangle"></i>
      <span>Daraja is not configured, so no status query can be sent. Incoming callbacks are still recorded and applied when their token is valid.</span>
    </div>
  <?php endif; ?>

  <div class="cp-metrics">
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--navy"><i class="bi bi-clipboard-check"></i></span>
      <div>
        <div class="cp-metric__label">Comparisons today</div>
        <div class="cp-metric__value"><?= (int) $metrics['checks_today'] ?></div>
      </div>
    </div>
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--red"><i class="bi bi-exclamation-octagon"></i></span>
      <div>
        <div class="cp-metric__label">Mismatches (7 days)</div>
        <div class="cp-metric__value"><?= (int) $metrics['mismatches_7d'] ?></div>
      </div>
    </div>
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--amber"><i class="bi bi-hourglass-split"></i></span>
      <div>
        <div class="cp-metric__label">Awaiting provider result</div>
        <div class="cp-metric__value"><?= (int) $metrics['awaiting'] ?></div>
      </div>
    </div>
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--green"><i class="bi bi-clock-history"></i></span>
      <div>
        <div class="cp-metric__label">Last check (UTC)</div>
        <div class="cp-metric__value cp-metric__value--sm"><?= e($metrics['last_check'] ?? 'Never') ?></div>
      </div>
    </div>
  </div>

  <?php if ($openAttempts !== []): ?>
    <div class="cp-card cp-card--spaced">
      <div class="cp-card__head">
        <h3 class="cp-card__title">Open attempts</h3>
        <span class="cp-chip cp-chip--muted"><?= count($openAttempts) ?> most recent</span>
      </div>
      <div class="table-responsive">
        <table class="table cp-table align-middle">
          <thead>
            <tr>
              <th scope="col">Reference</th>
              <th scope="col">Attempt</th>
              <th scope="col">Checkout request</th>
              <th scope="col">Last checked (UTC)</th>
              <th scope="col" class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($openAttempts as $attempt): ?>
              <tr>
                <td><a class="cp-table__ref" href="<?= e(url('/admin/payment-requests/' . $attempt['reference'])) ?>"><?= e($attempt['reference']) ?></a></td>
                <td>#<?= (int) $attempt['id'] ?></td>
                <td><code class="cp-code"><?= e($attempt['checkout_request_id']) ?></code></td>
                <td class="cp-muted cp-nowrap"><?= e($attempt['last_status_check_at'] ?? 'Never') ?></td>
                <td class="text-end">
                  <form method="post" action="<?= e(url('/admin/reconciliation/attempts/' . (int) $attempt['id'] . '/check')) ?>" class="d-inline">
                    <?= App\Core\Csrf::field() ?>
                    <button class="btn btn-sm cp-btn cp-btn--ghost" type="submit" <?= $darajaConfigured ? '' : 'disabled' ?>>
                      <i class="bi bi-search"></i> Query
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="cp-card cp-card--spaced">
    <div class="cp-card__head">
      <h3 class="cp-card__title">Comparison log</h3>
      <span class="cp-chip cp-chip--muted"><?= (int) $pager['total'] ?> record(s)</span>
    </div>

    <form method="get" action="<?= e(url('/admin/reconciliation')) ?>" class="cp-filters">
      <div class="cp-filters__field cp-filters__field--grow">
        <label class="form-label" for="cpReconQ">Search</label>
        <input class="form-control form-control-sm" id="cpReconQ" name="q" type="search" value="<?= e($filters['q']) ?>" placeholder="Reference or detail">
      </div>
      <div class="cp-filters__field">
        <label class="form-label" for="cpReconResult">Comparison</label>
        <select class="form-select form-select-sm" id="cpReconResult" name="result">
          <option value="">All results</option>
          <option value="match" <?= $filters['result'] === 'match' ? 'selected' : '' ?>>Matches only</option>
          <option value="mismatch" <?= $filters['result'] === 'mismatch' ? 'selected' : '' ?>>Mismatches only</option>
        </select>
      </div>
      <div class="cp-filters__actions">
        <button class="btn btn-sm cp-btn cp-btn--navy" type="submit"><i class="bi bi-funnel"></i> Apply</button>
        <a class="btn btn-sm cp-btn cp-btn--ghost" href="<?= e(url('/admin/reconciliation')) ?>">Clear</a>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table cp-table align-middle">
        <thead>
          <tr>
            <th scope="col">Checked (UTC)</th>
            <th scope="col">Reference</th>
            <th scope="col">Provider said</th>
            <th scope="col">Local status</th>
            <th scope="col">Comparison</th>
            <th scope="col">Details</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($records === []): ?>
            <tr>
              <td colspan="6">
                <div class="cp-empty cp-empty--compact">
                  <i class="bi bi-clipboard-check"></i>
                  <p>No reconciliation records match these filters. Checks appear here once a query has run.</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
          <?php foreach ($records as $record): ?>
            <?php
            $providerClass = match ($record['provider_status']) {
                'success' => 'cp-chip--ok',
                'processing' => 'cp-chip--warn',
                default => 'cp-chip--bad',
            };
            ?>
            <tr>
              <td class="cp-muted cp-nowrap"><?= e($record['checked_at']) ?></td>
              <td>
                <?php if ($record['reference'] !== null): ?>
                  <a class="cp-table__ref" href="<?= e(url('/admin/payment-requests/' . $record['reference'])) ?>"><?= e($record['reference']) ?></a>
                  <span class="cp-table__sub"><?= e($record['application_name'] ?? '—') ?><?= $record['amount'] !== null ? ' · ' . e(money_ksh($record['amount'])) : '' ?></span>
                <?php else: ?>
                  <span class="cp-muted">Request removed</span>
                <?php endif; ?>
              </td>
              <td><span class="cp-chip <?= e($providerClass) ?>"><?= e(ucfirst(str_replace('_', ' ', $record['provider_status']))) ?></span></td>
              <td><?= status_chip($record['transaction_status'] ?? $record['request_status'] ?? 'pending') ?></td>
              <td>
                <span class="cp-chip <?= $record['matches'] ? 'cp-chip--ok' : 'cp-chip--bad' ?>">
                  <?= $record['matches'] ? 'Match' : 'Mismatch' ?>
                </span>
              </td>
              <td class="cp-recon__detail"><?= e($record['details'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
    $path = '/admin/reconciliation';
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
  </div>
</section>
