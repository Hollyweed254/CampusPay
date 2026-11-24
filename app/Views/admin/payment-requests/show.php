<?php
$timeline = [];
$timeline[] = [
    'at' => $request['created_at'],
    'icon' => 'bi-plus-circle',
    'title' => 'Request created',
    'text' => 'Via ' . $request['created_via'] . ' · ' . payment_method_label($request['payment_method']),
];
foreach ($attempts as $attempt) {
    $detail = $attempt['error_message']
        ?: trim((string) ($attempt['response_code'] ?? '') . ' ' . (string) ($attempt['response_description'] ?? ''));
    $timeline[] = [
        'at' => $attempt['created_at'],
        'icon' => 'bi-phone',
        'title' => 'M-Pesa attempt #' . (int) $attempt['id'] . ' — ' . $attempt['status'],
        'text' => $detail === '' ? 'No provider response recorded.' : $detail,
    ];
}
foreach ($callbacks as $callback) {
    $timeline[] = [
        'at' => $callback['received_at'],
        'icon' => 'bi-inbox',
        'title' => 'Provider callback #' . (int) $callback['id'],
        'text' => ($callback['signature_valid'] ? 'Callback token verified' : 'Callback token not verified')
            . ' · ' . ($callback['processed'] ? 'processed' : 'not processed'),
    ];
}
if ($transaction !== null && $transaction['verified_at'] !== null) {
    $timeline[] = [
        'at' => $transaction['verified_at'],
        'icon' => 'bi-patch-check',
        'title' => 'Verified by M-Pesa',
        'text' => 'Receipt ' . ($transaction['provider_receipt'] ?? '—'),
    ];
}
foreach ($audit as $entry) {
    if (in_array($entry['action'], ['payment_request.cancelled', 'payment_request.expired'], true)) {
        $timeline[] = [
            'at' => $entry['created_at'],
            'icon' => $entry['action'] === 'payment_request.cancelled' ? 'bi-x-circle' : 'bi-hourglass-bottom',
            'title' => $entry['action'] === 'payment_request.cancelled' ? 'Request cancelled' : 'Request expired',
            'text' => 'By ' . $entry['actor_type'],
        ];
    }
}
usort($timeline, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']));
?>
<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <div class="cp-page__eyebrow">
        <a href="<?= e(url('/admin/payment-requests')) ?>">Payment requests</a>
        <span>/</span>
        <span><?= e($request['reference']) ?></span>
      </div>
      <h2 class="cp-page__title"><?= e($request['reference']) ?> <?= status_chip($request['status']) ?></h2>
      <p class="cp-page__sub"><?= e($request['description']) ?></p>
    </div>
    <div class="cp-page__actions">
      <a class="btn cp-btn cp-btn--ghost" href="<?= e(url('/checkout/' . $request['reference'])) ?>" target="_blank" rel="noopener">
        <i class="bi bi-box-arrow-up-right"></i> Open checkout
      </a>
      <?php if (in_array($request['status'], ['pending', 'processing'], true)): ?>
        <form method="post" action="<?= e(url('/admin/payment-requests/' . $request['reference'] . '/cancel')) ?>" class="d-inline" data-confirm="Cancel this payment request? Provider attempts stay on record.">
          <?= App\Core\Csrf::field() ?>
          <button class="btn cp-btn cp-btn--outline-danger" type="submit"><i class="bi bi-x-circle"></i> Cancel request</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="cp-detail">
    <div class="cp-detail__main">
      <div class="cp-card">
        <div class="cp-card__head">
          <h3 class="cp-card__title">Summary</h3>
          <span class="cp-chip cp-chip--info"><?= e(payment_method_label($request['payment_method'])) ?></span>
        </div>
        <dl class="cp-kv">
          <div><dt>Amount</dt><dd class="cp-kv__strong"><?= e(money_ksh($request['amount'])) ?></dd></div>
          <div><dt>Application</dt><dd><?= e($request['application_name']) ?></dd></div>
          <div><dt>Buyer</dt><dd><?= e($request['buyer_name'] ?? '—') ?></dd></div>
          <div><dt>Phone</dt><dd><?= e($request['buyer_phone'] ? mask_phone($request['buyer_phone']) : '—') ?></dd></div>
          <div><dt>Created (UTC)</dt><dd><?= e($request['created_at']) ?></dd></div>
          <div><dt>Expires (UTC)</dt><dd><?= e($request['expires_at'] ?? '—') ?></dd></div>
          <div><dt>Created via</dt><dd><?= e(ucfirst($request['created_via'])) ?></dd></div>
          <div><dt>Provider receipt</dt><dd><?= e($transaction['provider_receipt'] ?? '—') ?></dd></div>
        </dl>
      </div>

      <div class="cp-card cp-card--spaced">
        <div class="cp-card__head">
          <h3 class="cp-card__title">Event history</h3>
          <span class="cp-chip cp-chip--muted">Append-only</span>
        </div>
        <ol class="cp-timeline">
          <?php foreach ($timeline as $event): ?>
            <li class="cp-timeline__item">
              <span class="cp-timeline__icon"><i class="bi <?= e($event['icon']) ?>"></i></span>
              <div class="cp-timeline__body">
                <strong><?= e($event['title']) ?></strong>
                <p><?= e($event['text']) ?></p>
              </div>
              <time class="cp-timeline__time"><?= e($event['at']) ?></time>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>

      <div class="cp-card cp-card--spaced">
        <div class="cp-card__head">
          <h3 class="cp-card__title">Payment attempts</h3>
          <span class="cp-chip cp-chip--muted"><?= count($attempts) ?></span>
        </div>
        <?php if ($attempts === []): ?>
          <div class="cp-empty cp-empty--compact"><i class="bi bi-phone"></i><p>No M-Pesa attempts yet. The buyer starts one from the checkout page.</p></div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table cp-table align-middle">
              <thead>
                <tr><th>#</th><th>Status</th><th>Provider response</th><th>Checkout request</th><th>Created (UTC)</th></tr>
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
                <tr><th>#</th><th>Token</th><th>Processed</th><th>Received (UTC)</th></tr>
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
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($webhooks !== [] || $disputes !== []): ?>
        <div class="cp-detail__pair">
          <?php if ($webhooks !== []): ?>
            <div class="cp-card">
              <div class="cp-card__head"><h3 class="cp-card__title">Webhook deliveries</h3></div>
              <ul class="cp-list">
                <?php foreach ($webhooks as $webhook): ?>
                  <li>
                    <span><?= e($webhook['event']) ?> <?= status_chip($webhook['status']) ?></span>
                    <span class="cp-muted"><?= (int) $webhook['attempts'] ?> attempt<?= (int) $webhook['attempts'] === 1 ? '' : 's' ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <?php if ($disputes !== []): ?>
            <div class="cp-card">
              <div class="cp-card__head"><h3 class="cp-card__title">Disputes</h3></div>
              <ul class="cp-list">
                <?php foreach ($disputes as $dispute): ?>
                  <li>
                    <span><?= status_chip($dispute['status']) ?> <?= e($dispute['reason']) ?></span>
                    <span class="cp-muted"><?= e($dispute['created_at']) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <aside class="cp-detail__side">
      <div class="cp-card">
        <div class="cp-card__head"><h3 class="cp-card__title">Checkout link</h3></div>
        <p class="cp-note">Anyone with this link can see the amount and status. It cannot change the amount or confirm payment.</p>
        <div class="cp-copy">
          <input class="form-control form-control-sm" id="cpCheckoutUrl" type="text" readonly value="<?= e($checkoutUrl) ?>">
          <button class="btn btn-sm cp-btn cp-btn--outline" type="button" data-copy-target="#cpCheckoutUrl" data-copy-label="Copy">
            <i class="bi bi-clipboard"></i> Copy
          </button>
        </div>
      </div>

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
