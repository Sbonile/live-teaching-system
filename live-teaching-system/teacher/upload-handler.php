<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'teacher') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$connection = getDbConnection();
$teacherId = $_SESSION['user']['id'];

// Create directories if they don't exist
$uploadDirs = [
    dirname(__DIR__) . '/uploads/videos/',
    dirname(__DIR__) . '/uploads/recordings/',
    dirname(__DIR__) . '/uploads/thumbnails/'
];

foreach ($uploadDirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

define('VIDEO_DIR', dirname(__DIR__) . '/uploads/videos/');
define('RECORDING_DIR', dirname(__DIR__) . '/uploads/recordings/');
define('THUMBNAIL_DIR', dirname(__DIR__) . '/uploads/thumbnails/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Debug: Log received data
    error_log("POST data: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));
    
    // Get form data
    $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $is_preview = isset($_POST['is_preview']) ? 1 : 0;
    $video_source = isset($_POST['video_source']) ? $_POST['video_source'] : 'upload';
    
    // Validate class
    if ($class_id == 0) {
        echo json_encode(['error' => 'Invalid class ID']);
        exit;
    }
    
    // Verify class belongs to teacher
    $check = $connection->query("SELECT id FROM live_classes WHERE id = $class_id AND teacher_id = $teacherId");
    if ($check->num_rows == 0) {
        echo json_encode(['error' => 'You do not have permission to upload to this class']);
        exit;
    }
    
    // Validate title
    if (empty($title)) {
        echo json_encode(['error' => 'Video title is required']);
        exit;
    }
    
    $video_url = '';
    $video_type = $video_source;
    $thumbnail = '';
    
    // Handle file upload
    if ($video_source == 'upload' || $video_source == 'recording') {
        if (!isset($_FILES['video_file']) || $_FILES['video_file']['error'] != UPLOAD_ERR_OK) {
            $uploadError = isset($_FILES['video_file']) ? $_FILES['video_file']['error'] : 'No file uploaded';
            echo json_encode(['error' => 'Please select a video file to upload. Error: ' . $uploadError]);
            exit;
        }
        
        $file = $_FILES['video_file'];
        
        // Validate file size (500MB max)
        if ($file['size'] > 500 * 1024 * 1024) {
            echo json_encode(['error' => 'Video too large. Maximum size is 500MB']);
            exit;
        }
        
        // Validate file type
        $allowed_types = ['video/mp4', 'video/webm', 'video/ogg', 'video/mpeg', 'video/quicktime'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $allowed_types)) {
            echo json_encode(['error' => 'Invalid video format. Please upload MP4, WebM, or OGG files.']);
            exit;
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'video_' . uniqid() . '_' . time() . '.' . $extension;
        
        // Choose directory based on source
        $upload_dir = ($video_source == 'recording') ? RECORDING_DIR : VIDEO_DIR;
        $filepath = $upload_dir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $video_url = ($video_source == 'recording') ? 'uploads/recordings/' . $filename : 'uploads/videos/' . $filename;
            
            // Try to generate thumbnail (optional, don't fail if it doesn't work)
            try {
                $thumbnail = generateThumbnail($filepath, $filename);
            } catch (Exception $e) {
                error_log("Thumbnail generation failed: " . $e->getMessage());
            }
        } else {
            echo json_encode(['error' => 'Failed to save uploaded file. Please check folder permissions.']);
            exit;
        }
    } 
    // Handle external URLs (YouTube, Vimeo)
    else {
        $video_url = isset($_POST['video_url']) ? trim($_POST['video_url']) : '';
        if (empty($video_url)) {
            echo json_encode(['error' => 'Video URL is required']);
            exit;
        }
        
        // Extract thumbnail for YouTube
        if ($video_source == 'youtube') {
            preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $video_url, $matches);
            $video_id = $matches[1] ?? '';
            if ($video_id) {
                $thumbnail = "https://img.youtube.com/vi/{$video_id}/mqdefault.jpg";
            }
        }
    }
    
    if (empty($video_url)) {
        echo json_encode(['error' => 'Video URL or file is required']);
        exit;
    }
    
    // Save to database
    $stmt = $connection->prepare("
        INSERT INTO videos (class_id, teacher_id, title, description, video_url, video_type, is_preview, thumbnail, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param('iissssis', $class_id, $teacherId, $title, $description, $video_url, $video_type, $is_preview, $thumbnail);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => 'Video uploaded successfully', 
            'video_id' => $stmt->insert_id,
            'video_url' => $video_url
        ]);
    } else {
        echo json_encode(['error' => 'Database error: ' . $stmt->error]);
    }
    exit;
}

function generateThumbnail($videoPath, $filename) {
    // Check if FFmpeg is available
    $ffmpeg_path = 'ffmpeg';
    $thumbnail_name = 'thumb_' . pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
    $thumbnail_path = THUMBNAIL_DIR . $thumbnail_name;
    
    // Try to use FFmpeg if installed
    $cmd = "$ffmpeg_path -i " . escapeshellarg($videoPath) . " -ss 00:00:02 -vframes 1 -vf scale=320:-1 " . escapeshellarg($thumbnail_path) . " 2>&1";
    exec($cmd, $output, $returnCode);
    
    if ($returnCode === 0 && file_exists($thumbnail_path) && filesize($thumbnail_path) > 0) {
        return 'uploads/thumbnails/' . $thumbnail_name;
    }
    
    return null;
}
?>