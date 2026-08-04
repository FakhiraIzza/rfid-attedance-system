<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';

require_role('admin');
$active='teachers';
$title='Data Guru';
$subtitle='Kelola data guru dan kelas yang diampu (7/8/9)';

$pdo=db();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $nama = trim($_POST['nama_guru'] ?? '');
  $mapel = trim($_POST['mapel'] ?? '');
  $class = $_POST['class_level'] ?? '7';

  $pdo->prepare("INSERT INTO teachers (nama_guru,mapel,class_level) VALUES (?,?,?)")
      ->execute([$nama,$mapel,$class]);
  header('Location: ' . base_url('/admin/teachers.php'));
  exit;
}

if (isset($_GET['delete'])) {
  $id=(int)$_GET['delete'];
  $pdo->prepare("DELETE FROM teachers WHERE id_guru=?")->execute([$id]);
  header('Location: ' . base_url('/admin/teachers.php'));
  exit;
}

$rows=$pdo->query("SELECT * FROM teachers ORDER BY class_level, nama_guru")->fetchAll();

include __DIR__ . '/../partials/head.php';
?>
<div class="container-fluid">
  <div class="row">
    <?php include __DIR__ . '/../partials/sidebar.php'; ?>
    <div class="col-12 col-lg-9 col-xl-10 p-4">
      <?php include __DIR__ . '/../partials/topbar.php'; ?>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="fw-semibold mb-2">Tambah Guru</div>
          <form method="post" class="row g-2">
            <div class="col-12 col-md-5">
              <label class="form-label">Nama</label>
              <input name="nama_guru" class="form-control" required>
            </div>
            <div class="col-6 col-md-4">
              <label class="form-label">Mapel</label>
              <input name="mapel" class="form-control">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Kelas</label>
              <select name="class_level" class="form-select">
                <option value="7">7</option><option value="8">8</option><option value="9">9</option>
              </select>
            </div>
            <div class="col-12">
              <button class="btn btn-brand text-white">Simpan</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-body">
          <div class="fw-semibold mb-2">Daftar Guru</div>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>No</th><th>Nama</th><th>Mapel</th><th>Kelas</th><th>Aksi</th></tr></thead>
              <tbody>
                <?php $no = 1; foreach($rows as $r): ?>
                  <tr>
                    <td><?= $no++ ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($r['nama_guru']) ?></td>
                    <td><?= htmlspecialchars($r['mapel'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['class_level']) ?></td>
                    <td>
                      <a class="btn btn-sm btn-outline-danger" href="<?= base_url('/admin/teachers.php?delete='.(int)$r['id_guru']) ?>" onclick="return confirm('Hapus guru ini?')">Hapus</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if(!$rows): ?>
                  <tr><td colspan="5" class="small-muted">Belum ada data.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="small-muted mt-2">Untuk menghubungkan akun login guru → set <c>users.teacher_id</c</code>.</div>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/foot.php'; ?>
