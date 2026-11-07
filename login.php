<?php
require_once __DIR__ . '/bootstrap.php';

if (auth_check()) {
    redirect('/dashboard');
}

$storeName = APP_NAME;
try {
    $pdo = get_db_connection();
    if ($pdo instanceof PDO) {
        $companyName = $pdo->query('SELECT name FROM companies WHERE is_active = 1 ORDER BY company_id LIMIT 1')->fetchColumn();
        if (is_string($companyName) && trim($companyName) !== '') {
            $storeName = trim($companyName);
        }
    }
} catch (Throwable $exception) {
    $storeName = APP_NAME;
}

$error = null;
$message = null;
$loginValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = get_db_connection();
    $loginValue = trim((string) ($_POST['username'] ?? ''));

    if (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$pdo instanceof PDO) {
        $error = 'The database is unavailable. Please contact your administrator.';
    } else {
        $result = auth_attempt($pdo, $loginValue, (string) ($_POST['password'] ?? ''));
        if ($result['ok']) {
            auth_start_session($result['user']);
            auth_audit(
                $pdo,
                'LOGIN',
                'users',
                (int) $result['user']['user_id'],
                'Signed in',
                (int) $result['user']['user_id']
            );

            $intended = $_SESSION['_intended'] ?? '';
            unset($_SESSION['_intended']);
            $target = '/dashboard';
            if (is_string($intended) && $intended !== '') {
                $base = app_base_path();
                if ($base !== '' && strpos($intended, $base) === 0) {
                    $intended = substr($intended, strlen($base));
                }
                if ($intended !== '' && $intended[0] === '/' && strpos($intended, '//') !== 0) {
                    $target = $intended;
                }
            }
            redirect($target);
        }
        $error = $result['message'];
    }
}

$message = flash('auth_message');
$error = $error ?? flash('auth_error');
$loginValue = (string) old('login', $loginValue);
unset($_SESSION['_old']['login']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In — <?= e($storeName) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css"
        integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/LbrixFETvWa5a6sESd" crossorigin="anonymous">
  <link rel="stylesheet" href="<?= e(asset('vendor/sweetalert2/sweetalert2.min.css')) ?>">
  <script>
    /* The sign-in page does not use the workspace loader; clear any sidebar
       flag so it cannot leak into the next page load. */
    try { sessionStorage.removeItem('inv:navLoader'); } catch (e) {}
  </script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap');
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
    :root {
      --primary: #C8E932; --primary-dark: #a8c920;
      --dark: #111318; --dark-2: #1c2029; --dark-3: #252b36;
      --text-3: #8a96a8; --danger: #ef4444; --border: rgba(255,255,255,0.08);
    }
    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--dark);
      color: white;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      position: relative;
      overflow: hidden;
    }
    body::before {
      content: '';
      position: fixed; top: -20%; left: 50%;
      transform: translateX(-50%);
      width: 600px; height: 500px;
      border-radius: 50%;
      background: radial-gradient(ellipse, rgba(200,233,50,0.06), transparent 65%);
      pointer-events: none;
    }
    body::after {
      content: '';
      position: fixed; bottom: -20%; right: -10%;
      width: 400px; height: 400px;
      border-radius: 50%;
      background: radial-gradient(ellipse, rgba(126,206,198,0.05), transparent 65%);
      pointer-events: none;
    }
    .login-box {
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 20px;
      padding: 44px 40px;
      width: 100%; max-width: 420px;
      position: relative; z-index: 1;
    }
    .login-logo {
      font-size: 22px; font-weight: 800;
      color: var(--primary); letter-spacing: -0.5px;
      margin-bottom: 6px;
    }
    .login-logo span {
      display: block; font-size: 10px; font-weight: 500;
      color: var(--text-3); letter-spacing: 2px;
      text-transform: uppercase; margin-top: 2px;
    }
    .login-title {
      font-size: 24px; font-weight: 800;
      letter-spacing: -0.5px; margin: 28px 0 6px;
    }
    .login-sub { font-size: 13.5px; color: rgba(255,255,255,0.4); margin-bottom: 32px; }
    .role-tabs {
      display: grid; grid-template-columns: 1fr 1fr;
      background: rgba(255,255,255,0.04);
      border: 1px solid var(--border);
      border-radius: 10px; padding: 4px;
      margin-bottom: 24px; gap: 4px;
    }
    .role-tab {
      padding: 9px; border-radius: 7px;
      border: none; background: transparent;
      color: rgba(255,255,255,0.4);
      font-size: 13px; font-weight: 600;
      font-family: inherit; cursor: pointer;
      display: flex; align-items: center;
      justify-content: center; gap: 7px;
      transition: all 0.2s;
    }
    .role-tab.active { background: var(--primary); color: var(--dark); }
    .role-tab i { font-size: 15px; }
    .form-group { margin-bottom: 16px; }
    .form-label {
      display: block; font-size: 12.5px;
      font-weight: 600; color: rgba(255,255,255,0.5);
      margin-bottom: 8px;
    }
    .input-wrap { position: relative; }
    .input-wrap i {
      position: absolute; left: 13px; top: 50%;
      transform: translateY(-50%);
      color: rgba(255,255,255,0.25); font-size: 15px;
    }
    .form-control {
      width: 100%; padding: 11px 12px 11px 40px;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 9px;
      font-size: 14px; font-family: inherit;
      color: white; transition: all 0.2s;
    }
    .form-control::placeholder { color: rgba(255,255,255,0.2); }
    .form-control:focus {
      outline: none;
      border-color: rgba(200,233,50,0.4);
      background: rgba(255,255,255,0.07);
      box-shadow: 0 0 0 3px rgba(200,233,50,0.08);
    }
    .pw-toggle {
      position: absolute; right: 12px; top: 50%;
      transform: translateY(-50%);
      background: none; border: none;
      color: rgba(255,255,255,0.25);
      cursor: pointer; font-size: 15px;
      padding: 0; transition: color 0.2s;
    }
    .pw-toggle:hover { color: rgba(255,255,255,0.6); }
    .error-msg {
      background: rgba(239,68,68,0.1);
      border: 1px solid rgba(239,68,68,0.2);
      color: #fca5a5;
      padding: 10px 14px; border-radius: 8px;
      font-size: 13px; margin-bottom: 16px;
      display: none; align-items: center; gap: 8px;
    }
    .error-msg.show { display: flex; }
    .info-msg {
      background: rgba(200,233,50,0.08);
      border: 1px solid rgba(200,233,50,0.2);
      color: #dbe87d;
      padding: 10px 14px; border-radius: 8px;
      font-size: 13px; margin-bottom: 16px;
      display: flex; align-items: center; gap: 8px;
    }
    .btn-login {
      width: 100%; padding: 13px;
      background: var(--primary); color: var(--dark);
      font-size: 15px; font-weight: 700;
      font-family: inherit; border: none;
      border-radius: 10px; cursor: pointer;
      display: flex; align-items: center;
      justify-content: center; gap: 8px;
      transition: all 0.2s; margin-top: 8px;
    }
    .btn-login:hover { background: var(--primary-dark); transform: translateY(-1px); }
    .btn-login:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
    @keyframes spin { to { transform: rotate(360deg); } }
    /* Shown only while the sign-in POST is in flight. */
    #auth-loader {
      position: fixed; inset: 0; z-index: 1080;
      display: none; flex-direction: column; align-items: center; justify-content: center;
      background: rgba(17, 19, 24, 0.94);
      gap: 16px;
    }
    #auth-loader.show { display: flex; }
    #auth-loader .spinner {
      width: 42px; height: 42px; border-radius: 50%;
      border: 3px solid rgba(255,255,255,0.12);
      border-top-color: var(--primary);
      animation: spin 0.8s linear infinite;
    }
    #auth-loader p { font-size: 13.5px; color: rgba(255,255,255,0.55); letter-spacing: 0.3px; }
    @media (max-width: 480px) { .login-box { padding: 32px 22px; } }
  </style>
</head>
<body>
<div class="login-box">
  <div class="login-logo"><?= e($storeName) ?><span>Inventory Management System</span></div>
  <h1 class="login-title">Welcome back</h1>
  <p class="login-sub">Sign in to continue to your dashboard</p>

  <form id="loginForm" method="POST" action="<?= e(url('/login')) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="role-tabs">
      <button type="button" class="role-tab active" id="tabAdmin" onclick="selectRole('admin')">
        <i class="bi bi-shield-check"></i> Admin
      </button>
      <button type="button" class="role-tab" id="tabCashier" onclick="selectRole('staff')">
        <i class="bi bi-person-badge"></i> Staff
      </button>
    </div>

    <?php if ($message !== null): ?>
    <div class="info-msg">
      <i class="bi bi-info-circle"></i>
      <span><?= e($message) ?></span>
    </div>
    <?php endif; ?>

    <div class="error-msg<?= $error !== null ? ' show' : '' ?>" id="errorMsg">
      <i class="bi bi-exclamation-circle"></i>
      <span id="errorText"><?= e($error ?? 'Invalid credentials') ?></span>
    </div>

    <div class="form-group">
      <label class="form-label" for="emailInput">Username or Email</label>
      <div class="input-wrap">
        <i class="bi bi-person"></i>
        <input type="text" class="form-control" id="emailInput" name="username"
               value="<?= e($loginValue) ?>" placeholder="username or you@example.com"
               autocomplete="username" required>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label" for="passInput">Password</label>
      <div class="input-wrap">
        <i class="bi bi-lock"></i>
        <input type="password" class="form-control" id="passInput" name="password"
               placeholder="••••••••" autocomplete="current-password" required>
        <button class="pw-toggle" onclick="togglePw()" type="button">
          <i class="bi bi-eye" id="pwIcon"></i>
        </button>
      </div>
    </div>

    <button type="submit" class="btn-login" id="loginBtn" onclick="doLogin(event)">
      <i class="bi bi-box-arrow-in-right"></i> Sign In
    </button>
  </form>
</div>

<div id="auth-loader" aria-hidden="true">
  <div class="spinner"></div>
  <p>Signing in…</p>
</div>

<script src="<?= e(asset('vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script>
let selectedRole = 'admin';

function swalToast(icon, text) {
  if (!window.Swal) { return false; }
  Swal.fire({
    toast: true,
    position: 'top-end',
    icon: icon,
    title: text,
    showConfirmButton: false,
    timer: icon === 'success' ? 4000 : 6000,
    timerProgressBar: true
  });
  return true;
}

function selectRole(role) {
  selectedRole = role;
  document.getElementById('tabAdmin').classList.toggle('active', role === 'admin');
  document.getElementById('tabCashier').classList.toggle('active', role === 'staff');
}

function togglePw() {
  const inp  = document.getElementById('passInput');
  const icon = document.getElementById('pwIcon');
  if (inp.type === 'password') { inp.type = 'text';     icon.className = 'bi bi-eye-slash'; }
  else                         { inp.type = 'password'; icon.className = 'bi bi-eye'; }
}

function doLogin(ev) {
  if (ev) ev.preventDefault();

  const loginId  = document.getElementById('emailInput').value.trim();
  const password = document.getElementById('passInput').value;
  const btn      = document.getElementById('loginBtn');
  const errBox   = document.getElementById('errorMsg');

  if (!loginId || !password) { showError('Please enter your username/email and password'); return; }

  if (errBox) { errBox.classList.remove('show'); }
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-arrow-repeat" style="animation:spin 0.7s linear infinite"></i> Signing in…';
  var overlay = document.getElementById('auth-loader');
  if (overlay) { overlay.classList.add('show'); }
  document.getElementById('loginForm').submit();
}

function showError(msg) {
  if (swalToast('error', msg)) { return; }
  document.getElementById('errorText').textContent = msg;
  document.getElementById('errorMsg').classList.add('show');
}

document.addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); doLogin(); }
});

document.addEventListener('DOMContentLoaded', function () {
  const notice = document.querySelector('.info-msg');
  if (notice && swalToast('success', notice.textContent.trim())) { notice.remove(); }

  const errBox = document.getElementById('errorMsg');
  if (errBox && errBox.classList.contains('show')) {
    const text = document.getElementById('errorText').textContent.trim();
    if (swalToast('error', text)) { errBox.remove(); }
  }
});
</script>
</body>
</html>
