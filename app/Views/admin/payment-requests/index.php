<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <h2 class="cp-page__title">Payment Requests</h2>
      <p class="cp-page__sub">Every request created through the API, this dashboard or a checkout link. Status comes from the provider, never the browser.</p>
    </div>
    <a class="btn cp-btn cp-btn--navy" href="<?= e(url('/admin/payment-requests/create')) ?>">
      <i class="bi bi-plus-lg"></i> New request
    </a>
  </div>

  <?php
  $path = '/admin/payment-requests';
  $searchPlaceholder = 'Reference, description, buyer';
  require BASE_PATH . '/app/Views/partials/filters.php';
  ?>

  <div class="cp-card">
    <div class="table-responsive">
      <table class="table cp-table align-middle">
        <thead>
          <tr>
            <th scope="col">Reference</th>
            <th scope="col">Buyer</th>
            <th scope="col">Amount</th>
            <th scope="col">Method</th>
            <th scope="col">Status</th>
            <th scope="col">Created (UTC)</th>
            <th scope="col" class="text-end">Open</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($requests === []): ?>
            <tr>
              <td colspan="7">
                <div class="cp-empty cp-empty--compact">
                  <i class="bi bi-inboxes"></i>
                  <p>No payment requests match these filters.</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
          <?php foreach ($requests as $row): ?>
            <tr>
              <td>
                <a class="cp-table__ref" href="<?= e(url('/admin/payment-requests/' . $row['reference'])) ?>"><?= e($row['reference']) ?></a>
                <span class="cp-table__sub"><?= e($row['description']) ?></span>
              </td>
              <td>
                <strong class="cp-table__buyer"><?= e($row['buyer_name'] ?? '—') ?></strong>
                <span class="cp-table__sub"><?= e($row['application_name']) ?><?= $row['buyer_phone'] ? ' · ' . e(mask_phone($row['buyer_phone'])) : '' ?></span>
              </td>
              <td class="cp-nowrap"><?= e(money_ksh($row['amount'])) ?></td>
              <td><span class="cp-chip cp-chip--info"><?= e(payment_method_label($row['payment_method'])) ?></span></td>
              <td><?= status_chip($row['status']) ?></td>
              <td class="cp-muted cp-nowrap"><?= e($row['created_at']) ?></td>
              <td class="text-end">
                <a class="btn btn-sm cp-btn cp-btn--ghost" href="<?= e(url('/admin/payment-requests/' . $row['reference'])) ?>">
                  View <i class="bi bi-arrow-right"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php require BASE_PATH . '/app/Views/partials/pagination.php'; ?>
  </div>
</section>
