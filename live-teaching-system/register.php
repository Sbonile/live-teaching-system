<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/database.php';

if (isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$errors = [];
$success = '';
$formData = ['fullname' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['fullname'] = trim($_POST['fullname'] ?? '');
    $formData['email'] = trim($_POST['email'] ?? '');
    $formData['phone'] = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($formData['fullname'])) {
        $errors[] = 'Full name is required.';
    }
    if (empty($formData['email']) || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email address is required.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    
    if (empty($errors)) {
        $connection = getDbConnection();
        
        // Check if email exists
        $check = $connection->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param('s', $formData['email']);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            $errors[] = 'Email already registered. Please login.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $connection->prepare("INSERT INTO users (fullname, email, phone, password, role, status) VALUES (?, ?, ?, ?, 'student', 'active')");
            $stmt->bind_param('ssss', $formData['fullname'], $formData['email'], $formData['phone'], $hashed);
            
            if ($stmt->execute()) {
                $success = "Registration successful! You can now login.";
                $formData = ['fullname' => '', 'email' => '', 'phone' => ''];
            } else {
                $errors[] = 'Registration failed. Please try again.';
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
    max-width: 500px;
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

.lt-alert-success {
    background: rgba(217, 164, 65, 0.12);
    border-color: rgba(217, 164, 65, 0.4);
    color: #EBD3A0;
}

.lt-alert p {
    margin: 0 0 4px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.lt-alert p:last-child { margin-bottom: 0; }

.lt-alert-single {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

/* ---------- Form ---------- */
.lt-field {
    margin-bottom: 18px;
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

.lt-field label .lt-req {
    color: var(--lt-flame-bright);
    margin-left: 2px;
}

.lt-field label .lt-hint {
    text-transform: none;
    letter-spacing: 0;
    font-weight: 400;
    color: rgba(201, 190, 172, 0.7);
    font-size: 0.72rem;
    margin-left: 4px;
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

/* ---------- Field row (side-by-side on desktop) ---------- */
.lt-field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
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
    margin-top: 6px;
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
    .lt-field-row {
        grid-template-columns: 1fr;
        gap: 0;
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
                <h1>Create your account</h1>
                <p>Join thousands of South African students learning online.</p>
            </div>
            
            <!-- Success -->
            <?php if ($success): ?>
                <div class="lt-alert lt-alert-success">
                    <div class="lt-alert-single">
                        <span>✓</span>
                        <span><?php echo $success; ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
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
                    <label for="fullname">Full name <span class="lt-req">*</span></label>
                    <input type="text" id="fullname" name="fullname" value="<?php echo htmlspecialchars($formData['fullname']); ?>" required placeholder="Your full name" autocomplete="name">
                </div>
                
                <div class="lt-field">
                    <label for="email">Email address <span class="lt-req">*</span></label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($formData['email']); ?>" required placeholder="you@example.com" autocomplete="email">
                </div>
                
                <div class="lt-field">
                    <label for="phone">Phone number <span class="lt-hint">optional</span></label>
                    <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($formData['phone']); ?>" placeholder="+27 12 345 6789" autocomplete="tel">
                </div>
                
                <div class="lt-field-row">
                    <div class="lt-field">
                        <label for="password">Password <span class="lt-req">*</span></label>
                        <input type="password" id="password" name="password" required placeholder="Min 6 characters" autocomplete="new-password">
                    </div>
                    
                    <div class="lt-field">
                        <label for="confirm_password">Confirm <span class="lt-req">*</span></label>
                        <input type="password" id="confirm_password" name="confirm_password" required placeholder="Repeat password" autocomplete="new-password">
                    </div>
                </div>
                
                <button type="submit" class="lt-btn lt-btn-primary">Create account</button>
            </form>
            
            <!-- Login link -->
            <p class="lt-auth-foot">
                Already have an account? <a href="login.php">Sign in</a>
            </p>
        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>