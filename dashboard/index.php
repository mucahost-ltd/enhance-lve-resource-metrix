<?php
// enhance-usage-dashboard / index.php
// Standalone admin dashboard for per-website resource usage (CPU/RAM + limit hits).
// Reads the CSVs written by enhance-usage-logger.sh.
//
// IMPORTANT: run this as a standalone system service (see the .service file),
// NOT as a normal Enhance-hosted website - a sandboxed website container
// cannot read /var/log/enhance-usage on the host.
//
// Before deploying, generate a real password hash and paste it below:
//   php -r "echo password_hash('yourStrongPassword', PASSWORD_BCRYPT), PHP_EOL;"

$ADMIN_PASSWORD_HASH = 'CHANGE_ME_RUN: php -r "echo password_hash(\'yourStrongPassword\', PASSWORD_BCRYPT), PHP_EOL;"';
$LOG_DIR = '/var/log/enhance-usage';

session_start();

// ---------- Login ----------
$login_error = '';
if (isset($_POST['password'])) {
    if (password_verify($_POST['password'], $ADMIN_PASSWORD_HASH)) {
        $_SESSION['authed'] = true;
    } else {
        $login_error = 'Wrong password';
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ?');
    exit;
}
if (empty($_SESSION['authed'])) {
    ?>
    <!doctype html>
    <html><head><meta charset="utf-8"><title>Login</title>
    <style>
    body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;height:100vh;align-items:center;justify-content:center;margin:0}
    form{background:#1e293b;padding:2rem;border-radius:12px;width:280px}
    input{width:100%;padding:.6rem;margin:.5rem 0;border-radius:6px;border:1px solid #334155;background:#0f172a;color:#fff;box-sizing:border-box}
    button{width:100%;padding:.6rem;border-radius:6px;border:0;background:#3b82f6;color:#fff;cursor:pointer}
    .err{color:#f87171;font-size:.9rem}
    </style></head><body>
    <form method="post">
      <h2>Website Usage Dashboard</h2>
      <?php if ($login_error) echo '<p class="err">' . htmlspecialchars($login_error) . '</p>'; ?>
      <input type="password" name="password" placeholder="Admin password" autofocus>
      <button type="submit">Login</button>
    </form>
    </body></html>
    <?php
    exit;
}

// ---------- Helpers ----------
// CSV columns (v4): 0 timestamp,1 ts_epoch,2 unix_user,3 usage_usec,4 nr_throttled,
// 5 throttled_usec,6 mem_current_bytes,7 mem_max_bytes,8 oom_kill,9 cpu_quota,
// 10 cpu_period,11 io_rbytes,12 io_wbytes,13 io_rios,14 io_wios,15 io_riops_max,
// 16 io_wiops_max,17 swap_current_bytes,18 swap_max_bytes,19 swap_fail,
// 20 pids_current,21 pids_max,22 connections
// Rows with fewer columns (pre-migration files) are skipped.

const MIN_COLS_V4 = 23;

function list_sites($log_dir) {
    $sites = [];
    foreach (glob($log_dir . '/*.csv') as $f) {
        $base = basename($f, '.csv');
        if (str_ends_with($base, '.old') || preg_match('/\.\d{14}\.old$/', $base)) continue;
        $sites[] = $base;
    }
    sort($sites);
    return $sites;
}

function read_csv_rows($file, $since_epoch) {
    $rows = [];
    if (!is_readable($file)) return $rows;
    $fh = fopen($file, 'r');
    fgetcsv($fh); // skip header
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < MIN_COLS_V4) continue;
        if ((int)$row[1] >= $since_epoch) $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function summarize($rows) {
    $n = count($rows);
    if ($n < 2) return null;
    $first = $rows[0];
    $last = $rows[$n - 1];
    $wall = max(1, (int)$last[1] - (int)$first[1]);
    $avg_cpu = ((float)$last[3] - (float)$first[3]) / 1000000 / $wall;
    $throttle_delta = (int)$last[4] - (int)$first[4];
    $oom_delta = (int)$last[8] - (int)$first[8];
    $mem_current = (float)$last[6];
    $mem_max = (float)$last[7];
    $quota = $last[9];
    $period = (float)$last[10];
    $vcpu = ($quota === 'max') ? null : ((float)$quota / max(1, $period));

    $rios_delta = (float)$last[13] - (float)$first[13];
    $wios_delta = (float)$last[14] - (float)$first[14];
    $riops = $rios_delta / $wall;
    $wiops = $wios_delta / $wall;
    $riops_max = $last[15];
    $wiops_max = $last[16];

    $swap_current = (float)$last[17];
    $swap_max = $last[18];
    $swap_fail_delta = (int)$last[19] - (int)$first[19];

    $pids_current = (int)$last[20];
    $pids_max = $last[21];

    $connections = (int)$last[22];

    return [
        'avg_cpu'      => $avg_cpu,
        'throttle'     => $throttle_delta,
        'oom'          => $oom_delta,
        'mem_mb'       => $mem_current / 1048576,
        'mem_max_mb'   => ($mem_max > 0 && $mem_max < 1e15) ? $mem_max / 1048576 : null,
        'vcpu'         => $vcpu,
        'riops'        => $riops,
        'wiops'        => $wiops,
        'riops_max'    => ($riops_max === 'max') ? null : $riops_max,
        'wiops_max'    => ($wiops_max === 'max') ? null : $wiops_max,
        'swap_mb'      => $swap_current / 1048576,
        'swap_max_mb'  => ($swap_max !== 'max' && (float)$swap_max > 0 && (float)$swap_max < 1e15) ? ((float)$swap_max / 1048576) : null,
        'swap_fail'    => $swap_fail_delta,
        'pids'         => $pids_current,
        'pids_max'     => ($pids_max === 'max') ? null : $pids_max,
        'connections'  => $connections,
    ];
}

$windows = ['1h' => 3600, '24h' => 86400, '7d' => 604800];
$window  = $_GET['window'] ?? '1h';
if (!isset($windows[$window])) $window = '1h';
$since = time() - $windows[$window];

$view = ($_GET['view'] ?? '') === 'detail' ? 'detail' : 'table';
$site_raw = $_GET['site'] ?? '';
$site = basename($site_raw); // strip any path traversal attempt
$site_enc = htmlspecialchars(urlencode($site), ENT_QUOTES);
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Website Usage Dashboard</title>
<meta http-equiv="refresh" content="60">
<script src="chart.umd.min.js"></script>
<style>
body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:1.5rem}
h1{font-size:1.3rem}
a{color:#60a5fa;text-decoration:none}
table{width:100%;border-collapse:collapse;margin-top:1rem}
th,td{padding:.5rem .7rem;text-align:left;border-bottom:1px solid #334155;font-size:.9rem}
th{color:#94a3b8;font-weight:600}
.badge{padding:.15rem .6rem;border-radius:999px;font-size:.8rem}
.ok{background:#14532d;color:#86efac}
.hit{background:#7f1d1d;color:#fca5a5}
.tabs{margin:1rem 0}
.tabs a{margin-right:.8rem;padding:.3rem .7rem;border-radius:6px;background:#1e293b}
.tabs a.active{background:#3b82f6;color:#fff}
.card{background:#1e293b;border-radius:12px;padding:1rem;margin-bottom:1rem}
canvas{max-height:280px}
.top{display:flex;justify-content:space-between;align-items:center}
</style></head><body>
<div class="top">
  <h1>Website Resource Usage</h1>
  <a href="?logout=1">Logout</a>
</div>
<div class="tabs">
  <a href="?window=1h&view=<?= $view ?>&site=<?= $site_enc ?>" class="<?= $window == '1h' ? 'active' : '' ?>">Last 1h</a>
  <a href="?window=24h&view=<?= $view ?>&site=<?= $site_enc ?>" class="<?= $window == '24h' ? 'active' : '' ?>">Last 24h</a>
  <a href="?window=7d&view=<?= $view ?>&site=<?= $site_enc ?>" class="<?= $window == '7d' ? 'active' : '' ?>">Last 7d</a>
</div>

<?php if ($view === 'detail' && $site !== ''): ?>
  <?php
    $file = $LOG_DIR . '/' . $site . '.csv';
    $rows = read_csv_rows($file, $since);
    $labels = []; $cpu_data = []; $mem_data = []; $swap_data = [];
    $riops_data = []; $wiops_data = []; $conn_data = []; $nproc_data = [];
    $prev = null;
    foreach ($rows as $r) {
        if ($prev !== null) {
            $dt = (int)$r[1] - (int)$prev[1];
            if ($dt > 0) {
                $labels[]     = date('M j H:i', (int)$r[1]);
                $cpu_data[]   = round((((float)$r[3] - (float)$prev[3]) / 1000000) / $dt, 3);
                $mem_data[]   = round((float)$r[6] / 1048576, 1);
                $swap_data[]  = round((float)$r[17] / 1048576, 1);
                $riops_data[] = round((((float)$r[13] - (float)$prev[13])) / $dt, 1);
                $wiops_data[] = round((((float)$r[14] - (float)$prev[14])) / $dt, 1);
                $conn_data[]  = (int)$r[22];
                $nproc_data[] = (int)$r[20];
            }
        }
        $prev = $r;
    }
    $sum = summarize($rows);
  ?>
  <p><a href="?window=<?= $window ?>">&larr; Back to all sites</a></p>
  <h2><?= htmlspecialchars($site) ?></h2>
  <?php if ($sum): ?>
  <div class="card">
    vCPU allocation: <b><?= $sum['vcpu'] !== null ? $sum['vcpu'] : 'unlimited' ?></b> &nbsp;|&nbsp;
    Avg CPU (<?= $window ?>): <b><?= round($sum['avg_cpu'], 2) ?> cores</b> &nbsp;|&nbsp;
    Memory now: <b><?= round($sum['mem_mb']) ?> MB<?= $sum['mem_max_mb'] ? ' / ' . round($sum['mem_max_mb']) . ' MB' : '' ?></b> &nbsp;|&nbsp;
    Swap now: <b><?= round($sum['swap_mb']) ?> MB<?= $sum['swap_max_mb'] ? ' / ' . round($sum['swap_max_mb']) . ' MB' : ' / unlimited' ?></b> &nbsp;|&nbsp;
    nproc: <b><?= $sum['pids'] ?><?= $sum['pids_max'] ? ' / ' . $sum['pids_max'] : '' ?></b> &nbsp;|&nbsp;
    Connections: <b><?= $sum['connections'] ?></b>
    <br><br>
    Throttled: <span class="badge <?= $sum['throttle'] > 0 ? 'hit' : 'ok' ?>"><?= $sum['throttle'] ?>x</span> &nbsp;
    OOM killed: <span class="badge <?= $sum['oom'] > 0 ? 'hit' : 'ok' ?>"><?= $sum['oom'] ?>x</span> &nbsp;
    Swap fail: <span class="badge <?= $sum['swap_fail'] > 0 ? 'hit' : 'ok' ?>"><?= $sum['swap_fail'] ?>x</span> &nbsp;
    Read IOPS: <b><?= round($sum['riops'], 1) ?><?= $sum['riops_max'] ? ' / ' . $sum['riops_max'] : '' ?></b> &nbsp;
    Write IOPS: <b><?= round($sum['wiops'], 1) ?><?= $sum['wiops_max'] ? ' / ' . $sum['wiops_max'] : '' ?></b>
  </div>
  <?php else: ?>
    <p>Not enough data logged yet for this window.</p>
  <?php endif; ?>
  <div class="card"><canvas id="cpuChart"></canvas></div>
  <div class="card"><canvas id="memChart"></canvas></div>
  <div class="card"><canvas id="swapChart"></canvas></div>
  <div class="card"><canvas id="iopsChart"></canvas></div>
  <div class="card"><canvas id="nprocChart"></canvas></div>
  <div class="card"><canvas id="connChart"></canvas></div>
  <script>
    const labels = <?= json_encode($labels) ?>;
    new Chart(document.getElementById('cpuChart'), {
      type: 'line',
      data: { labels, datasets: [{ label: 'CPU cores used', data: <?= json_encode($cpu_data) ?>, borderColor: '#60a5fa', tension: .2, pointRadius: 0 }] },
      options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });
    new Chart(document.getElementById('memChart'), {
      type: 'line',
      data: { labels, datasets: [{ label: 'Memory (MB)', data: <?= json_encode($mem_data) ?>, borderColor: '#34d399', tension: .2, pointRadius: 0 }] },
      options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });
    new Chart(document.getElementById('swapChart'), {
      type: 'line',
      data: { labels, datasets: [{ label: 'Swap (MB)', data: <?= json_encode($swap_data) ?>, borderColor: '#fb923c', tension: .2, pointRadius: 0 }] },
      options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });
    new Chart(document.getElementById('iopsChart'), {
      type: 'line',
      data: { labels, datasets: [
        { label: 'Read IOPS', data: <?= json_encode($riops_data) ?>, borderColor: '#38bdf8', tension: .2, pointRadius: 0 },
        { label: 'Write IOPS', data: <?= json_encode($wiops_data) ?>, borderColor: '#f472b6', tension: .2, pointRadius: 0 }
      ] },
      options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });
    new Chart(document.getElementById('nprocChart'), {
      type: 'line',
      data: { labels, datasets: [{ label: 'nproc', data: <?= json_encode($nproc_data) ?>, borderColor: '#a78bfa', tension: .2, pointRadius: 0 }] },
      options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });
    new Chart(document.getElementById('connChart'), {
      type: 'line',
      data: { labels, datasets: [{ label: 'Concurrent connections', data: <?= json_encode($conn_data) ?>, borderColor: '#facc15', tension: .2, pointRadius: 0 }] },
      options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });
  </script>

<?php else: ?>
  <table>
    <tr>
      <th>Website</th><th>Avg CPU</th><th>vCPU limit</th><th>Memory</th><th>Swap</th>
      <th>IOPS (r/w)</th><th>nproc</th><th>Conns</th>
      <th>Throttled (<?= $window ?>)</th><th>OOM killed (<?= $window ?>)</th>
      <th>Swap fail (<?= $window ?>)</th><th></th>
    </tr>
    <?php foreach (list_sites($LOG_DIR) as $s): ?>
      <?php
        $rows = read_csv_rows($LOG_DIR . '/' . $s . '.csv', $since);
        $sum = summarize($rows);
        if (!$sum) continue;
        $s_enc = htmlspecialchars(urlencode($s), ENT_QUOTES);
      ?>
      <tr>
        <td><?= htmlspecialchars($s) ?></td>
        <td><?= round($sum['avg_cpu'], 2) ?></td>
        <td><?= $sum['vcpu'] !== null ? $sum['vcpu'] : 'unlimited' ?></td>
        <td><?= round($sum['mem_mb']) ?> MB<?= $sum['mem_max_mb'] ? ' / ' . round($sum['mem_max_mb']) . ' MB' : '' ?></td>
        <td><?= round($sum['swap_mb']) ?> MB<?= $sum['swap_max_mb'] ? ' / ' . round($sum['swap_max_mb']) . ' MB' : ' / unlimited' ?></td>
        <td><?= round($sum['riops'], 1) ?> / <?= round($sum['wiops'], 1) ?><?= $sum['riops_max'] ? ' (limit ' . $sum['riops_max'] . ')' : '' ?></td>
        <td><?= $sum['pids'] ?><?= $sum['pids_max'] ? ' / ' . $sum['pids_max'] : '' ?></td>
        <td><?= $sum['connections'] ?></td>
        <td><span class="badge <?= $sum['throttle'] > 0 ? 'hit' : 'ok' ?>"><?= $sum['throttle'] ?></span></td>
        <td><span class="badge <?= $sum['oom'] > 0 ? 'hit' : 'ok' ?>"><?= $sum['oom'] ?></span></td>
        <td><span class="badge <?= $sum['swap_fail'] > 0 ? 'hit' : 'ok' ?>"><?= $sum['swap_fail'] ?></span></td>
        <td><a href="?view=detail&site=<?= $s_enc ?>&window=<?= $window ?>">Details &rarr;</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

</body></html>
