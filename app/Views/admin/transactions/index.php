<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <h2 class="cp-page__title">Transactions</h2>
      <p class="cp-page__sub">Provider-verified and cash-on-meeting records. Only a validated M-Pesa result moves a payment to "successful".</p>
    </div>
    <a class="btn cp-btn cp-btn--outline" href="<?= e(filter_url('/admin/transactions/export', $filters)) ?>">
      <i class="bi bi-download"></i> Export CSV
    </a>
  </div>

  <?php
  $path = '/admin/transactions';
  $searchPlaceholder = 'Reference, receipt';
  require BASE_PATH . '/app/Views/partials/filters.php';
  ?>

  <div class="cp-card">
    <div class="table-responsive">
      <table class="table cp-table align-middle">
        <thead>
          <tr>
            <th scope="col">Reference</th>
            <th scope="col">Application</th>
            <th scope="col">Amount</th>
            <th scope="col">Status</th>
            <th scope="col">Receipt</th>
            <th scope="col">Created (UTC)</th>
            <th scope="col" class="text-end">Open</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($transactions === []): ?>
            <tr>
              <td colspan="7">
                <div class="cp-empty cp-empty--compact">
                  <i class="bi bi-arrow-left-right"></i>
                  <p>No transactions match these filters.</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
          <?php foreach ($transactions as $row): ?>
            <tr>
              <td>
                <a class="cp-table__ref" href="<?= e(url('/admin/transactions/' . (int) $row['id'])) ?>"><?= e($row['reference']) ?></a>
                <span class="cp-table__sub"><?= e($row['description']) ?></span>
              </td>
              <td><?= e($row['application_name']) ?><span class="cp-table__sub"><?= e(payment_method_label($row['provider'])) ?></span></td>
              <td class="cp-nowrap"><?= e(money_ksh($row['amount'])) ?></td>
              <td><?= status_chip($row['status']) ?></td>
              <td><?= e($row['provider_receipt'] ?? '—') ?></td>
              <td class="cp-muted cp-nowrap"><?= e($row['created_at']) ?></td>
              <td class="text-end">
                <a class="btn btn-sm cp-btn cp-btn--ghost" href="<?= e(url('/admin/transactions/' . (int) $row['id'])) ?>">
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
