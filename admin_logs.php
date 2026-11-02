<?php
session_start();
require 'db.php';
require_once __DIR__ . '/layout.php';
shell_redirect_if_top('admin');
date_default_timezone_set('Asia/Manila');   // Philippine Time
try { $pdo->exec("SET time_zone = '+08:00'"); } catch (Throwable $e) { }

// Prevent back-button caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['username']) || $_SESSION['username'] !== 'Admin') {
    header("Location: login.php");
    exit;
}

// One-time upgrade: allow the 'archived' status in the users table (safe to run repeatedly)
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($col && stripos($col['Type'], 'archived') === false) {
        $pdo->exec("ALTER TABLE users MODIFY status ENUM('pending','approved','locked','archived') NOT NULL DEFAULT 'pending'");
    }
} catch (Throwable $e) { /* ignore */ }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

// ---- Account actions (POST + CSRF). Accounts are never deleted: Archive only blocks access. ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $id = (int)($_POST['id'] ?? 0);
        $act = $_POST['action'] ?? '';
        if ($act === 'approve') {
            $pdo->prepare("UPDATE users SET status='approved', failed_attempts=0 WHERE id=? AND username!='Admin'")->execute([$id]);
        } elseif ($act === 'archive') {
            $pdo->prepare("UPDATE users SET status='archived' WHERE id=? AND username!='Admin'")->execute([$id]);
        } elseif ($act === 'restore') {
            $pdo->prepare("UPDATE users SET status='approved', failed_attempts=0 WHERE id=? AND username!='Admin' AND status='archived'")->execute([$id]);
        }
    }
    header("Location: admin_logs.php?" . http_build_query(array_intersect_key($_GET, array_flip(['range','page','ustatus','upage']))) . "#users");
    exit;
}

$username = $_SESSION['username'];
$initial = strtoupper(substr($username, 0, 1));
$PER_PAGE = 10;

// Build a link that keeps the current filters
function qs(array $over = [], string $hash = ''): string {
    $q = array_merge(array_intersect_key($_GET, array_flip(['range','page','ustatus','upage'])), $over);
    foreach ($q as $k => $v) if ($v === '' || $v === null) unset($q[$k]);
    return 'admin_logs.php' . ($q ? '?' . http_build_query($q) : '') . $hash;
}
// Pager: Prev, numbers (with ...), Next
function pager(int $page, int $pages, string $key, string $hash): string {
    if ($pages <= 1) return '';
    $nums = array_unique(array_filter([1, $page-2, $page-1, $page, $page+1, $page+2, $pages], fn($n) => $n >= 1 && $n <= $pages));
    sort($nums);
    $h = '<div class="pager">';
    $h .= $page > 1 ? '<a href="'.qs([$key => $page-1], $hash).'">&laquo; Prev</a>' : '<span class="dis">&laquo; Prev</span>';
    $prev = 0;
    foreach ($nums as $n) {
        if ($n - $prev > 1) $h .= '<span class="dis">…</span>';
        $h .= $n == $page ? '<span class="cur">'.$n.'</span>' : '<a href="'.qs([$key => $n], $hash).'">'.$n.'</a>';
        $prev = $n;
    }
    $h .= $page < $pages ? '<a href="'.qs([$key => $page+1], $hash).'">Next &raquo;</a>' : '<span class="dis">Next &raquo;</span>';
    return $h . '</div>';
}

// ---- Login audit trail: range filter + pager ----
$range = $_GET['range'] ?? 'all';
if (!in_array($range, ['today', 'week', 'month', 'all'], true)) $range = 'all';
$now = new DateTime('now', new DateTimeZone('Asia/Manila'));      // Philippine Time
$where = ''; $params = [];
if ($range === 'today')      { $where = 'WHERE login_time >= ?'; $params[] = $now->format('Y-m-d') . ' 00:00:00'; }
elseif ($range === 'week')   { $where = 'WHERE login_time >= ?'; $params[] = (clone $now)->modify('-7 days')->format('Y-m-d H:i:s'); }
elseif ($range === 'month')  { $where = 'WHERE login_time >= ?'; $params[] = (clone $now)->modify('-30 days')->format('Y-m-d H:i:s'); }

$c = $pdo->prepare("SELECT COUNT(*) FROM login_logs $where"); $c->execute($params);
$logTotal = (int)$c->fetchColumn();
$logPages = max(1, (int)ceil($logTotal / $PER_PAGE));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $logPages);
$s = $pdo->prepare("SELECT * FROM login_logs $where ORDER BY login_time DESC LIMIT $PER_PAGE OFFSET " . (($page-1)*$PER_PAGE));
$s->execute($params);
$logs = $s->fetchAll();

// ---- Users: status matrix + filter + pager ----
$counts = ['approved'=>0,'pending'=>0,'locked'=>0,'archived'=>0];
foreach ($pdo->query("SELECT status, COUNT(*) n FROM users GROUP BY status")->fetchAll() as $r) {
    if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['n'];
}
$totalUsers = array_sum($counts);
$ustatus = $_GET['ustatus'] ?? 'all';
if (!isset($counts[$ustatus])) $ustatus = 'all';
$uw = $ustatus === 'all' ? '' : 'WHERE status = ?'; $up = $ustatus === 'all' ? [] : [$ustatus];
$c = $pdo->prepare("SELECT COUNT(*) FROM users $uw"); $c->execute($up);
$userTotal = (int)$c->fetchColumn();
$userPages = max(1, (int)ceil($userTotal / $PER_PAGE));
$upage = min(max(1, (int)($_GET['upage'] ?? 1)), $userPages);
$s = $pdo->prepare("SELECT * FROM users $uw ORDER BY id LIMIT $PER_PAGE OFFSET " . (($upage-1)*$PER_PAGE));
$s->execute($up);
$users = $s->fetchAll();

$statusColor = ['approved'=>'var(--green)','pending'=>'var(--amber)','locked'=>'var(--red)','archived'=>'var(--text3)'];
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<?php
layout_start([
    'title' => 'Admin Logs & Users', 'heading' => 'Admin logs & users',
    'sub' => 'Login audit trail and account management.',
    'active' => 'admin', 'isAdmin' => true, 'username' => $username,
]);
?>
    <!-- User Login Audit Trail Panel -->
    <div class="panel" style="margin-bottom: 26px;" id="audit">
      <div class="panel-head">User login audit trail</div>
      <div class="panel-body">
        <div class="range-tabs">
          <?php foreach (['today'=>'Today','week'=>'Last week','month'=>'Last month','all'=>'All'] as $k=>$label): ?>
            <a href="<?= qs(['range'=>$k,'page'=>''], '#audit') ?>" class="<?= $range===$k?'on':'' ?>"><?= $label ?></a>
          <?php endforeach; ?>
        </div>
        <div class="table-wrapper">
          <table class="custom-table">
            <thead><tr><th>Username</th><th>Login Timestamp</th></tr></thead>
            <tbody>
              <?php foreach($logs as $l): ?>
              <tr>
                <td><?= $e($l['username']) ?></td>
                <td><?= $e(date('M d, Y · h:i:s A', strtotime($l['login_time']))) ?> PHT</td>
              </tr>
              <?php endforeach; ?>
              <?php if(!$logs): ?>
              <tr><td colspan="2" style="text-align:center; color:var(--text3);">No login logs for this period.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?= pager($page, $logPages, 'page', '#audit') ?>
        <div class="tbl-info"><?= $logTotal ?> record(s) · page <?= $page ?> of <?= $logPages ?></div>
      </div>
    </div>

    <!-- Registered System Users Management Panel -->
    <div class="panel" id="users">
      <div class="panel-head">Registered users &amp; account management</div>
      <div class="panel-body">
        <div class="matrix">
          <a href="<?= qs(['ustatus'=>'','upage'=>''], '#users') ?>" class="<?= $ustatus==='all'?'on':'' ?>"><div class="n"><?= $totalUsers ?></div><div class="l">Total</div></a>
          <?php foreach (['approved'=>'Approved','pending'=>'Pending','locked'=>'Locked','archived'=>'Archived'] as $k=>$label): ?>
          <a href="<?= qs(['ustatus'=>$k,'upage'=>''], '#users') ?>" class="<?= $ustatus===$k?'on':'' ?>"><div class="n" style="color:<?= $statusColor[$k] ?>"><?= $counts[$k] ?></div><div class="l"><?= $label ?></div></a>
          <?php endforeach; ?>
        </div>
        <form method="GET" action="admin_logs.php#users" class="user-filter">
          <?php if ($range !== 'all'): ?><input type="hidden" name="range" value="<?= $e($range) ?>"><?php endif; ?>
          <label for="ustatus">Show:</label>
          <select name="ustatus" id="ustatus" class="select-box" onchange="this.form.submit()">
            <option value="all" <?= $ustatus==='all'?'selected':'' ?>>All users</option>
            <option value="approved" <?= $ustatus==='approved'?'selected':'' ?>>Approved</option>
            <option value="pending" <?= $ustatus==='pending'?'selected':'' ?>>Pending</option>
            <option value="archived" <?= $ustatus==='archived'?'selected':'' ?>>Archived</option>
            <option value="locked" <?= $ustatus==='locked'?'selected':'' ?>>Locked</option>
          </select>
          <noscript><button class="btn" type="submit">Apply</button></noscript>
        </form>
        <div class="table-wrapper">
          <table class="custom-table">
            <thead>
              <tr><th>ID</th><th>Username</th><th>Email</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach($users as $u): ?>
              <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><?= $e($u['username']) ?></td>
                <td><?= $e($u['email']) ?></td>
                <td class="st-<?= $e($u['status']) ?>"><?= $e(ucfirst($u['status'])) ?></td>
                <td>
                  <?php if($u['username'] !== 'Admin'): ?>
                    <form method="POST" action="<?= qs([], '#users') ?>" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= $e($_SESSION['csrf']) ?>">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                      <?php if($u['status'] === 'archived'): ?>
                        <button class="linkbtn" style="color:var(--green)" name="action" value="restore">Restore</button>
                      <?php else: ?>
                        <?php if($u['status'] !== 'approved'): ?>
                          <button class="linkbtn" style="color:var(--green)" name="action" value="approve"><?= $u['status'] === 'locked' ? 'Unlock' : 'Approve' ?></button>
                        <?php endif; ?>
                        <button type="button" class="linkbtn js-archive" style="color:var(--red)" data-user="<?= $e($u['username']) ?>">Archive</button>
                      <?php endif; ?>
                    </form>
                  <?php else: ?>
                    <span style="color: var(--text3);">Protected Root</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if(!$users): ?>
              <tr><td colspan="5" style="text-align:center; color:var(--text3);">No users with this status.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?= pager($upage, $userPages, 'upage', '#users') ?>
        <div class="tbl-info"><?= $userTotal ?> user(s) · page <?= $upage ?> of <?= $userPages ?></div>
      </div>
    </div>

<div class="modal-backdrop" id="confirmModal" aria-hidden="true">
  <div class="modal-card" role="alertdialog" aria-modal="true">
    <div class="modal-icon">📦</div>
    <h3>Archive account?</h3>
    <p id="confirmText"></p>
    <div class="modal-actions">
      <button type="button" class="btn" id="confirmCancel">Cancel</button>
      <button type="button" class="btn danger" id="confirmOk">Archive</button>
    </div>
  </div>
</div>
<?php layout_end(<<<'JS'
<script>
  let pendingForm = null;
  const modal = document.getElementById('confirmModal');
  document.querySelectorAll('.js-archive').forEach(b => b.addEventListener('click', () => {
    pendingForm = b.closest('form');
    document.getElementById('confirmText').textContent =
      'Archive "' + b.dataset.user + '"? They will no longer be able to log in. Their account details stay saved and you can restore them any time.';
    modal.classList.add('show');
  }));
  document.getElementById('confirmCancel').onclick = () => { modal.classList.remove('show'); pendingForm = null; };
  modal.addEventListener('mousedown', ev => { if (ev.target === modal) modal.classList.remove('show'); });
  document.addEventListener('keydown', ev => { if (ev.key === 'Escape') modal.classList.remove('show'); });
  document.getElementById('confirmOk').onclick = () => {
    if (!pendingForm) return;
    const h = document.createElement('input'); h.type = 'hidden'; h.name = 'action'; h.value = 'archive';
    pendingForm.appendChild(h); pendingForm.submit();
  };
</script>
JS
); ?>