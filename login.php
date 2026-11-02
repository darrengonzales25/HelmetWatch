<?php
session_start();
require 'db.php';
require_once __DIR__ . '/layout.php';
date_default_timezone_set('Asia/Manila');   // Philippine Time
try { $pdo->exec("SET time_zone = '+08:00'"); } catch (Throwable $e) { }

if (isset($_SESSION['user_id'])) {
    header("Location: app.php");
    exit;
}

const MAX_EMPTY = 10;
const MAX_BAD_LOGIN = 5;
const MAX_WRONG_PASS = 3;
const BLOCK_SECONDS = 300;

function block_login(): void
{
    $_SESSION['login_block_until'] = time() + BLOCK_SECONDS;
    $_SESSION['empty_count'] = 0;
    $_SESSION['bad_count'] = 0;
}

function bad_attempt(): string
{
    $_SESSION['bad_count'] = ($_SESSION['bad_count'] ?? 0) + 1;
    if ($_SESSION['bad_count'] >= MAX_BAD_LOGIN) {
        block_login();
        return "Too many wrong attempts. Login is blocked for 5 minutes.";
    }
    return "Invalid credentials. (" . $_SESSION['bad_count'] . " / " . MAX_BAD_LOGIN . ")";
}

$error = isset($_GET['archived']) ? "This account has been archived. Please contact the Admin." : "";

theme_ensure_column($pdo);

$checkAdmin = $pdo->prepare("SELECT * FROM users WHERE username = 'Admin'");
$checkAdmin->execute();
if ($checkAdmin->rowCount() == 0) {
    $adminHash = password_hash('Admin123!', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, email, password_hash, status) VALUES ('Admin', 'dagonzales@ccc.edu.ph', ?, 'approved')")->execute([$adminHash]);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $until = $_SESSION['login_block_until'] ?? 0;

    if ($until > time()) {
        $error = "Too many attempts. Try again in " . ceil(($until - time()) / 60) . " minute(s).";
    } else {
        unset($_SESSION['login_block_until']);
        $user = trim($_POST['username'] ?? '');
        $pass = trim($_POST['password'] ?? '');

        if ($user === '' || $pass === '') {
            $_SESSION['empty_count'] = ($_SESSION['empty_count'] ?? 0) + 1;
            if ($_SESSION['empty_count'] >= MAX_EMPTY) {
                block_login();
                $error = "Too many empty attempts. Login is blocked for 5 minutes.";
            } else {
                $error = "Enter your username and password. (" . $_SESSION['empty_count'] . " / " . MAX_EMPTY . ")";
            }
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$user]);
            $account = $stmt->fetch();

            if ($account) {
                if ($account['status'] == 'locked') {
                    $error = "Account locked after 3 wrong passwords. Only the Admin can unlock it.";
                } elseif ($account['status'] == 'archived') {
                    $error = "This account has been archived. Please contact the Admin.";
                } elseif ($account['status'] == 'pending') {
                    $error = "Account is pending Admin approval.";
                } elseif (password_verify($pass, $account['password_hash'])) {
                    $pdo->prepare("UPDATE users SET failed_attempts = 0 WHERE id = ?")->execute([$account['id']]);
                    $pdo->prepare("INSERT INTO login_logs (username, login_time) VALUES (?, ?)")->execute([$user, date('Y-m-d H:i:s')]);

                    unset($_SESSION['empty_count'], $_SESSION['bad_count']);
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $account['id'];
                    $_SESSION['username'] = $account['username'];
                    $_SESSION['splash'] = 'Signing you in…';
                    theme_on_login($pdo, $account);

                    header("Location: app.php");
                    exit;
                } elseif ($account['username'] === 'Admin') {
                    $error = bad_attempt();
                } else {
                    $attempts = $account['failed_attempts'] + 1;
                    if ($attempts >= MAX_WRONG_PASS) {
                        $pdo->prepare("UPDATE users SET failed_attempts = ?, status = 'locked' WHERE id = ?")->execute([$attempts, $account['id']]);
                        $error = "Account locked after 3 wrong passwords. Only the Admin can unlock it.";
                    } else {
                        $pdo->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?")->execute([$attempts, $account['id']]);
                        $error = "Invalid password. Attempt $attempts / " . MAX_WRONG_PASS . ".";
                    }
                }
            } else {
                $error = bad_attempt();
            }
        }
    }
}

auth_start('Login');
?>
    <div class="auth-title">Log in</div>
    <?php if ($error) echo '<div class="auth-msg err">' . h($error) . '</div>'; ?>
    <form method="POST">
      <div class="form-group"><label class="form-label">Username</label><input type="text" name="username" class="form-input" value="<?= h($_POST['username'] ?? '') ?>" autofocus></div>
      <div class="form-group"><label class="form-label">Password</label><input type="password" name="password" class="form-input"></div>
      <div style="text-align:right; margin:-6px 0 16px; font-size:13px;"><a href="forgot_password.php" style="color:var(--accent); font-weight:700; text-decoration:none;">Forgot password?</a></div>
      <button type="submit" class="login-btn">Enter</button>
    </form>
    <div class="auth-links">Don't have an account? <a href="register.php">Register here</a></div>
<?php include __DIR__ . '/password_eye.php'; ?>
<?php auth_end(); ?>