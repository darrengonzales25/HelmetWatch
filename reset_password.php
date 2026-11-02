<?php
session_start();
require 'db.php';
require 'mailer.php';
require_once __DIR__ . '/layout.php';

const MAX_ATTEMPTS = 5, MAX_SENDS = 5, RESEND_WAIT = 60, CODE_TTL = 600;

if (empty($_SESSION['pending_reset'])) { header("Location: forgot_password.php"); exit; }
$p =& $_SESSION['pending_reset'];
$msg = ""; $done = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? 'reset';

    if ($action === 'cancel') {
        unset($_SESSION['pending_reset']);
        header("Location: login.php"); exit;
    }

    if ($action === 'resend') {
        if ($p['send_count'] >= MAX_SENDS) {
            $msg = "Too many codes sent. Please start over.";
        } elseif (time() - $p['sent_at'] < RESEND_WAIT) {
            $msg = "Please wait " . (RESEND_WAIT - (time() - $p['sent_at'])) . " seconds before requesting a new code.";
        } else {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $r = true;
            if ($p['user_id'] > 0) {
                $st = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $st->execute([$p['user_id']]);
                $r = send_code_email($p['email'], (string)$st->fetchColumn(), $code, 'reset');
            }
            if ($r === true) {
                $p['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
                $p['expires'] = time() + CODE_TTL;
                $p['attempts'] = 0;
                $p['sent_at'] = time();
                $p['send_count']++;
                $msg = "A new code was sent to your email.";
            } else { $msg = $r; }
        }
    } else { // reset
        $input   = preg_replace('/\D/', '', $_POST['code'] ?? '');
        $pass    = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        // Same rule as register.php: 8-20 chars, 1 upper, 1 lower, 1 number, 1 special
        $strongPass = preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,20}$/', $pass);

        if (time() > $p['expires']) {
            $msg = "This code has expired. Click “Resend code”.";
        } elseif ($p['attempts'] >= MAX_ATTEMPTS) {
            $msg = "Too many wrong attempts. Click “Resend code” for a new one.";
        } elseif ($pass !== $confirm) {
            $msg = "Passwords do not match.";
        } elseif (!$strongPass) {
            $msg = "Password must be 8-20 characters with uppercase, lowercase, number, and special character.";
        } elseif (!password_verify($input, $p['code_hash'])) {
            $p['attempts']++;
            $left = MAX_ATTEMPTS - $p['attempts'];
            $msg = "Incorrect code. $left attempt(s) left.";
        } else {
            if ($p['user_id'] > 0) {
                // New password; also clears failed attempts and unlocks a locked account.
                $pdo->prepare("UPDATE users
                               SET password_hash = ?, failed_attempts = 0,
                                   status = CASE WHEN status = 'locked' THEN 'approved' ELSE status END
                               WHERE id = ? AND status <> 'archived'")
                    ->execute([password_hash($pass, PASSWORD_DEFAULT), $p['user_id']]);
            }
            $done = true;
            unset($_SESSION['pending_reset']);
        }
    }
}

$masked = '';
if (!$done && !empty($_SESSION['pending_reset'])) {
    [$l, $d] = explode('@', $p['email']);
    $masked = substr($l, 0, 2) . str_repeat('*', max(1, strlen($l) - 2)) . '@' . $d;
}

auth_start('Reset Password');
?>
    <div class="auth-title">Reset your password</div>
    <?php if ($done): ?>
      <div class="auth-msg">Password updated. You can now log in with your new password.</div>
      <div class="auth-links"><a href="login.php">Go to log in</a></div>
    <?php else: ?>
      <p style="font-size:14px; color:var(--text2); margin-bottom:14px;">If an account exists for <b style="color:var(--text)"><?= h($masked) ?></b>, we sent a 6-digit code to it. Check your inbox (and Spam), then enter the code and choose a new password.</p>
      <?php if ($msg): ?><div class="auth-msg err"><?= h($msg) ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="action" value="reset">
        <div class="form-group"><label class="form-label">Reset code</label>
          <input type="text" name="code" class="form-input" inputmode="numeric" maxlength="6" pattern="\d{6}" autocomplete="one-time-code" autofocus required></div>
        <div class="form-group"><label class="form-label">New password (8-20 chars)</label>
          <input type="password" name="password" class="form-input" autocomplete="new-password" required></div>
        <div class="form-group"><label class="form-label">Confirm new password</label>
          <input type="password" name="confirm_password" class="form-input" autocomplete="new-password" required></div>
        <button type="submit" class="login-btn">Reset password</button>
      </form>
      <form method="POST" class="auth-actions">
        <button type="submit" name="action" value="resend" class="btn" formnovalidate>Resend code</button>
        <button type="submit" name="action" value="cancel" class="btn" formnovalidate>Cancel</button>
      </form>
    <?php endif; ?>
<?php auth_end(); ?>
