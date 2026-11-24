<?php
/** @var array $pager */
/** @var array $filters */
/** @var string $path */
?>
<nav class="cp-pagination" aria-label="Pagination">
  <span class="cp-pagination__summary">
    <?= number_format((int) $pager['total']) ?> record<?= (int) $pager['total'] === 1 ? '' : 's' ?>
    <?php if ((int) $pager['pages'] > 1): ?>
      · page <?= (int) $pager['page'] ?> of <?= (int) $pager['pages'] ?>
    <?php endif; ?>
  </span>
  <?php if ((int) $pager['pages'] > 1): ?>
    <div class="cp-pagination__links">
      <?php if ((int) $pager['page'] > 1): ?>
        <a class="cp-pagebtn" href="<?= e(filter_url($path, $filters, ['page' => (int) $pager['page'] - 1])) ?>" rel="prev">
          <i class="bi bi-chevron-left"></i> Previous
        </a>
      <?php else: ?>
        <span class="cp-pagebtn is-disabled"><i class="bi bi-chevron-left"></i> Previous</span>
      <?php endif; ?>
      <?php if ((int) $pager['page'] < (int) $pager['pages']): ?>
        <a class="cp-pagebtn" href="<?= e(filter_url($path, $filters, ['page' => (int) $pager['page'] + 1])) ?>" rel="next">
          Next <i class="bi bi-chevron-right"></i>
        </a>
      <?php else: ?>
        <span class="cp-pagebtn is-disabled">Next <i class="bi bi-chevron-right"></i></span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</nav>
