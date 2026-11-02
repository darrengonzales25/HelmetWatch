<?php
session_start();
require 'db.php';
require 'mailer.php';
require_once __DIR__ . '/layout.php';

$msg = "";
$modalMsg = "";
$done = false;
$action = $_POST['action'] ?? 'send';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if ($action === 'cancel') {
        unset($_SESSION['pending_reg']);
    } elseif ($action === 'verify' && !empty($_SESSION['pending_reg'])) {
        $p = $_SESSION['pending_reg'];
        $input = preg_replace('/\D/', '', $_POST['code'] ?? '');

        if (time() > $p['expires'] || $p['attempts'] >= 5) {
            unset($_SESSION['pending_reg']);
            $msg = "The code expired. Please register again.";
        } elseif (!password_verify($input, $p['code_hash'])) {
            $_SESSION['pending_reg']['attempts']++;
            $modalMsg = "Incorrect code. Try again.";
        } else {
            $check = $pdo->prepare("SELECT 1 FROM users WHERE username = ? OR email = ?");
            $check->execute([$p['username'], $p['email']]);
            unset($_SESSION['pending_reg']);
            if ($check->fetch()) {
                $msg = "That username or email was just taken. Please register again.";
            } else {
                $pdo->prepare("INSERT INTO users (username, email, password_hash, status) VALUES (?, ?, ?, 'pending')")
                    ->execute([$p['username'], $p['email'], $p['hash']]);
                $done = true;
            }
        }
    } elseif ($action === 'send') {
        $user = trim($_POST['username'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $strongPass = preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,20}$/', $pass);
        $validGmail = preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/', $email);

        if ($user === '') {
            $msg = "Username is required.";
        } elseif (!preg_match('/^[A-Za-z]{1,20}$/', $user)) {
            $msg = "Username must be letters only, up to 20 characters (no numbers or special characters).";
        } elseif ($pass !== $confirm) {
            $msg = "Passwords do not match.";
        } elseif (!$strongPass) {
            $msg = "Password must be 8-20 characters with uppercase, lowercase, number, and special character.";
        } elseif (!$validGmail) {
            $msg = "Email must be a valid @gmail.com address.";
        } else {
            $check = $pdo->prepare("SELECT 1 FROM users WHERE username = ? OR email = ?");
            $check->execute([$user, $email]);
            if ($check->fetch()) {
                $msg = "That username or email is already taken. Please choose another.";
            } else {
                $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $sent = send_verification_email($email, $user, $code);
                if ($sent === true) {
                    $_SESSION['pending_reg'] = [
                        'username'  => $user,
                        'email'     => $email,
                        'hash'      => password_hash($pass, PASSWORD_DEFAULT),
                        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
                        'expires'   => time() + 600,
                        'attempts'  => 0,
                    ];
                } else {
                    $msg = $sent;
                }
            }
        }
    }
}

$showModal = !$done && !empty($_SESSION['pending_reg']);

auth_start('Register');
?>
    <div class="auth-title">Register account</div>
    <?php if ($msg) echo '<div class="auth-msg err">' . h($msg) . '</div>'; ?>
    <?php if ($done): ?>
      <div class="auth-msg">Email verified. Your account now waits for Admin approval.</div>
      <div class="auth-links"><a href="login.php">Go to log in</a></div>
    <?php else: ?>
    <form method="POST">
      <input type="hidden" name="action" value="send">
      <div class="form-group"><label class="form-label">Username (letters only, max 20)</label><input type="text" name="username" class="form-input" value="<?= h($_POST['username'] ?? '') ?>" maxlength="20" pattern="[A-Za-z]{1,20}" title="Letters only, up to 20 characters" autocapitalize="none" autocomplete="username" oninput="this.value=this.value.replace(/[^A-Za-z]/g,'').slice(0,20)" required></div>
      <div class="form-group"><label class="form-label">Email (@gmail.com)</label><input type="email" name="email" class="form-input" placeholder="example@gmail.com" value="<?= h($_POST['email'] ?? '') ?>" required></div>
      <div class="form-group"><label class="form-label">Password (8-20 chars)</label><input type="password" name="password" class="form-input" required></div>
      <div class="form-group"><label class="form-label">Confirm password</label><input type="password" name="confirm_password" class="form-input" required></div>
      <button type="submit" class="login-btn">Send verification code</button>
    </form>
    <div class="auth-links">Already have an account? <a href="login.php">Log in</a></div>
    <?php endif; ?>

    <?php if ($showModal): ?>
    <style>
      .vm-backdrop{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.45);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px)}
      .vm-card{width:100%;max-width:360px;background:var(--panel,#121821);color:var(--text,#fff);border:1px solid var(--border,rgba(255,255,255,.12));border-radius:14px;padding:26px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.5)}
      .vm-card h3{margin:0 0 8px;font-size:18px}
      .vm-card p{font-size:13px;color:var(--text2,#9aa4b2);margin:0 0 14px}
      .vm-code{text-align:center;font-size:26px;letter-spacing:10px;margin-bottom:14px}
      .vm-cancel{background:none;border:0;color:var(--text2,#9aa4b2);margin-top:12px;cursor:pointer;font-size:13px}
    </style>
    <div class="vm-backdrop" id="vm">
      <form method="POST" class="vm-card">
        <h3>Enter 6-digit number</h3>
        <p>We sent a code to <b><?= h($_SESSION['pending_reg']['email']) ?></b>. It expires in 10 minutes.</p>
        <?php if ($modalMsg) echo '<div class="auth-msg err">' . h($modalMsg) . '</div>'; ?>
        <input type="text" name="code" class="form-input vm-code" inputmode="numeric" maxlength="6" pattern="\d{6}" autocomplete="one-time-code" placeholder="000000" required>
        <button type="submit" name="action" value="verify" class="login-btn">Verify</button>
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