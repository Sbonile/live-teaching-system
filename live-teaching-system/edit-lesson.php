<?php
session_start();
require_once '../config/database.php';
require_once '../includes/csrf.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'teacher') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$teacherId = (int)$_SESSION['user']['id'];
$lessonId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

$msg = '';
$error = '';

$stmt = $connection->prepare("
    SELECT l.*, c.title AS class_title
    FROM course_lessons l
    JOIN live_classes c ON c.id = l.class_id
    WHERE l.id = ? AND c.teacher_id = ?
");
$stmt->bind_param('ii', $lessonId, $teacherId);
$stmt->execute();
$lesson = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$lesson) {
    header('Location: classes.php');
    exit;
}

if (!$classId) {
    $classId = (int)$lesson['class_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $video_url = trim($_POST['video_url'] ?? '');
    $video_type = $_POST['video_type'] ?? 'youtube';
    $duration = trim($_POST['duration'] ?? '');
    $is_free_preview = isset($_POST['is_free_preview']) ? 1 : 0;
    $status = in_array($_POST['status'] ?? '', ['draft','published'], true) ? $_POST['status'] : 'draft';
    $order_position = (int)($_POST['order_position'] ?? $lesson['order_position']);

    if (empty($title)) {
        $error = 'Lesson title is required.';
    } else {
        if ($video_type === 'upload' && isset($_FILES['video_file']) && $_FILES['video_file']['error'] === 0) {
            $upload_dir = dirname(__DIR__) . '/uploads/videos/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $filename = 'lesson_' . uniqid() . '_' . time() . '.mp4';
            $filepath = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['video_file']['tmp_name'], $filepath)) {
                $video_url = 'uploads/videos/' . $filename;
            } else {
                $error = 'Failed to upload new video file.';
            }
        }

        if (empty($error)) {
            $upd = $connection->prepare("
                UPDATE course_lessons SET
                    title = ?,
                    description = ?,
                    video_url = ?,
                    video_type = ?,
                    duration = ?,
                    is_free_preview = ?,
                    status = ?,
                    order_position = ?
                WHERE id = ? AND teacher_id = ?
            ");
            $upd->bind_param(
                'sssssisiii',
                $title, $description, $video_url, $video_type, $duration,
                $is_free_preview, $status, $order_position,
                $lessonId, $teacherId
            );

            if ($upd->execute()) {
                $msg = 'Lesson updated successfully!' . ($status === 'published' ? ' It is now visible to students.' : '');
                $upd->close();
                $stmt2 = $connection->prepare("
                    SELECT l.*, c.title AS class_title
                    FROM course_lessons l
                    JOIN live_classes c ON c.id = l.class_id
                    WHERE l.id = ? AND c.teacher_id = ?
                ");
                $stmt2->bind_param('ii', $lessonId, $teacherId);
                $stmt2->execute();
                $lesson = $stmt2->get_result()->fetch_assoc();
                $stmt2->close();
            } else {
                $error = 'Failed to update lesson: ' . $connection->error;
            }
        }
    }
}

require_once '../includes/header.php';
?>

<style>
/* Same CSS as before — omitted here to keep the response shorter.
   Paste the full .lt-page CSS from the previous edit-lesson.php block. */
.lt-page {
    --lt-ink: #100D0A;
    --lt-charcoal: #1B1712;
    --lt-charcoal-raised: #241F18;
    --lt-flame: #C8341E;
    --lt-flame-bright: #E44E2E;
    --lt-gold: #D9A441;
    --lt-parchment: #F1E7D6;
    --lt-parchment-dim: #C9BEAC;
    --lt-line: rgba(241, 231, 214, 0.12);
    background: var(--lt-ink);
    color: var(--lt-parchment);
    font-family: 'Inter', sans-serif;
    min-height: 100vh;
}
.lt-page a { text-decoration: none; color: inherit; }
.lt-container { max-width: 900px; margin: 0 auto; padding: 0 24px; }
.lt-dash-header { padding: 56px 0 44px; border-bottom: 1px solid var(--lt-line); position: relative; overflow: hidden; }
.lt-dash-header::before {
    content: ""; position: absolute; top: -220px; right: -180px;
    width: 560px; height: 560px; border-radius: 50%;
    background: radial-gradient(circle, rgba(200, 52, 30, 0.28), transparent 70%);
    pointer-events: none;
}
.lt-dash-header-inner {
    position: relative; z-index: 1; display: flex; justify-content: space-between;
    align-items: flex-end; gap: 32px; flex-wrap: wrap;
}
.lt-eyebrow {
    display: inline-flex; align-items: center; gap: 9px;
    font-size: 0.78rem; letter-spacing: 0.05em; text-transform: uppercase;
    color: var(--lt-parchment-dim); border: 1px solid var(--lt-line);
    padding: 6px 13px; border-radius: 999px; margin-bottom: 20px; font-weight: 600;
}
.lt-eyebrow-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--lt-flame-bright); animation: lt-pulse 1.8s ease-in-out infinite;
}
@keyframes lt-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(228, 78, 46, 0.55); }
    50% { box-shadow: 0 0 0 6px rgba(228, 78, 46, 0); }
}
.lt-dash-header h1 {
    font-size: clamp(1.9rem, 3.4vw, 2.4rem); line-height: 1.15;
    margin: 0 0 10px; color: var(--lt-parchment);
    font-family: Georgia, serif; font-weight: 500;
}
.lt-dash-header p { color: var(--lt-parchment-dim); font-size: 0.95rem; margin: 0; line-height: 1.6; }
.lt-dash-header p strong { color: var(--lt-gold); font-weight: 500; }
.lt-header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.lt-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 11px 20px; border-radius: 8px; font-size: 0.88rem; font-weight: 600;
    border: 1px solid transparent; cursor: pointer; font-family: inherit;
    text-align: center; white-space: nowrap;
    transition: transform 0.15s, background 0.15s, border-color 0.15s, color 0.15s;
}
.lt-btn:hover { transform: translateY(-1px); }
.lt-btn-primary { background: var(--lt-flame); color: var(--lt-parchment); }
.lt-btn-primary:hover { background: var(--lt-flame-bright); }
.lt-btn-outline {
    background: transparent; border-color: var(--lt-line); color: var(--lt-parchment-dim);
}
.lt-btn-outline:hover { border-color: var(--lt-gold); color: var(--lt-gold); }
.lt-alert {
    border-radius: 10px; padding: 14px 18px; margin: 32px 0 0; font-size: 0.9rem;
    line-height: 1.5; border: 1px solid transparent;
    display: flex; align-items: flex-start; gap: 10px;
}
.lt-alert-success {
    background: rgba(217, 164, 65, 0.12); border-color: rgba(217, 164, 65, 0.4);
    color: #EBD3A0;
}
.lt-alert-error {
    background: rgba(200, 52, 30, 0.12); border-color: rgba(200, 52, 30, 0.4);
    color: #F5B8AC;
}
.lt-form { padding: 8px 0 72px; }
.lt-section {
    background: var(--lt-charcoal); border: 1px solid var(--lt-line);
    border-radius: 12px; padding: 28px 26px; margin-bottom: 22px;
}
.lt-section-head {
    display: flex; align-items: center; gap: 10px; margin-bottom: 22px;
    padding-bottom: 14px; border-bottom: 1px solid var(--lt-line);
}
.lt-section-head .lt-num {
    font-family: 'SFMono-Regular', monospace; font-size: 0.72rem;
    color: var(--lt-gold); letter-spacing: 0.08em; text-transform: uppercase;
    font-weight: 600;
}
.lt-section-head h2 {
    font-size: 1.15rem; margin: 0; color: var(--lt-parchment);
    font-family: Georgia, serif; font-weight: 500;
}
.lt-field { margin-bottom: 20px; }
.lt-field:last-child { margin-bottom: 0; }
.lt-field label {
    display: block; font-size: 0.72rem; letter-spacing: 0.05em;
    text-transform: uppercase; color: var(--lt-parchment-dim);
    margin-bottom: 8px; font-weight: 600;
}
.lt-field label .lt-req { color: var(--lt-flame-bright); margin-left: 2px; }
.lt-field label .lt-hint {
    text-transform: none; letter-spacing: 0; font-weight: 400;
    color: rgba(201, 190, 172, 0.65); font-size: 0.72rem; margin-left: 6px;
}
.lt-field input[type="text"],
.lt-field input[type="url"],
.lt-field input[type="number"],
.lt-field select,
.lt-field textarea {
    width: 100%; padding: 12px 14px; background: var(--lt-ink);
    border: 1px solid var(--lt-line); border-radius: 8px;
    color: var(--lt-parchment); font-size: 0.92rem; font-family: inherit;
    transition: border-color 0.15s, background 0.15s; box-sizing: border-box;
}
.lt-field input:focus, .lt-field select:focus, .lt-field textarea:focus {
    outline: none; border-color: var(--lt-flame); background: #14110D;
}
.lt-field textarea { resize: vertical; min-height: 80px; line-height: 1.55; }
.lt-field select {
    appearance: none;
    background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2212%22%20height%3D%228%22%20viewBox%3D%220%200%2012%208%22%3E%3Cpath%20fill%3D%22%23C9BEAC%22%20d%3D%22M6%208L0%200h12z%22%2F%3E%3C%2Fsvg%3E");
    background-repeat: no-repeat; background-position: right 14px center;
    padding-right: 38px;
}
.lt-field-help { font-size: 0.78rem; color: var(--lt-parchment-dim); margin-top: 8px; line-height: 1.55; }
.lt-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.lt-checkbox-row {
    display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px;
    background: var(--lt-ink); border: 1px solid var(--lt-line);
    border-radius: 8px; cursor: pointer;
    transition: border-color 0.15s; user-select: none;
}
.lt-checkbox-row:hover { border-color: var(--lt-gold); }
.lt-checkbox-row input[type="checkbox"] {
    width: 18px; height: 18px; accent-color: var(--lt-flame);
    cursor: pointer; flex-shrink: 0; margin-top: 2px;
}
.lt-checkbox-row .lt-cb-text {
    display: flex; flex-direction: column; gap: 3px; min-width: 0;
}
.lt-checkbox-row .lt-cb-label {
    font-size: 0.88rem; color: var(--lt-parchment); font-weight: 500;
}
.lt-checkbox-row .lt-cb-hint {
    font-size: 0.75rem; color: var(--lt-parchment-dim); line-height: 1.5;
}
.lt-form-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; }
.lt-form-actions .lt-btn { flex: 1; min-width: 180px; }
.lt-status-strip {
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    background: var(--lt-charcoal); border: 1px solid var(--lt-line);
    border-radius: 12px; padding: 16px 20px; margin: 32px 0 24px;
    flex-wrap: wrap;
}
.lt-badge {
    display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px;
    border-radius: 999px; font-size: 0.68rem; font-weight: 600;
    letter-spacing: 0.05em; text-transform: uppercase;
    white-space: nowrap; border: 1px solid transparent;
}
.lt-badge-published {
    background: rgba(217, 164, 65, 0.15); color: var(--lt-gold);
    border-color: rgba(217, 164, 65, 0.4);
}
.lt-badge-draft {
    background: rgba(241, 231, 214, 0.06); color: var(--lt-parchment-dim);
    border-color: var(--lt-line);
}
.lt-badge-free {
    background: rgba(217, 164, 65, 0.15); color: var(--lt-gold);
    border-color: rgba(217, 164, 65, 0.4);
}
.lt-current-video {
    background: var(--lt-ink); border: 1px solid var(--lt-line);
    border-radius: 10px; padding: 16px; margin-bottom: 18px;
}
.lt-current-video .lt-panel-label {
    font-size: 0.7rem; letter-spacing: 0.06em; text-transform: uppercase;
    color: var(--lt-gold); font-weight: 700; margin-bottom: 10px;
}
.lt-current-video iframe, .lt-current-video video {
    width: 100%; max-width: 100%; border: none; border-radius: 8px;
    background: #000; display: block;
}
.lt-current-video .lt-video-url {
    margin-top: 12px;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.78rem; color: var(--lt-parchment-dim); word-break: break-all;
}
@media (max-width: 700px) {
    .lt-field-row { grid-template-columns: 1fr; gap: 0; }
    .lt-field-row .lt-field { margin-bottom: 20px; }
    .lt-dash-header-inner { flex-direction: column; align-items: flex-start; }
    .lt-form-actions .lt-btn { flex: 1 1 100%; }
}
@media (max-width: 560px) {
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-section { padding: 22px 20px; }
    .lt-status-strip { padding: 14px 16px; }
}
@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot { animation: none; }
    .lt-btn:hover { transform: none; }
}
</style>

<main class="lt-page">
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    Edit lesson
                </span>
                <h1><?php echo htmlspecialchars($lesson['title']); ?></h1>
                <p>Editing in <strong><?php echo htmlspecialchars($lesson['class_title']); ?></strong></p>
            </div>
            <div class="lt-header-actions">
                <a href="course-content.php?class_id=<?php echo (int)$classId; ?>" class="lt-btn lt-btn-outline">← Back to course</a>
            </div>
        </div>
    </div>

    <div class="lt-container">
        <?php if ($msg): ?>
            <div class="lt-alert lt-alert-success">
                <span>✓</span><span><?php echo htmlspecialchars($msg); ?></span>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="lt-alert lt-alert-error">
                <span>⚠️</span><span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <div class="lt-status-strip">
            <div>
                <span class="lt-badge <?php echo $lesson['status'] === 'published' ? 'lt-badge-published' : 'lt-badge-draft'; ?>">
                    <?php echo ucfirst($lesson['status']); ?>
                </span>
                <?php if ($lesson['is_free_preview']): ?>
                    <span class="lt-badge lt-badge-free">⭐ Free preview</span>
                <?php endif; ?>
                <span style="font-size: 0.8rem; color: var(--lt-parchment-dim); margin-left: 8px;">
                    Position <?php echo (int)$lesson['order_position']; ?>
                </span>
            </div>
            <div style="font-size: 0.78rem; color: var(--lt-parchment-dim); text-align: right; max-width: 340px;">
                <?php if ($lesson['status'] === 'published'): ?>
                    ✅ This lesson is visible to enrolled students.
                <?php else: ?>
                    📝 This lesson is a draft — students can't see it yet.
                <?php endif; ?>
            </div>
        </div>

        <div class="lt-form">
            <form method="post" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>

                <div class="lt-section">
                    <div class="lt-section-head">
                        <span class="lt-num">01</span>
                        <h2>Lesson details</h2>
                    </div>

                    <div class="lt-field">
                        <label for="title">Lesson title <span class="lt-req">*</span></label>
                        <input type="text" id="title" name="title" required
                               value="<?php echo htmlspecialchars($lesson['title']); ?>"
                               placeholder="e.g., Introduction to Chapter 1">
                    </div>

                    <div class="lt-field-row">
                        <div class="lt-field">
                            <label for="duration">Duration <span class="lt-hint">optional</span></label>
                            <input type="text" id="duration" name="duration"
                                   value="<?php echo htmlspecialchars($lesson['duration'] ?? ''); ?>"
                                   placeholder="MM:SS">
                        </div>
                        <div class="lt-field">
                            <label for="order_position">Order position</label>
                            <input type="number" id="order_position" name="order_position" min="1"
                                   value="<?php echo (int)$lesson['order_position']; ?>">
                        </div>
                    </div>

                    <div class="lt-field">
                        <label for="description">Description <span class="lt-hint">optional</span></label>
                        <textarea id="description" name="description" rows="3"
                                  placeholder="What will students learn in this lesson?"><?php echo htmlspecialchars($lesson['description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="lt-section">
                    <div class="lt-section-head">
                        <span class="lt-num">02</span>
                        <h2>Video</h2>
                    </div>

                    <?php if ($lesson['video_url']): ?>
                        <div class="lt-current-video">
                            <div class="lt-panel-label">Current video</div>
                            <?php if ($lesson['video_type'] === 'youtube'):
                                preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $lesson['video_url'], $m);
                                $vid = $m[1] ?? '';
                            ?>
                                <iframe height="300" src="https://www.youtube.com/embed/<?php echo htmlspecialchars($vid); ?>" frameborder="0" allowfullscreen></iframe>
                            <?php elseif ($lesson['video_type'] === 'upload'): ?>
                                <video controls style="max-height: 300px;">
                                    <source src="../<?php echo htmlspecialchars($lesson['video_url']); ?>" type="video/mp4">
                                </video>
                            <?php endif; ?>
                            <div class="lt-video-url"><?php echo htmlspecialchars($lesson['video_url']); ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="lt-field">
                        <label for="video_type">Video type</label>
                        <select name="video_type" id="video_type" onchange="toggleVideoInput()">
                            <option value="youtube" <?php echo $lesson['video_type'] === 'youtube' ? 'selected' : ''; ?>>▶️ YouTube link</option>
                            <option value="upload" <?php echo $lesson['video_type'] === 'upload' ? 'selected' : ''; ?>>📁 Upload video (MP4)</option>
                            <option value="embed" <?php echo $lesson['video_type'] === 'embed' ? 'selected' : ''; ?>>🔗 Embed code</option>
                        </select>
                    </div>

                    <div id="youtube_input" class="lt-field">
                        <label for="video_url_yt">YouTube URL</label>
                        <input type="url" id="video_url_yt" name="video_url"
                               value="<?php echo $lesson['video_type'] === 'youtube' ? htmlspecialchars($lesson['video_url'] ?? '') : ''; ?>"
                               placeholder="https://www.youtube.com/watch?v=...">
                    </div>

                    <div id="upload_input" class="lt-field" style="display: none;">
                        <label for="video_file">Replace with new upload (MP4) <span class="lt-hint">optional</span></label>
                        <input type="file" id="video_file" name="video_file" accept="video/mp4">
                        <div class="lt-field-help">Leave empty to keep the current file. Choosing a new file replaces the existing video.</div>
                    </div>

                    <div id="embed_input" class="lt-field" style="display: none;">
                        <label for="video_url_embed">Embed code</label>
                        <textarea id="video_url_embed" name="video_url" rows="3"
                                  placeholder="<iframe src='...'></iframe>"><?php echo $lesson['video_type'] === 'embed' ? htmlspecialchars($lesson['video_url'] ?? '') : ''; ?></textarea>
                    </div>
                </div>

                <div class="lt-section">
                    <div class="lt-section-head">
                        <span class="lt-num">03</span>
                        <h2>Visibility</h2>
                    </div>

                    <div class="lt-field">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="draft" <?php echo $lesson['status'] === 'draft' ? 'selected' : ''; ?>>📝 Draft — hidden from students</option>
                            <option value="published" <?php echo $lesson['status'] === 'published' ? 'selected' : ''; ?>>✅ Published — visible to students</option>
                        </select>
                        <div class="lt-field-help">Only published lessons appear on the student course view. Free preview lessons appear on the public class page.</div>
                    </div>

                    <div class="lt-field">
                        <label class="lt-checkbox-row" for="is_free_preview">
                            <input type="checkbox" id="is_free_preview" name="is_free_preview" value="1" <?php echo $lesson['is_free_preview'] ? 'checked' : ''; ?>>
                            <span class="lt-cb-text">
                                <span class="lt-cb-label">⭐ Make this a free preview lesson</span>
                                <span class="lt-cb-hint">Free preview lessons are visible to non-enrolled students on the public class page.</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="lt-form-actions">
                    <button type="submit" class="lt-btn lt-btn-primary">Save changes →</button>
                    <a href="course-content.php?class_id=<?php echo (int)$classId; ?>" class="lt-btn lt-btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
function toggleVideoInput() {
    const type = document.getElementById('video_type').value;
    const youtubeDiv = document.getElementById('youtube_input');
    const uploadDiv = document.getElementById('upload_input');
    const embedDiv = document.getElementById('embed_input');

    if (youtubeDiv) youtubeDiv.style.display = type === 'youtube' ? 'block' : 'none';
    if (uploadDiv) uploadDiv.style.display = type === 'upload' ? 'block' : 'none';
    if (embedDiv) embedDiv.style.display = type === 'embed' ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    toggleVideoInput();
});
</script>

<?php require_once '../includes/footer.php'; ?>