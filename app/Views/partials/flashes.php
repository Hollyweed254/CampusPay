<?php foreach (flashes($flashScope ?? 'admin') as $flash): ?>
  <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show alert-autodismiss cp-flash" role="alert">
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endforeach; ?>
