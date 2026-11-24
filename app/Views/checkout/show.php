<?php
$status = (string) $request['status'];
$isOpen = in_array($status, ['pending', 'processing'], true);
$isMpesa = $request['payment_method'] === 'mpesa';
[$statusLabel, $statusClass] = status_meta($status);
$phoneValue = $request['buyer_phone'] !== null ? \App\Services\PhoneNumber::display($request['buyer_phone']) : '';
?>
<section class="cp-checkout">
  <div class="cp-checkout__card" id="cpCheckout"
       data-reference="<?= e($request['reference']) ?>"
       data-status="<?= e($status) ?>"
       data-poll="<?= $isOpen ? '1' : '0' ?>"
       data-status-url="<?= e(url('/checkout/' . $request['reference'] . '/status')) ?>">

    <div class="cp-checkout__head">
      <p class="cp-eyebrow"><?= e($request['application_name']) ?> · payment request</p>
      <h1 class="cp-checkout__amount"><?= e(money_ksh($request['amount'])) ?></h1>
      <p class="cp-checkout__desc"><?= e($request['description']) ?></p>
      <div class="cp-checkout__meta">
        <span class="cp-checkout__ref">
          Ref <code class="cp-code" id="cpRef"><?= e($request['reference']) ?></code>
          <button class="cp-copybtn" type="button" data-copy-target="#cpRef" data-copy-label="Copy reference" aria-label="Copy reference">
            <i class="bi bi-clipboard"></i>
          </button>
        </span>
        <span class="cp-chip cp-chip--info"><?= e(payment_method_label($request['payment_method'])) ?></span>
        <span class="cp-chip <?= e($statusClass) ?>" id="cpCheckoutChip"><?= e($statusLabel) ?></span>
      </div>
    </div>

    <div class="cp-checkout__body">
      <?php if ($isOpen && $isMpesa): ?>
        <h2 class="cp-checkout__title">Approve the payment on your phone</h2>
        <p class="cp-checkout__lead">We send an M-Pesa request to the number below. Enter your M-Pesa PIN on your handset — never on this page.</p>

        <?php if ($status === 'pending' && !$darajaConfigured): ?>
          <div class="cp-alert cp-alert--warn">
            <i class="bi bi-exclamation-triangle"></i>
            <span>M-Pesa payments are not enabled on this deployment yet. The request stays open until it expires — contact <?= e($request['application_name']) ?> before paying another way.</span>
          </div>
        <?php endif; ?>

        <?php if ($status === 'processing'): ?>
          <div class="cp-checkout__status cp-checkout__status--wait">
            <span class="cp-checkout__icon cp-checkout__icon--wait"><span class="cp-spinner cp-spinner--lg"></span></span>
            <h3>Check your phone</h3>
            <p id="cpCheckoutMessage"><?= e(App\Services\CheckoutService::message($request)) ?></p>
            <p class="cp-checkout__meta-line">Sent to <strong><?= e(mask_phone($request['buyer_phone'])) ?></strong></p>
          </div>
        <?php else: ?>
          <form method="post" action="<?= e(url('/checkout/' . $request['reference'] . '/pay')) ?>" class="cp-checkout__form" data-pay-form novalidate>
            <?= App\Core\Csrf::field() ?>
            <label class="form-label" for="cpPhone">M-Pesa phone number</label>
            <div class="cp-checkout__phone">
              <input class="form-control form-control-lg" id="cpPhone" name="phone" type="tel" inputmode="tel" autocomplete="tel"
                     required placeholder="0712 345 678" value="<?= e($phoneValue) ?>" <?= $darajaConfigured ? '' : 'disabled' ?>>
              <button class="btn cp-btn cp-btn--green btn-lg" type="submit" <?= $darajaConfigured ? '' : 'disabled' ?>>
                <i class="bi bi-phone"></i> Send M-Pesa prompt
              </button>
            </div>
            <p class="cp-note">Use the number that will approve the prompt. A request can take up to a minute to arrive.</p>
          </form>
        <?php endif; ?>

        <div class="cp-checkout__aside">
          <?php if ($request['expires_at'] !== null): ?>
            <span class="cp-checkout__countdown" id="cpCountdown" data-expires="<?= e($request['expires_at']) ?>">
              Expires <?= e($request['expires_at']) ?> UTC
            </span>
          <?php endif; ?>
          <span class="cp-checkout__poll" id="cpPollNote"><?= $status === 'processing' ? 'Checking status automatically…' : 'Status updates automatically once a prompt is sent.' ?></span>
          <?php if ($status === 'processing'): ?>
            <button class="cp-linkbtn" type="button" data-check-now>Check now</button>
          <?php endif; ?>
        </div>

      <?php elseif ($status === 'succeeded'): ?>
        <div class="cp-checkout__status cp-checkout__status--ok">
          <span class="cp-checkout__icon cp-checkout__icon--ok"><i class="bi bi-check2"></i></span>
          <h2>Payment successful</h2>
          <p class="cp-checkout__status-amount"><?= e(money_ksh($request['amount'])) ?></p>
          <p id="cpCheckoutMessage"><?= e(App\Services\CheckoutService::message($request)) ?></p>
          <dl class="cp-checkout__rows">
            <div><dt>M-Pesa receipt</dt><dd><strong><?= e($request['provider_receipt'] ?? '—') ?></strong></dd></div>
            <div><dt>Verified at (UTC)</dt><dd><?= e($request['verified_at'] ?? '—') ?></dd></div>
            <div><dt>Reference</dt><dd><?= e($request['reference']) ?></dd></div>
          </dl>
          <p class="cp-note">Confirmed by M-Pesa. You can close this page; <?= e($request['application_name']) ?> sees the same result.</p>
        </div>

      <?php elseif ($status === 'recorded'): ?>
        <div class="cp-checkout__status cp-checkout__status--info">
          <span class="cp-checkout__icon cp-checkout__icon--info"><i class="bi bi-cash-coin"></i></span>
          <h2>Cash on meeting recorded</h2>
          <p class="cp-checkout__status-amount"><?= e(money_ksh($request['amount'])) ?></p>
          <p id="cpCheckoutMessage"><?= e(App\Services\CheckoutService::message($request)) ?></p>
          <dl class="cp-checkout__rows">
            <div><dt>Method</dt><dd>Cash on meeting — not an M-Pesa payment</dd></div>
            <div><dt>Recorded (UTC)</dt><dd><?= e($request['transaction_created_at'] ?? $request['created_at']) ?></dd></div>
            <div><dt>Reference</dt><dd><?= e($request['reference']) ?></dd></div>
          </dl>
          <p class="cp-note">CampusPay did not move any money. Pay <?= e($request['application_name']) ?> in person at handover and keep this reference.</p>
        </div>

      <?php elseif ($status === 'failed'): ?>
        <div class="cp-checkout__status cp-checkout__status--bad">
          <span class="cp-checkout__icon cp-checkout__icon--bad"><i class="bi bi-x"></i></span>
          <h2>Payment not completed</h2>
          <p id="cpCheckoutMessage"><?= e(App\Services\CheckoutService::message($request)) ?></p>
          <p class="cp-note">A declined or cancelled prompt never charges you. You can try again from the same link while it is open.</p>
        </div>
        <?php if ($isMpesa && $request['expires_at'] !== null && strtotime($request['expires_at'] . ' UTC') > time()): ?>
          <form method="post" action="<?= e(url('/checkout/' . $request['reference'] . '/pay')) ?>" class="cp-checkout__form" data-pay-form novalidate>
            <?= App\Core\Csrf::field() ?>
            <label class="form-label" for="cpPhone">Try again with this number</label>
            <div class="cp-checkout__phone">
              <input class="form-control form-control-lg" id="cpPhone" name="phone" type="tel" inputmode="tel" autocomplete="tel"
                     required placeholder="0712 345 678" value="<?= e($phoneValue) ?>" <?= $darajaConfigured ? '' : 'disabled' ?>>
              <button class="btn cp-btn cp-btn--green btn-lg" type="submit" <?= $darajaConfigured ? '' : 'disabled' ?>>
                <i class="bi bi-arrow-repeat"></i> Send new prompt
              </button>
            </div>
            <?php if (!$darajaConfigured): ?>
              <p class="cp-note">M-Pesa is not enabled on this deployment yet.</p>
            <?php endif; ?>
          </form>
        <?php endif; ?>

      <?php elseif ($status === 'cancelled' || $status === 'expired'): ?>
        <div class="cp-checkout__status cp-checkout__status--muted">
          <span class="cp-checkout__icon cp-checkout__icon--muted"><i class="bi bi-slash-circle"></i></span>
          <h2><?= $status === 'cancelled' ? 'Request cancelled' : 'Request expired' ?></h2>
          <p id="cpCheckoutMessage"><?= e(App\Services\CheckoutService::message($request)) ?></p>
          <p class="cp-note">Ask <?= e($request['application_name']) ?> for a new payment request. Do not pay against this reference.</p>
        </div>

      <?php else: ?>
        <div class="cp-checkout__status cp-checkout__status--muted">
          <span class="cp-checkout__icon cp-checkout__icon--muted"><i class="bi bi-info-circle"></i></span>
          <h2><?= e($statusLabel) ?></h2>
          <p id="cpCheckoutMessage"><?= e(App\Services\CheckoutService::message($request)) ?></p>
        </div>
      <?php endif; ?>
    </div>

    <div class="cp-checkout__foot">
      <i class="bi bi-shield-lock"></i>
      <span>CampusPay never asks for your M-Pesa PIN, OTP or password. Only enter your PIN on the Safaricom prompt on your phone.</span>
    </div>
  </div>

  <p class="cp-checkout__fine">
    Payment status is confirmed by M-Pesa and displayed here. If this page is closed, open the link again to see the same reference.
  </p>
</section>
