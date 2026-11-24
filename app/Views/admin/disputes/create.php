<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <div class="cp-page__eyebrow">
        <a href="<?= e(url('/admin/transactions/' . (int) $transaction['id'])) ?>">Transaction</a>
        <span>/</span>
        <span>New dispute</span>
      </div>
      <h2 class="cp-page__title">Open a dispute</h2>
      <p class="cp-page__sub">The transaction is marked disputed while the case is open. Nothing else about the recorded money changes until you resolve or reject it.</p>
    </div>
  </div>

  <div class="cp-detail">
    <div class="cp-detail__main">
      <div class="cp-card">
        <div class="cp-card__head">
          <h3 class="cp-card__title">Transaction</h3>
          <?= status_chip($transaction['status']) ?>
        </div>
        <dl class="cp-kv">
          <div><dt>Reference</dt><dd><a class="cp-table__ref" href="<?= e(url('/admin/payment-requests/' . $transaction['reference'])) ?>"><?= e($transaction['reference']) ?></a></dd></div>
          <div><dt>Description</dt><dd><?= e($transaction['description']) ?></dd></div>
          <div><dt>Amount</dt><dd class="cp-kv__strong"><?= e(money_ksh($transaction['amount'])) ?></dd></div>
          <div><dt>Method</dt><dd><?= e(payment_method_label($transaction['provider'])) ?></dd></div>
          <div><dt>Receipt</dt><dd><?= e($transaction['provider_receipt'] ?? '—') ?></dd></div>
          <div><dt>Integration</dt><dd><?= e($transaction['application_name']) ?></dd></div>
          <div><dt>Buyer</dt><dd><?= e($transaction['buyer_name'] ?? '—') ?></dd></div>
          <div><dt>Phone</dt><dd><?= e($transaction['buyer_phone'] ? mask_phone($transaction['buyer_phone']) : '—') ?></dd></div>
        </dl>
      </div>
    </div>

    <aside class="cp-detail__side">
      <form method="post" action="<?= e(url('/admin/transactions/' . (int) $transaction['id'] . '/disputes')) ?>" class="cp-card cp-form">
        <?= App\Core\Csrf::field() ?>
        <h3 class="cp-card__title">Dispute reason</h3>
        <div>
          <label class="form-label" for="cpDisputeReason">What is contested?</label>
          <textarea class="form-control" id="cpDisputeReason" name="reason" rows="4" minlength="5" maxlength="500" required placeholder="For example: buyer says the receipt does not match their M-Pesa message."></textarea>
          <p class="cp-note mt-1 mb-0">5 to 500 characters. Record facts, not guesses; this reason is kept permanently.</p>
        </div>
        <div class="cp-form__foot">
          <a class="btn cp-btn cp-btn--ghost" href="<?= e(url('/admin/transactions/' . (int) $transaction['id'])) ?>">Cancel</a>
          <button class="btn cp-btn cp-btn--navy" type="submit"><i class="bi bi-exclamation-triangle"></i> Open dispute</button>
        </div>
      </form>
    </aside>
  </div>
</section>
