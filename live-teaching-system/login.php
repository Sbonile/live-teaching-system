<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/database.php';

// Check if already logged in
if (isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$errors = [];
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailValue = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($emailValue) || empty($password)) {
        $errors[] = 'Please enter both email and password.';
    }
    
    if (empty($errors)) {
        $connection = getDbConnection();
        
        // Check if users table exists
        $tableCheck = $connection->query("SHOW TABLES LIKE 'users'");
        if ($tableCheck->num_rows == 0) {
            $errors[] = 'Database not set up. Please run the SQL schema first.';
        } else {
            $stmt = $connection->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
            $stmt->bind_param('s', $emailValue);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'fullname' => $user['fullname'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ];
                
                // Redirect based on role
                if ($user['role'] == 'admin') {
                    header('Location: admin/dashboard.php');
                } elseif ($user['role'] == 'teacher') {
                    header('Location: teacher/dashboard.php');
                } else {
                    header('Location: dashboard/index.php');
                }
                exit;
            } else {
                $errors[] = 'Invalid email or password.';
            }
        }
    }
}

require_once 'includes/header.php';
?>

<style>
/* ===== LiveTeach auth revamp — scoped to .lt-auth, matches site style ===== */
.lt-auth {
    --lt-ink: #100D0A;
    --lt-charcoal: #1B1712;
    --lt-charcoal-raised: #241F18;
    --lt-flame: #C8341E;
    --lt-flame-dark: #8A2213;
    --lt-flame-bright: #E44E2E;
    --lt-gold: #D9A441;
    --lt-parchment: #F1E7D6;
    --lt-parchment-dim: #C9BEAC;
    --lt-line: rgba(241, 231, 214, 0.12);
    background: var(--lt-ink);
    color: var(--lt-parchment);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    min-height: 100vh;
    position: relative;
    overflow: hidden;
    padding: 80px 0;
    display: flex;
    align-items: center;
}

.lt-auth::before {
    content: "";
    position: absolute;
    top: -220px;
    right: -180px;
    width: 560px;
    height: 560px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(200, 52, 30, 0.30), transparent 70%);
    pointer-events: none;
}

.lt-auth::after {
    content: "";
    position: absolute;
    bottom: -260px;
    left: -200px;
    width: 520px;
    height: 520px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(217, 164, 65, 0.12), transparent 70%);
    pointer-events: none;
}

.lt-auth .lt-serif,
.lt-auth h1, .lt-auth h2, .lt-auth h3 {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-weight: 500;
}

.lt-auth a { text-decoration: none; color: inherit; }

.lt-auth-container {
    max-width: 460px;
    margin: 0 auto;
    padding: 0 24px;
    width: 100%;
    position: relative;
    z-index: 1;
}

/* ---------- Card ---------- */
.lt-auth-card {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 14px;
    padding: 44px 38px;
}

/* ---------- Brand mark ---------- */
.lt-auth-brand {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 28px;
}

.lt-auth-mark {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--lt-flame-dark), var(--lt-flame));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

.lt-auth-brand span {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.05rem;
    letter-spacing: 0.02em;
    color: var(--lt-parchment);
}

/* ---------- Heading ---------- */
.lt-auth-head {
    text-align: center;
    margin-bottom: 32px;
}

.lt-auth-head h1 {
    font-size: 1.9rem;
    line-height: 1.15;
    margin: 0 0 10px;
    color: var(--lt-parchment);
}

.lt-auth-head p {
    font-size: 0.92rem;
    color: var(--lt-parchment-dim);
    margin: 0;
    line-height: 1.55;
}

/* ---------- Alerts ---------- */
.lt-alert {
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 22px;
    font-size: 0.88rem;
    line-height: 1.5;
    border: 1px solid transparent;
}

.lt-alert-error {
    background: rgba(200, 52, 30, 0.12);
    border-color: rgba(200, 52, 30, 0.4);
    color: #F5B8AC;
}

.lt-alert p {
    margin: 0 0 4px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.lt-alert p:last-child { margin-bottom: 0; }

/* ---------- Form ---------- */
.lt-field {
    margin-bottom: 20px;
}

.lt-field label {
    display: block;
    font-size: 0.75rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    margin-bottom: 8px;
    font-weight: 600;
}

.lt-field input {
    width: 100%;
    padding: 12px 14px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.95rem;
    font-family: inherit;
    transition: border-color 0.15s ease, background 0.15s ease;
    box-sizing: border-box;
}

.lt-field input::placeholder {
    color: rgba(201, 190, 172, 0.45);
}

.lt-field input:focus {
    outline: none;
    border-color: var(--lt-flame);
    background: #14110D;
}

/* ---------- Button ---------- */
.lt-btn {
    display: inline-block;
    padding: 13px 22px;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    border: 1px solid transparent;
    transition: transform 0.15s ease, background 0.15s ease;
    cursor: pointer;
    font-family: inherit;
    text-align: center;
}

.lt-btn:hover { transform: translateY(-1px); }

.lt-btn-primary {
    background: var(--lt-flame);
    color: var(--lt-parchment);
    width: 100%;
}

.lt-btn-primary:hover { background: var(--lt-flame-bright); }

/* ---------- Footer link ---------- */
.lt-auth-foot {
    text-align: center;
    margin-top: 22px;
    font-size: 0.9rem;
    color: var(--lt-parchment-dim);
}

.lt-auth-foot a {
    color: var(--lt-gold);
    font-weight: 600;
    transition: color 0.15s ease;
}

.lt-auth-foot a:hover { color: var(--lt-flame-bright); }

/* ---------- Divider ---------- */
.lt-divider {
    display: flex;
    align-items: center;
    gap: 14px;
    margin: 30px 0 22px;
    color: var(--lt-parchment-dim);
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.lt-divider::before,
.lt-divider::after {
    content: "";
    flex: 1;
    height: 1px;
    background: var(--lt-line);
}

/* ---------- Demo accounts ---------- */
.lt-demo {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.lt-demo-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 11px 14px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    font-size: 0.8rem;
}

.lt-demo-role {
    font-size: 0.68rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--lt-gold);
    font-weight: 700;
    flex-shrink: 0;
}

.lt-demo-creds {
    color: var(--lt-parchment-dim);
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.76rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ---------- Responsive ---------- */
@media (max-width: 520px) {
    .lt-auth {
        padding: 48px 0;
    }
    .lt-auth-card {
        padding: 34px 24px;
    }
    .lt-auth-head h1 {
        font-size: 1.6rem;
    }
    .lt-demo-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
    .lt-demo-creds {
        white-space: normal;
        word-break: break-all;
    }
}
</style>

<main class="lt-auth">
    <div class="lt-auth-container">
        <div class="lt-auth-card">
            <!-- Brand -->
            <div class="lt-auth-brand">
                <div class="lt-auth-mark">🇿🇦</div>
                <span>LiveTeach SA</span>
            </div>
            
            <!-- Heading -->
            <div class="lt-auth-head">
                <h1>Welcome back</h1>
                <p>Sign in to access your classes and continue learning.</p>
            </div>
            
            <!-- Errors -->
            <?php if (!empty($errors)): ?>
                <div class="lt-alert lt-alert-error">
                    <?php foreach ($errors as $error): ?>
                        <p><span>⚠️</span><span><?php echo htmlspecialchars($error); ?></span></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Form -->
            <form method="post" action="">
                <div class="lt-field">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($emailValue); ?>" required placeholder="you@example.com" autocomplete="email">
                </div>
                
                <div class="lt-field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required placeholder="••••••••" autocomplete="current-password">
                </div>
                
                <button type="submit" class="lt-btn lt-btn-primary">Sign in</button>
            </form>
            
            <!-- Register link -->
            <p class="lt-auth-foot">
                Don't have an account? <a href="register.php">Register here</a>
            </p>
            
            <!-- Divider -->
            <div class="lt-divider">Demo accounts</div>
            
            <!-- Demo accounts -->
            <div class="lt-demo">
                <div class="lt-demo-row">
                    <span class="lt-demo-role">Admin</span>
                    <span class="lt-demo-creds">admin@teach.com / Admin@1234</span>
                </div>
                <div class="lt-demo-row">
                    <span class="lt-demo-role">Teacher</span>
                    <span class="lt-demo-creds">teacher@teach.com / Admin@1234</span>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>