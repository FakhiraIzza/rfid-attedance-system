<?php $u=current_user(); $cfg=app_cfg(); ?>
<?php
  function sidebar_links($u, $active='') {
    ob_start();
?>
    <?php if(($u['role'] ?? '')==='admin'): ?>
      <div class="sidebar-section">Utama</div>
      <a class="sidebar-link <?= ($active ?? '')==='dashboard'?'active':'' ?>" href="<?= base_url('/dashboard.php') ?>">
        <span class="nav-dot"></span> Dashboard
      </a>

      <div class="sidebar-section">Master Data</div>
      <a class="sidebar-link <?= ($active ?? '')==='students'?'active':'' ?>" href="<?= base_url('/admin/students.php') ?>">
        <span class="nav-dot"></span> Data Siswa dan Kartu
      </a>
      <a class="sidebar-link <?= ($active ?? '')==='parents'?'active':'' ?>" href="<?= base_url('/admin/parents.php') ?>">
        <span class="nav-dot"></span> Kontak Orang Tua
      </a>
      <a class="sidebar-link <?= ($active ?? '')==='teachers'?'active':'' ?>" href="<?= base_url('/admin/teachers.php') ?>">
        <span class="nav-dot"></span> Data Guru
      </a>
      <a class="sidebar-link <?= ($active ?? '')==='cards'?'active':'' ?>" href="<?= base_url('/admin/cards.php') ?>">
        <span class="nav-dot"></span> Manajemen Kartu RFID
      </a>

      <div class="sidebar-section">Presensi</div>
      <a class="sidebar-link <?= ($active ?? '')==='attendance'?'active':'' ?>" href="<?= base_url('/admin/attendance.php') ?>">
        <span class="nav-dot"></span> Rekap Presensi
      </a>
      <a class="sidebar-link <?= ($active ?? '')==='message_templates'?'active':'' ?>" href="<?= base_url('/admin/message_templates.php') ?>">
        <span class="nav-dot"></span> Template Pesan
      </a>
      <a class="sidebar-link <?= ($active ?? '')==='settings'?'active':'' ?>" href="<?= base_url('/admin/settings.php') ?>">
        <span class="nav-dot"></span> Pengaturan Jam
      </a>
    <?php elseif(($u['role'] ?? '')==='guru'): ?>
      <div class="sidebar-section">Utama</div>
      <a class="sidebar-link <?= ($active ?? '')==='dashboard'?'active':'' ?>" href="<?= base_url('/dashboard.php') ?>">
        <span class="nav-dot"></span> Dashboard
      </a>
      <div class="sidebar-section">Master Data</div>
      <a class="sidebar-link <?= ($active ?? '')==='parents'?'active':'' ?>" href="<?= base_url('/guru/parents.php') ?>">
        <span class="nav-dot"></span> Data Anak & Kontak Ortu
      </a>
      <div class="sidebar-section">Presensi</div>
      <a class="sidebar-link <?= ($active ?? '')==='attendance'?'active':'' ?>" href="<?= base_url('/guru/attendance.php') ?>">
        <span class="nav-dot"></span> Presensi Kelas
      </a>
    <?php else: ?>
      <div class="sidebar-section">Utama</div>
      <a class="sidebar-link <?= ($active ?? '')==='dashboard'?'active':'' ?>" href="<?= base_url('/dashboard.php') ?>">
        <span class="nav-dot"></span> Dashboard Anak
      </a>
      <a class="sidebar-link <?= ($active ?? '')==='history'?'active':'' ?>" href="<?= base_url('/ortu/history.php') ?>">
        <span class="nav-dot"></span> Riwayat
      </a>
    <?php endif; ?>

    <div class="sidebar-divider"></div>
    <a class="sidebar-link" href="<?= base_url('/logout.php') ?>">
      <span class="nav-dot"></span> Logout
    </a>
<?php
    return ob_get_clean();
  }
?>


<!-- Mobile: Offcanvas sidebar -->
<div class="offcanvas offcanvas-start d-lg-none" tabindex="-1" id="sidebarOffcanvas" aria-labelledby="sidebarOffcanvasLabel">
  <div class="offcanvas-header" style="background:linear-gradient(180deg,#0B5ED7,#0A58CA);">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= base_url('/assets/img/logo.png') ?>"
        alt="Logo Sekolah"
        style="width:40px;height:40px;object-fit:contain;border-radius:8px;background:#fff;padding:4px;flex-shrink:0;">
      <div>
        <div class="text-white fw-semibold" id="sidebarOffcanvasLabel">Presensi RFID</div>
        <div class="text-white-50 small"><?= htmlspecialchars($cfg['school_name']) ?></div>
      </div>
    </div>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>

  <div class="offcanvas-body p-0 sidebar">
    <div class="p-3">
      <div class="text-white-50 small mb-2">Login sebagai</div>
      <div class="text-white fw-semibold"><?= htmlspecialchars($u['nama_lengkap'] ?? '') ?></div>
      <div class="text-white-50 small"><?= htmlspecialchars(strtoupper($u['role'] ?? '')) ?></div>
    </div>
    <div class="px-2 pb-3 sidebar-nav">
      <?= sidebar_links($u, $active ?? '') ?>
    </div>
  </div>
</div>


<!-- Desktop: fixed sidebar column -->
<div class="col-12 col-lg-3 col-xl-2 p-0 sidebar d-none d-lg-block">
  <div class="p-3">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= base_url('/assets/img/logo.png') ?>"
        alt="Logo Sekolah"
        style="width:40px;height:40px;object-fit:contain;border-radius:8px;background:#fff;padding:4px;flex-shrink:0;">

      <div>
        <div class="text-white fw-semibold">Presensi RFID</div>
        <div class="text-white-50 small"><?= htmlspecialchars($cfg['school_name']) ?></div>
      </div>
    </div>
    <hr class="border-white border-opacity-25 my-3">
    <div class="text-white-50 small mb-2">Login sebagai</div>
    <div class="text-white fw-semibold"><?= htmlspecialchars($u['nama_lengkap'] ?? '') ?></div>
    <div class="text-white-50 small"><?= htmlspecialchars(strtoupper($u['role'] ?? '')) ?></div>
  </div>
  <div class="px-2 pb-3 sidebar-nav">
    <?= sidebar_links($u, $active ?? '') ?>
  </div>
</div>
