<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <h2 class="cp-page__title">Integrations</h2>
      <p class="cp-page__sub">Applications allowed to create payment requests. Each one controls its own settlement model and webhook target.</p>
    </div>
    <a class="btn cp-btn cp-btn--navy" href="<?= e(url('/admin/integrations/create')) ?>">
      <i class="bi bi-plus-lg"></i> New integration
    </a>
  </div>

  <div class="cp-card">
    <?php if ($applications === []): ?>
      <div class="cp-empty">
        <i class="bi bi-diagram-3"></i>
        <h3>No integrations yet</h3>
        <p>Create one to allow an application to request payments. API credentials are added in the API stage.</p>
        <a class="btn cp-btn cp-btn--navy" href="<?= e(url('/admin/integrations/create')) ?>">Create integration</a>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table cp-table align-middle">
          <thead>
            <tr>
              <th scope="col">Application</th>
              <th scope="col">Settlement model</th>
              <th scope="col">Cash on meeting</th>
              <th scope="col">Status</th>
              <th scope="col">Requests</th>
              <th scope="col">Last request (UTC)</th>
              <th scope="col" class="text-end">Open</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($applications as $application): ?>
              <tr>
                <td>
                  <a class="cp-table__ref" href="<?= e(url('/admin/integrations/' . (int) $application['id'])) ?>"><?= e($application['name']) ?></a>
                  <span class="cp-table__sub"><code class="cp-code"><?= e($application['slug']) ?></code></span>
                </td>
                <td>
                  <span class="cp-chip <?= $application['settlement_model'] === 'merchant_collection' ? 'cp-chip--ok' : ($application['settlement_model'] === 'undecided' ? 'cp-chip--warn' : 'cp-chip--info') ?>">
                    <?= e(ucfirst(str_replace('_', ' ', $application['settlement_model']))) ?>
                  </span>
                </td>
                <td><?= (int) $application['cash_on_meeting_enabled'] === 1 ? 'Enabled' : '<span class="cp-muted">Off</span>' ?></td>
                <td><?= status_chip($application['status']) ?></td>
                <td>
                  <?= (int) $application['request_count'] ?>
                  <span class="cp-table__sub"><?= (int) $application['succeeded_count'] ?> successful · <?= (int) $application['credential_count'] ?> active key<?= (int) $application['credential_count'] === 1 ? '' : 's' ?></span>
                </td>
                <td class="cp-muted cp-nowrap"><?= e($application['last_request_at'] ?? '—') ?></td>
                <td class="text-end">
                  <a class="btn btn-sm cp-btn cp-btn--ghost" href="<?= e(url('/admin/integrations/' . (int) $application['id'])) ?>">
                    Manage <i class="bi bi-arrow-right"></i>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
