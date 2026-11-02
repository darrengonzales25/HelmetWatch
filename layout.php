<?php
// Shared page frame (sidebar / top navigation, top bar, settings popup) used by every logged-in page.
require_once __DIR__ . '/theme.php';

function asset(string $path): string {
    $f = __DIR__ . '/' . $path;
    return $path . (is_file($f) ? '?v=' . filemtime($f) : '');
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function svg_icon(string $name): string {
    $p = [
        'shield'  => '<path d="M12 2.5 4 5.5v6c0 4.7 3.2 8.3 8 10 4.8-1.7 8-5.3 8-10v-6l-8-3Z" fill="currentColor" stroke="none"/><path d="M8 13.5a4 4 0 0 1 8 0H8Z" fill="#fff" stroke="none" opacity=".92"/>',
        'live'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.2" fill="currentColor"/>',
        'archive' => '<rect x="4" y="3.5" width="16" height="17" rx="3"/><path d="M8 9h8M8 13h8M8 17h5"/>',
        'user'    => '<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20c.9-3.6 4-5.4 7.5-5.4s6.6 1.8 7.5 5.4"/>',
        'lock'    => '<rect x="5" y="10.5" width="14" height="9.5" rx="2.5"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/>',
        'check'   => '<path d="M5 12.5 10 17.5 19 7.5"/>',
        'gear'    => '<circle cx="12" cy="12" r="3"/><path d="M12 2.8v2.4M12 18.8v2.4M2.8 12h2.4M18.8 12h2.4M5.5 5.5l1.7 1.7M16.8 16.8l1.7 1.7M5.5 18.5l1.7-1.7M16.8 7.2l1.7-1.7"/>',
        'logout'  => '<path d="M9 4.5H6.5A2 2 0 0 0 4.5 6.5v11a2 2 0 0 0 2 2H9"/><path d="M15 8l4 4-4 4M19 12H9"/>',
        'engine'  => '<rect x="5" y="5" width="14" height="14" rx="3"/><path d="M9 2.5v2.5M15 2.5v2.5M9 19v2.5M15 19v2.5M2.5 9H5M2.5 15H5M19 9h2.5M19 15h2.5"/>',
        'camera'  => '<rect x="3" y="6.5" width="13" height="11" rx="2.5"/><path d="m16 11 5-3v8l-5-3"/>',
    ];
    $fill = in_array($name, ['shield'], true) ? '' : ' fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"' . $fill . '>' . ($p[$name] ?? '') . '</svg>';
}

// The page is shown inside the dashboard (an iframe) so the AI model stays loaded while you move around.
function is_embedded(): bool {
    return !empty($_GET['embed']) || (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe');
}
// Opened directly (not inside the dashboard)? Send the browser to the dashboard, which then opens this page.
function shell_redirect_if_top(string $view): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'document' && empty($_GET['embed'])) {
        header('Location: app.php#' . $view);
        exit;
    }
}

function page_head(string $title, ?string $bodyClass = null, string $extra = ''): void {
    $theme = current_theme();
    $font = current_font();
    $csrf = $_SESSION['csrf'] ?? '';
    echo '<!DOCTYPE html><html lang="en" data-theme="' . h($theme) . '" data-font="' . h($font) . '"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<meta name="csrf" content="' . h($csrf) . '">'
       . '<title>' . h($title) . ' — HelmetWatch</title>'
       . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
       . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap">'
       . '<link rel="stylesheet" href="' . asset('css/themes.css') . '">'
       . '<link rel="stylesheet" href="' . asset('css/layout.css') . '">'
       . '<link rel="stylesheet" href="' . asset('css/pages.css') . '">'
       . '<script>window.addEventListener("pageshow",function(e){if(e.persisted||(performance.getEntriesByType&&(performance.getEntriesByType("navigation")[0]||{}).type==="back_forward")){location.reload();}});</script>'
       . $extra
       . '</head><body' . ($bodyClass ? ' class="' . h($bodyClass) . '"' : '') . '>';
}

/**
 * $o: title, heading, sub, active (live|violations|admin), isAdmin, username
 */
function layout_start(array $o): void {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $username = $o['username'] ?? ($_SESSION['username'] ?? 'User');
    $initial = strtoupper(substr($username, 0, 1));
    $isAdmin = !empty($o['isAdmin']);
    $active = $o['active'] ?? '';
    $shell = !empty($o['shell']);
    $embed = is_embedded();
    if ($embed) {
        page_head($o['title'] ?? $o['heading'], 'embed', $o['head_extra'] ?? '');
        echo '<div class="content">';
        return;
    }
    page_head($o['title'] ?? $o['heading'], null, $o['head_extra'] ?? '');
    $item = fn($key, $href, $icon, $label, $title = '', $sub = '') =>
        '<a class="nav-item' . ($active === $key ? ' active' : '') . '" href="' . $href . '"'
        . ($shell ? ' data-view="' . $key . '" data-title="' . h($title) . '" data-sub="' . h($sub) . '"' : '') . '>'
        . svg_icon($icon) . '<span>' . $label . '</span></a>';
    ?>
<aside class="sidebar">
  <a class="logo" href="app.php">
    <span class="logo-icon"><?= svg_icon('shield') ?></span>
    <span class="logo-text"><span class="name">HelmetWatch</span><span class="sub">Secure monitoring</span></span>
  </a>
  <nav class="nav">
    <div class="nav-label">Monitor</div>
    <?= $item('live', 'app.php', 'live', 'Live dashboard', 'Live dashboard', 'Real-time helmet violation detection.') ?>
    <?= $item('violations', 'violations.php', 'archive', 'Violations archive', 'Violations archive', 'Every captured helmet violation.') ?>
    <?php if ($isAdmin): ?>
    <div class="nav-label">Administration</div>
    <?= $item('admin', 'admin_logs.php', 'user', 'Admin logs &amp; users', 'Admin logs & users', 'Login audit trail and account management.') ?>
    <?php endif; ?>
  </nav>
  <div class="side-session">
    <div class="side-session-title"><?= svg_icon('lock') ?> Session active</div>
    <div class="side-session-sub">Signed in as <?= h($username) ?></div>
  </div>
</aside>

<main class="main">
  <header class="topbar">
    <div class="topbar-title">
      <h1><?= h($o['heading']) ?></h1>
      <?php if (!empty($o['sub'])): ?><p><?= h($o['sub']) ?></p><?php endif; ?>
    </div>
    <div class="topbar-right">
      <span class="pill pill-ok"><?= svg_icon('check') ?> System online</span>
      <div class="user-menu-wrap">
        <button type="button" class="user-profile-badge" id="userBtn" aria-haspopup="true" aria-expanded="false">
          <span class="user-avatar"><?= h($initial) ?></span>
          <span class="username-text"><?= h($username) ?></span>
          <svg class="chev" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="dropdown-menu" id="userDropdown">
          <button type="button" class="dropdown-item" id="openSettings"><?= svg_icon('gear') ?> Settings</button>
          <a href="logout.php" class="dropdown-item danger"><?= svg_icon('logout') ?> Log out</a>
        </div>
      </div>
    </div>
  </header>

  <div class="content">
    <div class="page-head"><h1><?= h($o['heading']) ?></h1><?php if (!empty($o['sub'])): ?><p><?= h($o['sub']) ?></p><?php endif; ?></div>
<?php
}

function layout_end(string $scripts = ''): void {
    if (is_embedded()) {            // inside the dashboard: no frame, no settings popup
        echo '</div>' . $scripts . '</body></html>';
        return;
    }
    $cur = current_theme();
    $curFont = current_font();
    ?>
  </div>
</main>

<!-- SETTINGS POPUP (theme) -->
<div class="modal-backdrop" id="settingsModal" aria-hidden="true">
  <div class="modal-card wide" role="dialog" aria-modal="true" aria-labelledby="settingsTitle">
    <div class="modal-head">
      <h3 id="settingsTitle">Settings</h3>
      <button type="button" class="modal-x" id="closeSettings" aria-label="Close">&times;</button>
    </div>
    <p class="modal-sub">Appearance — choose a theme and text size. They are saved to your account and used every time you log in.</p>
    <div class="theme-options" role="radiogroup" aria-label="Theme">
      <?php foreach (THEMES as $key => $t): ?>
      <button type="button" class="theme-option<?= $key === $cur ? ' selected' : '' ?>" role="radio" aria-checked="<?= $key === $cur ? 'true' : 'false' ?>" data-theme-choice="<?= h($key) ?>">
        <span class="theme-prev" style="background:<?= $t['prev'][0] ?>">
          <span class="tp-side" style="background:<?= $t['prev'][1] ?>"></span>
          <span class="tp-card" style="background:<?= $t['prev'][1] ?>"></span>
          <span class="tp-bar" style="background:<?= $t['prev'][2] ?>"></span>
        </span>
        <span class="theme-name"><?= h($t['name']) ?></span>
        <span class="theme-desc"><?= h($t['desc']) ?></span>
      </button>
      <?php endforeach; ?>
    </div>
    <div class="font-block">
      <div class="font-title">Text size</div>
      <div class="font-options" role="radiogroup" aria-label="Text size">
        <?php foreach (FONT_SIZES as $key => $label): ?>
        <button type="button" class="font-option<?= $key === $curFont ? ' selected' : '' ?>" role="radio" aria-checked="<?= $key === $curFont ? 'true' : 'false' ?>" data-font-choice="<?= h($key) ?>">
          <span class="font-a font-a-<?= h($key) ?>">Aa</span><span class="font-lbl"><?= h($label) ?></span>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="modal-foot"><span id="themeMsg" aria-live="polite"></span><button type="button" class="btn" id="doneSettings">Done</button></div>
  </div>
</div>
<script src="<?= asset('js/ui.js') ?>"></script>
<script src="<?= asset('js/shell.js') ?>"></script>
<?= $scripts ?>
</body>
</html>
<?php
}

// Frame for login / register / verify pages (no navigation)
function auth_start(string $title): void {
    page_head($title, 'auth-page', '<script>if(window.top!==window.self){window.top.location.href=window.location.href;}</script>');
    echo '<div class="auth-wrapper"><div class="login-card">'
       . '<div class="auth-brand"><span class="logo-icon">' . svg_icon('shield') . '</span>'
       . '<span class="logo-text"><span class="name">HelmetWatch</span><span class="sub">Secure monitoring</span></span></div>';
}
function auth_end(): void {
    echo '</div></div></body></html>';
}
