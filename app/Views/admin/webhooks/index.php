<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <h2 class="cp-page__title">Webhook Events</h2>
      <p class="cp-page__sub">Every signed delivery to an integrating application, with retry history. Events are queued when M-Pesa confirms or fails a payment and are signed with the application's newest active API key.</p>
    </div>
  </div>

  <div class="cp-metrics">
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--amber"><i class="bi bi-hourglass-split"></i></span>
      <div>
        <div class="cp-metric__label">Awaiting delivery</div>
        <div class="cp-metric__value"><?= (int) $metrics['awaiting'] ?></div>
      </div>
    </div>
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--green"><i class="bi bi-check2-circle"></i></span>
      <div>
        <div class="cp-metric__label">Delivered today</div>
        <div class="cp-metric__value"><?= (int) $metrics['delivered_today'] ?></div>
      </div>
    </div>
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--red"><i class="bi bi-x-octagon"></i></span>
      <div>
        <div class="cp-metric__label">Exhausted (7 days)</div>
        <div class="cp-metric__value"><?= (int) $metrics['exhausted_7d'] ?></div>
      </div>
    </div>
    <div class="cp-metric">
      <span class="cp-metric__icon cp-metric__icon--navy"><i class="bi bi-clock-history"></i></span>
      <div>
        <div class="cp-metric__label">Last delivery (UTC)</div>
        <div class="cp-metric__value cp-metric__value--sm"><?= e($metrics['last_delivery'] ?? 'Never') ?></div>
      </div>
    </div>
  </div>

  <div class="cp-card cp-card--spaced">
    <div class="cp-card__head">
      <h3 class="cp-card__title">Delivery log</h3>
      <span class="cp-chip cp-chip--muted"><?= (int) $pager['total'] ?> event(s)</span>
    </div>

    <form method="get" action="<?= e(url('/admin/webhook-events')) ?>" class="cp-filters">
      <div class="cp-filters__field">
        <label class="form-label" for="cpHookApp">Integration</label>
        <select class="form-select form-select-sm" id="cpHookApp" name="app">
          <option value="">All integrations</option>
          <?php foreach ($applications as $application): ?>
            <option value="<?= (int) $application['id'] ?>" <?= $filters['app'] === (string) $application['id'] ? 'selected' : '' ?>><?= e($application['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cp-filters__field">
        <label class="form-label" for="cpHookEvent">Event</label>
        <select class="form-select form-select-sm" id="cpHookEvent" name="event">
          <option value="">All events</option>
          <option value="payment.confirmed" <?= $filters['event'] === 'payment.confirmed' ? 'selected' : '' ?>>payment.confirmed</option>
          <option value="payment.failed" <?= $filters['event'] === 'payment.failed' ? 'selected' : '' ?>>payment.failed</option>
        </select>
      </div>
      <div class="cp-filters__field">
        <label class="form-label" for="cpHookStatus">Status</label>
        <select class="form-select form-select-sm" id="cpHookStatus" name="status">
          <option value="">All statuses</option>
          <?php foreach ($statuses as $value): ?>
            <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e(status_meta($value)[0]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cp-filters__actions">
        <button class="btn btn-sm cp-btn cp-btn--navy" type="submit"><i class="bi bi-funnel"></i> Apply</button>
        <a class="btn btn-sm cp-btn cp-btn--ghost" href="<?= e(url('/admin/webhook-events')) ?>">Clear</a>
      </div>
    </form>

    <?php if ($deliveries === []): ?>
      <div class="cp-empty cp-empty--compact">
        <i class="bi bi-broadcast"></i>
        <p>No webhook events match these filters. A payment must be confirmed or failed by M-Pesa, and the integration needs a webhook URL and an active API key.</p>
      </div>
    <?php else: ?>
      <div class="cp-hook-cards d-md-none">
        <?php foreach ($deliveries as $delivery): ?>
          <?php $payload = json_decode((string) $delivery['payload'], true); ?>
          <div class="cp-hook-card">
            <div class="cp-hook-card__top">
              <code class="cp-code"><?= e($delivery['event']) ?></code>
              <?= status_chip($delivery['status']) ?>
            </div>
            <div class="cp-hook-card__meta">
              <?= e($delivery['application_name']) ?>
              <?php if ($delivery['request_reference'] !== null): ?>
                · <a class="cp-table__ref" href="<?= e(url('/admin/payment-requests/' . $delivery['request_reference'])) ?>"><?= e($delivery['request_reference']) ?></a>
              <?php endif; ?>
            </div>
            <div class="cp-hook-card__meta">
              Queued <?= e($delivery['created_at']) ?> · <?= (int) $delivery['attempts'] ?> attempt(s)
              <?php if ($delivery['last_response_code'] !== null): ?>
                · HTTP <?= (int) $delivery['last_response_code'] ?>
              <?php elseif ($delivery['last_error'] !== null): ?>
                · <?= e($delivery['last_error']) ?>
              <?php endif; ?>
            </div>
            <?php if ($delivery['next_retry_at'] !== null): ?>
              <div class="cp-hook-card__meta">Next retry <?= e($delivery['next_retry_at']) ?> UTC</div>
            <?php endif; ?>
            <div class="cp-hook-card__actions">
              <?php if ($payload !== null): ?>
                <button class="btn btn-sm cp-btn cp-btn--ghost" type="button" data-bs-toggle="collapse" data-bs-target="#hookPayloadM<?= (int) $delivery['id'] ?>" aria-expanded="false" aria-controls="hookPayloadM<?= (int) $delivery['id'] ?>">
                  <i class="bi bi-code-slash"></i> Payload
                </button>
              <?php endif; ?>
              <form method="post" action="<?= e(url('/admin/webhook-events/' . (int) $delivery['id'] . '/resend')) ?>" data-confirm="Deliver this event again to <?= e($delivery['application_name']) ?> now?">
                <?= App\Core\Csrf::field() ?>
                <button class="btn btn-sm cp-btn cp-btn--outline" type="submit"><i class="bi bi-arrow-clockwise"></i> Resend</button>
              </form>
            </div>
            <?php if ($payload !== null): ?>
              <div class="collapse" id="hookPayloadM<?= (int) $delivery['id'] ?>">
                <pre class="cp-code-block mt-2"><?= e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="table-responsive d-none d-md-block">
        <table class="table cp-table align-middle cp-hook-table">
          <thead>
            <tr>
              <th scope="col">Queued (UTC)</th>
              <th scope="col">Event</th>
              <th scope="col">Integration</th>
              <th scope="col">Status</th>
              <th scope="col">Delivery</th>
              <th scope="col" class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($deliveries as $delivery): ?>
              <?php $payload = json_decode((string) $delivery['payload'], true); ?>
              <tr>
                <td class="cp-muted cp-nowrap"><?= e($delivery['created_at']) ?></td>
                <td>
                  <code class="cp-code"><?= e($delivery['event']) ?></code>
                  <span class="cp-table__sub">
                    <?php if ($delivery['request_reference'] !== null): ?>
                      <a class="cp-table__ref" href="<?= e(url('/admin/payment-requests/' . $delivery['request_reference'])) ?>"><?= e($delivery['request_reference']) ?></a>
                    <?php else: ?>
                      <?= e((string) ($payload['id'] ?? '')) ?>
                    <?php endif; ?>
                  </span>
                </td>
                <td><?= e($delivery['application_name']) ?></td>
                <td><?= status_chip($delivery['status']) ?></td>
                <td>
                  <?= (int) $delivery['attempts'] ?> attempt(s)
                  <?php if ($delivery['last_response_code'] !== null): ?>
                    <span class="cp-table__sub">HTTP <?= (int) $delivery['last_response_code'] ?></span>
                  <?php elseif ($delivery['last_error'] !== null): ?>
                    <span class="cp-table__sub"><?= e($delivery['last_error']) ?></span>
                  <?php endif; ?>
                  <?php if ($delivery['next_retry_at'] !== null): ?>
                    <span class="cp-table__sub">Next <?= e($delivery['next_retry_at']) ?> UTC</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <div class="d-flex justify-content-end gap-2">
                    <?php if ($payload !== null): ?>
                      <button class="btn btn-sm cp-btn cp-btn--ghost" type="button" data-bs-toggle="collapse" data-bs-target="#hookPayload<?= (int) $delivery['id'] ?>" aria-expanded="false" aria-controls="hookPayload<?= (int) $delivery['id'] ?>">
                        <i class="bi bi-code-slash"></i> Payload
                      </button>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('/admin/webhook-events/' . (int) $delivery['id'] . '/resend')) ?>" class="d-inline" data-confirm="Deliver this event again to <?= e($delivery['application_name']) ?> now?">
                      <?= App\Core\Csrf::field() ?>
                      <button class="btn btn-sm cp-btn cp-btn--outline" type="submit"><i class="bi bi-arrow-clockwise"></i> Resend</button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php if ($payload !== null): ?>
                <tr class="collapse" id="hookPayload<?= (int) $delivery['id'] ?>">
                  <td colspan="6">
                    <pre class="cp-code-block"><?= e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                  </td>
                </tr>
              <?php endif; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?php
    $path = '/admin/webhook-events';
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
  </div>
</section>
