<?php
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/stats.php';

require_role('orangtua','ortu');
$active='history';
$title='Riwayat Presensi';
$subtitle='Riwayat presensi anak berdasarkan tanggal';

$u=current_user();
$pdo=db();
$parentId = $u['parent_id'] ?? null;

$kidsStmt = $pdo->prepare("SELECT id_siswa, nama_siswa, class_level FROM students WHERE id_orangtua=? ORDER BY class_level, nama_siswa");
$kidsStmt->execute([$parentId]);
$kids = $kidsStmt->fetchAll();

$kidId = (int)($_GET['kid'] ?? ($kids[0]['id_siswa'] ?? 0));
$kid = null;
foreach ($kids as $k) { if ((int)$k['id_siswa'] === $kidId) $kid = $k; }

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-t');

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

  $stmt = $pdo->prepare("
    SELECT tanggal, scan_at, status, type, catatan
    FROM attendance
    WHERE id_siswa = ?
      AND tanggal BETWEEN ? AND ?
      AND (
        type IN ('MASUK','TIDAK MASUK','PULANG')
        OR LOWER(TRIM(status)) IN ('izin','ijin','sakit','alfa','alpha')
      )
    ORDER BY tanggal DESC, scan_at DESC
  ");
  $stmt->execute([$kidId, $from, $to]);
  echo json_encode([
    'success' => true,
    'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    'server_time' => date('Y-m-d H:i:s')
  ]);
  exit;
}

$stmt = $pdo->prepare("
  SELECT tanggal, scan_at, status, type, catatan
  FROM attendance
  WHERE id_siswa = ?
    AND tanggal BETWEEN ? AND ?
    AND (
      type IN ('MASUK','TIDAK MASUK','PULANG')
      OR LOWER(TRIM(status)) IN ('izin','ijin','sakit','alfa','alpha')
    )
  ORDER BY tanggal DESC, scan_at DESC
");
$stmt->execute([$kidId, $from, $to]);
$rows = $stmt->fetchAll();

$kidTitle = $kid ? ('Orang Tua Ananda ' . $kid['nama_siswa']) : $title;
$kidSubtitle = $kid ? ('Riwayat presensi - Kelas ' . $kid['class_level']) : $subtitle;
$title = $kidTitle;
$subtitle = $kidSubtitle;

include __DIR__ . '/../partials/head.php';
?>
<div class="container-fluid">
  <div class="row">
    <?php include __DIR__ . '/../partials/sidebar.php'; ?>
    <div class="col-12 col-lg-9 col-xl-10 p-4">
      <?php include __DIR__ . '/../partials/topbar.php'; ?>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <form class="row g-2 align-items-end" method="get">
            <div class="col-12 col-md-4">
              <label class="form-label">Anak</label>
              <select name="kid" class="form-select">
                <?php foreach($kids as $k): ?>
                  <option value="<?= (int)$k['id_siswa'] ?>" <?= (int)$k['id_siswa']===$kidId?'selected':'' ?>>
                    <?= htmlspecialchars($k['nama_siswa']) ?> (Kelas <?= htmlspecialchars($k['class_level']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Dari</label>
              <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">Sampai</label>
              <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control">
            </div>
            <div class="col-12 col-md-2">
              <button class="btn btn-brand text-white w-100">Terapkan</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>Tanggal</th><th>Waktu Scan</th><th>Status</th><th>Keterangan</th></tr></thead>
              <tbody id="historyBody">
                <?php foreach($rows as $r): ?>
                  <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($r['tanggal']) ?></td>
                    <td><?= htmlspecialchars($r['scan_at']) ?></td>
                    <td>
                      <span class="badge <?= htmlspecialchars(status_badge_class((string)($r['status'] ?? ''), (string)($r['type'] ?? ''))) ?>">
                        <?= htmlspecialchars($r['type']==='PULANG' ? 'Pulang' : $r['status']) ?>
                      </span>
                    </td>
                    <td><?= htmlspecialchars($r['catatan'] ?? '-') ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if(!$rows): ?>
                  <tr><td colspan="4" class="small-muted">Tidak ada data pada rentang ini.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <script>
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

        async function loadHistory() {
          try {
            const kid = document.querySelector('select[name="kid"]')?.value || "<?= (int)$kidId ?>";
            const from = document.querySelector('input[name="from"]')?.value || "<?= htmlspecialchars($from) ?>";
            const to = document.querySelector('input[name="to"]')?.value || "<?= htmlspecialchars($to) ?>";
            const url = "<?= base_url('/ortu/history.php') ?>?ajax=1&kid=" + encodeURIComponent(kid) + "&from=" + encodeURIComponent(from) + "&to=" + encodeURIComponent(to);
            const res = await fetch(url, { cache: "no-store" });
            if (!res.ok) return;
            const ctype = res.headers.get("content-type") || "";
            if (!ctype.includes("application/json")) return;
            const data = await res.json();
            if (!data.success) return;
            const tbody = document.getElementById("historyBody");
            if (!tbody) return;
            if (!data.rows || data.rows.length === 0) {
              tbody.innerHTML = '<tr><td colspan="4" class="small-muted">Tidak ada data pada rentang ini.</td></tr>';
              return;
            }
            tbody.innerHTML = data.rows.map(r => `
              <tr>
                <td class="fw-semibold">${escHtml(r.tanggal)}</td>
                <td>${escHtml(r.scan_at)}</td>
                <td><span class="badge ${badgeClass(r.status, r.type)}">${escHtml(r.type === "PULANG" ? "Pulang" : (r.status || "-"))}</span></td>
                <td>${escHtml(r.catatan || "-")}</td>
              </tr>
            `).join("");
          } catch (e) {
            // silent
          }
        }

        loadHistory();
        setInterval(loadHistory, 10000);
      </script>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/foot.php'; ?>
