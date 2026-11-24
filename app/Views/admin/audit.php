<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <h2 class="cp-page__title">Audit Logs</h2>
      <p class="cp-page__sub">Every sign-in attempt and privileged action, append-only. Showing the latest 30 of <?= (int) $total ?>.</p>
    </div>
  </div>

  <div class="cp-card">
    <?php if ($entries === []): ?>
      <p class="cp-muted mb-0">No audit entries yet.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table cp-table align-middle">
          <thead>
            <tr>
              <th scope="col">Time (UTC)</th>
              <th scope="col">Actor</th>
              <th scope="col">Action</th>
              <th scope="col">Target</th>
              <th scope="col">IP</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($entries as $entry): ?>
              <tr>
                <td class="cp-muted"><?= e($entry['created_at']) ?></td>
                <td>
                  <span class="cp-chip <?= $entry['actor_type'] === 'administrator' ? 'cp-chip--info' : 'cp-chip--muted' ?>">
                    <?= e($entry['actor_type']) ?><?= $entry['actor_id'] !== null ? ' #' . (int) $entry['actor_id'] : '' ?>
                  </span>
                </td>
                <td><code><?= e($entry['action']) ?></code></td>
                <td class="cp-muted">
                  <?= e($entry['target_type'] ?? '—') ?><?= $entry['target_id'] !== null ? ' / ' . e($entry['target_id']) : '' ?>
                </td>
                <td class="cp-muted"><?= e($entry['ip'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
