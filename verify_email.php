<?php
session_start();
require 'db.php';
require 'mailer.php';
require_once __DIR__ . '/layout.php';

const MAX_ATTEMPTS = 5, MAX_SENDS = 5, RESEND_WAIT = 60, CODE_TTL = 600;

if (isset($_SESSION['user_id'])) { header("Location: app.php"); exit; }
if (empty($_SESSION['pending_reg'])) { header("Location: register.php"); exit; }

$p =& $_SESSION['pending_reg'];
$msg = "";
$ok = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? 'verify';

    if ($action === 'cancel') {
        unset($_SESSION['pending_reg']);
        header("Location: register.php");
        exit;
    }

    if ($action === 'resend') {
        if ($p['send_count'] >= MAX_SENDS) {
            $msg = "Too many codes sent. Please start over.";
        } elseif (time() - $p['sent_at'] < RESEND_WAIT) {
            $msg = "Please wait " . (RESEND_WAIT - (time() - $p['sent_at'])) . " seconds before requesting a new code.";
        } else {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $r = send_verification_email($p['email'], $p['username'], $code);
            if ($r === true) {
                $p['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
                $p['expires'] = time() + CODE_TTL;
                $p['attempts'] = 0;
                $p['sent_at'] = time();
                $p['send_count']++;
                $msg = "A new code was sent to your email.";
            } else {
                $msg = $r;
            }
        }
    } else {
        $input = preg_replace('/\D/', '', $_POST['code'] ?? '');

        if (time() > $p['expires']) {
            $msg = "This code has expired. Click “Resend code”.";
        } elseif ($p['attempts'] >= MAX_ATTEMPTS) {
            $msg = "Too many wrong attempts. Click “Resend code” for a new one.";
        } elseif (!password_verify($input, $p['code_hash'])) {
            $p['attempts']++;
            $left = MAX_ATTEMPTS - $p['attempts'];
            $msg = "Incorrect code. $left attempt(s) left.";
        } else {
            $check = $pdo->prepare("SELECT 1 FROM users WHERE username = ? OR email = ?");
            $check->execute([$p['username'], $p['email']]);
            if ($check->fetch()) {
                unset($_SESSION['pending_reg']);
                $msg = "That username or email was taken in the meantime. Please register again.";
                $ok = false;
            } else {
                $pdo->prepare("INSERT INTO users (username, email, password_hash, status) VALUES (?, ?, ?, 'pending')")
                    ->execute([$p['username'], $p['email'], $p['hash']]);
                unset($_SESSION['pending_reg']);
                $ok = true;
            }
        }
    }
}

$masked = '';
if (!empty($_SESSION['pending_reg'])) {
    [$l, $d] = explode('@', $p['email']);
    $masked = substr($l, 0, 2) . str_repeat('*', max(1, strlen($l) - 2)) . '@' . $d;
}

auth_start('Verify Email');
?>
    <div class="auth-title">Verify your email</div>
    <?php if ($ok): ?>
      <div class="auth-msg">Email verified. Your account is now waiting for Admin approval, then you can log in.</div>
      <div class="auth-links"><a href="login.php">Go to log in</a></div>
    <?php elseif (empty($_SESSION['pending_reg'])): ?>
      <?php if ($msg): ?><div class="auth-msg err"><?= h($msg) ?></div><?php endif; ?>
      <div class="auth-links"><a href="register.php">Back to register</a></div>
    <?php else: ?>
      <p style="font-size:14px; color:var(--text2); margin-bottom:14px;">We sent a 6-digit code to <b style="color:var(--text)"><?= h($masked) ?></b>. Check your inbox (and Spam), then enter it below to finish registering.</p>
      <?php if ($msg): ?><div class="auth-msg err"><?= h($msg) ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="action" value="verify">
        <div class="form-group"><label class="form-label">Verification code</label>
          <input type="text" name="code" class="form-input" inputmode="numeric" maxlength="6" pattern="\d{6}" autocomplete="one-time-code" autofocus required></div>
        <button type="submit" class="login-btn">Verify &amp; create account</button>
      </form>
      <form method="POST" class="auth-actions">
        <button type="submit" name="action" value="resend" class="btn" formnovalidate>Resend code</button>
        <button type="submit" name="action" value="cancel" class="btn" formnovalidate>Cancel</button>
      </form>
    <?php endif; ?>
<?php auth_end(); ?>
