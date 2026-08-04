<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/attendance_features.php';

require_role('admin');
$active = 'message_templates';
$title = 'Template Pesan';
$subtitle = 'Kelola template notifikasi WhatsApp untuk orang tua';

$pdo = db();
$supportsTemplates = attendance_templates_supported($pdo);

function template_flash_redirect(string $message, string $type = 'success'): void
{
  $_SESSION['flash_' . $type] = $message;
  header('Location: ' . base_url('/admin/message_templates.php'));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$supportsTemplates) {
    template_flash_redirect('Tabel message_templates belum tersedia. Jalankan migrasi database terlebih dahulu.', 'error');
  }

  $action = trim((string)($_POST['action'] ?? ''));
  $userId = (int)(current_user()['id_user'] ?? 0);

  if ($action === 'save_template') {
    $idTemplate = (int)($_POST['id_template'] ?? 0);
    $titleInput = trim((string)($_POST['title'] ?? ''));
    $bodyInput = trim((string)($_POST['message_body'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $isDefault = isset($_POST['is_default']) ? 1 : 0;

    if ($titleInput === '' || $bodyInput === '') {
      template_flash_redirect('Judul dan isi template wajib diisi.', 'error');
    }

    $placeholders = array_keys(attendance_message_placeholders());
    $hasRequired = false;
    foreach (['{nama}', '{tanggal}', '{pukul}', '{status}'] as $requiredToken) {
      if (str_contains($bodyInput, $requiredToken)) {
        $hasRequired = true;
        break;
      }
    }
    if (!$hasRequired) {
      template_flash_redirect('Isi template minimal memuat salah satu placeholder utama seperti {nama}, {tanggal}, {pukul}, atau {status}.', 'error');
    }

    $pdo->beginTransaction();
    try {
      if ($idTemplate > 0) {
        $stmt = $pdo->prepare("
          UPDATE message_templates
          SET title = ?, message_body = ?, is_active = ?, updated_by_user_id = ?, updated_at = NOW()
          WHERE id_template = ?
        ");
        $stmt->execute([$titleInput, $bodyInput, $isActive, $userId ?: null, $idTemplate]);
      } else {
        $stmt = $pdo->prepare("
          INSERT INTO message_templates (title, message_body, is_active, is_default, created_by_user_id, updated_by_user_id, created_at, updated_at)
          VALUES (?, ?, ?, 0, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$titleInput, $bodyInput, $isActive, $userId ?: null, $userId ?: null]);
        $idTemplate = (int)$pdo->lastInsertId();
      }

      if ($isDefault) {
        $pdo->exec("UPDATE message_templates SET is_default = 0");
        $pdo->prepare("UPDATE message_templates SET is_default = 1, is_active = 1 WHERE id_template = ?")->execute([$idTemplate]);
      } elseif ((int)$pdo->query("SELECT COUNT(*) FROM message_templates WHERE is_default = 1")->fetchColumn() === 0) {
        $pdo->prepare("UPDATE message_templates SET is_default = 1 WHERE id_template = ?")->execute([$idTemplate]);
      }

      if ((int)$pdo->query("SELECT COUNT(*) FROM message_templates WHERE is_active = 1")->fetchColumn() === 0) {
        $pdo->prepare("UPDATE message_templates SET is_active = 1 WHERE id_template = ?")->execute([$idTemplate]);
      }

      $pdo->commit();
      template_flash_redirect('Template pesan berhasil disimpan.');
    } catch (Throwable $e) {
      $pdo->rollBack();
      template_flash_redirect('Template pesan gagal disimpan.', 'error');
    }
  }

  if ($action === 'set_default') {
    $idTemplate = (int)($_POST['id_template'] ?? 0);
    if ($idTemplate <= 0) {
      template_flash_redirect('Template default tidak valid.', 'error');
    }

    $pdo->beginTransaction();
    try {
      $pdo->exec("UPDATE message_templates SET is_default = 0");
      $stmt = $pdo->prepare("UPDATE message_templates SET is_default = 1, is_active = 1, updated_by_user_id = ?, updated_at = NOW() WHERE id_template = ?");
      $stmt->execute([$userId ?: null, $idTemplate]);
      $pdo->commit();
      template_flash_redirect('Template default diperbarui.');
    } catch (Throwable $e) {
      $pdo->rollBack();
      template_flash_redirect('Template default gagal diperbarui.', 'error');
    }
  }

  if ($action === 'toggle_active') {
    $idTemplate = (int)($_POST['id_template'] ?? 0);
    if ($idTemplate <= 0) {
      template_flash_redirect('Template tidak valid.', 'error');
    }

    $rowStmt = $pdo->prepare("SELECT is_active, is_default FROM message_templates WHERE id_template = ? LIMIT 1");
    $rowStmt->execute([$idTemplate]);
    $template = $rowStmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) {
      template_flash_redirect('Template tidak ditemukan.', 'error');
    }

    $nextActive = ((int)$template['is_active'] === 1) ? 0 : 1;
    if ($nextActive === 0) {
      $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM message_templates WHERE is_active = 1")->fetchColumn();
      if ($activeCount <= 1) {
        template_flash_redirect('Minimal harus ada satu template aktif.', 'error');
      }
      if ((int)$template['is_default'] === 1) {
        template_flash_redirect('Template default tidak bisa dinonaktifkan. Ubah default ke template lain terlebih dahulu.', 'error');
      }
    }

    $stmt = $pdo->prepare("UPDATE message_templates SET is_active = ?, updated_by_user_id = ?, updated_at = NOW() WHERE id_template = ?");
    $stmt->execute([$nextActive, $userId ?: null, $idTemplate]);
    template_flash_redirect($nextActive ? 'Template diaktifkan.' : 'Template dinonaktifkan.');
  }

  if ($action === 'delete_template') {
    $idTemplate = (int)($_POST['id_template'] ?? 0);
    if ($idTemplate <= 0) {
      template_flash_redirect('Template tidak valid.', 'error');
    }

    $rowStmt = $pdo->prepare("SELECT is_active, is_default FROM message_templates WHERE id_template = ? LIMIT 1");
    $rowStmt->execute([$idTemplate]);
    $template = $rowStmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) {
      template_flash_redirect('Template tidak ditemukan.', 'error');
    }

    if ((int)$template['is_default'] === 1) {
      template_flash_redirect('Template default tidak bisa dihapus. Ubah default ke template lain terlebih dahulu.', 'error');
    }

    $totalTemplates = (int)$pdo->query("SELECT COUNT(*) FROM message_templates")->fetchColumn();
    if ($totalTemplates <= 1) {
      template_flash_redirect('Minimal harus ada satu template pesan.', 'error');
    }

    if ((int)$template['is_active'] === 1) {
      $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM message_templates WHERE is_active = 1")->fetchColumn();
      if ($activeCount <= 1) {
        template_flash_redirect('Minimal harus ada satu template aktif.', 'error');
      }
    }

    $stmt = $pdo->prepare("DELETE FROM message_templates WHERE id_template = ?");
    $stmt->execute([$idTemplate]);
    template_flash_redirect('Template berhasil dihapus.');
  }
}

$templates = $supportsTemplates ? attendance_fetch_message_templates($pdo, false) : attendance_default_message_templates();
$editId = (int)($_GET['edit'] ?? 0);
$editing = null;
foreach ($templates as $template) {
  if ((int)($template['id_template'] ?? 0) === $editId) {
    $editing = $template;
    break;
  }
}

$placeholders = attendance_message_placeholders();
include __DIR__ . '/../partials/head.php';
?>
<div class="container-fluid">
  <div class="row">
    <?php include __DIR__ . '/../partials/sidebar.php'; ?>
    <div class="col-12 col-lg-9 col-xl-10 p-4">
      <?php include __DIR__ . '/../partials/topbar.php'; ?>

      <?php if(!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
        <?php unset($_SESSION['flash_error']); ?>
      <?php endif; ?>
      <?php if(!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
        <?php unset($_SESSION['flash_success']); ?>
      <?php endif; ?>

      <?php if (!$supportsTemplates): ?>
        <div class="alert alert-warning">
          Tabel <code>message_templates</code> belum ada. Jalankan migrasi <code>database/migrations/20260720_add_message_templates_and_attendance_evidence.sql</code> terlebih dahulu.
        </div>
      <?php endif; ?>

      <div class="row g-3">
        <div class="col-12 col-xl-5">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <div class="fw-semibold mb-3"><?= $editing ? 'Edit Template Pesan' : 'Tambah Template Pesan' ?></div>
              <form method="post" class="row g-3">
                <input type="hidden" name="action" value="save_template">
                <input type="hidden" name="id_template" value="<?= (int)($editing['id_template'] ?? 0) ?>">

                <div class="col-12">
                  <label class="form-label">Judul Template</label>
                  <input type="text" name="title" class="form-control" maxlength="120" value="<?= htmlspecialchars((string)($editing['title'] ?? '')) ?>" placeholder="Contoh: Template Kehadiran Pagi" required>
                </div>
                <div class="col-12">
                  <label class="form-label">Isi Pesan</label>
                  <textarea name="message_body" rows="10" class="form-control" placeholder="Gunakan placeholder seperti {nama}, {tanggal}, {pukul}, {status}" required><?= htmlspecialchars((string)($editing['message_body'] ?? '')) ?></textarea>
                </div>
                <div class="col-12">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_active" id="isActiveTemplate" <?= !isset($editing['is_active']) || (int)$editing['is_active'] === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="isActiveTemplate">Aktifkan template ini</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_default" id="isDefaultTemplate" <?= isset($editing['is_default']) && (int)$editing['is_default'] === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="isDefaultTemplate">Jadikan template default</label>
                  </div>
                </div>
                <div class="col-12 d-flex gap-2">
                  <button class="btn btn-brand text-white">Simpan Template</button>
                  <?php if ($editing): ?>
                    <a class="btn btn-outline-secondary" href="<?= base_url('/admin/message_templates.php') ?>">Batal Edit</a>
                  <?php endif; ?>
                </div>
              </form>

              <hr>
              <div class="fw-semibold mb-2">Placeholder yang tersedia</div>
              <div class="small-muted mb-2">Gunakan placeholder berikut di isi template agar data siswa otomatis masuk ke pesan.</div>
              <div class="d-flex flex-column gap-2">
                <?php foreach ($placeholders as $token => $label): ?>
                  <div><code><?= htmlspecialchars($token) ?></code> : <?= htmlspecialchars($label) ?></div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-xl-7">
          <div class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                  <div class="fw-semibold">Daftar Template</div>
                  <div class="small-muted">Template aktif dipakai bergantian untuk notifikasi presensi.</div>
                </div>
                <span class="badge text-bg-light"><?= count($templates) ?> template</span>
              </div>

              <div class="d-flex flex-column gap-3">
                <?php foreach ($templates as $template): ?>
                  <div class="border rounded p-3">
                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
                      <div>
                        <div class="fw-semibold"><?= htmlspecialchars((string)($template['title'] ?? '-')) ?></div>
                        <div class="small-muted mt-1">
                          <?php if ((int)($template['is_default'] ?? 0) === 1): ?>
                            <span class="badge text-bg-primary">Default</span>
                          <?php endif; ?>
                          <?php if ((int)($template['is_active'] ?? 0) === 1): ?>
                            <span class="badge text-bg-success">Aktif</span>
                          <?php else: ?>
                            <span class="badge text-bg-secondary">Nonaktif</span>
                          <?php endif; ?>
                        </div>
                      </div>
                      <div class="d-flex flex-wrap gap-2">
                        <?php if ((int)($template['id_template'] ?? 0) > 0): ?>
                          <a class="btn btn-sm btn-outline-primary" href="<?= base_url('/admin/message_templates.php?edit=' . (int)$template['id_template']) ?>">Edit</a>
                          <form method="post">
                            <input type="hidden" name="action" value="set_default">
                            <input type="hidden" name="id_template" value="<?= (int)$template['id_template'] ?>">
                            <button class="btn btn-sm btn-outline-dark" <?= (int)($template['is_default'] ?? 0) === 1 ? 'disabled' : '' ?>>Jadikan Default</button>
                          </form>
                          <form method="post">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id_template" value="<?= (int)$template['id_template'] ?>">
                            <button class="btn btn-sm btn-outline-secondary"><?= (int)($template['is_active'] ?? 0) === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                          </form>
                          <form method="post" onsubmit="return confirm('Hapus template ini?')">
                            <input type="hidden" name="action" value="delete_template">
                            <input type="hidden" name="id_template" value="<?= (int)$template['id_template'] ?>">
                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </div>
                    <pre class="mt-3 mb-0 p-3 bg-light border rounded small" style="white-space:pre-wrap"><?= htmlspecialchars((string)($template['message_body'] ?? '')) ?></pre>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/foot.php'; ?>
