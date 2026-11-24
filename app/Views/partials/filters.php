<?php
$filters = $filters ?? [];
$path = $path ?? '/admin';
?>
<form method="get" action="<?= e(url($path)) ?>" class="cp-filters">
  <div class="cp-filters__field cp-filters__field--grow">
    <label class="form-label" for="cpFilterQ">Search</label>
    <input class="form-control form-control-sm" id="cpFilterQ" name="q" type="search" value="<?= e($filters['q'] ?? '') ?>" placeholder="<?= e($searchPlaceholder ?? 'Reference, description, buyer') ?>">
  </div>
  <?php if (!empty($statuses)): ?>
    <div class="cp-filters__field">
      <label class="form-label" for="cpFilterStatus">Status</label>
      <select class="form-select form-select-sm" id="cpFilterStatus" name="status">
        <option value="">All statuses</option>
        <?php foreach ($statuses as $status): ?>
          <?php [$label] = status_meta($status); ?>
          <option value="<?= e($status) ?>" <?= ($filters['status'] ?? '') === $status ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>
  <?php if (!empty($applications)): ?>
    <div class="cp-filters__field">
      <label class="form-label" for="cpFilterApp">Application</label>
      <select class="form-select form-select-sm" id="cpFilterApp" name="app">
        <option value="">All applications</option>
        <?php foreach ($applications as $application): ?>
          <option value="<?= (int) $application['id'] ?>" <?= (string) ($filters['app'] ?? '') === (string) $application['id'] ? 'selected' : '' ?>>
            <?= e($application['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>
  <div class="cp-filters__field">
    <label class="form-label" for="cpFilterFrom">From (UTC)</label>
    <input class="form-control form-control-sm" id="cpFilterFrom" name="from" type="date" value="<?= e($filters['from'] ?? '') ?>">
  </div>
  <div class="cp-filters__field">
    <label class="form-label" for="cpFilterTo">To (UTC)</label>
    <input class="form-control form-control-sm" id="cpFilterTo" name="to" type="date" value="<?= e($filters['to'] ?? '') ?>">
  </div>
  <div class="cp-filters__actions">
    <button class="btn btn-sm cp-btn cp-btn--navy" type="submit"><i class="bi bi-funnel"></i> Apply</button>
    <a class="btn btn-sm cp-btn cp-btn--ghost" href="<?= e(url($path)) ?>">Clear</a>
  </div>
</form>
