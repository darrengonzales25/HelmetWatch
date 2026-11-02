<?php
// Theme helpers: which themes exist, which one the current visitor uses, and how it is remembered.
//  - logged in : saved per user in the database (users.theme) and kept in the session
//  - logged out: remembered in a browser cookie (hw_theme), so the login page keeps the last choice
const THEMES = [
    'indigo' => ['name' => 'Midnight Indigo', 'desc' => 'Dark · side navigation',  'prev' => ['#131a2c', '#1c2340', '#8da2ff']],
    'light'  => ['name' => 'Clean Light',     'desc' => 'Light · side navigation', 'prev' => ['#e4e9ef', '#ffffff', '#2e6fa7']],
    'teal'   => ['name' => 'Deep Teal',       'desc' => 'Dark · side navigation',  'prev' => ['#0e1822', '#15212d', '#5bb5ac']],
];
const DEFAULT_THEME = 'light';   // Clean Light is the starting theme until the user picks another

// Text sizes
const FONT_SIZES = ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large', 'xlarge' => 'Extra large'];
const DEFAULT_FONT = 'medium';
function font_valid($f): bool { return is_string($f) && isset(FONT_SIZES[$f]); }
function font_cookie_set(string $f): void {
    if (!headers_sent()) setcookie('hw_font', $f, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
    $_COOKIE['hw_font'] = $f;
}
function font_cookie(): ?string { $c = $_COOKIE['hw_font'] ?? ''; return font_valid($c) ? $c : null; }
function current_font(): string {
    if (!empty($_SESSION['font']) && font_valid($_SESSION['font'])) return $_SESSION['font'];
    return font_cookie() ?? DEFAULT_FONT;
}
function font_save_for_user(PDO $pdo, int $userId, string $f): void {
    theme_ensure_column($pdo);
    try { $pdo->prepare("UPDATE users SET fontsize = ? WHERE id = ?")->execute([$f, $userId]); } catch (Throwable $e) { /* ignore */ }
}

function theme_valid($t): bool { return is_string($t) && isset(THEMES[$t]); }

function theme_cookie_set(string $t): void {
    if (!headers_sent()) {
        setcookie('hw_theme', $t, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
    }
    $_COOKIE['hw_theme'] = $t;
}

function theme_cookie(): ?string {
    $c = $_COOKIE['hw_theme'] ?? '';
    return theme_valid($c) ? $c : null;
}

// Theme used to draw the current page
function current_theme(): string {
    if (!empty($_SESSION['theme']) && theme_valid($_SESSION['theme'])) return $_SESSION['theme'];
    return theme_cookie() ?? DEFAULT_THEME;
}

// Adds the users.theme column the first time it is needed
function theme_ensure_column(PDO $pdo): void {
    foreach (['theme' => 'VARCHAR(20)', 'fontsize' => 'VARCHAR(10)'] as $col => $type) {
        try {
            $pdo->query("SELECT $col FROM users LIMIT 1");
        } catch (Throwable $e) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN $col $type NULL DEFAULT NULL"); } catch (Throwable $e2) { /* ignore */ }
        }
    }
}

function theme_save_for_user(PDO $pdo, int $userId, string $t): void {
    theme_ensure_column($pdo);
    try { $pdo->prepare("UPDATE users SET theme = ? WHERE id = ?")->execute([$t, $userId]); } catch (Throwable $e) { /* ignore */ }
}

// Call right after a successful login: the user's saved theme wins; a user with no saved theme starts on Clean Light.
function theme_on_login(PDO $pdo, array $account): void {
    $saved = $account['theme'] ?? null;
    if (theme_valid($saved)) {
        $t = $saved;
    } else {
        $t = DEFAULT_THEME;   // first login: Clean Light; after the user picks a theme, theirs is used
        theme_save_for_user($pdo, (int)$account['id'], $t);
    }
    $_SESSION['theme'] = $t;
    theme_cookie_set($t);

    // text size: same idea
    $f = $account['fontsize'] ?? null;
    if (!font_valid($f)) {
        $f = font_cookie() ?? DEFAULT_FONT;
        font_save_for_user($pdo, (int)$account['id'], $f);
    }
    $_SESSION['font'] = $f;
    font_cookie_set($f);
}
