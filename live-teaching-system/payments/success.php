<?php
session_start();
require_once '../config/database.php';
require_once '../includes/header.php';

if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

$stmt = $connection->prepare("
    SELECT c.title, c.start_date, c.duration, u.fullname as teacher_name, e.payment_reference, e.amount_paid
    FROM live_classes c
    JOIN users u ON c.teacher_id = u.id
    JOIN enrollments e ON e.class_id = c.id AND e.student_id = ?
    WHERE c.id = ? AND e.payment_status = 'paid'
");
$stmt->bind_param('ii', $_SESSION['user']['id'], $classId);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    header('Location: ../classes/index.php');
    exit;
}
?>
<main>
    <div class="container" style="max-width:600px; padding:60px 20px; text-align:center;">
        <div class="card" style="padding:40px;">
            <div style="font-size:4rem; margin-bottom:20px;">🎉</div>
            <h1 style="color:var(--success);">Enrollment Successful!</h1>
            <p style="margin:15px 0 25px; opacity:0.8;">You are now enrolled in:</p>
            <h2 style="color:var(--primary-red); margin-bottom:15px;"><?php echo htmlspecialchars($data['title']); ?></h2>
            <p>👨‍🏫 <?php echo htmlspecialchars($data['teacher_name']); ?></p>
            <p>📅 Starts: <?php echo date('l, F j, Y \a\t g:i A', strtotime($data['start_date'])); ?></p>
            <p>⏱️ Duration: <?php echo htmlspecialchars($data['duration']); ?></p>
            <div class="alert alert-success" style="margin:25px 0; text-align:left;">
                <strong>Payment Confirmed</strong><br>
                Amount Paid: R <?php echo number_format($data['amount_paid'], 2); ?> ZAR<br>
                Reference: <?php echo htmlspecialchars($data['payment_reference']); ?>
            </div>
            <a href="../dashboard/index.php" class="btn btn-primary" style="margin-right:10px;">Go to My Dashboard</a>
            <a href="../classes/index.php" class="btn btn-outline">Browse More Classes</a>
        </div>
    </div>
</main>
<?php require_once '../includes/footer.php'; ?>
