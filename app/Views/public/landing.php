<section class="cp-hero">
  <div class="container">
    <div class="cp-hero__grid">
      <div class="cp-hero__copy">
        <span class="cp-eyebrow"><i class="bi bi-shield-check"></i> Payments for campus marketplaces</span>
        <h1>Collect M&#8209;Pesa payments you can actually verify.</h1>
        <p class="cp-hero__lead">
          CampusPay gives campus marketplaces a checkout that records every attempt, waits for the
          provider's confirmation, and only then calls a payment successful.
        </p>
        <div class="cp-hero__actions">
          <a class="btn cp-btn cp-btn--navy btn-lg" href="#how-it-works">See how it works</a>
          <a class="btn cp-btn cp-btn--outline btn-lg" href="<?= e(url('/login')) ?>">Administrator sign in</a>
        </div>
        <ul class="cp-hero__points">
          <li><i class="bi bi-check2-circle"></i> Provider-confirmed payments only</li>
          <li><i class="bi bi-check2-circle"></i> PINs and OTPs are never requested</li>
          <li><i class="bi bi-check2-circle"></i> Signed API and webhooks</li>
        </ul>
      </div>
      <div class="cp-hero__media">
        <div class="cp-photo-frame cp-photo-frame--hero">
          <picture>
            <source srcset="<?= e(url('/assets/images/hero-campus.webp')) ?>" type="image/webp">
            <img class="cp-photo" src="<?= e(url('/assets/images/hero-campus.jpg')) ?>"
                 width="1600" height="914" alt="University students walking through campus, one checking a payment on a phone" fetchpriority="high">
          </picture>
          <div class="cp-photo-fallback" hidden>
            <i class="bi bi-people"></i>
            <span>Campus life, verified payments.</span>
          </div>
          <div class="cp-hero__badge">
            <span class="cp-hero__badge-icon"><i class="bi bi-patch-check"></i></span>
            <div>
              <strong>Verified before it counts</strong>
              <span>A provider response confirms every successful payment.</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="cp-section cp-section--tint" id="how-it-works">
  <div class="container">
    <div class="cp-section__head">
      <span class="cp-eyebrow">How it works</span>
      <h2>Three steps, no guesswork about money.</h2>
      <p>Integrating applications create a request; the buyer pays; CampusPay records the provider's answer and tells the application what happened.</p>
    </div>
    <div class="cp-steps">
      <article class="cp-step">
        <span class="cp-step__num">1</span>
        <span class="cp-step__icon"><i class="bi bi-code-slash"></i></span>
        <h3>Your app creates a request</h3>
        <p>The application sends the description, amount and buyer phone. CampusPay returns a reference and a checkout link.</p>
      </article>
      <article class="cp-step">
        <span class="cp-step__num">2</span>
        <span class="cp-step__icon"><i class="bi bi-phone"></i></span>
        <h3>The buyer pays on their phone</h3>
        <p>The checkout asks for the buyer's number and starts an STK Push. The PIN is entered only in the Safaricom prompt on the buyer's own phone.</p>
      </article>
      <article class="cp-step">
        <span class="cp-step__num">3</span>
        <span class="cp-step__icon"><i class="bi bi-arrow-repeat"></i></span>
        <h3>CampusPay verifies and notifies</h3>
        <p>Only a validated provider callback or status query confirms the payment. The receipt is stored and the app receives a signed webhook.</p>
      </article>
    </div>
  </div>
</section>

<section class="cp-section" id="security">
  <div class="container">
    <div class="cp-security">
      <div class="cp-security__media">
        <div class="cp-photo-frame cp-photo-frame--portrait">
          <picture>
            <source srcset="<?= e(url('/assets/images/handover-campus.webp')) ?>" type="image/webp">
            <img class="cp-photo" src="<?= e(url('/assets/images/handover-campus.jpg')) ?>"
                 width="1200" height="800" alt="Two students completing a campus handover, one showing a payment confirmation on a phone" loading="lazy">
          </picture>
          <div class="cp-photo-fallback" hidden>
            <i class="bi bi-bag-check"></i>
            <span>Campus handovers, recorded properly.</span>
          </div>
        </div>
      </div>
      <div class="cp-security__copy">
        <span class="cp-eyebrow">Security and honesty</span>
        <h2>Financial records come before looks.</h2>
        <p>CampusPay is built around one rule: a payment is only successful when the provider says so. Everything else is an attempt, and attempts are recorded honestly.</p>
        <ul class="cp-checks">
          <li>
            <span class="cp-checks__icon"><i class="bi bi-patch-check"></i></span>
            <div>
              <strong>Provider-confirmed only</strong>
              <span>A browser redirect or a success message cannot mark a payment as paid.</span>
            </div>
          </li>
          <li>
            <span class="cp-checks__icon"><i class="bi bi-shield-lock"></i></span>
            <div>
              <strong>No PINs, no OTPs</strong>
              <span>CampusPay never asks for or stores an M-Pesa PIN or one-time code.</span>
            </div>
          </li>
          <li>
            <span class="cp-checks__icon"><i class="bi bi-journal-check"></i></span>
            <div>
              <strong>Append-only trail</strong>
              <span>Attempts, provider callbacks and administrative actions are kept as an audit record.</span>
            </div>
          </li>
          <li>
            <span class="cp-checks__icon"><i class="bi bi-key"></i></span>
            <div>
              <strong>Signed API and webhooks</strong>
              <span>HMAC request signing, timestamp checks and replay protection protect the integration channel.</span>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </div>
</section>

<section class="cp-section cp-section--tint" id="methods">
  <div class="container">
    <div class="cp-section__head">
      <span class="cp-eyebrow">Supported payments</span>
      <h2>Two methods, clearly separated.</h2>
      <p>CampusPay processes M-Pesa collections. Cash on meeting is recorded for marketplaces that explicitly enable it — it is not an M-Pesa payment and CampusPay does not hold the money.</p>
    </div>
    <div class="cp-methods">
      <article class="cp-method">
        <div class="cp-method__head">
          <span class="cp-method__icon cp-method__icon--green"><i class="bi bi-phone"></i></span>
          <div>
            <h3>M-Pesa</h3>
            <span class="cp-chip cp-chip--ok">Provider-verified</span>
          </div>
        </div>
        <p>The buyer approves a payment prompt from Safaricom on their phone. CampusPay records the attempt, waits for the provider confirmation, stores the M-Pesa receipt and reports the result.</p>
        <ul class="cp-method__list">
          <li><i class="bi bi-check2"></i> STK Push to a validated Kenyan number</li>
          <li><i class="bi bi-check2"></i> Status query and reconciliation</li>
          <li><i class="bi bi-check2"></i> Automatic expiry of unanswered requests</li>
        </ul>
        <p class="cp-method__note">Requires an approved Safaricom merchant arrangement for the deployment's shortcode.</p>
      </article>
      <article class="cp-method">
        <div class="cp-method__head">
          <span class="cp-method__icon cp-method__icon--navy"><i class="bi bi-people"></i></span>
          <div>
            <h3>Cash on meeting</h3>
            <span class="cp-chip cp-chip--info">Recorded only</span>
          </div>
        </div>
        <p>For face-to-face handovers, an integrating marketplace can offer cash on meeting. CampusPay records the agreed amount and reference — no money moves through the platform.</p>
        <ul class="cp-method__list">
          <li><i class="bi bi-check2"></i> Enabled per application, off by default</li>
          <li><i class="bi bi-check2"></i> Never labelled as verified M-Pesa money</li>
          <li><i class="bi bi-check2"></i> Useful evidence for disputes</li>
        </ul>
        <p class="cp-method__note">Cash on meeting is not escrow. Neither party is protected by CampusPay holding funds.</p>
      </article>
    </div>
  </div>
</section>

<section class="cp-section" id="integrations">
  <div class="container">
    <div class="cp-integration">
      <div class="cp-integration__copy">
        <span class="cp-eyebrow cp-eyebrow--light">For developers</span>
        <h2>One API, clear outcomes.</h2>
        <p>Create a payment request, send the buyer to checkout, then listen for a signed webhook. Status is always queryable, and the API never trusts a status supplied by your application.</p>
        <ul class="cp-integration__list">
          <li><i class="bi bi-check2-circle"></i> Key-based authentication with request signing</li>
          <li><i class="bi bi-check2-circle"></i> Idempotency keys for safe retries</li>
          <li><i class="bi bi-check2-circle"></i> Signed delivery events with retry history</li>
        </ul>
        <a class="btn cp-btn cp-btn--green" href="<?= e(url('/login')) ?>">Open the administrator dashboard</a>
      </div>
      <div class="cp-integration__code" aria-label="Example payment request">
        <div class="cp-code__bar"><span></span><span></span><span></span><em>payment request</em></div>
        <pre><code>POST /api/v1/payment-requests
X-CP-Key: cp_…
X-CP-Timestamp: 1763…
X-CP-Nonce: 9f2c…
X-CP-Signature: hmac-sha256(…)
Idempotency-Key: order-1042

{
  "description": "Dell XPS 13 — campus handover",
  "amount": "42500.00",
  "buyerPhone": "0712 345 678"
}</code></pre>
        <div class="cp-code__result">
          <i class="bi bi-arrow-return-right"></i>
          <span>reference <code>CP-…</code> · checkout link · pending status</span>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="cp-section cp-section--tint" id="faq">
  <div class="container">
    <div class="cp-section__head">
      <span class="cp-eyebrow">Questions</span>
      <h2>Straight answers.</h2>
    </div>
    <div class="accordion cp-faq" id="cpFaq">
      <div class="accordion-item">
        <h3 class="accordion-header">
          <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1" aria-expanded="true" aria-controls="faq1">
            What is CampusPay?
          </button>
        </h3>
        <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#cpFaq">
          <div class="accordion-body">CampusPay is an independent payment platform that campus marketplaces use to collect M-Pesa payments and track exactly what happened. It has its own database, dashboard and API.</div>
        </div>
      </div>
      <div class="accordion-item">
        <h3 class="accordion-header">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2" aria-expanded="false" aria-controls="faq2">
            Does CampusPay hold my money?
          </button>
        </h3>
        <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#cpFaq">
          <div class="accordion-body">No. M-Pesa payments settle to the merchant's approved Safaricom shortcode for the deployment. CampusPay records and verifies the payment; it is not a wallet, an escrow service or a savings product.</div>
        </div>
      </div>
      <div class="accordion-item">
        <h3 class="accordion-header">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3" aria-expanded="false" aria-controls="faq3">
            How do I complete a payment?
          </button>
        </h3>
        <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#cpFaq">
          <div class="accordion-body">Enter your phone number on the checkout, then approve the payment prompt that Safaricom sends to your phone. Type your PIN only in that prompt. CampusPay never asks for your PIN or an OTP.</div>
        </div>
      </div>
      <div class="accordion-item">
        <h3 class="accordion-header">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4" aria-expanded="false" aria-controls="faq4">
            Can I pay cash on meeting?
          </button>
        </h3>
        <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#cpFaq">
          <div class="accordion-body">Only when the marketplace enables it. CampusPay records the agreed amount and reference for both sides, but the cash changes hands in person and is never presented as a verified M-Pesa payment.</div>
        </div>
      </div>
      <div class="accordion-item">
        <h3 class="accordion-header">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5" aria-expanded="false" aria-controls="faq5">
            Is CampusPay affiliated with Safaricom?
          </button>
        </h3>
        <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#cpFaq">
          <div class="accordion-body">No. CampusPay is an independent platform that integrates with the Safaricom Daraja API. M-Pesa is a trademark of Safaricom PLC, and CampusPay is not certified or endorsed by Safaricom.</div>
        </div>
      </div>
      <div class="accordion-item">
        <h3 class="accordion-header">
          <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq6" aria-expanded="false" aria-controls="faq6">
            What happens when a payment fails?
          </button>
        </h3>
        <div id="faq6" class="accordion-collapse collapse" data-bs-parent="#cpFaq">
          <div class="accordion-body">The attempt is recorded with the provider's description. If the request is still open you can retry; cancelled, expired and failed requests stay in the record instead of disappearing.</div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="cp-cta">
  <div class="container cp-cta__inner">
    <div>
      <h2>Ready when your integration is.</h2>
      <p>Administrators can review every request, attempt and callback from one dashboard.</p>
    </div>
    <a class="btn cp-btn cp-btn--navy btn-lg" href="<?= e(url('/login')) ?>">Sign in <i class="bi bi-arrow-right"></i></a>
  </div>
</section>
