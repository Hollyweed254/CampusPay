<section class="cp-legal">
  <div class="container cp-legal__wrap">
    <div class="cp-legal__head">
      <span class="cp-eyebrow">Terms</span>
      <h1>Terms of use</h1>
      <p class="cp-muted">Last updated 24 November 2026. These terms cover use of this CampusPay deployment.</p>
    </div>

    <div class="cp-legal__body">
      <h2>What CampusPay is</h2>
      <p>CampusPay is an independent platform that helps integrating applications create, display and verify M-Pesa payment requests. It is not a bank, a wallet, an escrow service or a party to the sale of goods between buyers and sellers.</p>

      <h2>Independent service</h2>
      <p>M-Pesa is a trademark of Safaricom PLC. CampusPay is not affiliated with, certified by or endorsed by Safaricom. M-Pesa payments are subject to Safaricom's own terms. Collection requires an approved merchant arrangement for the deployment's shortcode.</p>

      <h2>Payment status</h2>
      <p>A payment is only successful when a validated provider response confirms it. A pending prompt, a browser redirect or a message shown by an integrating application is not proof of payment. CampusPay records failed, cancelled and expired attempts alongside successful ones.</p>

      <h2>Cash on meeting</h2>
      <p>Where an integrating application enables cash on meeting, CampusPay records the agreed amount and reference only. No cash moves through CampusPay, and no escrow protection is provided. The parties settle in person.</p>

      <h2>Buyer responsibilities</h2>
      <ul>
        <li>Use a phone number you are authorised to pay from.</li>
        <li>Approve the payment only for the amount shown on the checkout.</li>
        <li>Enter your PIN only in the Safaricom prompt on your own phone.</li>
      </ul>

      <h2>Integration responsibilities</h2>
      <p>Integrating applications must keep their API credentials secret, sign their requests, treat webhooks as notifications rather than proof, and verify status through the API before releasing goods or services.</p>

      <h2>Availability and liability</h2>
      <p>The platform is provided as-is for this deployment. Provider outages, network failures and incorrect phone numbers can prevent a payment. To the extent permitted by law, the operator is not liable for indirect losses arising from a failed or delayed payment; the recorded transaction and audit trail are the reference for resolving disputes.</p>

      <h2>Disputes</h2>
      <p>Disputes about a transaction are handled by the administrators of this deployment using the recorded attempts, provider callbacks and receipts. Marketplace disputes about the goods themselves remain between the buyer and the seller.</p>

      <h2>Changes</h2>
      <p>These terms may be updated as the platform evolves. The update date at the top of this page reflects the current version.</p>

      <h2>Contact</h2>
      <p>Questions about these terms: <a href="mailto:admin@campuspay.local">admin@campuspay.local</a>.</p>
    </div>

    <a class="cp-link" href="<?= e(url('/')) ?>"><i class="bi bi-arrow-left"></i> Back to CampusPay</a>
  </div>
</section>
