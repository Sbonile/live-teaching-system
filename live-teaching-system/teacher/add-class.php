<?php
require_once '../config/database.php';
require_once '../includes/header.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'teacher') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $short_description = trim($_POST['short_description'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $duration = trim($_POST['duration'] ?? '');
    $level = $_POST['level'] ?? 'beginner';
    $category = trim($_POST['category'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $max_students = (int)($_POST['max_students'] ?? 50);
    $meeting_link = trim($_POST['meeting_link'] ?? '');
    
    if (empty($title)) $errors[] = 'Class title is required.';
    if (empty($description)) $errors[] = 'Class description is required.';
    if (empty($start_date)) $errors[] = 'Start date is required.';
    
    if (empty($errors)) {
        $stmt = $connection->prepare("
            INSERT INTO live_classes (teacher_id, title, short_description, description, price, duration, level, category, start_date, max_students, meeting_link, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'upcoming')
        ");
        $stmt->bind_param('isssdssssis', $_SESSION['user']['id'], $title, $short_description, $description, $price, $duration, $level, $category, $start_date, $max_students, $meeting_link);
        
        if ($stmt->execute()) {
            $success = "Class created successfully!";
        } else {
            $errors[] = "Failed to create class.";
        }
    }
}
?>

<style>
/* ===== LiveTeach add-class revamp — scoped to .lt-page ===== */
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
}

.lt-page .lt-serif,
.lt-page h1, .lt-page h2, .lt-page h3, .lt-page h4 {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-weight: 500;
}

.lt-page a { text-decoration: none; color: inherit; }

.lt-container {
    max-width: 900px;
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
}

.lt-dash-header p {
    color: var(--lt-parchment-dim);
    font-size: 0.95rem;
    margin: 0;
    line-height: 1.6;
    max-width: 52ch;
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
    transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    cursor: pointer;
    font-family: inherit;
    text-align: center;
    white-space: nowrap;
}

.lt-btn:hover { transform: translateY(-1px); }

.lt-btn-primary {
    background: var(--lt-flame);
    color: var(--lt-parchment);
}
.lt-btn-primary:hover { background: var(--lt-flame-bright); }

.lt-btn-outline {
    background: transparent;
    border-color: var(--lt-line);
    color: var(--lt-parchment-dim);
}
.lt-btn-outline:hover {
    border-color: var(--lt-gold);
    color: var(--lt-gold);
}

/* ---------- Alerts ---------- */
.lt-alert {
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 20px;
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
}

.lt-alert p {
    margin: 0 0 4px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.lt-alert p:last-child { margin-bottom: 0; }

.lt-alert a {
    color: var(--lt-gold);
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 3px;
}

.lt-alert a:hover { color: var(--lt-flame-bright); }

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
    margin-bottom: 24px;
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

.lt-section-desc {
    font-size: 0.85rem;
    color: var(--lt-parchment-dim);
    margin: -10px 0 22px;
    line-height: 1.6;
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
.lt-field input[type="number"],
.lt-field input[type="url"],
.lt-field input[type="datetime-local"],
.lt-field select,
.lt-field textarea {
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

.lt-field input::placeholder,
.lt-field textarea::placeholder {
    color: rgba(201, 190, 172, 0.45);
}

.lt-field input:focus,
.lt-field select:focus,
.lt-field textarea:focus {
    outline: none;
    border-color: var(--lt-flame);
    background: #14110D;
}

.lt-field textarea {
    resize: vertical;
    min-height: 80px;
    line-height: 1.55;
}

.lt-field select {
    appearance: none;
    background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2212%22%20height%3D%228%22%20viewBox%3D%220%200%2012%208%22%3E%3Cpath%20fill%3D%22%23C9BEAC%22%20d%3D%22M6%208L0%200h12z%22%2F%3E%3C%2Fsvg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 38px;
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

/* Row layout for paired fields */
.lt-field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

/* Price input with currency prefix */
.lt-price-wrap {
    position: relative;
}

.lt-price-prefix {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 0.95rem;
    color: var(--lt-gold);
    pointer-events: none;
}

.lt-price-wrap input {
    padding-left: 38px !important;
}

/* ---------- Form actions ---------- */
.lt-form-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 24px;
}

.lt-form-actions .lt-btn {
    flex: 1;
    min-width: 180px;
}

/* ---------- Tips card ---------- */
.lt-tips {
    background: linear-gradient(135deg, var(--lt-flame-dark), var(--lt-flame));
    border: none;
    border-radius: 12px;
    padding: 24px 26px;
    margin-bottom: 22px;
}

.lt-tips h3 {
    color: var(--lt-parchment);
    font-size: 1rem;
    margin: 0 0 14px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.lt-tips ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.lt-tips li {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    font-size: 0.86rem;
    color: rgba(241, 231, 214, 0.95);
    line-height: 1.55;
}

.lt-tips li .lt-tip-icon {
    flex-shrink: 0;
    font-size: 0.95rem;
    margin-top: 1px;
}

/* ---------- Responsive ---------- */
@media (max-width: 700px) {
    .lt-field-row {
        grid-template-columns: 1fr;
        gap: 0;
    }
    .lt-field-row .lt-field {
        margin-bottom: 20px;
    }
    .lt-form-actions .lt-btn {
        flex: 1 1 100%;
    }
}

@media (max-width: 560px) {
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-section { padding: 22px 20px; }
    .lt-tips { padding: 20px 20px; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot { animation: none; }
}
</style>

<main class="lt-page">
    <!-- Header -->
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    New class
                </span>
                <h1>Create a live class.</h1>
                <p>Publish a new session for students to discover and enroll in. You can update details and status any time from your dashboard.</p>
            </div>
            <a href="dashboard.php" class="lt-btn lt-btn-outline">← Dashboard</a>
        </div>
    </div>

    <div class="lt-container">
        <div class="lt-form">
            <!-- Alerts -->
            <?php if ($success): ?>
                <div class="lt-alert lt-alert-success">
                    <span>✓</span>
                    <span><?php echo $success; ?> <a href="dashboard.php">Go to dashboard →</a></span>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="lt-alert lt-alert-error">
                    <div>
                        <?php foreach ($errors as $error): ?>
                            <p><span>⚠️</span><span><?php echo htmlspecialchars($error); ?></span></p>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <!-- 01: Basics -->
                <div class="lt-section">
                    <div class="lt-section-head">
                        <span class="lt-num">01</span>
                        <h2>The basics</h2>
                    </div>
                    
                    <div class="lt-field">
                        <label for="title">Class title <span class="lt-req">*</span></label>
                        <input type="text" id="title" name="title" required placeholder="e.g. Introduction to Calculus" value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                    </div>
                    
                    <div class="lt-field">
                        <label for="short_description">Short description <span class="lt-hint">optional</span></label>
                        <textarea id="short_description" name="short_description" rows="2" placeholder="Brief summary shown in class cards"><?php echo isset($_POST['short_description']) ? htmlspecialchars($_POST['short_description']) : ''; ?></textarea>
                    </div>
                    
                    <div class="lt-field">
                        <label for="description">Full description <span class="lt-req">*</span></label>
                        <textarea id="description" name="description" rows="5" required placeholder="What will students learn? What makes this class valuable?"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                        <div class="lt-field-help">
                            <span class="lt-help-icon">💡</span>
                            <span>A good description outlines the outcomes, format, and any prerequisites.</span>
                        </div>
                    </div>
                </div>
                
                <!-- 02: Details -->
                <div class="lt-section">
                    <div class="lt-section-head">
                        <span class="lt-num">02</span>
                        <h2>Details &amp; pricing</h2>
                    </div>
                    
                    <div class="lt-field-row">
                        <div class="lt-field">
                            <label for="price">Price (ZAR) <span class="lt-req">*</span></label>
                            <div class="lt-price-wrap">
                                <span class="lt-price-prefix">R</span>
                                <input type="number" id="price" name="price" step="0.01" min="0" value="<?php echo isset($_POST['price']) ? htmlspecialchars($_POST['price']) : '0'; ?>" required>
                            </div>
                            <div class="lt-field-help">
                                <span class="lt-help-icon">🎁</span>
                                <span>Set to 0 for a free class.</span>
                            </div>
                        </div>
                        
                        <div class="lt-field">
                            <label for="duration">Duration <span class="lt-hint">optional</span></label>
                            <input type="text" id="duration" name="duration" placeholder="6 weeks" value="<?php echo isset($_POST['duration']) ? htmlspecialchars($_POST['duration']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="lt-field-row">
                        <div class="lt-field">
                            <label for="level">Level</label>
                            <select id="level" name="level">
                                <option value="beginner" <?php echo (isset($_POST['level']) && $_POST['level'] == 'beginner') ? 'selected' : ''; ?>>🌱 Beginner</option>
                                <option value="intermediate" <?php echo (isset($_POST['level']) && $_POST['level'] == 'intermediate') ? 'selected' : ''; ?>>📘 Intermediate</option>
                                <option value="advanced" <?php echo (isset($_POST['level']) && $_POST['level'] == 'advanced') ? 'selected' : ''; ?>>🎓 Advanced</option>
                            </select>
                        </div>
                        
                        <div class="lt-field">
                            <label for="category">Category <span class="lt-hint">optional</span></label>
                            <input type="text" id="category" name="category" placeholder="Mathematics, Science..." value="<?php echo isset($_POST['category']) ? htmlspecialchars($_POST['category']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="lt-field">
                        <label for="max_students">Max students</label>
                        <input type="number" id="max_students" name="max_students" min="1" value="<?php echo isset($_POST['max_students']) ? htmlspecialchars($_POST['max_students']) : '50'; ?>">
                    </div>
                </div>
                
                <!-- 03: Schedule -->
                <div class="lt-section">
                    <div class="lt-section-head">
                        <span class="lt-num">03</span>
                        <h2>Schedule</h2>
                    </div>
                    
                    <div class="lt-field">
                        <label for="start_date">Start date &amp; time <span class="lt-req">*</span></label>
                        <input type="datetime-local" id="start_date" name="start_date" required value="<?php echo isset($_POST['start_date']) ? htmlspecialchars($_POST['start_date']) : ''; ?>">
                        <div class="lt-field-help">
                            <span class="lt-help-icon">🕒</span>
                            <span>Choose a time that works for your students — evenings and weekends tend to fill fastest.</span>
                        </div>
                    </div>
                    
                    <div class="lt-field">
                        <label for="meeting_link">Meeting link <span class="lt-hint">optional</span></label>
                        <input type="url" id="meeting_link" name="meeting_link" placeholder="https://zoom.us/j/..." value="<?php echo isset($_POST['meeting_link']) ? htmlspecialchars($_POST['meeting_link']) : ''; ?>">
                        <div class="lt-field-help">
                            <span class="lt-help-icon">🔗</span>
                            <span>Students will use this link to join the live class. You can also add one later from the class page.</span>
                        </div>
                    </div>
                </div>
                
                <!-- Tips -->
                <div class="lt-tips">
                    <h3>💡 Before you publish</h3>
                    <ul>
                        <li><span class="lt-tip-icon">📹</span><span>Add free preview videos after creating the class to attract more students.</span></li>
                        <li><span class="lt-tip-icon">🎯</span><span>Classes with clear descriptions and a defined duration enroll faster.</span></li>
                        <li><span class="lt-tip-icon">🏆</span><span>You can issue certificates and track attendance from the class page.</span></li>
                    </ul>
                </div>
                
                <!-- Actions -->
                <div class="lt-form-actions">
                    <button type="submit" class="lt-btn lt-btn-primary">Create class →</button>
                    <a href="dashboard.php" class="lt-btn lt-btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</main>

<?php require_once '../includes/footer.php'; ?>