<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <h2 class="cp-page__title">New payment request</h2>
      <p class="cp-page__sub">Creates a checkout link like an API call would. The buyer can then pay with M-Pesa or, where enabled, agree cash on meeting.</p>
    </div>
    <a class="btn cp-btn cp-btn--ghost" href="<?= e(url('/admin/payment-requests')) ?>">
      <i class="bi bi-arrow-left"></i> All requests
    </a>
  </div>

  <form method="post" action="<?= e(url('/admin/payment-requests')) ?>" class="cp-card cp-form" novalidate>
    <?= App\Core\Csrf::field() ?>
    <div class="cp-form__grid">
      <div class="cp-form__span">
        <label class="form-label" for="reqApp">Application</label>
        <select class="form-select" id="reqApp" name="application_id" required>
          <?php foreach ($applications as $application): ?>
            <option value="<?= (int) $application['id'] ?>"><?= e($application['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="cp-note mt-1 mb-0">M-Pesa needs a merchant-collection settlement model. Cash on meeting must be enabled on the application.</p>
      </div>
      <div class="cp-form__span">
        <label class="form-label" for="reqDescription">Description</label>
        <input class="form-control" id="reqDescription" name="description" type="text" minlength="3" maxlength="255" required placeholder="Dell Latitude 7480 i5 laptop">
      </div>
      <div>
        <label class="form-label" for="reqAmount">Amount (KSh)</label>
        <input class="form-control" id="reqAmount" name="amount" type="text" inputmode="decimal" required placeholder="58000.00">
      </div>
      <div>
        <label class="form-label" for="reqMethod">Payment method</label>
        <select class="form-select" id="reqMethod" name="payment_method">
          <option value="mpesa">M-Pesa</option>
          <option value="cash_on_meeting">Cash on meeting</option>
        </select>
      </div>
      <div>
        <label class="form-label" for="reqBuyer">Buyer name <span class="cp-muted">(optional)</span></label>
        <input class="form-control" id="reqBuyer" name="buyer_name" type="text" maxlength="120">
      </div>
      <div>
        <label class="form-label" for="reqPhone">Buyer phone (M-Pesa) <span class="cp-muted">(optional)</span></label>
        <input class="form-control" id="reqPhone" name="buyer_phone" type="tel" inputmode="tel" placeholder="0712 345 678">
      </div>
      <div>
        <label class="form-label" for="reqExpiry">Checkout window (minutes)</label>
        <input class="form-control" id="reqExpiry" name="expires_minutes" type="number" min="5" max="1440" placeholder="30">
        <p class="cp-note mt-1 mb-0">Between 5 and 1440 minutes. Defaults to 30.</p>
      </div>
    </div>
    <div class="cp-form__foot">
      <p class="cp-note mb-0">The checkout link is the buyer's access. Share it only with them.</p>
      <button class="btn cp-btn cp-btn--navy" type="submit"><i class="bi bi-plus-lg"></i> Create request</button>
    </div>
  </form>
</section>
