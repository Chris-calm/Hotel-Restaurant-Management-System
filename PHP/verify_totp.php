<?php
require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/rbac_middleware.php';

RBACMiddleware::init();

if (!isset($_SESSION['pending_2fa_user_id'])) {
    Response::redirect('../index.php');
}

$userId = (int)$_SESSION['pending_2fa_user_id'];
$errors = [];

$conn = Database::getConnection();
if (!$conn) {
    Flash::set('error', 'Database unavailable for verification.');
    Response::redirect('../index.php');
}

$hasUsersGuestIdColumn = false;
try {
    $dbRow = $conn->query('SELECT DATABASE()');
    $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
    $db = $conn->real_escape_string($db);
    if ($db !== '') {
        $res = $conn->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'guest_id'"
        );
        $hasUsersGuestIdColumn = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
    }
} catch (Throwable $e) {
    $hasUsersGuestIdColumn = false;
}

$user = null;
$twofa = null;
if ($conn) {
    $uSel = $hasUsersGuestIdColumn
        ? 'SELECT id, guest_id, username, role FROM users WHERE id = ? LIMIT 1'
        : 'SELECT id, username, role FROM users WHERE id = ? LIMIT 1';

    $stmt = $conn->prepare($uSel);
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }

    $tStmt = $conn->prepare('SELECT totp_secret, enabled FROM user_2fa WHERE user_id = ? LIMIT 1');
    if ($tStmt instanceof mysqli_stmt) {
        $tStmt->bind_param('i', $userId);
        $tStmt->execute();
        $twofa = $tStmt->get_result()->fetch_assoc() ?: null;
        $tStmt->close();
    }
}

if (!$user || !$twofa || (int)($twofa['enabled'] ?? 0) !== 1) {
    unset($_SESSION['pending_2fa_user_id']);
    Flash::set('error', '2FA is not enabled for this account.');
    Response::redirect('../index.php');
}

if (Request::isPost()) {
    $code = trim((string)Request::post('code', ''));
    $remember = (string)Request::post('remember_device', '0') === '1';

    if ($code === '') {
        $errors['code'] = 'Code is required.';
    } else {
        $secret = (string)($twofa['totp_secret'] ?? '');
        if (!Totp::verifyCode($secret, $code, 1, 30, 6)) {
            $errors['code'] = 'Invalid code.';
        }
    }

    if (empty($errors)) {
        unset($_SESSION['pending_2fa_user_id']);

        $_SESSION['user_id'] = (int)($user['id'] ?? 0);
        $_SESSION['username'] = (string)($user['username'] ?? '');
        $_SESSION['role'] = (string)($user['role'] ?? '');
        if ($hasUsersGuestIdColumn) {
            $_SESSION['guest_id'] = (int)($user['guest_id'] ?? 0);
        }

        if ($remember) {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = date('Y-m-d H:i:s', time() + (7 * 24 * 60 * 60));
            $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

            $iStmt = $conn->prepare('INSERT INTO user_trusted_devices (user_id, token_hash, expires_at, user_agent, last_used_at) VALUES (?, ?, ?, ?, NOW())');
            if ($iStmt instanceof mysqli_stmt) {
                $iStmt->bind_param('isss', $userId, $tokenHash, $expiresAt, $ua);
                $iStmt->execute();
                $iStmt->close();

                $cookieExpire = time() + (7 * 24 * 60 * 60);
                setcookie('trusted_device', $rawToken, [
                    'expires' => $cookieExpire,
                    'path' => '/',
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }

        if ((string)($_SESSION['role'] ?? '') === 'guest') {
            Response::redirect('guest/index.php');
        }
        Response::redirect('Dashboard.php');
    }
}

$pageTitle = 'Verify Code - Hotel Management System';
$APP_BASE_URL = App::baseUrl();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            height: 100vh;
            overflow: hidden;
        }
        #particles-js { position: absolute; width: 50%; height: 100%; top: 0; left: 0; z-index: 1; }
        #trianglify-canvas { position: absolute; width: 50%; height: 100%; top: 0; right: 0; z-index: 1; }
        .login-container { display: flex; width: 100%; height: 100vh; overflow: hidden; }
        .login-left {
            flex: 1;
            padding: 60px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-end;
            background: #f8f9fa;
            position: relative;
        }
        .login-form-container { width: 100%; max-width: 420px; padding: 40px; position: relative; z-index: 3; }
        .logo-section { text-align: center; margin-bottom: 26px; }
        .logo-container { display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; transition: all 0.3s ease; }
        .logo-container:hover { transform: scale(1.05); }
        .logo-icon { width: 110px; height: 110px; object-fit: contain; filter: drop-shadow(0 4px 15px rgba(0, 123, 255, 0.3)); transition: transform 0.3s ease; }
        .logo-container:hover .logo-icon { transform: rotate(2deg); filter: drop-shadow(0 6px 20px rgba(0, 123, 255, 0.4)); }
        .logo-section h1 { font-size: 22px; font-weight: 700; color: rgb(12, 46, 94); margin-bottom: 6px; }
        .logo-section p { font-size: 13px; color: #6b7280; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 14px; font-weight: 600; color: #1a365d; margin-bottom: 10px; }
        .otp-row { display: flex; gap: 10px; justify-content: center; }
        .otp-box {
            width: 46px;
            height: 52px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 20px;
            font-weight: 700;
            color: #1a365d;
            text-align: center;
            background: rgba(255, 255, 255, 0.9);
            transition: all 0.2s ease;
        }
        .otp-box:hover { border-color: #007bff; box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15); transform: translateY(-1px); }
        .otp-box:focus { outline: none; border-color: #007bff; box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.12); background: rgba(255, 255, 255, 1); }
        .remember-row { display: flex; align-items: center; gap: 10px; margin-top: 2px; }
        .remember-row input { width: 16px; height: 16px; }
        .remember-row label { font-size: 13px; color: #1a365d; }
        .btn-primary {
            width: 100%;
            padding: 14px;
            background: #007bff;
            border: none;
            border-radius: 10px;
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }
        .btn-primary:hover { background: #0056b3; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3); }
        .btn-primary:active { transform: translateY(0); }
        .btn-secondary {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid #ddd;
            background: #ffffff;
            color: #1a365d;
            font-weight: 700;
            text-decoration: none;
            display: block;
            text-align: center;
            margin-top: 10px;
            transition: all 0.2s ease;
        }
        .btn-secondary:hover { background: #f1f5f9; border-color: #cbd5e1; }
        .error-message {
            margin-top: 10px;
            padding: 12px 14px;
            border-radius: 10px;
            background: rgba(220, 53, 69, 0.1);
            color: #b42318;
            border: 1px solid rgba(220, 53, 69, 0.25);
            font-size: 13px;
            text-align: center;
        }
        .hint { font-size: 12px; color: #6b7280; margin-top: 10px; text-align: center; line-height: 1.35; }
        .login-right {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            padding: 60px 50px;
            color: #ffffff;
            position: relative;
            padding-left: 10px;
        }
        .right-text { text-align: left; z-index: 3; position: relative; }
        .right-text h2 {
            font-size: 36px;
            font-weight: 800;
            color: #ffffff;
            margin: 0;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
            line-height: 1.2;
        }
        .right-text p { margin-top: 18px; font-size: 15px; opacity: 0.95; max-width: 440px; line-height: 1.55; }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.22);
            margin-top: 26px;
        }
        .badge span { font-size: 13px; font-weight: 700; }
        @media (max-width: 768px) {
            body { overflow: auto; }
            .login-container { flex-direction: column; height: auto; min-height: 100vh; }
            #particles-js, #trianglify-canvas { display: none; }
            .login-right { order: -1; padding: 40px 30px; }
            .login-left { padding: 40px 30px; align-items: center; }
            .login-form-container { padding: 26px; }
        }
    </style>
</head>
<body>
    <div id="particles-js"></div>
    <canvas id="trianglify-canvas"></canvas>
    <div class="login-container">
        <div class="login-left">
            <div class="login-form-container">
                <div class="logo-section">
                    <div class="logo-container">
                        <img src="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/H.png" alt="Hotel Management System Logo" class="logo-icon">
                    </div>
                    <h1>Two-Factor Authentication</h1>
                    <p>Enter the 6-digit code from your authenticator app</p>
                </div>

                <?php $flash = Flash::get(); ?>
                <?php if ($flash): ?>
                    <div class="error-message" style="background:<?= $flash['type'] === 'success' ? 'rgba(40,167,69,0.10)' : 'rgba(220,53,69,0.10)' ?>;border-color:<?= $flash['type'] === 'success' ? 'rgba(40,167,69,0.25)' : 'rgba(220,53,69,0.25)' ?>;color:<?= $flash['type'] === 'success' ? '#027a48' : '#b42318' ?>;">
                        <?= htmlspecialchars($flash['message']) ?>
                    </div>
                <?php endif; ?>

                <form method="post" id="totpForm" autocomplete="off">
                    <div class="form-group">
                        <label for="otp1">Authenticator code</label>
                        <div class="otp-row" aria-label="6 digit code">
                            <input id="otp1" class="otp-box" inputmode="numeric" maxlength="1" autocomplete="one-time-code" />
                            <input id="otp2" class="otp-box" inputmode="numeric" maxlength="1" />
                            <input id="otp3" class="otp-box" inputmode="numeric" maxlength="1" />
                            <input id="otp4" class="otp-box" inputmode="numeric" maxlength="1" />
                            <input id="otp5" class="otp-box" inputmode="numeric" maxlength="1" />
                            <input id="otp6" class="otp-box" inputmode="numeric" maxlength="1" />
                        </div>
                        <input type="hidden" name="code" id="code" value="" />
                        <?php if (isset($errors['code'])): ?>
                            <div class="error-message" style="margin-top:14px;">
                                <?= htmlspecialchars($errors['code']) ?>
                            </div>
                        <?php endif; ?>
                        <div class="hint">Tip: you can paste the full 6-digit code. Use the latest code shown in your app.</div>
                    </div>

                    <div class="form-group">
                        <div class="remember-row">
                            <input type="hidden" name="remember_device" value="0" />
                            <input id="remember_device" type="checkbox" name="remember_device" value="1" />
                            <label for="remember_device">Remember this device for 7 days</label>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">Verify</button>
                    <a class="btn-secondary" href="../index.php">Back to Login</a>
                </form>
            </div>
        </div>

        <div class="login-right">
            <div class="right-text">
                <h2>Hotel Management</h2>
                <h2>System</h2>
                <p>
                    We enabled two-factor authentication to keep your guest account safe.
                    This extra step helps prevent unauthorized access.
                </p>
                <div class="badge"><span>Security check</span><span>•</span><span>Google Authenticator</span><span>•</span><span>7-day trusted device</span></div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var inputs = [
                document.getElementById('otp1'),
                document.getElementById('otp2'),
                document.getElementById('otp3'),
                document.getElementById('otp4'),
                document.getElementById('otp5'),
                document.getElementById('otp6')
            ];
            var hidden = document.getElementById('code');
            var form = document.getElementById('totpForm');

            function sanitize(v) {
                return (v || '').replace(/\D+/g, '');
            }

            function syncHidden() {
                var v = '';
                for (var i = 0; i < inputs.length; i++) {
                    v += sanitize(inputs[i].value).slice(0, 1);
                }
                hidden.value = v;
                return v;
            }

            function focusIndex(idx) {
                if (idx < 0) idx = 0;
                if (idx >= inputs.length) idx = inputs.length - 1;
                inputs[idx].focus();
                inputs[idx].select();
            }

            inputs.forEach(function (inp, idx) {
                inp.addEventListener('input', function (e) {
                    var v = sanitize(inp.value);
                    inp.value = v.slice(0, 1);
                    syncHidden();
                    if (inp.value && idx < inputs.length - 1) {
                        focusIndex(idx + 1);
                    }
                });

                inp.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace' && !inp.value && idx > 0) {
                        focusIndex(idx - 1);
                    }
                    if (e.key === 'ArrowLeft') {
                        e.preventDefault();
                        focusIndex(idx - 1);
                    }
                    if (e.key === 'ArrowRight') {
                        e.preventDefault();
                        focusIndex(idx + 1);
                    }
                });

                inp.addEventListener('paste', function (e) {
                    var data = '';
                    if (e.clipboardData && e.clipboardData.getData) {
                        data = e.clipboardData.getData('text');
                    }
                    data = sanitize(data);
                    if (!data) {
                        return;
                    }
                    e.preventDefault();
                    for (var i = 0; i < inputs.length; i++) {
                        inputs[i].value = data[i] ? data[i] : '';
                    }
                    syncHidden();
                    var code = hidden.value;
                    if (code.length === 6) {
                        form.requestSubmit ? form.requestSubmit() : form.submit();
                    } else {
                        focusIndex(Math.min(code.length, 5));
                    }
                });
            });

            window.setTimeout(function () {
                focusIndex(0);
            }, 50);

            form.addEventListener('submit', function () {
                syncHidden();
            });
        })();
    </script>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script src="https://unpkg.com/trianglify@^4/dist/trianglify.bundle.js"></script>
    <script>
        particlesJS('particles-js', {
            "particles": {
                "number": {
                    "value": 120,
                    "density": {
                        "enable": true,
                        "value_area": 800
                    }
                },
                "color": {
                    "value": "#007bff"
                },
                "shape": {
                    "type": "circle",
                    "stroke": {
                        "width": 0,
                        "color": "#000000"
                    }
                },
                "opacity": {
                    "value": 0.4,
                    "random": false,
                    "anim": {
                        "enable": false,
                        "speed": 1,
                        "opacity_min": 0.1,
                        "sync": false
                    }
                },
                "size": {
                    "value": 4,
                    "random": true,
                    "anim": {
                        "enable": false,
                        "speed": 40,
                        "size_min": 2,
                        "sync": false
                    }
                },
                "line_linked": {
                    "enable": true,
                    "distance": 150,
                    "color": "#007bff",
                    "opacity": 0.3,
                    "width": 1
                },
                "move": {
                    "enable": true,
                    "speed": 3,
                    "direction": "none",
                    "random": false,
                    "straight": false,
                    "out_mode": "out",
                    "bounce": false,
                    "attract": {
                        "enable": false,
                        "rotateX": 600,
                        "rotateY": 1200
                    }
                }
            },
            "interactivity": {
                "detect_on": "canvas",
                "events": {
                    "onhover": {
                        "enable": true,
                        "mode": "grab"
                    },
                    "onclick": {
                        "enable": false,
                        "mode": "push"
                    },
                    "resize": true
                },
                "modes": {
                    "grab": {
                        "distance": 200,
                        "line_linked": {
                            "opacity": 0.6
                        }
                    },
                    "bubble": {
                        "distance": 400,
                        "size": 40,
                        "duration": 2,
                        "opacity": 8,
                        "speed": 3
                    },
                    "repulse": {
                        "distance": 200,
                        "duration": 0.4
                    },
                    "push": {
                        "particles_nb": 4
                    },
                    "remove": {
                        "particles_nb": 2
                    }
                }
            },
            "retina_detect": true
        });

        function initTrianglify() {
            const canvas = document.getElementById('trianglify-canvas');
            const pattern = trianglify({
                width: window.innerWidth / 2,
                height: window.innerHeight,
                cellSize: 75,
                variance: 0.75,
                xColors: ['#1a365d', '#4a90e2'],
                yColors: 'match',
                fill: true,
                strokeWidth: 0,
                seed: null
            });

            pattern.toCanvas(canvas);
        }

        window.addEventListener('load', initTrianglify);
        window.addEventListener('resize', initTrianglify);
    </script>
</body>
</html>
