<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <div class="cp-page__eyebrow">
        <a href="<?= e(url('/admin/transactions')) ?>">Transactions</a>
        <span>/</span>
        <span>#<?= (int) $transaction['id'] ?></span>
      </div>
      <h2 class="cp-page__title"><?= e($transaction['reference']) ?> <?= status_chip($transaction['status']) ?></h2>
      <p class="cp-page__sub"><?= e($transaction['description']) ?></p>
    </div>
    <div class="cp-page__actions">
      <?php if ($canDispute): ?>
        <a class="btn cp-btn cp-btn--outline" href="<?= e(url('/admin/disputes/new?transaction=' . (int) $transaction['id'])) ?>">
          <i class="bi bi-exclamation-triangle"></i> Open dispute
        </a>
      <?php elseif ($activeDispute !== null): ?>
        <a class="btn cp-btn cp-btn--outline" href="<?= e(url('/admin/disputes/' . (int) $activeDispute['id'])) ?>">
          <i class="bi bi-exclamation-triangle"></i> Dispute #<?= (int) $activeDispute['id'] ?> open
        </a>
      <?php endif; ?>
      <a class="btn cp-btn cp-btn--ghost" href="<?= e(url('/admin/payment-requests/' . $transaction['reference'])) ?>">
        <i class="bi bi-inboxes"></i> Payment request
      </a>
      <a class="btn cp-btn cp-btn--ghost" href="<?= e(url('/checkout/' . $transaction['reference'])) ?>" target="_blank" rel="noopener">
        <i class="bi bi-box-arrow-up-right"></i> Checkout
      </a>
    </div>
  </div>

  <div class="cp-detail">
    <div class="cp-detail__main">
      <div class="cp-card">
        <div class="cp-card__head">
          <h3 class="cp-card__title">Transaction</h3>
          <span class="cp-chip cp-chip--info"><?= e(payment_method_label($transaction['provider'])) ?></span>
        </div>
        <dl class="cp-kv">
          <div><dt>Amount</dt><dd class="cp-kv__strong"><?= e(money_ksh($transaction['amount'])) ?></dd></div>
          <div><dt>Currency</dt><dd><?= e($transaction['currency']) ?></dd></div>
          <div><dt>Application</dt><dd><?= e($transaction['application_name']) ?></dd></div>
          <div><dt>Request status</dt><dd><?= status_chip($transaction['request_status']) ?></dd></div>
          <div><dt>Provider receipt</dt><dd><?= e($transaction['provider_receipt'] ?? '—') ?></dd></div>
          <div><dt>Provider reference</dt><dd><code class="cp-code"><?= e($transaction['provider_reference'] ?? '—') ?></code></dd></div>
          <div><dt>Verified (UTC)</dt><dd><?= e($transaction['verified_at'] ?? 'Not verified') ?></dd></div>
          <div><dt>Recorded (UTC)</dt><dd><?= e($transaction['created_at']) ?></dd></div>
          <div><dt>Buyer</dt><dd><?= e($transaction['buyer_name'] ?? '—') ?></dd></div>
          <div><dt>Phone</dt><dd><?= e($transaction['buyer_phone'] ? mask_phone($transaction['buyer_phone']) : '—') ?></dd></div>
          <div><dt>Created via</dt><dd><?= e(ucfirst($transaction['created_via'])) ?></dd></div>
          <div><dt>Request created</dt><dd><?= e($transaction['request_created_at']) ?></dd></div>
          <div><dt>Request expires</dt><dd><?= e($transaction['expires_at'] ?? '—') ?></dd></div>
        </dl>
        <?php if ($transaction['status'] === 'reported'): ?>
          <div class="cp-alert cp-alert--info">
            <i class="bi bi-info-circle"></i>
            <span>This is a cash-on-meeting record. CampusPay did not process money; the parties settled in person.</span>
          </div>
        <?php elseif ($transaction['status'] === 'provider_confirmed'): ?>
          <div class="cp-alert cp-alert--ok">
            <i class="bi bi-patch-check"></i>
            <span>Confirmed by a validated M-Pesa result, not by the checkout browser.</span>
          </div>
        <?php endif; ?>
      </div>

      <div class="cp-card cp-card--spaced">
        <div class="cp-card__head">
          <h3 class="cp-card__title">Payment attempts</h3>
          <span class="cp-chip cp-chip--muted"><?= count($attempts) ?></span>
        </div>
        <?php if ($attempts === []): ?>
          <div class="cp-empty cp-empty--compact"><i class="bi bi-phone"></i><p>No provider attempts. Cash records never create one.</p></div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table cp-table align-middle">
              <thead>
                <tr><th>#</th><th>Status</th><th>Response</th><th>Checkout request</th><th>Created (UTC)</th></tr>
              </thead>
              <tbody>
                <?php foreach ($attempts as $attempt): ?>
                  <tr>
                    <td><?= (int) $attempt['id'] ?></td>
                    <td><?= status_chip($attempt['status']) ?></td>
                    <td>
                      <?= e(trim((string) ($attempt['response_code'] ?? '') . ' ' . (string) ($attempt['response_description'] ?? '')) ?: '—') ?>
                      <?php if (!empty($attempt['error_message'])): ?>
                        <span class="cp-table__sub"><?= e($attempt['error_message']) ?></span>
                      <?php endif; ?>
                    </td>
                    <td><code class="cp-code"><?= e($attempt['checkout_request_id'] ?? '—') ?></code></td>
                    <td class="cp-muted cp-nowrap"><?= e($attempt['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($callbacks !== []): ?>
        <div class="cp-card cp-card--spaced">
          <div class="cp-card__head">
            <h3 class="cp-card__title">Provider callbacks</h3>
            <span class="cp-chip cp-chip--muted"><?= count($callbacks) ?></span>
          </div>
          <div class="table-responsive">
            <table class="table cp-table align-middle">
              <thead>
                <tr><th>#</th><th>Token</th><th>Processed</th><th>Received (UTC)</th><th>Payload</th></tr>
              </thead>
              <tbody>
                <?php foreach ($callbacks as $callback): ?>
                  <tr>
                    <td><?= (int) $callback['id'] ?></td>
                    <td>
                      <span class="cp-chip <?= $callback['signature_valid'] ? 'cp-chip--ok' : 'cp-chip--bad' ?>">
                        <?= $callback['signature_valid'] ? 'Valid' : 'Invalid' ?>
                      </span>
                    </td>
                    <td><?= $callback['processed'] ? 'Yes' : 'No' ?></td>
                    <td class="cp-muted cp-nowrap"><?= e($callback['received_at']) ?></td>
                    <td><code class="cp-code cp-code--wrap"><?= e((string) $callback['payload']) ?></code></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="cp-note">Payloads are stored exactly as received. M-Pesa callbacks contain the paying phone number; PINs and OTPs are never part of a callback.</p>
        </div>
      <?php endif; ?>

      <?php if ($reconciliation !== []): ?>
        <div class="cp-card cp-card--spaced">
          <div class="cp-card__head"><h3 class="cp-card__title">Reconciliation checks</h3></div>
          <div class="table-responsive">
            <table class="table cp-table align-middle">
              <thead><tr><th>Checked (UTC)</th><th>Provider status</th><th>Matches</th><th>Details</th></tr></thead>
              <tbody>
                <?php foreach ($reconciliation as $check): ?>
                  <tr>
                    <td class="cp-muted cp-nowrap"><?= e($check['checked_at']) ?></td>
                    <td><?= e($check['provider_status']) ?></td>
                    <td><span class="cp-chip <?= $check['matches'] ? 'cp-chip--ok' : 'cp-chip--bad' ?>"><?= $check['matches'] ? 'Match' : 'Mismatch' ?></span></td>
                    <td><?= e($check['details'] ?? '—') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <aside class="cp-detail__side">
      <?php if ($disputes !== []): ?>
        <div class="cp-card">
          <div class="cp-card__head"><h3 class="cp-card__title">Disputes</h3></div>
          <ul class="cp-list">
            <?php foreach ($disputes as $dispute): ?>
              <li>
                <span><a class="cp-table__ref" href="<?= e(url('/admin/disputes/' . (int) $dispute['id'])) ?>">#<?= (int) $dispute['id'] ?></a> <?= status_chip($dispute['status']) ?> <?= e($dispute['reason']) ?></span>
                <span class="cp-muted">Opened <?= e($dispute['created_at']) ?></span>
                <?php if ($dispute['resolution'] !== null): ?>
                  <span class="cp-muted"><?= e($dispute['resolution']) ?></span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($webhooks !== []): ?>
        <div class="cp-card<?= $disputes !== [] ? ' cp-card--spaced' : '' ?>">
          <div class="cp-card__head"><h3 class="cp-card__title">Webhook deliveries</h3></div>
          <ul class="cp-list">
            <?php foreach ($webhooks as $webhook): ?>
              <li>
                <span><?= e($webhook['event']) ?> <?= status_chip($webhook['status']) ?></span>
                <span class="cp-muted"><?= (int) $webhook['attempts'] ?> attempt<?= (int) $webhook['attempts'] === 1 ? '' : 's' ?>
                  <?= $webhook['last_response_code'] !== null ? ' · HTTP ' . (int) $webhook['last_response_code'] : '' ?></span>
                <?php if (!empty($webhook['last_error'])): ?>
                  <span class="cp-muted"><?= e($webhook['last_error']) ?></span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="cp-card cp-card--spaced">
        <div class="cp-card__head"><h3 class="cp-card__title">Audit trail</h3></div>
        <ul class="cp-list cp-list--tight">
          <?php if ($audit === []): ?>
            <li class="cp-muted">No audit entries.</li>
          <?php endif; ?>
          <?php foreach ($audit as $entry): ?>
            <li>
              <span><code class="cp-code"><?= e($entry['action']) ?></code></span>
              <span class="cp-muted"><?= e($entry['actor_type']) ?> · <?= e($entry['created_at']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </aside>
  </div>
</section>
