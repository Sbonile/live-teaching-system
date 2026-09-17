<?php
session_start();
require_once '../config/database.php';
require_once '../includes/header.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'admin') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'student';
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if (empty($fullname)) $errors[] = 'Full name is required.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';
    
    // Check if email exists
    $check = $connection->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param('s', $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $errors[] = 'Email already exists.';
    }
    
    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $connection->prepare("INSERT INTO users (fullname, email, phone, password, role, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->bind_param('sssss', $fullname, $email, $phone, $hashed, $role);
        
        if ($stmt->execute()) {
            $success = "User created successfully!";
            $_POST = [];
        } else {
            $errors[] = 'Failed to create user.';
        }
    }
}
?>

<style>
/* ===== LiveTeach create-user revamp — scoped to .lt-page ===== */
.lt-page {
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
}

.lt-page .lt-serif,
.lt-page h1, .lt-page h2, .lt-page h3 {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-weight: 500;
}

.lt-page a { text-decoration: none; color: inherit; }

.lt-container {
    max-width: 720px;
    margin: 0 auto;
    padding: 0 24px;
}

/* ---------- Header ---------- */
.lt-dash-header {
    padding: 56px 0 44px;
    border-bottom: 1px solid var(--lt-line);
    position: relative;
    overflow: hidden;
}

.lt-dash-header::before {
    content: "";
    position: absolute;
    top: -220px;
    right: -180px;
    width: 560px;
    height: 560px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(200, 52, 30, 0.28), transparent 70%);
    pointer-events: none;
    animation: lt-glow-float 12s ease-in-out infinite;
}

@keyframes lt-glow-float {
    0%, 100% { transform: translate(0, 0) scale(1); opacity: 1; }
    50% { transform: translate(-24px, 18px) scale(1.08); opacity: 0.88; }
}

.lt-dash-header-inner {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 32px;
    flex-wrap: wrap;
}

.lt-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    font-size: 0.78rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    border: 1px solid var(--lt-line);
    padding: 6px 13px;
    border-radius: 999px;
    margin-bottom: 20px;
    font-weight: 600;
    opacity: 0;
    transform: translateY(-12px);
    animation: lt-fade-in-down 0.7s cubic-bezier(0.22, 1, 0.36, 1) 0.1s forwards;
}

@keyframes lt-fade-in-down {
    to { opacity: 1; transform: translateY(0); }
}

.lt-eyebrow-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--lt-flame-bright);
    animation: lt-pulse 1.8s ease-in-out infinite;
}

@keyframes lt-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(228, 78, 46, 0.55); }
    50% { box-shadow: 0 0 0 6px rgba(228, 78, 46, 0); }
}

.lt-dash-header h1 {
    font-size: clamp(1.9rem, 3.4vw, 2.4rem);
    line-height: 1.15;
    margin: 0 0 10px;
    color: var(--lt-parchment);
    opacity: 0;
    transform: translateY(20px);
    animation: lt-hero-title 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.25s forwards;
}

@keyframes lt-hero-title {
    to { opacity: 1; transform: translateY(0); }
}

.lt-dash-header p {
    color: var(--lt-parchment-dim);
    font-size: 0.95rem;
    margin: 0;
    line-height: 1.6;
    max-width: 56ch;
    opacity: 0;
    transform: translateY(16px);
    animation: lt-hero-copy 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.45s forwards;
}

@keyframes lt-hero-copy {
    to { opacity: 1; transform: translateY(0); }
}

.lt-dash-header p strong {
    color: var(--lt-gold);
    font-weight: 500;
}

.lt-header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    opacity: 0;
    transform: translateY(16px);
    animation: lt-hero-btn 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.65s forwards;
}

@keyframes lt-hero-btn {
    to { opacity: 1; transform: translateY(0); }
}

/* ---------- Buttons ---------- */
.lt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 12px 22px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    border: 1px solid transparent;
    transition: transform 0.25s cubic-bezier(0.22, 1, 0.36, 1),
                background 0.2s ease,
                border-color 0.2s ease,
                color 0.2s ease,
                box-shadow 0.25s ease;
    cursor: pointer;
    font-family: inherit;
    text-align: center;
    white-space: nowrap;
    position: relative;
    overflow: hidden;
}

.lt-btn:hover { transform: translateY(-2px); }

.lt-btn-primary {
    background: var(--lt-flame);
    color: var(--lt-parchment);
}
.lt-btn-primary:hover {
    background: var(--lt-flame-bright);
    box-shadow: 0 10px 30px -10px rgba(228, 78, 46, 0.55);
}

.lt-btn-outline {
    background: transparent;
    border-color: var(--lt-line);
    color: var(--lt-parchment-dim);
}
.lt-btn-outline:hover {
    border-color: var(--lt-gold);
    color: var(--lt-gold);
    box-shadow: 0 10px 30px -12px rgba(217, 164, 65, 0.4);
}

.lt-btn-block { width: 100%; }

/* ---------- Alerts ---------- */
.lt-alert {
    border-radius: 10px;
    padding: 14px 18px;
    margin: 32px 0 0;
    font-size: 0.9rem;
    line-height: 1.55;
    border: 1px solid transparent;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.lt-alert-success {
    background: rgba(217, 164, 65, 0.12);
    border-color: rgba(217, 164, 65, 0.4);
    color: #EBD3A0;
}

.lt-alert-error {
    background: rgba(200, 52, 30, 0.12);
    border-color: rgba(200, 52, 30, 0.4);
    color: #F5B8AC;
    flex-direction: column;
    gap: 6px;
}

.lt-alert-error p {
    margin: 0;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    width: 100%;
}

/* ---------- Form sections ---------- */
.lt-form {
    padding: 40px 0 72px;
}

.lt-section {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 28px 26px;
    margin-bottom: 22px;
}

.lt-section-head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 22px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--lt-line);
}

.lt-section-head .lt-num {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.72rem;
    color: var(--lt-gold);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 600;
}

.lt-section-head h2 {
    font-size: 1.15rem;
    margin: 0;
    color: var(--lt-parchment);
}

/* ---------- Fields ---------- */
.lt-field {
    margin-bottom: 20px;
}

.lt-field:last-child { margin-bottom: 0; }

.lt-field label {
    display: block;
    font-size: 0.72rem;
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
    color: rgba(201, 190, 172, 0.65);
    font-size: 0.72rem;
    margin-left: 6px;
}

.lt-field input[type="text"],
.lt-field input[type="email"],
.lt-field input[type="tel"],
.lt-field input[type="password"],
.lt-field select {
    width: 100%;
    padding: 12px 14px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.92rem;
    font-family: inherit;
    transition: border-color 0.15s ease, background 0.15s ease;
    box-sizing: border-box;
}

.lt-field input::placeholder { color: rgba(201, 190, 172, 0.45); }

.lt-field input:focus,
.lt-field select:focus {
    outline: none;
    border-color: var(--lt-flame);
    background: #14110D;
}

.lt-field select {
    appearance: none;
    background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2212%22%20height%3D%228%22%20viewBox%3D%220%200%2012%208%22%3E%3Cpath%20fill%3D%22%23C9BEAC%22%20d%3D%22M6%208L0%200h12z%22%2F%3E%3C%2Fsvg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 38px;
    cursor: pointer;
}

.lt-field-help {
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
    margin-top: 8px;
    line-height: 1.55;
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.lt-field-help .lt-help-icon {
    flex-shrink: 0;
    opacity: 0.7;
}

/* Paired fields */
.lt-field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

/* Role option cards */
.lt-role-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.lt-role-option {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 18px 14px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s ease, transform 0.2s ease, background 0.2s ease;
    user-select: none;
    position: relative;
}

.lt-role-option:hover {
    border-color: var(--lt-gold);
    transform: translateY(-2px);
}

.lt-role-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.lt-role-option .lt-role-icon {
    font-size: 1.6rem;
    display: block;
    margin-bottom: 8px;
}

.lt-role-option .lt-role-name {
    font-size: 0.86rem;
    font-weight: 600;
    color: var(--lt-parchment);
    margin-bottom: 3px;
}

.lt-role-option .lt-role-desc {
    font-size: 0.72rem;
    color: var(--lt-parchment-dim);
    line-height: 1.4;
}

.lt-role-option.lt-selected {
    border-color: var(--lt-flame);
    background: var(--lt-charcoal-raised);
    box-shadow: 0 12px 24px -16px rgba(200, 52, 30, 0.6);
}

.lt-role-option.lt-selected .lt-role-name { color: var(--lt-gold); }

/* Form actions */
.lt-form-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.lt-form-actions .lt-btn {
    flex: 1;
    min-width: 180px;
}

/* ---------- Reveal utilities ---------- */
.lt-reveal {
    opacity: 0;
    transform: translateY(20px);
    transition:
        opacity 0.7s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
    will-change: opacity, transform;
}
.lt-reveal.lt-visible { opacity: 1; transform: translateY(0); }

.lt-delay-1 { transition-delay: 0.08s; }
.lt-delay-2 { transition-delay: 0.16s; }
.lt-delay-3 { transition-delay: 0.24s; }

/* ---------- Responsive ---------- */
@media (max-width: 700px) {
    .lt-field-row {
        grid-template-columns: 1fr;
        gap: 0;
    }
    .lt-field-row .lt-field {
        margin-bottom: 20px;
    }
    .lt-role-grid {
        grid-template-columns: 1fr;
    }
    .lt-dash-header-inner {
        flex-direction: column;
        align-items: flex-start;
    }
    .lt-form-actions .lt-btn {
        flex: 1 1 100%;
    }
}

@media (max-width: 480px) {
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-section { padding: 22px 20px; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot,
    .lt-dash-header::before { animation: none; }

    .lt-eyebrow,
    .lt-dash-header h1,
    .lt-dash-header p,
    .lt-header-actions {
        opacity: 1;
        transform: none;
        animation: none;
    }

    .lt-reveal { opacity: 1; transform: none; transition: none; }
    .lt-btn:hover,
    .lt-role-option:hover { transform: none; }
}
</style>

<main class="lt-page">
    <!-- Header -->
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    New account
                </span>
                <h1>Create a user.</h1>
                <p>Add a new student, teacher, or admin to the platform.</p>
            </div>
            <div class="lt-header-actions">
                <a href="users.php" class="lt-btn lt-btn-outline">← Back to users</a>
            </div>
        </div>
    </div>

    <div class="lt-container">
        <div class="lt-form">
            <!-- Alerts -->
            <?php if ($success): ?>
                <div class="lt-alert lt-alert-success">
                    <span>✓</span><span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="lt-alert lt-alert-error">
                    <?php foreach ($errors as $e): ?>
                        <p><span>⚠️</span><span><?php echo htmlspecialchars($e); ?></span></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <!-- 01: Identity -->
                <div class="lt-section lt-reveal lt-delay-1">
                    <div class="lt-section-head">
                        <span class="lt-num">01</span>
                        <h2>Identity</h2>
                    </div>
                    
                    <div class="lt-field">
                        <label for="fullname">Full name <span class="lt-req">*</span></label>
                        <input type="text" id="fullname" name="fullname" value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>" required placeholder="e.g., Thandiwe Mokoena">
                    </div>
                    
                    <div class="lt-field-row">
                        <div class="lt-field">
                            <label for="email">Email <span class="lt-req">*</span></label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required placeholder="name@example.com">
                        </div>
                        <div class="lt-field">
                            <label for="phone">Phone <span class="lt-hint">optional</span></label>
                            <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" placeholder="+27 12 345 6789">
                        </div>
                    </div>
                </div>
                
                <!-- 02: Role -->
                <div class="lt-section lt-reveal lt-delay-2">
                    <div class="lt-section-head">
                        <span class="lt-num">02</span>
                        <h2>Role</h2>
                    </div>
                    
                    <div class="lt-role-grid">
                        <label class="lt-role-option <?php echo (($_POST['role'] ?? 'student') === 'student') ? 'lt-selected' : ''; ?>" data-role="student">
                            <input type="radio" name="role" value="student" <?php echo (($_POST['role'] ?? 'student') === 'student') ? 'checked' : ''; ?>>
                            <span class="lt-role-icon">🎓</span>
                            <div class="lt-role-name">Student</div>
                            <div class="lt-role-desc">Enrolls in classes</div>
                        </label>
                        <label class="lt-role-option <?php echo (($_POST['role'] ?? '') === 'teacher') ? 'lt-selected' : ''; ?>" data-role="teacher">
                            <input type="radio" name="role" value="teacher" <?php echo (($_POST['role'] ?? '') === 'teacher') ? 'checked' : ''; ?>>
                            <span class="lt-role-icon">👨‍🏫</span>
                            <div class="lt-role-name">Teacher</div>
                            <div class="lt-role-desc">Publishes classes</div>
                        </label>
                        <label class="lt-role-option <?php echo (($_POST['role'] ?? '') === 'admin') ? 'lt-selected' : ''; ?>" data-role="admin">
                            <input type="radio" name="role" value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'checked' : ''; ?>>
                            <span class="lt-role-icon">👑</span>
                            <div class="lt-role-name">Admin</div>
                            <div class="lt-role-desc">Full platform access</div>
                        </label>
                    </div>
                </div>
                
                <!-- 03: Credentials -->
                <div class="lt-section lt-reveal lt-delay-3">
                    <div class="lt-section-head">
                        <span class="lt-num">03</span>
                        <h2>Credentials</h2>
                    </div>
                    
                    <div class="lt-field-row">
                        <div class="lt-field">
                            <label for="password">Password <span class="lt-req">*</span></label>
                            <input type="password" id="password" name="password" required placeholder="Min 6 characters" autocomplete="new-password">
                        </div>
                        <div class="lt-field">
                            <label for="confirm_password">Confirm password <span class="lt-req">*</span></label>
                            <input type="password" id="confirm_password" name="confirm_password" required placeholder="Repeat password" autocomplete="new-password">
                        </div>
                    </div>
                    
                    <div class="lt-field-help" style="margin-top: 14px;">
                        <span class="lt-help-icon">💡</span>
                        <span>The user will be created with an active status and can sign in immediately using this email and password.</span>
                    </div>
                </div>
                
                <!-- Actions -->
                <div class="lt-form-actions">
                    <button type="submit" class="lt-btn lt-btn-primary">Create user →</button>
                    <a href="users.php" class="lt-btn lt-btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
/* Reveal animation controller */
(function () {
    'use strict';

    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReduced) {
        document.querySelectorAll('.lt-reveal').forEach(el => el.classList.add('lt-visible'));
        return;
    }

    const revealTargets = document.querySelectorAll('.lt-reveal');
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('lt-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
        revealTargets.forEach(el => observer.observe(el));
    } else {
        revealTargets.forEach(el => el.classList.add('lt-visible'));
    }

    /* Role option highlight on change */
    document.querySelectorAll('.lt-role-option').forEach(function (option) {
        option.addEventListener('click', function () {
            document.querySelectorAll('.lt-role-option').forEach(o => o.classList.remove('lt-selected'));
            this.classList.add('lt-selected');
        });
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>