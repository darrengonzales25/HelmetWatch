<?php
session_start();
require_once __DIR__ . "/check_active.php";   // also loads db.php ($pdo)
require_once __DIR__ . "/layout.php";
date_default_timezone_set('Asia/Manila');   // Philippine Time
try { $pdo->exec("SET time_zone = '+08:00'"); } catch (Throwable $e) { }

// Strictly disable browser caching after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$isAdmin = (isset($_SESSION['username']) && $_SESSION['username'] === 'Admin');
$username = $_SESSION['username'];
$splash = $_SESSION['splash'] ?? '';
unset($_SESSION['splash']);

// Latest violations + total for the dashboard
try {
    $col = $pdo->query("SHOW COLUMNS FROM violations LIKE 'archived'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec("ALTER TABLE violations ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0");
    }
    $recent = $pdo->query("SELECT * FROM violations WHERE archived = 0 ORDER BY timestamp DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
    $vCount = (int)$pdo->query("SELECT COUNT(*) FROM violations WHERE archived = 0")->fetchColumn();
} catch (Throwable $e) { $recent = []; $vCount = 0; }

layout_start([
    'title' => 'Live Dashboard', 'heading' => 'Live dashboard',
    'sub' => 'Real-time helmet violation detection.',
    'active' => 'live', 'isAdmin' => $isAdmin, 'username' => $username, 'shell' => true,
    'head_extra' => '<script src="https://cdn.jsdelivr.net/npm/onnxruntime-web@1.30.0/dist/ort.webgpu.min.js"></script>',
]);
?>
<?php if ($splash !== ''): ?>
  <div id="hw-loader" class="hw-loader show" role="status" aria-live="polite">
    <div class="hw-loader-box">
      <div class="hw-loader-scan">
        <svg class="hw-loader-logo" viewBox="0 0 40 46" aria-hidden="true"><path d="M20 2 4 8v14c0 11 7 19 16 22 9-3 16-11 16-22V8L20 2z" fill="currentColor"/><path d="M12 26a8 8 0 0 1 16 0z" style="fill:var(--bg)"/></svg>
        <span class="hw-scanline"></span>
      </div>
      <div class="hw-loader-name">HelmetWatch</div>
      <div class="hw-loader-text"><?= h($splash) ?></div>
      <div class="hw-loader-bar"><span></span></div>
    </div>
  </div>
<?php endif; ?>
  <div id="view-live">
    <div id="statusStrip" class="status-strip">
      <div class="status-card"><span class="status-ico ok"><?= svg_icon('check') ?></span><div><div class="status-t">System online</div><div class="status-s">Detection ready</div></div></div>
      <div class="status-card"><span class="status-ico"><?= svg_icon('lock') ?></span><div><div class="status-t">Session active</div><div class="status-s">Signed in as <?= h($username) ?></div></div></div>
      <div class="status-card"><span class="status-ico"><?= svg_icon('engine') ?></span><div><div class="status-t">YOLOv8 engine</div><div class="status-s">Runs in your browser</div></div></div>
      <div class="status-card"><span class="status-ico"><?= svg_icon('archive') ?></span><div><div class="status-t"><?= $vCount ?> violation<?= $vCount === 1 ? '' : 's' ?> logged</div><div class="status-s">See the archive</div></div></div>
    </div>

    <div class="workspace-grid">
      <div class="panel">
        <div class="panel-head"><span>Live feed</span><span class="pill system-status-pill" id="system-status">Loading…</span></div>
        <div class="panel-body">
          <div class="camera-container" id="camera-container">
            <div id="alert-banner">⚠ VIOLATION DETECTED ON MOTORCYCLE</div>
            <video id="video" autoplay playsinline muted></video>
            <canvas id="overlay-canvas"></canvas>
            <div class="cam-placeholder" id="cam-placeholder"><p>Camera offline — click Start camera</p></div>
            <div id="model-overlay"><p>Initializing neural engine…</p></div>
          </div>
          <div class="cam-controls">
            <button class="btn primary" id="btn-start" onclick="startCamera()">Start camera</button>
            <button class="btn danger" id="btn-stop" onclick="stopCamera()" disabled>Stop</button>
            <select id="cam-select" class="select-box"><option value="">— select camera —</option></select>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head">Event log</div>
        <div class="panel-body">
          <div class="setting-row">
            <div class="row-top"><label for="cooldown-slider">Auto-capture cooldown</label><b id="cooldown-val">5 s</b></div>
            <input type="range" id="cooldown-slider" min="2" max="15" step="1" value="5" oninput="autoCooldown=+this.value; document.getElementById('cooldown-val').textContent=this.value+' s';">
          </div>
          <div id="event-log"></div>
        </div>
      </div>
    </div>

    <div id="recentBlock">
    <div class="section-head">
      <h2>Recent violations</h2>
      <a class="pill" href="violations.php" style="text-decoration:none;"><?= $vCount ?> logged</a>
    </div>
    <?php if ($recent): ?>
    <div class="recent-grid">
      <?php foreach ($recent as $r):
        $pct = number_format($r['confidence'] <= 1.0 ? $r['confidence'] * 100 : $r['confidence'], 1);
        $label = ucfirst(strtolower(str_replace(['-', '_'], ' ', $r['violation_type'])));
      ?>
      <a class="recent-card" href="violations.php">
        <img src="<?= h($r['filepath']) ?>" alt="Violation" loading="lazy" onerror="this.style.visibility='hidden'">
        <div class="recent-meta">
          <span class="badge-red"><?= h($label) ?></span>
          <span class="recent-date">Confidence: <strong style="color:var(--text)"><?= $pct ?>%</strong></span>
          <span class="recent-date"><?= h(date('M d, Y • h:i:s A', strtotime($r['timestamp'])) . ' PHT') ?></span>
          <?php $loc = trim((string)($r['location'] ?? '')); if ($loc !== ''): ?><span class="recent-date">📍 <?= h($loc) ?></span><?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <div class="empty-note">No violations captured yet.</div>
    <?php endif; ?>
    </div>
  </div>
  <iframe id="viewFrame" title="Page" hidden></iframe>
<?php layout_end('<script src="app.js"></script><script src="js/transitions.js"></script>'); ?>