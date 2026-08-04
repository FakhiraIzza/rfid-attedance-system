<?php $cfg=app_cfg(); ?>
<div class="d-flex align-items-center justify-content-between mb-3 gap-2 flex-wrap">
  <div class="d-flex align-items-center gap-2">
    <!-- Toggle sidebar on mobile -->
    <button class="btn btn-outline-primary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" aria-controls="sidebarOffcanvas">
      ☰
    </button>
    <div>
      <div class="fw-semibold fs-4"><?= htmlspecialchars($title ?? 'Dashboard') ?></div>
      <div class="small-muted"><?= htmlspecialchars($subtitle ?? '') ?></div>
    </div>
  </div>
  <span class="badge rounded-pill brand-badge px-3 py-2">Al-Azhar Bukittinggi</span>
</div>
