<?php
session_start();
require_once '../config/database.php';
require_once '../includes/header.php';

$connection = getDbConnection();
$streamId = isset($_GET['stream_id']) ? (int)$_GET['stream_id'] : 0;

// Get stream details
$stream = $connection->query("
    SELECT ls.*, c.title as class_title, c.price, u.fullname as teacher_name
    FROM live_streams ls
    JOIN live_classes c ON ls.class_id = c.id
    JOIN users u ON c.teacher_id = u.id
    WHERE ls.id = $streamId
")->fetch_assoc();

if (!$stream) {
    header('Location: ../classes/index.php');
    exit;
}

$canJoin = false;
$isEnrolled = false;

// Check if user can join
if (isset($_SESSION['user'])) {
    if ($_SESSION['user']['role'] == 'teacher' && $stream['teacher_id'] == $_SESSION['user']['id']) {
        $canJoin = true;
    } elseif ($_SESSION['user']['role'] == 'admin') {
        $canJoin = true;
    } elseif ($_SESSION['user']['role'] == 'student') {
        $check = $connection->query("SELECT id FROM enrollments WHERE student_id = {$_SESSION['user']['id']} AND class_id = {$stream['class_id']} AND payment_status = 'paid'");
        $isEnrolled = $check->num_rows > 0;
        $canJoin = $isEnrolled;
    }
}

// Update status to live if it's time
if ($stream['status'] == 'scheduled' && strtotime($stream['scheduled_time']) <= time()) {
    $connection->query("UPDATE live_streams SET status = 'live', actual_start_time = NOW() WHERE id = $streamId");
    $stream['status'] = 'live';
}
?>

<main>
    <div class="container" style="padding: 40px 20px;">
        <div style="margin-bottom: 20px;">
            <a href="<?php echo $canJoin ? 'javascript:history.back()' : '../classes/class.php?id=' . $stream['class_id']; ?>" class="btn btn-outline">← Back</a>
        </div>
        
        <?php if (!$canJoin): ?>
            <div class="card" style="text-align: center; padding: 60px 40px;">
                <div style="font-size: 4rem; margin-bottom: 20px;">🔒</div>
                <h2 style="color: var(--primary-red);">Enrollment Required</h2>
                <p style="margin: 20px 0;">Please enroll in this class to join the live stream.</p>
                <a href="../classes/class.php?id=<?php echo $stream['class_id']; ?>" class="btn btn-primary">Enroll Now</a>
            </div>
        <?php else: ?>
            <div style="text-align: center; margin-bottom: 20px;">
                <?php if ($stream['status'] == 'live'): ?>
                    <span class="badge" style="background: var(--success); animation: pulse 1s infinite;">🔴 LIVE NOW</span>
                <?php else: ?>
                    <span class="badge badge-upcoming">⏰ Starts: <?php echo date('M d, Y H:i', strtotime($stream['scheduled_time'])); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="card" style="padding: 20px;">
                <h1 style="color: var(--primary-red);"><?php echo htmlspecialchars($stream['class_title']); ?></h1>
                <p>👨‍🏫 <?php echo htmlspecialchars($stream['teacher_name']); ?></p>
                
                <?php if ($stream['status'] == 'live'): ?>
                    <div class="video-container" style="margin-top: 20px;">
                        <?php echo $stream['embed_code']; ?>
                    </div>
                    
                    <div class="meeting-box" style="margin-top: 20px;">
                        <p style="margin-bottom: 10px;">Join the live session using the button below:</p>
                        <a href="<?php echo htmlspecialchars($stream['stream_url']); ?>" target="_blank" class="btn btn-success" style="font-size: 1.1rem;">
                            🎥 Join Live Stream →
                        </a>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 60px 20px;">
                        <div style="font-size: 3rem; margin-bottom: 20px;">⏰</div>
                        <h3>Stream starts on <?php echo date('l, F j, Y \a\t g:i A', strtotime($stream['scheduled_time'])); ?></h3>
                        <p style="margin-top: 20px;">Check back at the scheduled time to join the live class.</p>
                        <div class="progress-bar" style="margin-top: 30px; width: 100%;">
                            <div class="progress-fill" style="width: 0%;"></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<style>
@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.6; }
    100% { opacity: 1; }
}
.video-container {
    position: relative;
    padding-bottom: 56.25%;
    height: 0;
    overflow: hidden;
    border-radius: 12px;
}
.video-container iframe {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
}
</style>

<?php require_once '../includes/footer.php'; ?>