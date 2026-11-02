<?php
session_start();
require 'db.php';
require 'mailer.php';
require_once __DIR__ . '/layout.php';

if (isset($_SESSION['user_id'])) { header("Location: app.php"); exit; }

$msg = "";
$modalMsg = "";
$done = false;
$action = $_POST['action'] ?? 'send';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if ($action === 'cancel') {
        unset($_SESSION['pending_reset']);
    } elseif ($action === 'verify' && !empty($_SESSION['pending_reset'])) {
        $p = $_SESSION['pending_reset'];
        $input = preg_replace('/\D/', '', $_POST['code'] ?? '');
        $pass = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $strongPass = preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,20}$/', $pass);

        if (time() > $p['expires'] || $p['attempts'] >= 5) {
            unset($_SESSION['pending_reset']);
            $msg = "The code expired. Please try again.";
        } elseif ($pass !== $confirm) {
            $modalMsg = "Passwords do not match.";
        } elseif (!$strongPass) {
            $modalMsg = "Password must be 8-20 characters with uppercase, lowercase, number, and special character.";
        } elseif (!password_verify($input, $p['code_hash'])) {
            $_SESSION['pending_reset']['attempts']++;
            $modalMsg = "Incorrect code. Try again.";
        } else {
            if ($p['user_id'] > 0) {
                $pdo->prepare("UPDATE users SET password_hash = ?, failed_attempts = 0 WHERE id = ? AND status NOT IN ('archived', 'locked')")
                    ->execute([password_hash($pass, PASSWORD_DEFAULT), $p['user_id']]);
            }
            unset($_SESSION['pending_reset']);
            $done = true;
        }
    } elseif ($action === 'send') {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = "Enter a valid email address.";
        } else {
            $st = $pdo->prepare("SELECT id, username, status FROM users WHERE email = ?");
            $st->execute([$email]);
            $acc = $st->fetch();

            if (!$acc) {
                $msg = "This email is not registered.";
            } elseif ($acc['status'] === 'archived') {
                $msg = "This account has been archived. Please contact the Admin.";
            } elseif ($acc['status'] === 'locked') {
                $msg = "This account is locked. Only the Admin can unlock it.";
            } else {
                $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $r = send_code_email($email, $acc['username'], $code, 'reset');
                if ($r === true) {
                    $_SESSION['pending_reset'] = [
                        'user_id'   => (int)$acc['id'],
                        'email'     => $email,
                        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
                        'expires'   => time() + 600,
                        'attempts'  => 0,
                    ];
                } else {
                    $msg = $r;
                }
            }
        }
    }
}

$showModal = !$done && !empty($_SESSION['pending_reset']);

auth_start('Forgot Password');
?>
    <div class="auth-title">Forgot password</div>
    <?php if ($msg) echo '<div class="auth-msg err">' . h($msg) . '</div>'; ?>
    <?php if ($done): ?>
      <div class="auth-msg">Password updated. You can now log in with your new password.</div>
      <div class="auth-links"><a href="login.php">Go to log in</a></div>
    <?php else: ?>
    <p style="font-size:14px; color:var(--text2); margin-bottom:14px;">Enter the email you registered with. We will send a 6-digit code so you can set a new password.</p>
    <form method="POST">
      <input type="hidden" name="action" value="send">
      <div class="form-group"><label class="form-label">Email</label>
        <input type="email" name="email" class="form-input" placeholder="example@gmail.com" value="<?= h($_POST['email'] ?? '') ?>" required autofocus></div>
      <button type="submit" class="login-btn">Send reset code</button>
    </form>
    <div class="auth-links"><a href="login.php">Back to log in</a></div>
    <?php endif; ?>

    <?php if ($showModal): ?>
    <style>
      .vm-backdrop{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.45);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px)}
      .vm-card{width:100%;max-width:360px;background:var(--panel,#121821);color:var(--text,#fff);border:1px solid var(--border,rgba(255,255,255,.12));border-radius:14px;padding:26px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.5)}
      .vm-card h3{margin:0 0 8px;font-size:18px}
      .vm-card p{font-size:13px;color:var(--text2,#9aa4b2);margin:0 0 14px}
      .vm-card .form-group{text-align:left}
      .vm-code{text-align:center;font-size:26px;letter-spacing:10px;margin-bottom:14px}
      .vm-cancel{background:none;border:0;color:var(--text2,#9aa4b2);margin-top:12px;cursor:pointer;font-size:13px}
    </style>
    <div class="vm-backdrop" id="vm">
      <form method="POST" class="vm-card">
        <h3>Enter 6-digit number</h3>
        <p>We sent a code to <b><?= h($_SESSION['pending_reset']['email']) ?></b>. It expires in 10 minutes.</p>
        <?php if ($modalMsg) echo '<div class="auth-msg err">' . h($modalMsg) . '</div>'; ?>
        <input type="text" name="code" class="form-input vm-code" inputmode="numeric" maxlength="6" pattern="\d{6}" autocomplete="one-time-code" placeholder="000000" required>
        <div class="form-group"><label class="form-label">New password (8-20 chars)</label>
          <input type="password" name="password" class="form-input" autocomplete="new-password" required></div>
        <div class="form-group"><label class="form-label">Confirm new password</label>
          <input type="password" name="confirm_password" class="form-input" autocomplete="new-password" required></div>
        <button type="submit" name="action" value="verify" class="login-btn">Reset password</button>
        <button type="submit" name="action" value="cancel" class="vm-cancel" formnovalidate>Cancel</button>
      </form>
    </div>
    <script>
      var vm = document.getElementById('vm');
      document.body.appendChild(vm);
      vm.querySelector('input[name=code]').focus();
    </script>
    <?php endif; ?>
<?php include __DIR__ . '/password_eye.php'; ?>
<?php auth_end(); ?>
