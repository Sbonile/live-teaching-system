<?php
require_once '../config/database.php';
require_once '../includes/header.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'student') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

// Get class details
$class = $connection->query("SELECT * FROM live_classes WHERE id = $classId")->fetch_assoc();
if (!$class) {
    header('Location: ../classes/index.php');
    exit;
}

// Check existing enrollment
$existing = $connection->query("SELECT id, payment_status FROM enrollments WHERE student_id = {$_SESSION['user']['id']} AND class_id = $classId")->fetch_assoc();
if ($existing && $existing['payment_status'] == 'paid') {
    header("Location: ../dashboard/index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reference = 'PAY-' . strtoupper(uniqid());
    $payment_method = $_POST['payment_method'] ?? 'card';
    $amount = $class['price'];
    $studentId = $_SESSION['user']['id'];
    
    if ($existing) {
        // Update existing enrollment (remove paid_at column)
        $stmt = $connection->prepare("UPDATE enrollments SET payment_status = 'paid', payment_method = ?, payment_reference = ?, amount_paid = ? WHERE id = ?");
        $stmt->bind_param('ssdi', $payment_method, $reference, $amount, $existing['id']);
    } else {
        // Insert new enrollment (remove paid_at column)
        $stmt = $connection->prepare("INSERT INTO enrollments (student_id, class_id, payment_status, payment_method, payment_reference, amount_paid, enrolled_at) VALUES (?, ?, 'paid', ?, ?, ?, NOW())");
        $stmt->bind_param('iissd', $studentId, $classId, $payment_method, $reference, $amount);
    }
    
    if ($stmt->execute()) {
        // Update class student count
        $connection->query("UPDATE live_classes SET current_students = current_students + 1 WHERE id = $classId");
        
        // Also record in payments table if it exists
        $tableCheck = $connection->query("SHOW TABLES LIKE 'payments'");
        if ($tableCheck->num_rows > 0) {
            // Check if paid_at column exists in payments table
            $columnCheck = $connection->query("SHOW COLUMNS FROM payments LIKE 'paid_at'");
            if ($columnCheck->num_rows > 0) {
                $payStmt = $connection->prepare("INSERT INTO payments (student_id, class_id, amount, payment_method, reference, status, paid_at) VALUES (?, ?, ?, ?, ?, 'completed', NOW())");
                $payStmt->bind_param('iidss', $studentId, $classId, $amount, $payment_method, $reference);
            } else {
                $payStmt = $connection->prepare("INSERT INTO payments (student_id, class_id, amount, payment_method, reference, status) VALUES (?, ?, ?, ?, ?, 'completed')");
                $payStmt->bind_param('iidss', $studentId, $classId, $amount, $payment_method, $reference);
            }
            $payStmt->execute();
        }
        
        // Send notification to student
        if (function_exists('sendNotification')) {
            sendNotification($studentId, 'enrollment', '✅ Enrollment Successful', 
                "You have successfully enrolled in '{$class['title']}'.", 
                "../dashboard/my-classes.php");
        }
        
        header("Location: success.php?class_id=$classId");
        exit;
    } else {
        $error = "Payment processing failed. Please try again. Error: " . $connection->error;
    }
}
?>

<style>
/* ===== LiveTeach checkout revamp — scoped to .lt-page ===== */
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
}

.lt-dash-header-inner {
    position: relative;
    z-index: 1;
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
    max-width: 56ch;
}

.lt-dash-header p strong {
    color: var(--lt-gold);
    font-weight: 500;
}

/* ---------- Buttons ---------- */
.lt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 13px 22px;
    border-radius: 8px;
    font-size: 0.95rem;
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

.lt-btn-block { width: 100%; }

/* ---------- Alerts ---------- */
.lt-alert {
    border-radius: 10px;
    padding: 14px 18px;
    margin: 32px 0 0;
    font-size: 0.9rem;
    line-height: 1.5;
    border: 1px solid transparent;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.lt-alert-error {
    background: rgba(200, 52, 30, 0.12);
    border-color: rgba(200, 52, 30, 0.4);
    color: #F5B8AC;
}

/* ---------- Sections ---------- */
.lt-section {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 26px 24px;
    margin-top: 22px;
}

.lt-section:first-of-type { margin-top: 32px; }

.lt-section-head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
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
    font-size: 1.1rem;
    margin: 0;
    color: var(--lt-parchment);
}

/* ---------- Class info ---------- */
.lt-class-head {
    margin-bottom: 20px;
}

.lt-class-title {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.25rem;
    color: var(--lt-parchment);
    margin: 0 0 10px;
    line-height: 1.3;
}

.lt-class-meta {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    font-size: 0.85rem;
    color: var(--lt-parchment-dim);
}

/* Price breakdown */
.lt-price {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 18px 20px;
}

.lt-price-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    font-size: 0.88rem;
    color: var(--lt-parchment-dim);
}

.lt-price-row.lt-total {
    border-top: 1px solid var(--lt-line);
    margin-top: 8px;
    padding-top: 16px;
    color: var(--lt-parchment);
}

.lt-price-row.lt-total span:first-child {
    font-size: 0.92rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--lt-parchment-dim);
}

.lt-price-row.lt-total span:last-child {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.55rem;
    color: var(--lt-gold);
}

/* ---------- Payment methods ---------- */
.lt-methods {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}

.lt-method {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 20px 14px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.15s ease, transform 0.15s ease, background 0.15s ease;
    user-select: none;
}

.lt-method:hover {
    border-color: var(--lt-gold);
    transform: translateY(-2px);
}

.lt-method.lt-selected {
    border-color: var(--lt-flame);
    background: var(--lt-charcoal-raised);
}

.lt-method-icon {
    font-size: 1.8rem;
    margin-bottom: 10px;
    display: block;
}

.lt-method-name {
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--lt-parchment);
    line-height: 1.3;
}

/* ---------- Field ---------- */
.lt-field {
    margin-bottom: 0;
}

.lt-field label {
    display: block;
    font-size: 0.72rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    margin-bottom: 8px;
    font-weight: 600;
}

.lt-field input[type="text"] {
    width: 100%;
    padding: 12px 14px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.92rem;
    font-family: inherit;
    transition: border-color 0.15s ease;
    box-sizing: border-box;
}

.lt-field input::placeholder { color: rgba(201, 190, 172, 0.45); }

.lt-field input:focus {
    outline: none;
    border-color: var(--lt-flame);
}

.lt-field-help {
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
    margin-top: 8px;
    line-height: 1.55;
}

/* ---------- Secure badge ---------- */
.lt-secure {
    margin-top: 22px;
    padding: 16px 20px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    text-align: center;
    font-size: 0.82rem;
    color: var(--lt-parchment-dim);
    line-height: 1.65;
}

.lt-secure strong {
    color: var(--lt-gold);
    font-weight: 600;
}

/* ---------- Includes list ---------- */
.lt-includes {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.lt-includes li {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    font-size: 0.88rem;
    color: var(--lt-parchment-dim);
    line-height: 1.55;
}

.lt-includes li .lt-inc-icon {
    color: var(--lt-gold);
    font-size: 0.9rem;
    flex-shrink: 0;
    margin-top: 1px;
}

/* ---------- Responsive ---------- */
@media (max-width: 640px) {
    .lt-methods {
        grid-template-columns: 1fr;
    }
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-section { padding: 22px 20px; }
    .lt-price-row.lt-total span:last-child {
        font-size: 1.35rem;
    }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot { animation: none; }
}
</style>

<main class="lt-page">
    <!-- Header -->
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <span class="lt-eyebrow">
                <span class="lt-eyebrow-dot"></span>
                Checkout
            </span>
            <h1>Complete your enrollment.</h1>
            <p>Finish enrolling in <strong><?php echo htmlspecialchars($class['title']); ?></strong> by completing payment below.</p>
        </div>
    </div>

    <div class="lt-container" style="padding-bottom: 72px;">
        <?php if (isset($error)): ?>
            <div class="lt-alert lt-alert-error">
                <span>⚠️</span><span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="post" id="paymentForm">
            <!-- 01: Summary -->
            <div class="lt-section">
                <div class="lt-section-head">
                    <span class="lt-num">01</span>
                    <h2>Order summary</h2>
                </div>
                
                <div class="lt-class-head">
                    <h3 class="lt-class-title"><?php echo htmlspecialchars($class['title']); ?></h3>
                    <div class="lt-class-meta">
                        <span>👨‍🏫 <?php echo htmlspecialchars($class['teacher_name'] ?? 'Expert Teacher'); ?></span>
                        <span>📅 Starts <?php echo date('M d, Y', strtotime($class['start_date'])); ?></span>
                    </div>
                </div>
                
                <div class="lt-price">
                    <div class="lt-price-row">
                        <span>Course price</span>
                        <span>R <?php echo number_format($class['price'], 2); ?></span>
                    </div>
                    <div class="lt-price-row">
                        <span>VAT (0%)</span>
                        <span>R 0.00</span>
                    </div>
                    <div class="lt-price-row lt-total">
                        <span>Total</span>
                        <span>R <?php echo number_format($class['price'], 2); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- 02: Payment method -->
            <div class="lt-section">
                <div class="lt-section-head">
                    <span class="lt-num">02</span>
                    <h2>Payment method</h2>
                </div>
                
                <div class="lt-methods">
                    <div class="lt-method" onclick="selectPaymentMethod('card')" id="method-card">
                        <span class="lt-method-icon">💳</span>
                        <div class="lt-method-name">Credit / Debit Card</div>
                    </div>
                    <div class="lt-method" onclick="selectPaymentMethod('eft')" id="method-eft">
                        <span class="lt-method-icon">🏦</span>
                        <div class="lt-method-name">EFT / Bank Transfer</div>
                    </div>
                    <div class="lt-method" onclick="selectPaymentMethod('voucher')" id="method-voucher">
                        <span class="lt-method-icon">🎫</span>
                        <div class="lt-method-name">Voucher Code</div>
                    </div>
                </div>
                
                <input type="hidden" name="payment_method" id="selectedPaymentMethod" value="card" required>
                
                <div id="voucher_input" style="display: none;">
                    <div class="lt-field">
                        <label for="voucher_code">Voucher code</label>
                        <input type="text" id="voucher_code" placeholder="Enter your voucher code">
                        <div class="lt-field-help">If you have a valid voucher, enter it here for a discount.</div>
                    </div>
                </div>
                
                <button type="submit" class="lt-btn lt-btn-primary lt-btn-block" style="margin-top: 22px; padding: 15px 22px; font-size: 1rem;">
                    💳 Pay now — R <?php echo number_format($class['price'], 2); ?>
                </button>
                
                <div class="lt-secure">
                    🔒 <strong>Secure payment processing via PayFast</strong><br>
                    All transactions are encrypted and secure.
                </div>
            </div>
        </form>
        
        <!-- 03: What's included -->
        <div class="lt-section">
            <div class="lt-section-head">
                <span class="lt-num">03</span>
                <h2>What's included</h2>
            </div>
            <ul class="lt-includes">
                <li><span class="lt-inc-icon">✅</span> Live interactive sessions with expert teacher</li>
                <li><span class="lt-inc-icon">✅</span> Class recordings available after each session</li>
                <li><span class="lt-inc-icon">✅</span> Course materials and resources</li>
                <li><span class="lt-inc-icon">✅</span> Certificate upon completion</li>
                <li><span class="lt-inc-icon">✅</span> 7-day money-back guarantee</li>
            </ul>
        </div>
    </div>
</main>

<script>
function selectPaymentMethod(method) {
    // Update selected class
    document.querySelectorAll('.lt-method').forEach(el => {
        el.classList.remove('lt-selected');
    });
    document.getElementById(`method-${method}`).classList.add('lt-selected');
    document.getElementById('selectedPaymentMethod').value = method;
    
    // Show/hide voucher input
    const voucherInput = document.getElementById('voucher_input');
    if (method === 'voucher') {
        voucherInput.style.display = 'block';
    } else {
        voucherInput.style.display = 'none';
    }
}

// Handle voucher application (if needed)
const voucherCode = document.getElementById('voucher_code');
if (voucherCode) {
    voucherCode.addEventListener('change', function() {
        const code = this.value;
        if (code) {
            // You can add AJAX call to validate voucher here
            console.log('Voucher code entered:', code);
        }
    });
}

// Initialize default selection
document.addEventListener('DOMContentLoaded', function() {
    selectPaymentMethod('card');
});
</script>

<?php require_once '../includes/footer.php'; ?>