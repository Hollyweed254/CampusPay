<?php
$isActive = in_array($dispute['status'], ['open', 'under_review'], true);
$isCash = $dispute['provider'] === 'cash_on_meeting';
$outcomes = $isCash
    ? [
        'record_upheld' => 'Cash record upheld — the agreement was completed in person',
        'payment_failed' => 'Mark as failed — the agreement did not happen',
    ]
    : [
        'payment_confirmed' => 'Payment confirmed — the provider-verified record stands',
        'payment_failed' => 'Payment failed — correct the record to failed',
    ];
$previous = $dispute['previous_transaction_status'] ?? null;
?>
<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <div class="cp-page__eyebrow">
        <a href="<?= e(url('/admin/disputes')) ?>">Disputes</a>
        <span>/</span>
        <span>#<?= (int) $dispute['id'] ?></span>
      </div>
      <h2 class="cp-page__title">Dispute #<?= (int) $dispute['id'] ?> <?= status_chip($dispute['status']) ?></h2>
      <p class="cp-page__sub"><?= e($dispute['reference']) ?> · <?= e($dispute['application_name']) ?> · <?= e(money_ksh($dispute['amount'])) ?></p>
    </div>
    <div class="cp-page__actions">
      <a class="btn cp-btn cp-btn--ghost" href="<?= e(url('/admin/transactions/' . (int) $dispute['tx_id'])) ?>">
        <i class="bi bi-arrow-left-right"></i> Transaction
      </a>
      <a class="btn cp-btn cp-btn--ghost" href="<?= e(url('/admin/payment-requests/' . $dispute['reference'])) ?>">
        <i class="bi bi-inboxes"></i> Payment request
      </a>
    </div>
  </div>

  <div class="cp-detail">
    <div class="cp-detail__main">
      <div class="cp-card">
        <div class="cp-card__head">
          <h3 class="cp-card__title">Case</h3>
          <span class="cp-chip cp-chip--muted">Opened by <?= e($dispute['opened_by_type']) ?></span>
        </div>
        <dl class="cp-kv">
          <div><dt>Reason</dt><dd><?= e($dispute['reason']) ?></dd></div>
          <div><dt>Transaction status now</dt><dd><?= status_chip($dispute['transaction_status']) ?></dd></div>
          <div><dt>Status before dispute</dt><dd><?= $previous !== null ? status_chip($previous) : '—' ?></dd></div>
          <div><dt>Request status</dt><dd><?= status_chip($dispute['request_status']) ?></dd></div>
          <div><dt>Opened (UTC)</dt><dd><?= e($dispute['created_at']) ?></dd></div>
          <div><dt>Closed (UTC)</dt><dd><?= e($dispute['resolved_at'] ?? 'Still open') ?></dd></div>
        </dl>
        <?php if ($dispute['resolution'] !== null): ?>
          <div class="cp-alert <?= $dispute['status'] === 'resolved' ? 'cp-alert--ok' : 'cp-alert--info' ?>">
            <i class="bi <?= $dispute['status'] === 'resolved' ? 'bi-check2-circle' : 'bi-shield-x' ?>"></i>
            <span><strong>Resolution:</strong> <?= e($dispute['resolution']) ?></span>
          </div>
        <?php elseif ($isActive): ?>
          <div class="cp-alert cp-alert--warn">
            <i class="bi bi-info-circle"></i>
            <span>The transaction stays marked disputed until this case is resolved or rejected. Provider callbacks that arrive later are still stored for the record.</span>
          </div>
        <?php endif; ?>
      </div>

      <div class="cp-card cp-card--spaced">
        <div class="cp-card__head"><h3 class="cp-card__title">Transaction record</h3></div>
        <dl class="cp-kv">
          <div><dt>Reference</dt><dd><a class="cp-table__ref" href="<?= e(url('/admin/payment-requests/' . $dispute['reference'])) ?>"><?= e($dispute['reference']) ?></a></dd></div>
          <div><dt>Description</dt><dd><?= e($dispute['description']) ?></dd></div>
          <div><dt>Amount</dt><dd class="cp-kv__strong"><?= e(money_ksh($dispute['amount'])) ?></dd></div>
          <div><dt>Method</dt><dd><?= e(payment_method_label($dispute['provider'])) ?></dd></div>
          <div><dt>Receipt</dt><dd><?= e($dispute['provider_receipt'] ?? '—') ?></dd></div>
          <div><dt>Provider reference</dt><dd><code class="cp-code"><?= e($dispute['provider_reference'] ?? '—') ?></code></dd></div>
          <div><dt>Verified (UTC)</dt><dd><?= e($dispute['verified_at'] ?? 'Not verified') ?></dd></div>
          <div><dt>Buyer</dt><dd><?= e($dispute['buyer_name'] ?? '—') ?></dd></div>
          <div><dt>Phone</dt><dd><?= e($dispute['buyer_phone'] ? mask_phone($dispute['buyer_phone']) : '—') ?></dd></div>
        </dl>
      </div>
    </div>

    <aside class="cp-detail__side">
      <?php if ($isActive): ?>
        <div class="cp-card">
          <div class="cp-card__head"><h3 class="cp-card__title">Actions</h3></div>
          <?php if ($dispute['status'] === 'open'): ?>
            <form method="post" action="<?= e(url('/admin/disputes/' . (int) $dispute['id'] . '/review')) ?>" class="mb-3">
              <?= App\Core\Csrf::field() ?>
              <button class="btn cp-btn cp-btn--outline w-100" type="submit"><i class="bi bi-search"></i> Start review</button>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= e(url('/admin/disputes/' . (int) $dispute['id'] . '/resolve')) ?>" class="cp-form" data-confirm="Resolve this dispute and update the recorded outcome? This closes the case permanently.">
            <?= App\Core\Csrf::field() ?>
            <div>
              <label class="form-label" for="cpOutcome">Resolve as</label>
              <select class="form-select" id="cpOutcome" name="outcome" required>
                <?php foreach ($outcomes as $value => $label): ?>
                  <option value="<?= e($value) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="form-label" for="cpResolution">Resolution note</label>
              <textarea class="form-control" id="cpResolution" name="resolution" rows="3" minlength="5" maxlength="500" required placeholder="What evidence decided this?"></textarea>
            </div>
            <button class="btn cp-btn cp-btn--navy w-100" type="submit"><i class="bi bi-check2-circle"></i> Resolve dispute</button>
          </form>
          <hr class="my-3">
          <form method="post" action="<?= e(url('/admin/disputes/' . (int) $dispute['id'] . '/reject')) ?>" class="cp-form" data-confirm="Reject this dispute and restore the transaction's previous status?">
            <?= App\Core\Csrf::field() ?>
            <div>
              <label class="form-label" for="cpRejectNote">Reject — reason</label>
              <textarea class="form-control" id="cpRejectNote" name="resolution" rows="3" minlength="5" maxlength="500" required placeholder="Why does the recorded outcome stand?"></textarea>
            </div>
            <button class="btn cp-btn cp-btn--outline-danger w-100" type="submit"><i class="bi bi-x-circle"></i> Reject dispute</button>
          </form>
        </div>
      <?php endif; ?>

      <div class="cp-card<?= $isActive ? ' cp-card--spaced' : '' ?>">
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
