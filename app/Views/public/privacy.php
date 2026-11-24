<section class="cp-legal">
  <div class="container cp-legal__wrap">
    <div class="cp-legal__head">
      <span class="cp-eyebrow">Privacy</span>
      <h1>Privacy notice</h1>
      <p class="cp-muted">Last updated 24 November 2026. This notice describes what this CampusPay deployment stores and why.</p>
    </div>

    <div class="cp-legal__body">
      <h2>What we store</h2>
      <ul>
        <li>Payment request details: description, amount, reference, application, payment method and status.</li>
        <li>The buyer's name and phone number when supplied for a payment, because the provider requires them to start it.</li>
        <li>Provider responses needed to confirm a payment, including the M-Pesa receipt number when one is issued.</li>
        <li>Administrator account details (name, email, hashed password) and an audit trail of sign-ins and administrative actions.</li>
        <li>Technical request logs without credentials, PINs or card data.</li>
      </ul>

      <h2>What we never store</h2>
      <p>CampusPay never asks for or stores an M-Pesa PIN, an OTP or any provider password. If any page asks you for a PIN outside the Safaricom prompt on your own phone, stop and report it.</p>

      <h2>Why we process it</h2>
      <p>To initiate and verify payments, to keep financial records for the integrating application and administrators, to resolve disputes, to secure the service against abuse, and to meet record-keeping obligations that apply to the deployment.</p>

      <h2>Who receives data</h2>
      <ul>
        <li>Safaricom's Daraja API, to initiate and verify M-Pesa payments.</li>
        <li>The integrating application that created the payment request, through authenticated status responses and signed webhook events.</li>
        <li>Administrators of this deployment, for support, reconciliation and dispute handling.</li>
      </ul>
      <p>We do not sell personal data.</p>

      <h2>Retention</h2>
      <p>Financial records (requests, attempts, provider callbacks, reconciliation and audit logs) are retained as an append-only trail. Sign-in throttling records may be pruned. Data is not deleted merely because a payment failed; failed attempts are part of the record.</p>

      <h2>Cookies</h2>
      <p>Only a session cookie is used, for administrators. It is HttpOnly and SameSite=Lax. There is no advertising or analytics cookie in this application.</p>

      <h2>Your choices</h2>
      <p>You can ask the operator of this deployment about the payment records that reference you, and request correction of inaccurate contact details where the law allows. For a payment made through a marketplace, that marketplace is also responsible for its own copy of the transaction record.</p>

      <h2>Contact</h2>
      <p>Privacy questions: <a href="mailto:admin@campuspay.local">admin@campuspay.local</a>.</p>
    </div>

    <a class="cp-link" href="<?= e(url('/')) ?>"><i class="bi bi-arrow-left"></i> Back to CampusPay</a>
  </div>
</section>
