<?php
session_start();

// ─── SMTP CONFIGURATION ─────────────────────────
$smtp_host = 'smtp.gmail.com';
$smtp_port = 465;
$smtp_user = 'agradeepkar@gmail.com';
$smtp_pass = 'qivr nqfk dldj jicz';

// ─── Raw SMTP mail function (unchanged) ─────────
function send_smtp_mail($to, $subject, $body, $user, $pass, $host, $port, $from_name) {
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);

    $socket = stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) return "Connection failed: $errstr ($errno)";

    $read_server = function($socket) {
        $response = "";
        while ($str = fgets($socket, 515)) {
            $response .= $str;
            if (substr($str, 3, 1) == " ") break;
        }
        return $response;
    };

    $read_server($socket);
    fputs($socket, "EHLO {$host}\r\n"); $read_server($socket);
    fputs($socket, "AUTH LOGIN\r\n"); $read_server($socket);
    fputs($socket, base64_encode($user) . "\r\n"); $read_server($socket);
    fputs($socket, base64_encode($pass) . "\r\n"); $read_server($socket);
    fputs($socket, "MAIL FROM: <{$user}>\r\n"); $read_server($socket);
    fputs($socket, "RCPT TO: <{$to}>\r\n"); $read_server($socket);
    fputs($socket, "DATA\r\n"); $read_server($socket);

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$from_name} <{$user}>\r\n";
    $headers .= "To: <{$to}>\r\n";
    $headers .= "Subject: {$subject}\r\n";

    fputs($socket, "$headers\r\n$body\r\n.\r\n");
    $read_server($socket);
    fputs($socket, "QUIT\r\n");
    fclose($socket);

    return true;
}

// ─── OTP Logic ──────────────────────────────────
$message = '';
$message_type = '';

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 1) Generate & send OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_otp') {
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);

    if (!$email) {
        $message = 'Please enter a valid email address.';
        $message_type = 'error';
    } else {
        $otp = sprintf("%06d", mt_rand(1, 999999));
        $_SESSION['otp'] = $otp;
        $_SESSION['otp_email'] = $email;
        $_SESSION['otp_expiry'] = time() + 300;

        // Professional email template with "Cloudy" branding
        $subject = "🔐 Cloudy – Your OTP Verification Code";
        $body = "
        <!DOCTYPE html>
        <html>
        <head><meta charset='UTF-8'></head>
        <body style='margin:0;padding:0;background:#f6f9fc;font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
            <div style='max-width:520px;margin:40px auto;background:#ffffff;border-radius:24px;box-shadow:0 20px 35px -10px rgba(0,0,0,0.05);overflow:hidden;border:1px solid #eef2f6;'>
                <div style='background: linear-gradient(135deg, #1E3A8A 0%, #3B82F6 100%);padding:32px 28px;text-align:center;'>
                    <div style='font-size:42px;font-weight:800;letter-spacing:-1px;color:#ffffff;margin-bottom:8px;'>☁️ Cloudy</div>
                    <div style='font-size:14px;color:rgba(255,255,255,0.85);'>secure authentication</div>
                </div>
                <div style='padding:36px 32px;'>
                    <h2 style='margin-top:0;margin-bottom:16px;font-size:24px;font-weight:600;color:#1e293b;'>Verification Code</h2>
                    <p style='color:#475569;line-height:1.5;margin-bottom:28px;'>Use the following OTP to complete your login. This code expires in <strong>5 minutes</strong>.</p>
                    <div style='background:#f8fafc;border-radius:18px;padding:18px;text-align:center;border:1px solid #e2e8f0;margin-bottom:28px;'>
                        <div style='font-size:42px;font-weight:700;font-family:monospace;letter-spacing:8px;color:#1e3a8a;'>$otp</div>
                    </div>
                    <p style='color:#475569;font-size:14px;margin-bottom:8px;'>If you didn't request this, please ignore this email.</p>
                    <hr style='margin:28px 0 16px;border:none;border-top:1px solid #eef2f6;'>
                    <p style='color:#94a3b8;font-size:12px;text-align:center;margin:0;'>Cloudy Security Team – protecting your account</p>
                </div>
            </div>
        </body>
        </html>";

        $result = send_smtp_mail($email, $subject, $body, $smtp_user, $smtp_pass, $smtp_host, $smtp_port, "Cloudy Auth");

        if ($result === true) {
            $message = "✅ OTP sent to $email. Check your inbox (or spam).";
            $message_type = 'success';
            $_SESSION['otp_sent'] = true;
        } else {
            $message = "❌ Failed to send OTP. Mail error: $result";
            $message_type = 'error';
        }
    }
}

// 2) Verify OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    $entered_otp = preg_replace('/[^0-9]/', '', $_POST['otp'] ?? '');
    $stored_otp  = $_SESSION['otp'] ?? '';
    $expiry      = $_SESSION['otp_expiry'] ?? 0;

    if (empty($stored_otp) || time() > $expiry) {
        $message = '❌ OTP expired or not requested. Please generate a new OTP.';
        $message_type = 'error';
    } elseif ($entered_otp === $stored_otp) {
        $_SESSION['authenticated'] = true;
        $_SESSION['auth_email'] = $_SESSION['otp_email'];
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_expiry'], $_SESSION['otp_sent']);
        $message = '🎉 Successfully authenticated! You are now logged in.';
        $message_type = 'success';
    } else {
        $message = '❌ Invalid OTP. Please try again.';
        $message_type = 'error';
    }
}

$is_logged_in = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Cloudy • Secure Calculator</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(145deg, #f0f9ff 0%, #e6f0fa 100%);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
        }

        /* animated blobs */
        .blob {
            position: fixed;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(59,130,246,0.12) 0%, rgba(37,99,235,0.02) 100%);
            border-radius: 50%;
            filter: blur(70px);
            z-index: 0;
        }
        .blob-1 { top: -200px; left: -200px; }
        .blob-2 { bottom: -200px; right: -200px; background: radial-gradient(circle, rgba(99,102,241,0.1), rgba(139,92,246,0.02)); }

        /* main card */
        .card {
            position: relative;
            z-index: 2;
            max-width: 520px;
            width: 100%;
            background: rgba(255,255,255,0.96);
            backdrop-filter: blur(2px);
            border-radius: 40px;
            box-shadow: 0 25px 45px -12px rgba(0,0,0,0.15), 0 1px 2px rgba(0,0,0,0.02);
            overflow: hidden;
            transition: transform 0.2s ease;
        }

        .card-header {
            background: linear-gradient(135deg, #0f2b63 0%, #1e4b8f 100%);
            padding: 24px 28px;
            text-align: center;
        }
        .card-header h1 {
            font-size: 32px;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .card-header p {
            color: rgba(255,255,255,0.8);
            font-size: 14px;
            margin-top: 6px;
        }

        .card-body {
            padding: 32px 28px 40px;
        }

        .form-group {
            margin-bottom: 24px;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
            letter-spacing: -0.2px;
        }
        .input-group {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .input-group input {
            flex: 1;
            padding: 14px 16px;
            font-size: 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 28px;
            font-family: 'Inter', monospace;
            transition: all 0.2s;
            background: #ffffff;
        }
        .input-group input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59,130,246,0.15);
        }
        button {
            border: none;
            border-radius: 40px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
        }
        .btn-primary {
            width: 100%;
            padding: 14px;
            background: linear-gradient(105deg, #1e3a8a, #2563eb);
            color: white;
            box-shadow: 0 6px 14px rgba(37,99,235,0.25);
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px -5px rgba(37,99,235,0.4);
        }
        .btn-copy {
            background: #f1f5f9;
            color: #1e293b;
            padding: 0 18px;
            height: 52px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #e2e8f0;
        }
        .btn-copy:hover {
            background: #e6edf5;
        }
        .btn-secondary {
            background: #1e293b;
            color: white;
            width: 100%;
            padding: 12px;
            font-weight: 500;
        }
        .btn-secondary:hover {
            background: #0f172a;
        }
        .divider {
            margin: 24px 0;
            text-align: center;
            position: relative;
            font-size: 12px;
            color: #94a3b8;
        }
        .divider::before, .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 42%;
            height: 1px;
            background: #e2e8f0;
        }
        .divider::before { left: 0; }
        .divider::after { right: 0; }

        .alert {
            padding: 14px 18px;
            border-radius: 28px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
            background: #f8fafc;
            border-left: 4px solid;
        }
        .alert-success {
            background: #e6f7ec;
            border-left-color: #10b981;
            color: #065f46;
        }
        .alert-error {
            background: #fee9e6;
            border-left-color: #ef4444;
            color: #991b1b;
        }
        
        /* Calculator Styles */
        .calculator-app {
            background: #ffffff;
            border-radius: 32px;
            padding: 20px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
        }
        .calc-display {
            background: #f1f5f9;
            padding: 24px 20px;
            border-radius: 28px;
            margin-bottom: 24px;
            text-align: right;
            font-size: 32px;
            font-weight: 600;
            font-family: 'Inter', monospace;
            letter-spacing: 1px;
            color: #0f172a;
            word-wrap: break-word;
            word-break: break-all;
            min-height: 85px;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.05);
        }
        .calc-buttons {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }
        .calc-btn {
            background: #f8fafc;
            border: none;
            padding: 18px 0;
            border-radius: 28px;
            font-size: 20px;
            font-weight: 600;
            color: #1e293b;
            transition: all 0.2s;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .calc-btn:hover {
            background: #eef2ff;
            transform: translateY(-2px);
        }
        .calc-btn.operator {
            background: #e0e7ff;
            color: #1e3a8a;
            font-size: 24px;
        }
        .calc-btn.equals {
            background: linear-gradient(105deg, #1e3a8a, #2563eb);
            color: white;
            grid-column: span 1;
        }
        .calc-btn.clear {
            background: #fee2e2;
            color: #b91c1c;
        }
        .user-badge {
            background: #e0e7ff;
            padding: 6px 14px;
            border-radius: 40px;
            font-size: 12px;
            font-weight: 500;
            color: #1e40af;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 20px;
        }
        .logout-link-calc {
            display: inline-block;
            margin-top: 20px;
            color: #64748b;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            border-bottom: 1px dashed #cbd5e1;
            transition: color 0.2s;
        }
        .logout-link-calc:hover {
            color: #ef4444;
        }
        .footer-note {
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid #ecf3f9;
        }
        @media (max-width: 500px) {
            .card-body { padding: 28px 20px; }
            .calc-btn { padding: 14px 0; font-size: 18px; }
            .calc-display { font-size: 26px; padding: 18px 16px; }
        }
    </style>
</head>
<body>
<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<div class="card">
    <div class="card-header">
        <h1>☁️ Cloudy</h1>
        <p><?= $is_logged_in ? 'secure calculator' : 'OTP authentication' ?></p>
    </div>
    <div class="card-body">

        <?php if (!empty($message) && !$is_logged_in): ?>
            <div class="alert alert-<?= $message_type ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($is_logged_in): ?>
            <!-- LOGGED IN: CALCULATOR APP -->
            <div class="calculator-app">
                <div class="user-badge">
                    <span>🔐</span> <?= htmlspecialchars($_SESSION['auth_email'] ?? 'user') ?>
                </div>
                <div class="calc-display" id="calcDisplay">0</div>
                <div class="calc-buttons">
                    <button class="calc-btn clear" data-action="clear">C</button>
                    <button class="calc-btn" data-action="backspace">⌫</button>
                    <button class="calc-btn operator" data-op="%">%</button>
                    <button class="calc-btn operator" data-op="/">÷</button>
                    
                    <button class="calc-btn" data-num="7">7</button>
                    <button class="calc-btn" data-num="8">8</button>
                    <button class="calc-btn" data-num="9">9</button>
                    <button class="calc-btn operator" data-op="*">×</button>
                    
                    <button class="calc-btn" data-num="4">4</button>
                    <button class="calc-btn" data-num="5">5</button>
                    <button class="calc-btn" data-num="6">6</button>
                    <button class="calc-btn operator" data-op="-">−</button>
                    
                    <button class="calc-btn" data-num="1">1</button>
                    <button class="calc-btn" data-num="2">2</button>
                    <button class="calc-btn" data-num="3">3</button>
                    <button class="calc-btn operator" data-op="+">+</button>
                    
                    <button class="calc-btn" data-num="0">0</button>
                    <button class="calc-btn" data-num="00">00</button>
                    <button class="calc-btn" data-num=".">.</button>
                    <button class="calc-btn equals" data-action="equals">=</button>
                </div>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="?logout=1" class="logout-link-calc">→ Sign out</a>
                </div>
            </div>
            <div class="footer-note">
                ☁️ Cloudy • secure & private calculator
            </div>
        <?php else: ?>
            <?php if (!isset($_SESSION['otp_sent']) || $_SESSION['otp_sent'] !== true): ?>
                <!-- Step 1: Request OTP -->
                <form method="POST">
                    <input type="hidden" name="action" value="send_otp">
                    <div class="form-group">
                        <label>📧 Email address</label>
                        <input type="email" name="email" required placeholder="hello@cloudy.com" autocomplete="email">
                    </div>
                    <button type="submit" class="btn-primary">📨 Generate OTP</button>
                </form>
            <?php else: ?>
                <!-- Step 2: Verify OTP with Copy Button -->
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?= $message_type ?>">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>
                <form method="POST" id="otpForm">
                    <input type="hidden" name="action" value="verify_otp">
                    <div class="form-group">
                        <label>🔢 One‑Time Password (OTP)</label>
                        <div class="input-group">
                            <input type="text" name="otp" id="otpInput" required placeholder="6‑digit code" maxlength="6" autocomplete="off">
                            <button type="button" class="btn-copy" id="copyOtpBtn">📋 Copy</button>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary">✓ Verify & Login</button>
                </form>
                <div class="divider">or</div>
                <form method="POST">
                    <input type="hidden" name="action" value="send_otp">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($_SESSION['otp_email'] ?? '') ?>">
                    <button type="submit" class="btn-secondary">⟳ Resend OTP</button>
                </form>
                <div class="hint" style="font-size:12px; color:#64748b; text-align:center; margin-top:16px;">🔒 OTP valid for 5 minutes. Check spam folder if needed.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Calculator JavaScript (only runs when logged in) -->
<?php if ($is_logged_in): ?>
<script>
    (function() {
        let currentInput = "0";
        let previousInput = "";
        let operation = null;
        let shouldResetDisplay = false;

        const displayElement = document.getElementById('calcDisplay');

        function updateDisplay() {
            // Limit display length for readability
            let displayValue = currentInput;
            if (displayValue.length > 18) {
                displayValue = parseFloat(displayValue).toExponential(10);
            }
            displayElement.innerText = displayValue;
        }

        function handleNumber(num) {
            if (shouldResetDisplay) {
                currentInput = "0";
                shouldResetDisplay = false;
            }
            if (currentInput === "0" && num !== ".") {
                currentInput = num;
            } else {
                // Prevent multiple decimals
                if (num === "." && currentInput.includes(".")) return;
                currentInput += num;
            }
            updateDisplay();
        }

        function handleOperator(op) {
            if (operation !== null && !shouldResetDisplay) {
                calculate();
            }
            previousInput = currentInput;
            operation = op;
            shouldResetDisplay = true;
        }

        function calculate() {
            if (operation === null || shouldResetDisplay) return;
            let a = parseFloat(previousInput);
            let b = parseFloat(currentInput);
            if (isNaN(a) || isNaN(b)) return;
            
            let result;
            switch (operation) {
                case '+': result = a + b; break;
                case '-': result = a - b; break;
                case '*': result = a * b; break;
                case '/': 
                    if (b === 0) {
                        result = "Error";
                    } else {
                        result = a / b;
                    }
                    break;
                case '%': result = a % b; break;
                default: return;
            }
            
            if (result === "Error") {
                currentInput = "Error";
                operation = null;
                previousInput = "";
                shouldResetDisplay = true;
                updateDisplay();
                return;
            }
            
            // Format result: avoid too many decimals
            result = parseFloat(result.toFixed(8));
            currentInput = result.toString();
            operation = null;
            previousInput = "";
            shouldResetDisplay = true;
            updateDisplay();
        }

        function clearAll() {
            currentInput = "0";
            previousInput = "";
            operation = null;
            shouldResetDisplay = false;
            updateDisplay();
        }

        function backspace() {
            if (shouldResetDisplay) {
                clearAll();
                return;
            }
            if (currentInput.length === 1 || (currentInput.length === 2 && currentInput.startsWith("-"))) {
                currentInput = "0";
            } else {
                currentInput = currentInput.slice(0, -1);
            }
            updateDisplay();
        }

        // Attach event listeners
        document.querySelectorAll('.calc-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const num = btn.getAttribute('data-num');
                const op = btn.getAttribute('data-op');
                const action = btn.getAttribute('data-action');
                
                if (num !== null) {
                    handleNumber(num);
                } else if (op !== null) {
                    handleOperator(op);
                } else if (action === 'equals') {
                    calculate();
                } else if (action === 'clear') {
                    clearAll();
                } else if (action === 'backspace') {
                    backspace();
                }
            });
        });

        // Keyboard support
        document.addEventListener('keydown', (e) => {
            const key = e.key;
            if (/[0-9]/.test(key)) {
                handleNumber(key);
                e.preventDefault();
            } else if (key === '.') {
                handleNumber('.');
                e.preventDefault();
            } else if (key === '+' || key === '-' || key === '*' || key === '/') {
                let op = key;
                if (op === '/') op = '/';
                if (op === '*') op = '*';
                handleOperator(op);
                e.preventDefault();
            } else if (key === '%') {
                handleOperator('%');
                e.preventDefault();
            } else if (key === 'Enter' || key === '=') {
                calculate();
                e.preventDefault();
            } else if (key === 'Escape') {
                clearAll();
                e.preventDefault();
            } else if (key === 'Backspace') {
                backspace();
                e.preventDefault();
            }
        });

        updateDisplay();
    })();
</script>
<?php endif; ?>

<?php if (!$is_logged_in && isset($_SESSION['otp_sent']) && $_SESSION['otp_sent'] === true): ?>
<script>
    // Copy button functionality for OTP page
    const copyBtn = document.getElementById('copyOtpBtn');
    const otpInput = document.getElementById('otpInput');
    if (copyBtn && otpInput) {
        copyBtn.addEventListener('click', function() {
            if (otpInput.value.trim() !== "") {
                navigator.clipboard.writeText(otpInput.value).then(() => {
                    const originalText = copyBtn.innerHTML;
                    copyBtn.innerHTML = "✓ Copied!";
                    setTimeout(() => {
                        copyBtn.innerHTML = originalText;
                    }, 1500);
                }).catch(() => {
                    alert("Could not copy. Select and copy manually.");
                });
            } else {
                alert("Nothing to copy – please enter the OTP first.");
            }
        });
    }
</script>
<?php endif; ?>
</body>
</html>