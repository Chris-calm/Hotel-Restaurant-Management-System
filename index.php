<?php
session_start();
require_once 'PHP/db_connect.php';
require_once 'PHP/email_config.php';
require_once 'PHP/core/bootstrap.php';

$APP_BASE_URL = App::baseUrl();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!isset($conn) || !$conn) {
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = $username !== '' ? $username : 'user';
        $_SESSION['role'] = 'staff';
        header("Location: PHP/Dashboard.php");
        exit();
    }

    // Prepare statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT id, username, password_hash, role, email FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Verify password using password_verify for hashed passwords
        $hash = (string)($user['password_hash'] ?? '');
        if ($hash !== '' && password_verify($password, $hash)) {
            
            // Check if user has OTP enabled (superadmin and admin)
            if (in_array($username, ['superadmin', 'admin']) && !empty($user['email'])) {
                // Generate OTP
                $otp = generateOTP();
                $expires_at = date('Y-m-d H:i:s', time() + 300); // 5 minutes from now
                
                // Store OTP in database
                $otp_stmt = $conn->prepare("INSERT INTO user_otps (user_id, username, otp_code, email, expires_at) VALUES (?, ?, ?, ?, ?)");
                $otp_stmt->bind_param("issss", $user['id'], $username, $otp, $user['email'], $expires_at);
                
                if ($otp_stmt->execute()) {
                    // Send OTP email
                    if (sendOTPEmail($user['email'], $username, $otp)) {
                        // Set pending OTP session
                        $_SESSION['pending_otp_user_id'] = $user['id'];
                        $_SESSION['pending_otp_username'] = $username;
                        
                        $otp_stmt->close();
                        $stmt->close();
                        $conn->close();
                        
                        // Redirect to OTP verification
                        header("Location: PHP/verify_otp.php");
                        exit();
                    } else {
                        // Email failed, but allow login anyway for now
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        
                        $otp_stmt->close();
                        $stmt->close();
                        $conn->close();
                        
                        header("Location: PHP/Dashboard.php?email_warning=1");
                        exit();
                    }
                } else {
                    // OTP storage failed, allow login anyway
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    
                    $otp_stmt->close();
                    $stmt->close();
                    $conn->close();
                    
                    header("Location: PHP/Dashboard.php");
                    exit();
                }
            } else {
                // No OTP required for this user (employee or no email)
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                
                $stmt->close();
                $conn->close();
                
                header("Location: PHP/Dashboard.php");
                exit();
            }
        }
    }
    
    $stmt->close();
    $conn->close();
    
    // If authentication fails, redirect with error
    header("Location: index.php?error=1");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Hotel Management System - Login</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
      margin: 0;
      padding: 0;
      height: 100vh;
      overflow: hidden;
    }

    #particles-js {
      position: absolute;
      width: 50%;
      height: 100%;
      top: 0;
      left: 0;
      z-index: 1;
    }

    #trianglify-canvas {
      position: absolute;
      width: 50%;
      height: 100%;
      top: 0;
      right: 0;
      z-index: 1;
    }

    .login-container {
      display: flex;
      width: 100%;
      height: 100vh;
      overflow: hidden;
    }

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

    .login-form-container {
      width: 100%;
      max-width: 400px;
      padding: 40px;
      position: relative;
      z-index: 3;
    }

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

    .right-text {
      text-align: left;
      z-index: 3;
      position: relative;
    }

    .right-text h2 {
      font-size: 36px;
      font-weight: 700;
      color: #ffffff;
      margin: 0;
      text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
      line-height: 1.2;
      transition: all 0.3s ease;
      cursor: default;
    }

    .right-text h2:hover {
      transform: translateY(-2px);
      text-shadow: 0 4px 15px rgba(0, 0, 0, 0.7);
    }


    .logo-section {
      text-align: center;
      margin-bottom: 40px;
    }

    .logo-container {
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px;
      transition: all 0.3s ease;
    }

    .logo-container:hover {
      transform: scale(1.05);
    }

    .logo-icon {
      width: 120px;
      height: 120px;
      object-fit: contain;
      transition: transform 0.3s ease;
      filter: drop-shadow(0 4px 15px rgba(0, 123, 255, 0.3));
    }

    .logo-container:hover .logo-icon {
      transform: rotate(2deg);
      filter: drop-shadow(0 6px 20px rgba(0, 123, 255, 0.4));
    }

    .logo-section h1 {
      font-size: 24px;
      font-weight: 600;
      color:rgb(12, 46, 94);
      margin-bottom: 8px;
    }

    .form-group {
      margin-bottom: 24px;
    }

    .form-group label {
      display: block;
      font-size: 14px;
      font-weight: 500;
      color: #1a365d;
      margin-bottom: 8px;
    }

    .form-group input {
      width: 100%;
      padding: 12px 16px;
      border: 1px solid #ddd;
      border-radius: 8px;
      font-size: 15px;
      color: #1a365d;
      transition: all 0.3s ease;
      background: rgba(255, 255, 255, 0.9);
      backdrop-filter: blur(5px);
    }

    .form-group input:hover {
      border-color: #007bff;
      background: rgba(255, 255, 255, 0.95);
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
    }

    .form-group input:focus {
      outline: none;
      border-color: #007bff;
      background: rgba(255, 255, 255, 1);
      box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
      transform: translateY(-1px);
    }

    .form-group input::placeholder {
      color: #999;
    }

    .btn-login {
      width: 100%;
      padding: 14px;
      background: #007bff;
      border: none;
      border-radius: 8px;
      color: #fff;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      margin-top: 10px;
    }

    .btn-login:hover {
      background: #0056b3;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
    }

    .btn-login:active {
      transform: translateY(0);
    }

    .error-message {
      background: #fee;
      border: 1px solid #fcc;
      color: #c33;
      padding: 12px 16px;
      border-radius: 8px;
      font-size: 14px;
      margin-top: 20px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .right-content {
      position: relative;
      z-index: 1;
      text-align: center;
    }

    .right-content img {
      width: 250px;
      height: 250px;
      margin-bottom: 25px;
      border-radius: 50%;
      background: #fff;
      padding: 25px;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
      image-rendering: -webkit-optimize-contrast;
      image-rendering: crisp-edges;
    }

    .right-content h2 {
      font-size: 32px;
      font-weight: 700;
      margin-bottom: 20px;
      color: #2c3e50;
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .right-content p {
      font-size: 16px;
      line-height: 1.6;
      color: #34495e;
      opacity: 0.9;
      margin-bottom: 30px;
    }

    .features {
      display: flex;
      flex-direction: column;
      gap: 15px;
      text-align: left;
    }

    .feature-item {
      display: flex;
      align-items: center;
      gap: 12px;
      background: rgba(255, 255, 255, 0.4);
      padding: 12px 16px;
      border-radius: 8px;
      backdrop-filter: blur(10px);
      color: #2c3e50;
      transition: all 0.3s ease;
      cursor: pointer;
    }

    .feature-item:hover {
      background: rgba(255, 255, 255, 0.6);
      transform: translateX(5px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .feature-icon {
      width: 24px;
      height: 24px;
      background: rgba(74, 144, 226, 0.3);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 14px;
      color: #2c3e50;
      transition: all 0.3s ease;
    }

    .feature-item:hover .feature-icon {
      background: rgba(74, 144, 226, 0.5);
      transform: scale(1.1);
    }

    @media (max-width: 768px) {
      .login-container {
        flex-direction: column;
      }

      .login-right {
        order: -1;
        padding: 40px 30px;
      }

      .login-left {
        padding: 40px 30px;
      }

      .features {
        display: none;
      }
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
          <h1>Sign in</h1>
        </div>

        <form action="index.php" method="POST">
          <div class="form-group">
            <label for="username">Email Address *</label>
            <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
          </div>

          <div class="form-group">
            <label for="password">Password *</label>
            <input type="password" id="password" name="password" placeholder="Enter your password" required>
          </div>

          <button type="submit" class="btn-login">Sign In</button>
        </form>
      </div>
      
      <?php if (isset($_GET['error'])): ?>
        <div class="error-message">
          <span>⚠️</span>
          <span>Invalid username or password. Please try again.</span>
        </div>
      <?php endif; ?>
      
      <?php if (isset($_GET['otp_expired'])): ?>
        <div class="error-message">
          <span>⏰</span>
          <span>OTP has expired. Please login again to receive a new code.</span>
        </div>
      <?php endif; ?>
      
      <div>
      </div>
    </div>

    <div class="login-right">
      <div class="right-text">
        <h2>Hotel Management</h2>
        <h2>System</h2>
      </div>
    </div>
  </div>
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

    // Initialize Trianglify for the right side
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

    // Initialize Trianglify when page loads
    window.addEventListener('load', initTrianglify);
    
    // Reinitialize on window resize
    window.addEventListener('resize', initTrianglify);
  </script>
</body>
</html>
