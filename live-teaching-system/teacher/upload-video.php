<?php
session_start();
require_once '../config/database.php';
require_once '../includes/header.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'teacher') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$teacherId = $_SESSION['user']['id'];
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

// Get class info
$class = $connection->query("SELECT * FROM live_classes WHERE id = $classId AND teacher_id = $teacherId")->fetch_assoc();
if (!$class) {
    header('Location: dashboard.php');
    exit;
}

$upload_error = '';
$upload_success = '';

// Handle form submission directly (no AJAX for simplicity)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_upload'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $is_preview = isset($_POST['is_preview']) ? 1 : 0;
    $video_source = $_POST['video_source'];
    
    if (empty($title)) {
        $upload_error = "Video title is required";
    } else {
        $video_url = '';
        $video_type = $video_source;
        
        if ($video_source == 'upload') {
            if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] == UPLOAD_ERR_OK) {
                $file = $_FILES['video_file'];
                
                // Create uploads directory if not exists
                $upload_dir = dirname(__DIR__) . '/uploads/videos/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'video_' . uniqid() . '_' . time() . '.' . $extension;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    $video_url = 'uploads/videos/' . $filename;
                } else {
                    $upload_error = "Failed to upload file";
                }
            } else {
                $upload_error = "Please select a video file to upload";
            }
        } elseif ($video_source == 'youtube' || $video_source == 'vimeo') {
            $video_url = trim($_POST['video_url']);
            if (empty($video_url)) {
                $upload_error = "Please enter a video URL";
            }
        }
        
        if (empty($upload_error) && !empty($video_url)) {
            $stmt = $connection->prepare("
                INSERT INTO videos (class_id, teacher_id, title, description, video_url, video_type, is_preview) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('iissssi', $classId, $teacherId, $title, $description, $video_url, $video_type, $is_preview);
            
            if ($stmt->execute()) {
                $upload_success = "Video added successfully!";
                // Clear form
                $_POST = array();
            } else {
                $upload_error = "Database error: " . $connection->error;
            }
        }
    }
}
?>

<style>
/* ===== LiveTeach upload-video revamp — scoped to .lt-page ===== */
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
    max-width: 820px;
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
    max-width: 56ch;
}

.lt-dash-header p strong {
    color: var(--lt-gold);
    font-weight: 500;
}

.lt-header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
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

.lt-btn-block { width: 100%; }

/* ---------- Alerts ---------- */
.lt-alert {
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 22px;
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

.lt-section-intro {
    font-size: 0.88rem;
    color: var(--lt-parchment-dim);
    margin: -8px 0 20px;
    line-height: 1.6;
}

.lt-section-intro strong {
    color: var(--lt-gold);
    font-weight: 500;
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
.lt-field input[type="url"],
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

/* ---------- Upload area ---------- */
.lt-upload-area {
    border: 1.5px dashed var(--lt-line);
    border-radius: 12px;
    padding: 44px 24px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s ease, background 0.2s ease;
    margin-bottom: 20px;
    background: var(--lt-ink);
}

.lt-upload-area:hover {
    border-color: var(--lt-flame);
    background: rgba(200, 52, 30, 0.05);
}

.lt-upload-icon {
    font-size: 2.6rem;
    margin-bottom: 14px;
    display: block;
}

.lt-upload-primary {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.05rem;
    color: var(--lt-parchment);
    margin: 0 0 8px;
}

.lt-upload-hint {
    font-size: 0.8rem;
    color: var(--lt-parchment-dim);
    margin: 0;
}

/* ---------- Preview ---------- */
.lt-preview {
    display: none;
    margin-top: 20px;
    padding: 18px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
}

.lt-preview.lt-open { display: block; }

.lt-preview h4 {
    font-size: 0.78rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-gold);
    margin: 0 0 12px;
    font-weight: 700;
}

.lt-preview video {
    width: 100%;
    max-height: 380px;
    border-radius: 8px;
    background: #000;
    display: block;
}

/* ---------- Checkbox ---------- */
.lt-checkbox-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 16px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    cursor: pointer;
    transition: border-color 0.15s ease;
    user-select: none;
}

.lt-checkbox-row:hover { border-color: var(--lt-gold); }

.lt-checkbox-row input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--lt-flame);
    cursor: pointer;
    flex-shrink: 0;
    margin-top: 2px;
}

.lt-checkbox-row .lt-cb-text {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
}

.lt-checkbox-row .lt-cb-label {
    font-size: 0.88rem;
    color: var(--lt-parchment);
    font-weight: 500;
}

.lt-checkbox-row .lt-cb-hint {
    font-size: 0.75rem;
    color: var(--lt-parchment-dim);
    line-height: 1.5;
}

/* ---------- Storage info ---------- */
.lt-storage {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 18px 20px;
    margin-top: 8px;
}

.lt-storage h4 {
    font-size: 0.78rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-gold);
    margin: 0 0 14px;
    font-weight: 700;
}

.lt-storage-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--lt-line);
    font-size: 0.86rem;
}

.lt-storage-row:last-child { border-bottom: none; }

.lt-storage-row .lt-st-label {
    color: var(--lt-parchment-dim);
}

.lt-storage-row .lt-st-value {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 0.95rem;
    color: var(--lt-parchment);
}

/* ---------- Form actions ---------- */
.lt-form-actions {
    margin-top: 8px;
}

/* ---------- Responsive ---------- */
@media (max-width: 560px) {
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-section { padding: 22px 20px; }
    .lt-upload-area { padding: 32px 18px; }
    .lt-dash-header h1 { font-size: 1.6rem; }
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
                    New video
                </span>
                <h1>Upload a video.</h1>
                <p>Add lesson recordings, previews, or supplementary material to <strong><?php echo htmlspecialchars($class['title']); ?></strong>.</p>
            </div>
            <div class="lt-header-actions">
                <a href="live-stream.php?class_id=<?php echo $classId; ?>" class="lt-btn lt-btn-outline">← Back to class</a>
            </div>
        </div>
    </div>

    <div class="lt-container">
        <div class="lt-form">
            <!-- Alerts -->
            <?php if ($upload_success): ?>
                <div class="lt-alert lt-alert-success">
                    <span>✓</span><span><?php echo htmlspecialchars($upload_success); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($upload_error): ?>
                <div class="lt-alert lt-alert-error">
                    <span>⚠️</span><span><?php echo htmlspecialchars($upload_error); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="post" enctype="multipart/form-data">
                <!-- 01: Video info -->
                <div class="lt-section">
                    <div class="lt-section-head">
                        <span class="lt-num">01</span>
                        <h2>Video info</h2>
                    </div>
                    
                    <div class="lt-field">
                        <label for="title">Video title <span class="lt-req">*</span></label>
                        <input type="text" id="title" name="title" required placeholder="e.g., Lesson 1 — Introduction" value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                    </div>
                    
                    <div class="lt-field">
                        <label for="description">Description <span class="lt-hint">optional</span></label>
                        <textarea id="description" name="description" rows="3" placeholder="What will students learn from this video?"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <!-- 02: Video source -->
                <div class="lt-section">
                    <div class="lt-section-head">
                        <span class="lt-num">02</span>
                        <h2>Video source</h2>
                    </div>
                    
                    <div class="lt-field">
                        <label for="video_source">Where is the video hosted?</label>
                        <select name="video_source" id="video_source" required onchange="toggleSource()">
                            <option value="upload">📁 Upload a video file (MP4, WebM · up to 500 MB)</option>
                            <option value="youtube">▶️ YouTube link</option>
                            <option value="vimeo">🎥 Vimeo link</option>
                        </select>
                    </div>
                    
                    <!-- Upload Area -->
                    <div id="upload_div" class="lt-upload-area" onclick="document.getElementById('video_file').click()">
                        <span class="lt-upload-icon">📹</span>
                        <p class="lt-upload-primary">Click to select a video file</p>
                        <p class="lt-upload-hint">MP4, WebM, or OGG · up to 500 MB</p>
                        <input type="file" id="video_file" name="video_file" accept="video/mp4,video/webm,video/ogg" style="display: none;">
                    </div>
                    
                    <!-- URL Input Area -->
                    <div id="url_div" style="display: none;">
                        <div class="lt-field">
                            <label for="video_url">Video URL <span class="lt-req">*</span></label>
                            <input type="url" id="video_url" name="video_url" placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/...">
                        </div>
                    </div>
                    
                    <!-- Video Preview -->
                    <div id="preview_div" class="lt-preview">
                        <h4>Preview</h4>
                        <video id="video_preview" controls></video>
                    </div>
                </div>
                
                <!-- 03: Visibility -->
                <div class="lt-section">
                    <div class="lt-section-head">
                        <span class="lt-num">03</span>
                        <h2>Visibility</h2>
                    </div>
                    
                    <label class="lt-checkbox-row" for="is_preview">
                        <input type="checkbox" id="is_preview" name="is_preview" value="1" <?php echo isset($_POST['is_preview']) ? 'checked' : ''; ?>>
                        <span class="lt-cb-text">
                            <span class="lt-cb-label">⭐ Make this a free preview video</span>
                            <span class="lt-cb-hint">Free preview videos are visible to non-enrolled students on the class page, helping attract new sign-ups.</span>
                        </span>
                    </label>
                </div>
                
                <!-- Actions -->
                <div class="lt-form-actions">
                    <button type="submit" name="submit_upload" class="lt-btn lt-btn-primary lt-btn-block">Upload video →</button>
                </div>
            </form>
            
            <!-- Storage Info -->
            <div class="lt-section" style="margin-top: 22px;">
                <div class="lt-section-head">
                    <span class="lt-num">04</span>
                    <h2>Storage</h2>
                </div>
                
                <?php
                $video_dir = dirname(__DIR__) . '/uploads/videos/';
                $total_size = 0;
                if (is_dir($video_dir)) {
                    $files = glob($video_dir . '*');
                    foreach ($files as $file) {
                        if (is_file($file)) $total_size += filesize($file);
                    }
                }
                $total_mb = round($total_size / 1024 / 1024, 2);
                ?>
                <div class="lt-storage">
                    <div class="lt-storage-row">
                        <span class="lt-st-label">📁 Videos used</span>
                        <span class="lt-st-value"><?php echo $total_mb; ?> MB</span>
                    </div>
                    <div class="lt-storage-row">
                        <span class="lt-st-label">📊 Free space</span>
                        <span class="lt-st-value"><?php echo round(disk_free_space(dirname(__DIR__)) / 1024 / 1024 / 1024, 2); ?> GB</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
function toggleSource() {
    const source = document.getElementById('video_source').value;
    const uploadDiv = document.getElementById('upload_div');
    const urlDiv = document.getElementById('url_div');
    const previewDiv = document.getElementById('preview_div');
    const videoUrl = document.getElementById('video_url');
    
    if (source === 'upload') {
        uploadDiv.style.display = 'block';
        urlDiv.style.display = 'none';
        videoUrl.removeAttribute('required');
    } else {
        uploadDiv.style.display = 'none';
        urlDiv.style.display = 'block';
        videoUrl.setAttribute('required', 'required');
    }
    previewDiv.style.display = 'none';
}

// File preview
const fileInput = document.getElementById('video_file');
const previewDiv = document.getElementById('preview_div');
const videoPreview = document.getElementById('video_preview');

fileInput.addEventListener('change', function(e) {
    if (this.files && this.files[0]) {
        const file = this.files[0];
        const url = URL.createObjectURL(file);
        videoPreview.src = url;
        previewDiv.style.display = 'block';
    }
});

// Initialize on page load
toggleSource();
</script>

<?php require_once '../includes/footer.php'; ?>