<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <h2 class="cp-page__title"><?= e($sectionTitle) ?></h2>
      <p class="cp-page__sub"><?= e($description) ?></p>
    </div>
    <span class="cp-chip cp-chip--warn">Scheduled for Stage <?= (int) $stage ?></span>
  </div>

  <div class="cp-card cp-empty">
    <span class="cp-empty__icon"><i class="bi bi-tools"></i></span>
    <h3>Not built yet</h3>
    <p class="cp-muted">
      This section arrives in Stage <?= (int) $stage ?>. It is deliberately empty —
      no placeholder numbers or fake records are shown anywhere in CampusPay.
    </p>
  </div>
</section>
