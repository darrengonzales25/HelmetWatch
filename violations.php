<?php
session_start();
require_once __DIR__ . "/check_active.php";
require_once 'db.php';
require_once __DIR__ . "/layout.php";
date_default_timezone_set('Asia/Manila');                                   // Philippine Time
try { $pdo->exec("SET time_zone = '+08:00'"); } catch (Throwable $e) { }
shell_redirect_if_top('violations');

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$isAdmin = (isset($_SESSION['username']) && $_SESSION['username'] === 'Admin') ||
           (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin');
$username = $_SESSION['username'] ?? 'User';
$initial = strtoupper(substr($username, 0, 1));

$message = "";
$messageType = "";

try {
    $col = $pdo->query("SHOW COLUMNS FROM violations LIKE 'archived'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec("ALTER TABLE violations ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Throwable $e) {
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function vqs(array $over = []): string {
    $q = array_merge(array_intersect_key($_GET, array_flip(['range', 'vtype', 'page', 'view'])), $over);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null || $v === 'all' || ($k === 'page' && (int)$v <= 1) || ($k === 'view' && $v === 'active')) unset($q[$k]);
    }
    return 'violations.php' . ($q ? '?' . http_build_query($q) : '');
}

$view = ($isAdmin && ($_GET['view'] ?? '') === 'archived') ? 'archived' : 'active';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $_SESSION['flash_msg'] = "Access denied: Only Administrator accounts can archive violation records.";
        $_SESSION['flash_type'] = "error";
        header("Location: violations.php");
        exit;
    }

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $_SESSION['flash_msg'] = "Invalid request. Please try again.";
        $_SESSION['flash_type'] = "error";
        header("Location: " . vqs());
        exit;
    }

    $ids = [];
    if (isset($_POST['archive_id']) || isset($_POST['restore_id'])) {
        $ids = [(int)($_POST['archive_id'] ?? $_POST['restore_id'])];
    } elseif (!empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
        $ids = array_map('intval', $_POST['selected_ids']);
    }
    $ids = array_values(array_filter($ids, fn($i) => $i > 0));
    $restore = isset($_POST['restore_id']) || isset($_POST['bulk_restore']);

    if (!$ids) {
        $_SESSION['flash_msg'] = "No records were selected.";
        $_SESSION['flash_type'] = "error";
    } else {
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE violations SET archived = ? WHERE id IN ($in)");
            $stmt->execute(array_merge([$restore ? 0 : 1], $ids));
            $n = count($ids);
            $_SESSION['flash_msg'] = $restore
                ? "Restored $n violation record(s). They are visible to everyone again."
                : "Archived $n violation record(s). Only the Admin can see them now.";
            $_SESSION['flash_type'] = "success";
        } catch (PDOException $e) {
            $_SESSION['flash_msg'] = "Error updating records: " . $e->getMessage();
            $_SESSION['flash_type'] = "error";
        }
    }
    header("Location: " . vqs());
    exit;
}

$PER_PAGE = 12;
$range = $_GET['range'] ?? 'all';
if (!in_array($range, ['today', 'week', 'month', 'all'], true)) $range = 'all';
$vtype = $_GET['vtype'] ?? 'all';
if (!in_array($vtype, ['nohelmet', 'other', 'all'], true)) $vtype = 'all';

$violations = [];
$total = 0; $pages = 1; $page = 1;
try {
    $where = [$view === 'archived' ? 'archived = 1' : 'archived = 0'];
    $params = [];
    $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
    if ($range === 'today')      { $where[] = 'timestamp >= ?'; $params[] = $now->format('Y-m-d') . ' 00:00:00'; }
    elseif ($range === 'week')   { $where[] = 'timestamp >= ?'; $params[] = (clone $now)->modify('-7 days')->format('Y-m-d H:i:s'); }
    elseif ($range === 'month')  { $where[] = 'timestamp >= ?'; $params[] = (clone $now)->modify('-30 days')->format('Y-m-d H:i:s'); }
    if ($vtype === 'nohelmet')   { $where[] = "LOWER(violation_type) LIKE '%no_helmet%'"; }
    elseif ($vtype === 'other')  { $where[] = "(violation_type IS NULL OR LOWER(violation_type) NOT LIKE '%no_helmet%')"; }
    $w = 'WHERE ' . implode(' AND ', $where);

    $c = $pdo->prepare("SELECT COUNT(*) FROM violations $w"); $c->execute($params);
    $total = (int)$c->fetchColumn();
    $pages = max(1, (int)ceil($total / $PER_PAGE));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
    $stmt = $pdo->prepare("SELECT * FROM violations $w ORDER BY timestamp DESC LIMIT $PER_PAGE OFFSET " . (($page - 1) * $PER_PAGE));
    $stmt->execute($params);
    $violations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $message = "Database query failed: " . $e->getMessage();
    $messageType = "error";
}

if (isset($_SESSION['flash_msg'])) {
    $message = $_SESSION['flash_msg'];
    $messageType = $_SESSION['flash_type'];
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}
?>
<?php
layout_start([
    'title' => $view === 'archived' ? 'Archived Violations' : 'Violations Archive',
    'heading' => $view === 'archived' ? 'Archived violations' : 'Violations archive',
    'sub' => $view === 'archived' ? 'Hidden from everyone except the Admin.' : 'Every captured helmet violation.',
    'active' => 'violations', 'isAdmin' => $isAdmin, 'username' => $username,
]);
?>
    <div class="panel">
      <div class="panel-head">
        <span><?= $view === 'archived' ? 'Archived violations' : 'Captured violations' ?></span>
        <span class="pill"><?= $total ?> record<?= $total === 1 ? '' : 's' ?></span>
      </div>

      <div class="panel-body">
        <?php if (!empty($message)): ?>
          <div class="flash <?= $messageType === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="filter-block">
          <?php if ($isAdmin): ?>
          <div class="filter-row"><span class="filter-label">View</span>
            <div class="range-tabs">
              <?php foreach (['active' => 'Active', 'archived' => 'Archived'] as $k => $label): ?>
                <a href="<?= vqs(['view' => $k === 'active' ? '' : $k, 'page' => '']) ?>" class="<?= $view === $k ? 'on' : '' ?>"><?= $label ?></a>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
          <div class="filter-row"><span class="filter-label">Period</span>
            <div class="range-tabs">
              <?php foreach (['all' => 'All', 'today' => 'Today', 'week' => 'Last week', 'month' => 'Last month'] as $k => $label): ?>
                <a href="<?= vqs(['range' => $k, 'page' => '']) ?>" class="<?= $range === $k ? 'on' : '' ?>"><?= $label ?></a>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="filter-row"><span class="filter-label">Type</span>
            <div class="range-tabs">
              <?php foreach (['all' => 'All', 'nohelmet' => 'No helmet', 'other' => 'Other helmet'] as $k => $label): ?>
                <a href="<?= vqs(['vtype' => $k, 'page' => '']) ?>" class="<?= $vtype === $k ? 'on' : '' ?>"><?= $label ?></a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <?php if (!empty($violations)): ?>
          <form id="bulkForm" action="<?= htmlspecialchars(vqs()) ?>" method="POST">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

            <?php if ($isAdmin): ?>
              <div class="sticky-toolbar">
                <label class="check-label">
                  <input type="checkbox" id="selectAllCheckbox"> Select all on this page
                </label>
                <?php if ($view === 'archived'): ?>
                  <button type="button" id="bulkActionBtn" class="btn sm" data-field="bulk_restore" data-verb="Restore" disabled>
                    Restore selected (<span id="selectedCount">0</span>)
                  </button>
                <?php else: ?>
                  <button type="button" id="bulkActionBtn" class="btn danger sm" data-field="bulk_archive" data-verb="Archive" disabled>
                    Archive selected (<span id="selectedCount">0</span>)
                  </button>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="admin-only-notice">🔒 Archive controls are restricted to Administrator accounts.</div>
            <?php endif; ?>

            <div class="violations-grid">
              <?php foreach ($violations as $row): ?>
                <?php
                  $confidencePct = number_format(($row['confidence'] <= 1.0 ? $row['confidence'] * 100 : $row['confidence']), 1);
                  $formattedTime = date("M d, Y • h:i:s A", strtotime($row['timestamp'])) . ' PHT';
                  $loc = trim((string)($row['location'] ?? ''));
                ?>
                <div class="grid-card">
                  <div class="grid-card-img-wrap">
                    <img src="<?= htmlspecialchars($row['filepath']) ?>" alt="Violation image" class="grid-card-img" loading="lazy" onerror="this.onerror=null; this.style.visibility='hidden';">
                    <?php if ($isAdmin): ?>
                      <div class="grid-checkbox-overlay">
                        <input type="checkbox" name="selected_ids[]" value="<?= (int)$row['id'] ?>" class="record-checkbox">
                      </div>
                    <?php endif; ?>
                  </div>

                  <div class="grid-card-body">
                    <span class="badge-red"><?= htmlspecialchars(ucfirst(strtolower(str_replace(['-', '_'], ' ', $row['violation_type'])))) ?></span>
                    <p class="conf">Confidence: <strong><?= $confidencePct ?>%</strong></p>
                    <p class="when"><?= htmlspecialchars($formattedTime) ?></p>
                    <?php if ($loc !== ''): ?><p class="when">📍 <?= htmlspecialchars($loc) ?></p><?php endif; ?>
                    <div class="grid-card-actions">
                      <button type="button" class="btn sm js-view-photo" data-src="<?= htmlspecialchars($row['filepath']) ?>" data-caption="<?= htmlspecialchars(ucfirst(strtolower(str_replace(['-', '_'], ' ', $row['violation_type']))) . ' • ' . $confidencePct . '% • ' . $formattedTime . ($loc !== '' ? ' • ' . $loc : '')) ?>">View</button>
                      <?php if ($isAdmin && $view === 'archived'): ?>
                        <button type="button" class="btn sm js-action-btn" data-field="restore_id" data-value="<?= (int)$row['id'] ?>" data-title="Restore violation?" data-ok="Restore" data-confirm-text="This violation will be visible to all users again.">Restore</button>
                      <?php elseif ($isAdmin): ?>
                        <button type="button" class="btn danger sm js-action-btn" data-field="archive_id" data-value="<?= (int)$row['id'] ?>" data-title="Archive violation?" data-ok="Archive" data-confirm-text="This violation and its photo will be hidden from everyone except the Admin. You can restore it anytime from the Archived view.">Archive</button>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </form>
          <?php if ($pages > 1): ?>
          <div class="pager">
            <?php if ($page > 1): ?><a href="<?= vqs(['page' => $page - 1]) ?>">&laquo; Prev</a><?php else: ?><span class="dis">&laquo; Prev</span><?php endif; ?>
            <?php $prev = 0; foreach (array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], fn($n) => $n >= 1 && $n <= $pages)) as $n): ?>
              <?php if ($n - $prev > 1): ?><span class="dis">…</span><?php endif; ?>
              <?php if ($n == $page): ?><span class="cur"><?= $n ?></span><?php else: ?><a href="<?= vqs(['page' => $n]) ?>"><?= $n ?></a><?php endif; ?>
              <?php $prev = $n; endforeach; ?>
            <?php if ($page < $pages): ?><a href="<?= vqs(['page' => $page + 1]) ?>">Next &raquo;</a><?php else: ?><span class="dis">Next &raquo;</span><?php endif; ?>
          </div>
          <div class="tbl-info">Page <?= $page ?> of <?= $pages ?></div>
          <?php endif; ?>
        <?php else: ?>
          <div class="empty-note inline"><?= $view === 'archived' ? 'No archived violations.' : (($range !== 'all' || $vtype !== 'all') ? 'No violations match these filters.' : 'No violations recorded yet.') ?></div>
        <?php endif; ?>
      </div>
    </div>

<div class="modal-backdrop" id="confirmModal" aria-hidden="true">
  <div class="modal-card" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle" aria-describedby="confirmText">
    <div class="modal-icon">📦</div>
    <h3 id="confirmTitle">Archive violation?</h3>
    <p id="confirmText"></p>
    <div class="modal-actions">
      <button type="button" class="btn" id="confirmCancel">Cancel</button>
      <button type="button" class="btn danger" id="confirmOk">Archive</button>
    </div>
  </div>
</div>
<div class="modal-backdrop" id="photoModal" aria-hidden="true">
  <div class="photo-card" role="dialog" aria-modal="true" aria-label="Violation photo">
    <button type="button" class="photo-close" id="photoClose" aria-label="Close">&times;</button>
    <img id="photoImg" alt="Violation photo">
    <div class="photo-caption" id="photoCaption"></div>
  </div>
</div>
<?php layout_end(<<<'JS'
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const recordCheckboxes = document.querySelectorAll('.record-checkbox');
    const bulkBtn = document.getElementById('bulkActionBtn');
    const selectedCountSpan = document.getElementById('selectedCount');

    function updateSelectionState() {
      const checkedCount = document.querySelectorAll('.record-checkbox:checked').length;
      if (selectedCountSpan) selectedCountSpan.textContent = checkedCount;

      if (bulkBtn) {
        bulkBtn.disabled = checkedCount === 0;
        bulkBtn.style.opacity = checkedCount > 0 ? '1' : '0.5';
        bulkBtn.style.cursor = checkedCount > 0 ? 'pointer' : 'not-allowed';
      }

      if (selectAllCheckbox && recordCheckboxes.length > 0) {
        if (checkedCount === recordCheckboxes.length) {
          selectAllCheckbox.checked = true;
          selectAllCheckbox.indeterminate = false;
        } else if (checkedCount > 0) {
          selectAllCheckbox.checked = false;
          selectAllCheckbox.indeterminate = true;
        } else {
          selectAllCheckbox.checked = false;
          selectAllCheckbox.indeterminate = false;
        }
      }
    }

    if (selectAllCheckbox) {
      selectAllCheckbox.addEventListener('change', function () {
        recordCheckboxes.forEach(cb => { cb.checked = selectAllCheckbox.checked; });
        updateSelectionState();
      });
    }

    recordCheckboxes.forEach(cb => {
      cb.addEventListener('change', updateSelectionState);
    });
  });

  (function () {
    const modal = document.getElementById('confirmModal');
    const titleEl = document.getElementById('confirmTitle');
    const textEl = document.getElementById('confirmText');
    const okBtn = document.getElementById('confirmOk');
    const cancelBtn = document.getElementById('confirmCancel');
    const form = document.getElementById('bulkForm');
    let onConfirm = null;
    let lastFocus = null;

    function openConfirm(title, text, okLabel, callback) {
      titleEl.textContent = title;
      textEl.textContent = text;
      okBtn.textContent = okLabel;
      onConfirm = callback;
      lastFocus = document.activeElement;
      modal.classList.add('show');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      cancelBtn.focus();
    }

    function closeConfirm() {
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      onConfirm = null;
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    function submitWith(name, value) {
      const flag = document.createElement('input');
      flag.type = 'hidden';
      flag.name = name;
      flag.value = value;
      form.appendChild(flag);
      form.submit();
    }

    cancelBtn.addEventListener('click', closeConfirm);
    okBtn.addEventListener('click', function () {
      const cb = onConfirm;
      closeConfirm();
      if (cb) cb();
    });
    modal.addEventListener('mousedown', function (e) { if (e.target === modal) closeConfirm(); });
    document.addEventListener('keydown', function (e) {
      if (!modal.classList.contains('show')) return;
      if (e.key === 'Escape') { closeConfirm(); return; }
      if (e.key === 'Tab') {
        e.preventDefault();
        (document.activeElement === cancelBtn ? okBtn : cancelBtn).focus();
      }
    });

    document.addEventListener('click', function (e) {
      const btn = e.target.closest('.js-action-btn');
      if (!btn || !form) return;
      e.preventDefault();
      openConfirm(btn.dataset.title, btn.dataset.confirmText, btn.dataset.ok, function () {
        submitWith(btn.dataset.field, btn.dataset.value);
      });
    });

    const bulkBtn = document.getElementById('bulkActionBtn');
    if (bulkBtn && form) {
      bulkBtn.addEventListener('click', function (e) {
        e.preventDefault();
        const count = document.querySelectorAll('.record-checkbox:checked').length;
        if (count === 0) return;
        const verb = bulkBtn.dataset.verb;
        const noun = count === 1 ? 'violation' : 'violations';
        const text = verb === 'Archive'
          ? 'The selected ' + noun + ' and photo(s) will be hidden from everyone except the Admin. You can restore them anytime from the Archived view.'
          : 'The selected ' + noun + ' will be visible to all users again.';
        openConfirm(verb + ' ' + count + ' ' + noun + '?', text, verb + ' ' + count, function () {
          submitWith(bulkBtn.dataset.field, '1');
        });
      });
    }
  })();

  (function () {
    const modal = document.getElementById('photoModal');
    const img = document.getElementById('photoImg');
    const cap = document.getElementById('photoCaption');
    const closeBtn = document.getElementById('photoClose');
    if (!modal) return;
    let lastFocus = null;

    function openPhoto(src, caption) {
      cap.textContent = caption || '';
      img.src = src;
      lastFocus = document.activeElement;
      modal.classList.add('show');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      closeBtn.focus();
    }

    function closePhoto() {
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      img.removeAttribute('src');
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    img.addEventListener('error', function () {
      if (img.getAttribute('src')) cap.textContent = 'Image file not found.';
    });

    document.addEventListener('click', function (e) {
      const btn = e.target.closest('.js-view-photo');
      if (btn) { openPhoto(btn.dataset.src, btn.dataset.caption); return; }
      const thumb = e.target.closest('.grid-card-img');
      if (thumb) {
        const b = thumb.closest('.grid-card').querySelector('.js-view-photo');
        if (b) openPhoto(b.dataset.src, b.dataset.caption);
      }
    });

    closeBtn.addEventListener('click', closePhoto);
    modal.addEventListener('mousedown', function (e) { if (e.target === modal) closePhoto(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('show')) closePhoto();
    });
  })();
</script>

JS
); ?>