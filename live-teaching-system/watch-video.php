<?php
session_start();
require_once '../config/database.php';
require_once '../includes/header.php';

$connection = getDbConnection();
$videoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get video details
$video = $connection->query("
    SELECT v.*, c.title as class_title, c.price, u.fullname as teacher_name
    FROM videos v
    JOIN live_classes c ON v.class_id = c.id
    JOIN users u ON c.teacher_id = u.id
    WHERE v.id = $videoId AND v.status = 'active'
")->fetch_assoc();

if (!$video) {
    header('Location: index.php');
    exit;
}

// Check if user can view
$canView = false;
$isEnrolled = false;

if ($video['is_preview']) {
    $canView = true;
} elseif (isset($_SESSION['user'])) {
    if ($_SESSION['user']['role'] == 'teacher' || $_SESSION['user']['role'] == 'admin') {
        $canView = true;
    } elseif ($_SESSION['user']['role'] == 'student') {
        $check = $connection->query("SELECT id FROM enrollments WHERE student_id = {$_SESSION['user']['id']} AND class_id = {$video['class_id']} AND payment_status = 'paid'");
        $isEnrolled = $check->num_rows > 0;
        $canView = $isEnrolled;
    }
}

// Get embed URL
$embed_url = '';
$is_local = false;

if ($video['video_type'] == 'upload' || strpos($video['video_url'], 'uploads/') === 0) {
    $embed_url = '../' . $video['video_url'];
    $is_local = true;
} elseif ($video['video_type'] == 'youtube') {
    preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $video['video_url'], $matches);
    $video_id = $matches[1] ?? '';
    $embed_url = "https://www.youtube.com/embed/{$video_id}";
} elseif ($video['video_type'] == 'vimeo') {
    preg_match('/vimeo\.com\/(\d+)/', $video['video_url'], $matches);
    $video_id = $matches[1] ?? '';
    $embed_url = "https://player.vimeo.com/video/{$video_id}";
} else {
    $embed_url = $video['video_url'];
}

// Increment view count
$connection->query("UPDATE videos SET views = views + 1 WHERE id = $videoId");
?>

<style>
.video-container {
    position: relative;
    padding-bottom: 56.25%;
    height: 0;
    overflow: hidden;
    border-radius: 16px;
    background: #000;
}
.video-container video,
.video-container iframe {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
}
</style>

<main>
    <div class="container" style="padding: 40px 20px;">
        <?php if (!$canView): ?>
            <div class="card" style="text-align: center; padding: 60px 40px;">
                <div style="font-size: 4rem;">🔒</div>
                <h2 style="color: var(--primary-red);">Enrollment Required</h2>
                <p style="margin: 20px 0;">This video is part of a paid course. Please enroll to access all class content.</p>
                <a href="class.php?id=<?php echo $video['class_id']; ?>" class="btn btn-primary">Enroll Now</a>
            </div>
        <?php else: ?>
            <div class="video-container">
                <?php if ($is_local): ?>
                    <video src="<?php echo $embed_url; ?>" controls autoplay></video>
                <?php else: ?>
                    <iframe src="<?php echo $embed_url; ?>" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                <?php endif; ?>
            </div>
            
            <div style="margin-top: 30px;">
                <h1 style="color: var(--primary-red);"><?php echo htmlspecialchars($video['title']); ?></h1>
                <p style="opacity: 0.7;">
                    📚 <?php echo htmlspecialchars($video['class_title']); ?> • 
                    👨‍🏫 <?php echo htmlspecialchars($video['teacher_name']); ?> • 
                    👁️ <?php echo $video['views']; ?> views
                </p>
                <?php if ($video['description']): ?>
                    <div style="background: var(--charcoal-light); border-radius: 16px; padding: 20px; margin-top: 20px;">
                        <h3>📝 Description</h3>
                        <p><?php echo nl2br(htmlspecialchars($video['description'])); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once '../includes/footer.php'; ?>