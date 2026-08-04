<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/stats.php';

require_role('orangtua','ortu');
$active='dashboard';
$u=current_user();
$pdo=db();

$parentId = $u['parent_id'] ?? null;
if (!$parentId) {
  http_response_code(400);
  echo "Akun orang tua belum dihubungkan ke nomor HP.";
  exit;
}

$students = $pdo->prepare("SELECT id_siswa, nama_siswa, class_level FROM students WHERE id_orangtua=? ORDER BY class_level, nama_siswa");
$students->execute([$parentId]);
$kids = $students->fetchAll();

$kidId = (int)($_GET['kid'] ?? ($kids[0]['id_siswa'] ?? 0));
$kid = null;
foreach ($kids as $k) { if ((int)$k['id_siswa']===$kidId) $kid=$k; }
if (!$kid && $kids) { $kid = $kids[0]; $kidId=(int)$kid['id_siswa']; }

$range = $_GET['range'] ?? 'week';
if (!in_array($range, ['week','month','all'], true)) $range='week';
[$startDate, $endDate] = range_dates($range);

// ========= AJAX MODE (REALTIME) =========
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

  if (!$kidId) {
    echo json_encode(['success'=>false,'message'=>'Tidak ada siswa']);
    exit;
  }

  $counts = counts_for_student($kidId, $startDate, $endDate);

  // presensi hari ini (masuk + pulang + status manual)
  $today = date('Y-m-d');
  $st = $pdo->prepare("
    SELECT scan_at, status, type, catatan
    FROM attendance
    WHERE id_siswa = ?
      AND tanggal = ?
      AND (
        type IN ('MASUK','PULANG','TIDAK MASUK')
        OR LOWER(TRIM(status)) IN ('izin','ijin','sakit','alfa','alpha')
      )
    ORDER BY scan_at DESC
  ");
  $st->execute([$kidId, $today]);
  $todayRows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'success' => true,
    'kidId' => $kidId,
    'range' => $range,
    'startDate' => $startDate,
    'endDate' => $endDate,
    'counts' => [
      'Hadir' => (int)($counts['Hadir'] ?? 0),
      'Sakit' => (int)($counts['Sakit'] ?? 0),
      'Izin'  => (int)($counts['Izin']  ?? 0),
      'Alfa'  => (int)($counts['Alfa']  ?? 0),
    ],
    'today_rows' => $todayRows,
    'server_time' => date('Y-m-d H:i:s')
  ]);
  exit;
}
// ========= END AJAX MODE =========

$counts = $kidId ? counts_for_student($kidId, $startDate, $endDate) : ['Hadir'=>0,'Sakit'=>0,'Izin'=>0,'Alfa'=>0];

$title='Dashboard Orang Tua';
$subtitle='Monitoring kehadiran anak (real-time)';

$todayRows = [];
$today = date('Y-m-d');
if ($kidId) {
  $st = $pdo->prepare("
    SELECT scan_at, status, type, catatan
    FROM attendance
    WHERE id_siswa = ?
      AND tanggal = ?
      AND (
        type IN ('MASUK','PULANG','TIDAK MASUK')
        OR LOWER(TRIM(status)) IN ('izin','ijin','sakit','alfa','alpha')
      )
    ORDER BY scan_at DESC
  ");
  $st->execute([$kidId, $today]);
  $todayRows = $st->fetchAll();
}

$kidTitle = $kid ? ('Orang Tua Ananda ' . $kid['nama_siswa']) : $title;
$kidSubtitle = $kid ? ('Kelas ' . $kid['class_level'] . ' - Monitoring kehadiran (real-time)') : $subtitle;
$title = $kidTitle;
$subtitle = $kidSubtitle;

include __DIR__ . '/../partials/head.php';
?>
<div class="container-fluid">
  <div class="row">
    <?php include __DIR__ . '/../partials/sidebar.php'; ?>
    <div class="col-12 col-lg-9 col-xl-10 p-4">
      <?php include __DIR__ . '/../partials/topbar.php'; ?>

      <div class="admin-hero mb-3">
        <div>
          <div class="hero-title">Presensi Real-time</div>
          <div class="small-muted">Pantau kehadiran ananda secara langsung.</div>
        </div>
        <div class="clock-pill">
          <div id="clockDate">-</div>
          <div id="clockTime">-</div>
        </div>
      </div>

      <div class="card shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-between">
          <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
            <div class="small-muted">Anak:</div>
            <select name="kid" id="kidSelect" class="form-select" style="max-width:280px" onchange="this.form.submit()">
              <?php foreach($kids as $k): ?>
                <option value="<?= (int)$k['id_siswa'] ?>" <?= (int)$k['id_siswa']===$kidId?'selected':'' ?>>
                  <?= htmlspecialchars($k['nama_siswa']) ?> (Kelas <?= htmlspecialchars($k['class_level']) ?>)
                </option>
              <?php endforeach; ?>
            </select>

            <div class="small-muted ms-0 ms-lg-3">Rentang:</div>
            <select name="range" id="rangeSelect" class="form-select" style="max-width:220px" onchange="this.form.submit()">
              <option value="week" <?= $range==='week'?'selected':'' ?>>7 Hari Terakhir</option>
              <option value="month" <?= $range==='month'?'selected':'' ?>>Bulan Ini</option>
              <option value="all" <?= $range==='all'?'selected':'' ?>>Semua Data</option>
            </select>
          </form>

          <div class="small-muted">
            Periode: <b id="periodStart"><?= htmlspecialchars($startDate) ?></b> s/d <b id="periodEnd"><?= htmlspecialchars($endDate) ?></b>
            <span class="text-muted ms-2 small">(update: <span id="lastUpdate">-</span>)</span>
          </div>
        </div>
      </div>

      <?php if(!$kidId): ?>
        <div class="alert alert-warning">Belum ada data siswa yang terhubung ke akun orang tua ini.</div>
      <?php else: ?>
        <div class="row g-3 justify-content-center">
          <div class="col-12 col-xl-10">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="fw-semibold mb-1">Ringkasan Kehadiran</div>
                <div class="small-muted mb-3">
                  <?= htmlspecialchars($kid['nama_siswa']) ?> - Kelas <?= htmlspecialchars($kid['class_level']) ?>
                </div>

                <div style="height:220px">
                  <canvas id="chartOrtu" style="max-height:220px"></canvas>
                </div>

                <div class="row mt-3 g-2">
                  <div class="col-6 col-lg-3">
                    <div class="p-3 rounded-4 border border-success-subtle bg-success-subtle text-success">
                      <div class="small">Hadir</div>
                      <div class="fs-4 fw-bold"><span id="hadirCount"><?= (int)($counts['Hadir'] ?? 0) ?></span></div>
                    </div>
                  </div>
                  <div class="col-6 col-lg-3">
                    <div class="p-3 rounded-4 border border-info-subtle bg-info-subtle text-info">
                      <div class="small">Sakit</div>
                      <div class="fs-4 fw-bold"><span id="sakitCount"><?= (int)($counts['Sakit'] ?? 0) ?></span></div>
                    </div>
                  </div>
                  <div class="col-6 col-lg-3">
                    <div class="p-3 rounded-4 border border-purple-subtle bg-purple-subtle text-purple">
                      <div class="small">Izin</div>
                      <div class="fs-4 fw-bold"><span id="izinCount"><?= (int)($counts['Izin'] ?? 0) ?></span></div>
                    </div>
                  </div>
                  <div class="col-6 col-lg-3">
                    <div class="p-3 rounded-4 border border-danger-subtle bg-danger-subtle text-danger">
                      <div class="small">Alfa</div>
                      <div class="fs-4 fw-bold"><span id="alfaCount"><?= (int)($counts['Alfa'] ?? 0) ?></span></div>
                    </div>
                  </div>
                </div>

              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-12">
            <div class="card shadow-sm">
              <div class="card-body">
                <div class="fw-semibold mb-1">Presensi Hari Ini</div>
                <div class="small-muted mb-3">Data masuk, pulang, dan status manual hari ini.</div>

                <div class="table-responsive">
                  <table class="table table-sm align-middle">
                    <thead><tr><th>Waktu</th><th>Status</th><th>Keterangan</th></tr></thead>
                    <tbody id="recentBody">
                      <?php foreach($todayRows as $r): ?>
                        <tr>
                          <td><?= htmlspecialchars($r['scan_at']) ?></td>
                          <td>
                            <span class="badge <?= htmlspecialchars(status_badge_class((string)($r['status'] ?? ''), (string)($r['type'] ?? ''))) ?>">
                              <?= htmlspecialchars($r['type']==='PULANG' ? 'Pulang' : $r['status']) ?>
                            </span>
                          </td>
                          <td><?= htmlspecialchars($r['catatan'] ?? '-') ?></td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if(!$todayRows): ?>
                        <tr><td colspan="3" class="small-muted">Belum ada data presensi hari ini.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>

                <a class="btn btn-sm btn-outline-primary" href="<?= base_url('/ortu/history.php?kid='.$kidId) ?>">Lihat Riwayat</a>
              </div>
            </div>
          </div>
        </div>

<script>
          // ===== Chart init =====
          const realtimeUrl = "<?= base_url('/ortu/dashboard.php') ?>";
          const initialKidId = "<?= (int)$kidId ?>";
          const initialRange = <?= json_encode($range) ?>;
          const chartCtx = document.getElementById('chartOrtu');
          let chartOrtu = null;

          function buildChart(counts) {
            const labels = ["Hadir", "Izin", "Sakit", "Alfa"];
            const data = [counts.Hadir, counts.Izin, counts.Sakit, counts.Alfa];
            const chartColors = ["#22c55e", "#a855f7", "#38bdf8", "#ef4444"];

            if (!window.Chart || !chartCtx) return;
            if (chartOrtu) {
              chartOrtu.data.labels = labels;
              chartOrtu.data.datasets[0].data = data;
              chartOrtu.update();
              return;
            }

            chartOrtu = new Chart(chartCtx, {
              type: 'doughnut',
              data: { labels, datasets: [{ data, backgroundColor: chartColors, borderWidth: 0 }] },
              options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
              }
            });
          }


          function escHtml(value) {
            const div = document.createElement("div");
            div.textContent = value ?? "";
            return div.innerHTML;
          }

          function badgeClass(status, type) {
            if (type === "PULANG") return "status-badge status-pulang";
            const s = status || "";
            if (s.includes("Tepat")) return "status-badge status-ontime";
            if (s.includes("Terlambat")) return "status-badge status-late";
            if (s.includes("Izin")) return "status-badge status-permit";
            if (s.includes("Sakit")) return "status-badge status-sick";
            if (s.includes("Alfa")) return "status-badge status-absent";
            return "status-badge status-default";
          }

          function renderTodayTable(rows) {
            const tbody = document.getElementById("recentBody");
            if (!tbody) return;
            if (!rows || rows.length === 0) {
              tbody.innerHTML = '<tr><td colspan="3" class="small-muted">Belum ada data presensi hari ini.</td></tr>';
              return;
            }
            tbody.innerHTML = rows.map(r => `
              <tr>
                <td>${escHtml(r.scan_at)}</td>
                <td><span class="badge ${badgeClass(r.status, r.type)}">${escHtml(r.type === "PULANG" ? "Pulang" : (r.status || "-"))}</span></td>
                <td>${escHtml(r.catatan || "-")}</td>
              </tr>
            `).join("");
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

          // ===== Realtime polling =====
          async function fetchRealtime() {
            try {
              const kid = document.getElementById("kidSelect")?.value || initialKidId;
              const range = document.getElementById("rangeSelect")?.value || initialRange;
              const url = `${realtimeUrl}?ajax=1&kid=${encodeURIComponent(kid)}&range=${encodeURIComponent(range)}&_=${Date.now()}`;

              const res = await fetch(url, {
                cache: "no-store",
                credentials: "same-origin"
              });
              if (!res.ok) return;
              const contentType = res.headers.get("content-type") || "";
              if (!contentType.includes("application/json")) return;
              const data = await res.json();
              if (!data.success) return;

              // update periode + last update
              document.getElementById("periodStart").innerText = data.startDate;
              document.getElementById("periodEnd").innerText = data.endDate;
              document.getElementById("lastUpdate").innerText = data.server_time;

              // update angka
              document.getElementById("hadirCount").innerText = data.counts.Hadir;
              document.getElementById("sakitCount").innerText = data.counts.Sakit;
              document.getElementById("izinCount").innerText  = data.counts.Izin;
              document.getElementById("alfaCount").innerText  = data.counts.Alfa;

              // update table hari ini
              renderTodayTable(data.today_rows || []);

              // update chart
              buildChart(data.counts);
              // donut chart only
            } catch (e) {
              // silent
            }
          }

          // init chart from server-side counts
          window.addEventListener('load', () => {
            const initialCounts = {
              Hadir: <?= (int)($counts['Hadir'] ?? 0) ?>,
              Sakit: <?= (int)($counts['Sakit'] ?? 0) ?>,
              Izin:  <?= (int)($counts['Izin']  ?? 0) ?>,
              Alfa:  <?= (int)($counts['Alfa']  ?? 0) ?>
            };
            buildChart(initialCounts);
            renderTodayTable(<?= json_encode($todayRows) ?>);

            // start polling
            updateClock();
            fetchRealtime();
            setInterval(updateClock, 1000);
            setInterval(fetchRealtime, 3000);
          });
        </script>
      <?php endif; ?>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/foot.php'; ?>
