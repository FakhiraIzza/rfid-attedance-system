<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/attendance_features.php';
require_once __DIR__ . '/../lib/stats.php';

require_role('guru');
app_cfg();
$active='dashboard';
$u=current_user();
$pdo=db();

$teacherId = $u['teacher_id'] ?? null;
$classLevel = null;
if ($teacherId) {
  $st = $pdo->prepare("SELECT class_level, nama_guru FROM teachers WHERE id_guru=?");
  $st->execute([$teacherId]);
  $t = $st->fetch();
  $classLevel = $t['class_level'] ?? null;
  $teacherName = $t['nama_guru'] ?? ($u['nama_lengkap'] ?? 'Guru');
} else {
  $teacherName = $u['nama_lengkap'] ?? 'Guru';
}

$title='Dashboard Guru';
$subtitle='Rekap presensi kelas yang diampu (kelas '.$classLevel.')';

$today = date('Y-m-d');
$serverNow = date('Y-m-d H:i:s');

$countsToday = [
  'Hadir Tepat Waktu' => 0,
  'Hadir Terlambat' => 0,
  'Izin' => 0,
  'Sakit' => 0,
  'Alfa' => 0,
];
$stmtCounts = $pdo->prepare("
  SELECT a.status, COUNT(*) AS cnt
  FROM attendance a
  JOIN students s ON s.id_siswa = a.id_siswa
  WHERE s.class_level = ?
    AND a.tanggal = ?
    AND (
      a.type IN ('MASUK','TIDAK MASUK')
      OR LOWER(TRIM(a.status)) IN ('izin','ijin','sakit','alfa','alpha')
    )
  GROUP BY a.status
");
$stmtCounts->execute([$classLevel, $today]);
foreach ($stmtCounts->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $status = normalize_status((string)$row['status']);
  if (isset($countsToday[$status])) {
    $countsToday[$status] = (int)$row['cnt'];
  }
}
$totalStudentsStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_level = ?");
$totalStudentsStmt->execute([$classLevel]);
$totalStudents = (int)$totalStudentsStmt->fetchColumn();
$presentStmt = $pdo->prepare("
  SELECT COUNT(DISTINCT a.id_siswa)
  FROM attendance a
  JOIN students s ON s.id_siswa = a.id_siswa
  WHERE s.class_level = ?
    AND a.tanggal = ?
");
$presentStmt->execute([$classLevel, $today]);
$presentStudents = (int)$presentStmt->fetchColumn();
$missingStudents = max($totalStudents - $presentStudents, 0);

// Presensi hari ini (masuk + pulang + status manual)
$stmt = $pdo->prepare("
  SELECT a.scan_at, a.status, a.type, a.catatan, s.nama_siswa
  FROM attendance a
  JOIN students s ON s.id_siswa=a.id_siswa
  WHERE s.class_level = ?
    AND a.tanggal = ?
    AND (
      a.type IN ('MASUK','PULANG','TIDAK MASUK')
      OR LOWER(TRIM(a.status)) IN ('izin','ijin','sakit','alfa','alpha')
    )
  ORDER BY a.scan_at DESC
");
$stmt->execute([$classLevel, $today]);
$recent = $stmt->fetchAll();
$recent = attendance_attach_evidence_to_rows($pdo, $recent);

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

      <div class="admin-hero mb-3">
        <div>
          <div class="hero-title">Presensi Real-time Kelas <?= htmlspecialchars($classLevel) ?></div>
          <div class="small-muted">Pantau kehadiran siswa secara langsung.</div>
        </div>
        <div class="clock-pill">
          <div id="clockDate">-</div>
          <div id="clockTime">-</div>
        </div>
      </div>

      <div class="card shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-between">
          <div>
            <div class="fw-semibold"><?= htmlspecialchars($teacherName) ?></div>
            <div class="small-muted">Wali/ Guru Kelas <?= htmlspecialchars($classLevel) ?></div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-12">
          <div class="card shadow-sm stats-card">
            <div class="card-body">
              <div class="d-flex align-items-start justify-content-between">
                <div>
                  <div class="fw-semibold mb-1">Rekap Hari Ini</div>
                  <div class="small-muted">Masuk, izin, sakit, alfa, dan siswa belum absen.</div>
                </div>
                <div class="small-muted" id="statsDate">-</div>
              </div>
              <div class="stats-grid mt-3">
                <div class="stat-chip">
                  <div class="label">Total Siswa</div>
                  <div class="value" id="statTotal">-</div>
                </div>
                <div class="stat-chip">
                  <div class="label">Sudah Absen</div>
                  <div class="value" id="statPresent">-</div>
                </div>
                <div class="stat-chip highlight">
                  <div class="label">Belum Absen</div>
                  <div class="value" id="statMissing">-</div>
                </div>
              </div>
              <div class="row mt-3 align-items-center">
                <div class="col-12 col-lg-7">
                  <div class="legend-list" id="todayLegend"></div>
                </div>
                <div class="col-12 col-lg-5">
                  <div style="height:180px">
                    <canvas id="chartGuruToday" style="max-height:180px"></canvas>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12">
          <div class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="fw-semibold">Presensi Hari Ini</div>
                  <div class="small-muted">Masuk, pulang, dan status manual hari ini untuk kelas <?= htmlspecialchars($classLevel) ?></div>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="<?= base_url('/guru/attendance.php') ?>">Lihat Presensi Kelas</a>
              </div>
              <div class="table-responsive mt-3">
                <table class="table table-sm align-middle">
                  <thead><tr><th>Waktu</th><th>Nama</th><th>Status</th><th>Keterangan</th><th>Evidence</th></tr></thead>
                  <tbody id="recentBody">
                    <?php foreach($recent as $r): ?>
                      <tr>
                        <td><?= htmlspecialchars($r['scan_at']) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($r['nama_siswa']) ?></td>
                        <td>
                          <span class="badge <?= htmlspecialchars(status_badge_class((string)($r['status'] ?? ''), (string)($r['type'] ?? ''))) ?>">
                            <?= htmlspecialchars($r['type']==='PULANG' ? 'Pulang' : $r['status']) ?>
                          </span>
                        </td>
                        <td><?= htmlspecialchars($r['catatan'] ?? '-') ?></td>
                        <td>
                          <?php if (!empty($r['evidence_url'])): ?>
                            <a href="<?= htmlspecialchars($r['evidence_url']) ?>" target="_blank" rel="noopener noreferrer" class="d-inline-flex align-items-center gap-2 text-decoration-none">
                              <img src="<?= htmlspecialchars($r['evidence_url']) ?>" alt="Evidence" style="width:52px;height:52px;object-fit:cover;border-radius:10px;border:1px solid #dbe2ea;">
                              <span class="small">Lihat</span>
                            </a>
                          <?php else: ?>
                            <span class="small-muted">Belum ada</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if(!$recent): ?>
                      <tr><td colspan="5" class="small-muted">Belum ada data presensi hari ini.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <script>
        window.addEventListener('load', () => {
        const realtimeUrl = "<?= base_url('/api/dashboard_realtime_guru.php') ?>";
        const recentUrl = "<?= base_url('/api/attendance_latest_guru.php') ?>";
        const initialServerTime = <?= json_encode($serverNow) ?>;
        const initialCounts = <?= json_encode($countsToday) ?>;
        const initialTotals = {
          total: <?= json_encode($totalStudents) ?>,
          present: <?= json_encode($presentStudents) ?>,
          missing: <?= json_encode($missingStudents) ?>
        };

        const statsDate = document.getElementById('statsDate');
        const statTotal = document.getElementById('statTotal');
        const statPresent = document.getElementById('statPresent');
        const statMissing = document.getElementById('statMissing');
        const todayLegend = document.getElementById('todayLegend');
        const recentBody = document.getElementById('recentBody');

        const chartCtx = document.getElementById('chartGuruToday');
        const chartLabels = [
          "Hadir Tepat Waktu",
          "Hadir Terlambat",
          "Izin",
          "Sakit",
          "Alfa"
        ];
        const chartColors = ["#22c55e","#f59e0b","#a855f7","#38bdf8","#ef4444"];
        let chartToday = null;
        if (window.Chart && chartCtx) {
          chartToday = new Chart(chartCtx, {
            type: 'doughnut',
            data: {
              labels: chartLabels,
              datasets: [{
                data: [0,0,0,0,0],
                backgroundColor: chartColors,
                borderWidth: 0
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: { legend: { display: false } }
            }
          });
        }

        function updateLegend(counts) {
          const items = [
            { label: "Hadir Tepat Waktu", key: "Hadir Tepat Waktu", color: "#22c55e" },
            { label: "Hadir Terlambat", key: "Hadir Terlambat", color: "#f59e0b" },
            { label: "Izin", key: "Izin", color: "#a855f7" },
            { label: "Sakit", key: "Sakit", color: "#38bdf8" },
            { label: "Alfa", key: "Alfa", color: "#ef4444" }
          ];
          todayLegend.innerHTML = items.map(item => `
            <div class="legend-item">
              <span class="dot" style="background:${item.color}"></span>
              <span>${item.label}</span>
              <span class="value">${counts[item.key] ?? 0}</span>
            </div>
          `).join("");
        }

        function escapeHtml(value) {
          const div = document.createElement("div");
          div.textContent = value ?? "";
          return div.innerHTML;
        }

        function badgeClassForStatus(status, type) {
          if (type === "PULANG") return "status-badge status-pulang";
          const s = status || "";
          if (s.includes("Tepat")) return "status-badge status-ontime";
          if (s.includes("Terlambat")) return "status-badge status-late";
          if (s.includes("Izin")) return "status-badge status-permit";
          if (s.includes("Sakit")) return "status-badge status-sick";
          if (s.includes("Alfa")) return "status-badge status-absent";
          return "status-badge status-default";
        }

        function renderRecentTable(rows) {
          if (!recentBody) return;
          if (!rows || rows.length === 0) {
            recentBody.innerHTML = '<tr><td colspan="5" class="small-muted">Belum ada data presensi hari ini.</td></tr>';
            return;
          }
          recentBody.innerHTML = rows.map(row => `
            <tr>
              <td>${escapeHtml(row.scan_at)}</td>
              <td class="fw-semibold">${escapeHtml(row.nama_siswa)}</td>
              <td><span class="badge ${badgeClassForStatus(row.status, row.type)}">${escapeHtml(row.type === "PULANG" ? "Pulang" : (row.status || "-"))}</span></td>
              <td>${escapeHtml(row.catatan || "-")}</td>
              <td>${row.evidence_url ? `<a href="${escapeHtml(row.evidence_url)}" target="_blank" rel="noopener noreferrer" class="d-inline-flex align-items-center gap-2 text-decoration-none"><img src="${escapeHtml(row.evidence_url)}" alt="Evidence" style="width:52px;height:52px;object-fit:cover;border-radius:10px;border:1px solid #dbe2ea;"><span class="small">Lihat</span></a>` : '<span class="small-muted">Belum ada</span>'}</td>
            </tr>
          `).join("");
        }

        async function fetchRealtime() {
          try {
            const res = await fetch(`${realtimeUrl}?_=${Date.now()}`, {
              cache: "no-store",
              credentials: "same-origin"
            });
            if (!res.ok) return;
            const contentType = res.headers.get("content-type") || "";
            if (!contentType.includes("application/json")) return;
            const data = await res.json();
            if (!data.success) return;

            statsDate.textContent = data.server_time || "-";
            statTotal.textContent = data.total_students ?? "-";
            statPresent.textContent = data.present_students ?? "-";
            statMissing.textContent = data.missing_students ?? "-";

            const counts = data.counts || {};
            if (chartToday) {
              chartToday.data.datasets[0].data = [
                counts["Hadir Tepat Waktu"] ?? 0,
                counts["Hadir Terlambat"] ?? 0,
                counts["Izin"] ?? 0,
                counts["Sakit"] ?? 0,
                counts["Alfa"] ?? 0
              ];
              chartToday.update();
            }
            updateLegend(counts);
          } catch (err) {
            // silent
          }
        }

        async function fetchRecent() {
          try {
            const res = await fetch(`${recentUrl}?today=1&types=all&limit=50&_=${Date.now()}`, {
              cache: "no-store",
              credentials: "same-origin"
            });
            if (!res.ok) return;
            const contentType = res.headers.get("content-type") || "";
            if (!contentType.includes("application/json")) return;
            const data = await res.json();
            if (!data.success) return;
            renderRecentTable(data.rows || []);
          } catch (err) {
            // silent
          }
        }

        function updateClock() {
          const now = new Date();
          document.getElementById('clockDate').textContent = now.toLocaleDateString("id-ID", {
            weekday: "long",
            day: "2-digit",
            month: "long",
            year: "numeric"
          });
          document.getElementById('clockTime').textContent = now.toLocaleTimeString("id-ID");
        }

        updateClock();
        statsDate.textContent = initialServerTime;
        statTotal.textContent = initialTotals.total;
        statPresent.textContent = initialTotals.present;
        statMissing.textContent = initialTotals.missing;
        updateLegend(initialCounts);
        if (chartToday) {
          chartToday.data.datasets[0].data = [
            initialCounts["Hadir Tepat Waktu"] ?? 0,
            initialCounts["Hadir Terlambat"] ?? 0,
            initialCounts["Izin"] ?? 0,
            initialCounts["Sakit"] ?? 0,
            initialCounts["Alfa"] ?? 0
          ];
          chartToday.update();
        }
        renderRecentTable(<?= json_encode($recent) ?>);
        fetchRealtime();
        fetchRecent();
        setInterval(updateClock, 1000);
        setInterval(fetchRealtime, 3000);
        setInterval(fetchRecent, 3000);
        });
      </script>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/foot.php'; ?>
