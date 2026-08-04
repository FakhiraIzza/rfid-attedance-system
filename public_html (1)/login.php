<?php
session_start();
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/stats.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  if (login($username, $password)) {
    header('Location: ' . base_url('/dashboard.php'));
    exit;
  }
  $err = 'Username atau password salah.';
}

$body_class = 'login-body';
include __DIR__ . '/partials/head.php';

$today = date('Y-m-d');
$levels = ['7', '8', '9'];
$stats = [];
$overallTotal = 0;
$lastScanAt = '-';

$cacheTtlSeconds = 5;
$cacheKey = hash('sha256', 'login_stats|' . $today);
$cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'login_stats_' . $cacheKey . '.cache';
$cacheHit = false;
if (is_file($cacheFile) && (time() - filemtime($cacheFile) <= $cacheTtlSeconds)) {
  $raw = @file_get_contents($cacheFile);
  $payload = $raw !== false ? @unserialize($raw) : null;
  if (is_array($payload) && isset($payload['stats'], $payload['overall_total'], $payload['last_scan_at'])) {
    $stats = $payload['stats'];
    $overallTotal = (int)$payload['overall_total'];
    $lastScanAt = (string)$payload['last_scan_at'];
    $cacheHit = true;
  }
}

if (!$cacheHit) {
  $pdo = db();
  $lastScanStmt = $pdo->prepare("
    SELECT MAX(scan_at) AS last_scan
    FROM attendance
    WHERE tanggal=?
      AND type IN ('MASUK','TIDAK MASUK')
  ");
  $lastScanStmt->execute([$today]);
  $lastScanRow = $lastScanStmt->fetch();
  $lastScanAt = $lastScanRow && $lastScanRow['last_scan'] ? date('H:i', strtotime($lastScanRow['last_scan'])) : '-';

  foreach ($levels as $level) {
    $stats[$level] = [
      'ontime' => 0,
      'late' => 0,
      'sick' => 0,
      'permit' => 0,
      'absent' => 0,
      'total' => 0,
    ];
  }

  $stmt = $pdo->prepare("
    SELECT s.class_level, a.status, COUNT(*) AS cnt
    FROM attendance a
    JOIN students s ON s.id_siswa = a.id_siswa
    WHERE a.tanggal = ?
      AND s.class_level IN ('7','8','9')
      AND (
        a.type IN ('MASUK','TIDAK MASUK')
        OR LOWER(TRIM(a.status)) IN ('izin','ijin','sakit','alfa','alpha')
      )
    GROUP BY s.class_level, a.status
  ");
  $stmt->execute([$today]);
  $rows = $stmt->fetchAll();

  $map = [
    'Hadir Tepat Waktu' => 'ontime',
    'Hadir Terlambat' => 'late',
    'Sakit' => 'sick',
    'Izin' => 'permit',
    'Alfa' => 'absent',
  ];
  foreach ($rows as $r) {
    $level = (string)$r['class_level'];
    if (!isset($stats[$level])) continue;
    $normalized = normalize_status((string)$r['status']);
    if (!isset($map[$normalized])) continue;
    $key = $map[$normalized];
    $stats[$level][$key] += (int)$r['cnt'];
  }

  foreach ($levels as $level) {
    $stats[$level]['total'] = $stats[$level]['ontime'] + $stats[$level]['late'] + $stats[$level]['sick'] + $stats[$level]['permit'] + $stats[$level]['absent'];
    $overallTotal += $stats[$level]['total'];
  }

  $payload = serialize([
    'stats' => $stats,
    'overall_total' => $overallTotal,
    'last_scan_at' => $lastScanAt,
  ]);
  @file_put_contents($cacheFile, $payload, LOCK_EX);
}
?>
<div class="login-hero">
  <div class="login-bg">
    <div class="tile" style="background-image:url('<?= base_url('/assets/img/alazhar1.jpg') ?>')"></div>
    <div class="tile" style="background-image:url('<?= base_url('/assets/img/alazhar2.jpg') ?>')"></div>
    <div class="tile" style="background-image:url('<?= base_url('/assets/img/alazhar3.jpeg') ?>')"></div>
    <div class="tile" style="background-image:url('<?= base_url('/assets/img/alazhar4.jpeg') ?>')"></div>
    <div class="tile" style="background-image:url('<?= base_url('/assets/img/alazhar5.jpeg') ?>')"></div>
    <div class="tile" style="background-image:url('<?= base_url('/assets/img/alazhar6.jpeg') ?>')"></div>
  </div>
  <div class="login-overlay"></div>

  <div class="container login-content py-4 py-lg-5">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
      <div class="brand-pill">
        <img src="<?= base_url('/assets/img/logo.png') ?>"
          alt="Logo Sekolah"
          style="width:42px;height:42px;object-fit:contain;border-radius:10px;background:#fff;padding:5px;">
        <div>
          <div class="brand-title">Presensi RFID</div>
          <div class="brand-sub"><?= htmlspecialchars(app_cfg()['school_name']) ?></div>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <div class="date-pill">Hari ini <?= date('d M Y') ?></div>
        <a class="btn btn-light btn-sm" href="#login-card">Login</a>
      </div>
    </div>

    <div class="row g-4 align-items-stretch">
      <div class="col-12 col-lg-8 order-2 order-lg-1">
        <div class="dashboard-card p-4 h-100">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
              <div class="text-uppercase small text-muted-light">Ringkasan presensi</div>
              <h1 class="login-title mb-1">Dashboard Absensi Hari Ini</h1>
              <div class="text-muted-light">Tepat waktu, terlambat, sakit, izin, dan alfa per jenjang.</div>
            </div>
            <div class="total-chip">
              <div class="chip-label">Total Presensi</div>
              <div class="chip-value"><?= $overallTotal ?></div>
              <div class="text-muted-light" style="font-size:.75rem;">update <?= htmlspecialchars($lastScanAt) ?></div>
            </div>
          </div>

          <div class="chart-grid mt-3">
            <div class="chart-card">
              <div class="chart-header">
                <div class="chart-title">Kelas 7</div>
                <div class="chart-total">Total <?= $stats['7']['total'] ?></div>
              </div>
              <div class="chart-wrap">
                <canvas id="chartK7"></canvas>
              </div>
              <div class="legend-list mt-3">
                <div class="legend-item"><span class="dot dot-ontime"></span> Tepat waktu <span class="value"><?= $stats['7']['ontime'] ?></span></div>
                <div class="legend-item"><span class="dot dot-late"></span> Terlambat <span class="value"><?= $stats['7']['late'] ?></span></div>
                <div class="legend-item"><span class="dot dot-sick"></span> Sakit <span class="value"><?= $stats['7']['sick'] ?></span></div>
                <div class="legend-item"><span class="dot dot-permit"></span> Izin <span class="value"><?= $stats['7']['permit'] ?></span></div>
                <div class="legend-item"><span class="dot dot-absent"></span> Alfa <span class="value"><?= $stats['7']['absent'] ?></span></div>
              </div>
            </div>
            <div class="chart-card">
              <div class="chart-header">
                <div class="chart-title">Kelas 8</div>
                <div class="chart-total">Total <?= $stats['8']['total'] ?></div>
              </div>
              <div class="chart-wrap">
                <canvas id="chartK8"></canvas>
              </div>
              <div class="legend-list mt-3">
                <div class="legend-item"><span class="dot dot-ontime"></span> Tepat waktu <span class="value"><?= $stats['8']['ontime'] ?></span></div>
                <div class="legend-item"><span class="dot dot-late"></span> Terlambat <span class="value"><?= $stats['8']['late'] ?></span></div>
                <div class="legend-item"><span class="dot dot-sick"></span> Sakit <span class="value"><?= $stats['8']['sick'] ?></span></div>
                <div class="legend-item"><span class="dot dot-permit"></span> Izin <span class="value"><?= $stats['8']['permit'] ?></span></div>
                <div class="legend-item"><span class="dot dot-absent"></span> Alfa <span class="value"><?= $stats['8']['absent'] ?></span></div>
              </div>
            </div>
            <div class="chart-card">
              <div class="chart-header">
                <div class="chart-title">Kelas 9</div>
                <div class="chart-total">Total <?= $stats['9']['total'] ?></div>
              </div>
              <div class="chart-wrap">
                <canvas id="chartK9"></canvas>
              </div>
              <div class="legend-list mt-3">
                <div class="legend-item"><span class="dot dot-ontime"></span> Tepat waktu <span class="value"><?= $stats['9']['ontime'] ?></span></div>
                <div class="legend-item"><span class="dot dot-late"></span> Terlambat <span class="value"><?= $stats['9']['late'] ?></span></div>
                <div class="legend-item"><span class="dot dot-sick"></span> Sakit <span class="value"><?= $stats['9']['sick'] ?></span></div>
                <div class="legend-item"><span class="dot dot-permit"></span> Izin <span class="value"><?= $stats['9']['permit'] ?></span></div>
                <div class="legend-item"><span class="dot dot-absent"></span> Alfa <span class="value"><?= $stats['9']['absent'] ?></span></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-4 order-1 order-lg-2">
        <div id="login-card" class="login-panel h-100">
          <div class="mb-3">
            <div class="fw-semibold">Masuk ke Sistem</div>
            <div class="text-muted">Admin, guru, atau orang tua.</div>
          </div>

          <?php if ($err): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
          <?php endif; ?>

          <form method="post" class="vstack gap-3">
            <div>
              <label class="form-label">Email / Username / NIS</label>
              <input name="username" class="form-control" placeholder="contoh: admin atau NIS siswa" required>
            </div>
            <div>
              <label class="form-label">Password</label>
              <div class="input-group">
                <input type="password" name="password" class="form-control" placeholder="password akun" required>
                <button class="btn btn-outline-secondary" type="button" data-toggle="password">Lihat</button>
              </div>
            </div>
            <button class="btn btn-brand text-white w-100">Masuk</button>
          </form>

          <div class="d-flex justify-content-between mt-2">
            <a class="small" href="<?= base_url('/change_password.php') ?>">Ubah Password</a>
            <a class="small" href="<?= base_url('/forgot_password.php') ?>">Lupa Password</a>
          </div>

          <hr class="my-4">
          <div class="small-muted">
            Info: Orang tua login awal/pertama kali menggunakan <b>NIS</b> + <b>No HP</b>.
            Untuk keamanan, boleh mengganti username ke <b>Email</b> dan password sendiri.
          </div>
        </div>
        <div class="login-footer text-center mt-3">&copy; <?= date('Y') ?> <?= htmlspecialchars(app_cfg()['school_name']) ?></div>
      </div>
    </div>
  </div>
</div>

<script>
  window.addEventListener('load', function () {
    if (!window.Chart) return;
    const labels = ['Tepat waktu', 'Terlambat', 'Sakit', 'Izin', 'Alfa'];
    const colors = ['#22c55e', '#f59e0b', '#38bdf8', '#a855f7', '#ef4444'];
    const baseOptions = {
      type: 'doughnut',
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: {
          legend: { display: false },
          tooltip: { enabled: true }
        }
      }
    };

    const chartData = <?= json_encode([
      'chartK7' => [$stats['7']['ontime'], $stats['7']['late'], $stats['7']['sick'], $stats['7']['permit'], $stats['7']['absent']],
      'chartK8' => [$stats['8']['ontime'], $stats['8']['late'], $stats['8']['sick'], $stats['8']['permit'], $stats['8']['absent']],
      'chartK9' => [$stats['9']['ontime'], $stats['9']['late'], $stats['9']['sick'], $stats['9']['permit'], $stats['9']['absent']],
    ]) ?>;

    Object.keys(chartData).forEach(function (key) {
      const ctx = document.getElementById(key);
      if (!ctx) return;
      new Chart(ctx, {
        ...baseOptions,
        data: {
          labels: labels,
          datasets: [{
            data: chartData[key],
            backgroundColor: colors,
            borderWidth: 0
          }]
        }
      });
    });
  });
  const toggleButtons = document.querySelectorAll('[data-toggle="password"]');
  toggleButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const input = btn.parentElement?.querySelector('input');
      if (!input) return;
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      btn.textContent = isHidden ? 'Sembunyi' : 'Lihat';
    });
  });
</script>
<?php include __DIR__ . '/partials/foot.php'; ?>
