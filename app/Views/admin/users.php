<section class="cp-page">
  <div class="cp-page__head">
    <div>
      <h2 class="cp-page__title">Users and Administrators</h2>
      <p class="cp-page__sub">Who can open this dashboard. Only active administrators can sign in.</p>
    </div>
    <span class="cp-chip cp-chip--info"><?= count($admins) ?> account<?= count($admins) === 1 ? '' : 's' ?></span>
  </div>

  <div class="cp-card">
    <div class="cp-card__head">
      <h3 class="cp-card__title">Administrator accounts</h3>
      <span class="cp-chip cp-chip--muted"><?= (int) $activeSuperadmins ?> active superadmin<?= (int) $activeSuperadmins === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
      <table class="table cp-table align-middle">
        <thead>
          <tr>
            <th scope="col">Administrator</th>
            <th scope="col">Role</th>
            <th scope="col">Status</th>
            <th scope="col">Last sign-in (UTC)</th>
            <th scope="col" class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($admins as $row): ?>
            <tr>
              <td>
                <div class="cp-table__person">
                  <span class="cp-avatar cp-avatar--sm"><?= e(mb_strtoupper(mb_substr($row['name'], 0, 1))) ?></span>
                  <div>
                    <strong><?= e($row['name']) ?></strong>
                    <span class="cp-muted"><?= e($row['email']) ?></span>
                  </div>
                </div>
              </td>
              <td>
                <span class="cp-chip <?= $row['role'] === 'superadmin' ? 'cp-chip--info' : 'cp-chip--muted' ?>">
                  <?= e($row['role'] === 'superadmin' ? 'Superadmin' : 'Admin') ?>
                </span>
              </td>
              <td><?= status_chip($row['status']) ?></td>
              <td class="cp-muted"><?= e($row['last_login_at'] ?? 'Never') ?></td>
              <td class="text-end">
                <?php if ((int) $row['id'] === $currentId): ?>
                  <span class="cp-chip cp-chip--muted">You</span>
                <?php else: ?>
                  <form method="post" action="<?= e(url('/admin/users/' . (int) $row['id'] . '/status')) ?>" class="d-inline">
                    <?= App\Core\Csrf::field() ?>
                    <button class="btn btn-sm cp-btn cp-btn--outline-danger" type="submit">
                      <?= $row['status'] === 'disabled' ? 'Enable' : 'Disable' ?>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="cp-note">Disabling an account keeps its history but blocks sign-in immediately. The last active superadmin can never be disabled.</p>
  </div>

  <div class="cp-card cp-card--spaced">
    <div class="cp-card__head">
      <h3 class="cp-card__title">Add an administrator</h3>
      <span class="cp-chip cp-chip--muted">Superadmin only</span>
    </div>
    <form method="post" action="<?= e(url('/admin/users')) ?>" class="cp-form" novalidate>
      <?= App\Core\Csrf::field() ?>
      <div class="cp-form__grid">
        <div>
          <label class="form-label" for="adminName">Full name</label>
          <input class="form-control" id="adminName" name="name" type="text" maxlength="100" required value="<?= e($oldForm['name']) ?>">
        </div>
        <div>
          <label class="form-label" for="adminEmail">Email</label>
          <input class="form-control" id="adminEmail" name="email" type="email" maxlength="150" required value="<?= e($oldForm['email']) ?>">
        </div>
        <div>
          <label class="form-label" for="adminRole">Role</label>
          <select class="form-select" id="adminRole" name="role">
            <option value="admin" <?= $oldForm['role'] === 'admin' ? 'selected' : '' ?>>Admin — day-to-day operations</option>
            <option value="superadmin" <?= $oldForm['role'] === 'superadmin' ? 'selected' : '' ?>>Superadmin — credentials, settings, administrators</option>
          </select>
        </div>
        <div>
          <label class="form-label" for="adminPassword">Password</label>
          <input class="form-control" id="adminPassword" name="password" type="password" minlength="12" autocomplete="new-password" required>
        </div>
        <div>
          <label class="form-label" for="adminPasswordConfirm">Confirm password</label>
          <input class="form-control" id="adminPasswordConfirm" name="password_confirm" type="password" minlength="12" autocomplete="new-password" required>
        </div>
      </div>
      <div class="cp-form__foot">
        <p class="cp-note mb-0">Minimum 12 characters. Passwords are stored with bcrypt hashes, never in plaintext.</p>
        <button class="btn cp-btn cp-btn--navy" type="submit"><i class="bi bi-person-plus"></i> Create administrator</button>
      </div>
    </form>
  </div>
</section>
