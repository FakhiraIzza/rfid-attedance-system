<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/attendance_features.php';
require_once __DIR__ . '/../lib/stats.php';

require_role('admin');
app_cfg();
$active='dashboard';
$u=current_user();
$pdo=db();

$title='Dashboard Admin';
$subtitle='Rekap presensi seluruh sekolah (real-time)';

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
  SELECT status, COUNT(*) AS cnt
  FROM attendance
  WHERE tanggal = ?
    AND (
      type IN ('MASUK','TIDAK MASUK')
      OR LOWER(TRIM(status)) IN ('izin','ijin','sakit','alfa','alpha')
    )
  GROUP BY status
");
$stmtCounts->execute([$today]);
foreach ($stmtCounts->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $status = normalize_status((string)$row['status']);
  if (isset($countsToday[$status])) {
    $countsToday[$status] = (int)$row['cnt'];
  }
}

$totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$presentStmt = $pdo->prepare("SELECT COUNT(DISTINCT id_siswa) FROM attendance WHERE tanggal = ?");
$presentStmt->execute([$today]);
$presentStudents = (int)$presentStmt->fetchColumn();
$missingStudents = max($totalStudents - $presentStudents, 0);

// Presensi hari ini (masuk + pulang + status manual)
$stmt = $pdo->prepare("
  SELECT a.scan_at, a.status, a.type, a.catatan, s.nama_siswa, s.class_level
  FROM attendance a
  JOIN students s ON s.id_siswa=a.id_siswa
  WHERE a.tanggal = ?
    AND (
      a.type IN ('MASUK','PULANG','TIDAK MASUK')
      OR LOWER(TRIM(a.status)) IN ('izin','ijin','sakit','alfa','alpha')
    )
  ORDER BY a.scan_at DESC
  LIMIT 50
");
$stmt->execute([$today]);
$recent = $stmt->fetchAll();
$recent = attendance_attach_evidence_to_rows($pdo, $recent);
$latest = $recent[0] ?? null;

include __DIR__ . '/../partials/head.php';
?>
<style>
  .latest-monitor { min-height: 260px; }
  .latest-monitor .small-muted { font-size: 1.1rem; }
  .latest-monitor #latestName { font-size: 2.2rem; }
  .latest-monitor #latestClass { font-size: 1.2rem; }
  .latest-monitor #latestStatus { font-size: 1.1rem; padding: 0.4rem 0.75rem; }
  .latest-monitor #latestTime { font-size: 1.15rem; }
  @media (min-width: 1200px) {
    .latest-monitor { min-height: 300px; }
    .latest-monitor #latestName { font-size: 2.6rem; }
    .latest-monitor #latestStatus { font-size: 1.25rem; }
  }
</style>
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
          <div class="hero-title">Presensi Real-time Sekolah</div>
          <div class="small-muted">Pantau kehadiran seluruh siswa secara langsung.</div>
        </div>
        <div class="clock-pill">
          <div id="clockDate">-</div>
          <div id="clockTime">-</div>
        </div>
      </div>

      <div class="card shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-between">
          <div>
            <div class="fw-semibold"><?= htmlspecialchars($u['nama_lengkap'] ?? 'Admin') ?></div>
            <div class="small-muted">Administrator</div>
          </div>
          <div class="small-muted">
            Periode: <b id="periodStart"><?= htmlspecialchars($today) ?></b> s/d <b id="periodEnd"><?= htmlspecialchars($today) ?></b>
            <span class="text-muted ms-2 small">(update: <span id="lastUpdate">-</span>)</span>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-12 col-lg-5">
          <div class="card shadow-sm h-100 latest-monitor">
            <div class="card-body">
              <div class="small-muted mb-2">Update terakhir <span id="latestUpdate">-</span></div>
              <div class="fs-4 fw-semibold" id="latestName">-</div>
              <div class="small-muted" id="latestClass">-</div>
              <div class="mt-3">
                <span class="badge status-badge status-default" id="latestStatus">-</span>
              </div>
              <div class="small-muted mt-2" id="latestTime">-</div>
              <div class="small-muted mt-2" id="latestEvidence">-</div>
            </div>
          </div>
        </div>
        <div class="col-12 col-lg-7">
          <div class="card shadow-sm stats-card h-100">
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
                    <canvas id="chartAdminToday" style="max-height:180px"></canvas>
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
                  <div class="small-muted">Masuk, pulang, dan status manual hari ini.</div>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="<?= base_url('/admin/attendance.php') ?>">Lihat Rekap Presensi</a>
              </div>
              <div class="table-responsive mt-3">
                <table class="table table-sm align-middle">
                  <thead><tr><th>Waktu</th><th>Nama</th><th>Kelas</th><th>Status</th><th>Keterangan</th><th>Evidence</th></tr></thead>
                  <tbody id="recentBody">
                    <?php foreach($recent as $r): ?>
                      <tr>
                        <td><?= htmlspecialchars($r['scan_at']) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($r['nama_siswa']) ?></td>
                        <td><?= htmlspecialchars($r['class_level']) ?></td>
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
                      <tr><td colspan="6" class="small-muted">Belum ada data presensi hari ini.</td></tr>
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
        const realtimeUrl = "<?= base_url('/api/dashboard_realtime_admin.php') ?>";
        const recentUrl = "<?= base_url('/api/attendance_latest_admin.php') ?>";
        const initialServerTime = <?= json_encode($serverNow) ?>;
        const initialCounts = <?= json_encode($countsToday) ?>;
        const initialLatest = <?= json_encode($latest) ?>;
        const initialTotals = {
          total: <?= json_encode($totalStudents) ?>,
          present: <?= json_encode($presentStudents) ?>,
          missing: <?= json_encode($missingStudents) ?>
        };
        const initialToday = <?= json_encode($today) ?>;

        const statsDate = document.getElementById('statsDate');
        const statTotal = document.getElementById('statTotal');
        const statPresent = document.getElementById('statPresent');
        const statMissing = document.getElementById('statMissing');
        const todayLegend = document.getElementById('todayLegend');
        const recentBody = document.getElementById('recentBody');
        const latestUpdate = document.getElementById('latestUpdate');
        const latestName = document.getElementById('latestName');
        const latestClass = document.getElementById('latestClass');
        const latestStatus = document.getElementById('latestStatus');
        const latestTime = document.getElementById('latestTime');
        const latestEvidence = document.getElementById('latestEvidence');

        const chartCtx = document.getElementById('chartAdminToday');
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
            recentBody.innerHTML = '<tr><td colspan="6" class="small-muted">Belum ada data presensi hari ini.</td></tr>';
            return;
          }
          recentBody.innerHTML = rows.map(row => `
            <tr>
              <td>${escapeHtml(row.scan_at)}</td>
              <td class="fw-semibold">${escapeHtml(row.nama_siswa)}</td>
              <td>${escapeHtml(row.class_level)}</td>
              <td><span class="badge ${badgeClassForStatus(row.status, row.type)}">${escapeHtml(row.type === "PULANG" ? "Pulang" : (row.status || "-"))}</span></td>
              <td>${escapeHtml(row.catatan || "-")}</td>
              <td>${row.evidence_url ? `<a href="${escapeHtml(row.evidence_url)}" target="_blank" rel="noopener noreferrer" class="d-inline-flex align-items-center gap-2 text-decoration-none"><img src="${escapeHtml(row.evidence_url)}" alt="Evidence" style="width:52px;height:52px;object-fit:cover;border-radius:10px;border:1px solid #dbe2ea;"><span class="small">Lihat</span></a>` : '<span class="small-muted">Belum ada</span>'}</td>
            </tr>
          `).join("");
        }

        function renderLatest(latest, serverTime) {
          if (!latestName || !latestStatus || !latestClass || !latestTime || !latestUpdate) return;
          latestUpdate.textContent = serverTime || "-";
          const maxAgeMs = 15 * 60 * 1000;
          const parseTime = (value) => {
            if (!value) return null;
            const iso = String(value).replace(' ', 'T');
            const dt = new Date(iso);
            return isNaN(dt.getTime()) ? null : dt;
          };
          const latestAt = parseTime(latest && latest.scan_at ? latest.scan_at : null);
          const serverAt = parseTime(serverTime);
          const tooOld = latestAt && serverAt ? (serverAt - latestAt) > maxAgeMs : false;
          if (!latest || tooOld) {
            latestName.textContent = "-";
            latestClass.textContent = "-";
            latestStatus.textContent = "-";
            latestStatus.className = "badge status-badge status-default";
            latestTime.textContent = "-";
            if (latestEvidence) latestEvidence.innerHTML = "-";
            return;
          }
          latestName.textContent = latest.nama_siswa || "-";
          latestClass.textContent = latest.class_level ? ("Kelas " + latest.class_level) : "-";
          const statusLabel = latest.type === "PULANG" ? "Pulang" : (latest.status || "-");
          latestStatus.textContent = statusLabel;
          latestStatus.className = "badge " + badgeClassForStatus(latest.status, latest.type);
          latestTime.textContent = latest.scan_at || "-";
          if (latestEvidence) {
            latestEvidence.innerHTML = latest.evidence_url
              ? `<a href="${escapeHtml(latest.evidence_url)}" target="_blank" rel="noopener noreferrer">Lihat evidence foto</a>`
              : "Belum ada evidence foto";
          }
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
            renderLatest(data.latest || null, data.server_time || "-");
          } catch (err) {
            // silent
          }
        }

        async function fetchRecent() {
          try {
            const url = `${recentUrl}?from=${encodeURIComponent(initialToday)}&to=${encodeURIComponent(initialToday)}&limit=50&_=${Date.now()}`;
            const res = await fetch(url, {
              cache: "no-store",
              credentials: "same-origin"
            });
            if (!res.ok) return;
            const contentType = res.headers.get("content-type") || "";
            if (!contentType.includes("application/json")) return;
            const data = await res.json();
            if (!data.success) return;
            renderRecentTable(data.rows || []);
            document.getElementById("lastUpdate").innerText = data.server_time || "-";
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
        renderLatest(initialLatest || null, initialServerTime);
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
