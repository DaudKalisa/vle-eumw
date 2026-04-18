<?php
// add_content.php - Add weekly content to VLE course
require_once '../includes/auth.php';
require_once '../includes/email.php';
requireLogin();
requireRole(['lecturer']);

$conn = getDbConnection();

// Get course_id and week from URL
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$week = isset($_GET['week']) ? (int)$_GET['week'] : 0;

// Verify lecturer owns this course
$user = getCurrentUser();
$stmt = $conn->prepare("SELECT * FROM vle_courses WHERE course_id = ? AND lecturer_id = ?");
$stmt->bind_param("ii", $course_id, $user['related_lecturer_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}

$course = $result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $content_type = $_POST['content_type'] ?? 'text';
    $content_url = trim($_POST['content_url'] ?? '');
    $video_source_type = $_POST['video_source_type'] ?? '';
    $is_mandatory = isset($_POST['is_mandatory']) ? 1 : 0;
    $file_path = null;
    $file_name = null;
    
    // If URL is provided for video/audio/link types (and not uploading/recording), store it in description
    if (in_array($content_type, ['audio', 'link']) && !empty($content_url)) {
        $description = $content_url;
    }
    
    // Handle video URL
    if ($content_type === 'video' && $video_source_type === 'url' && !empty($content_url)) {
        $description = $content_url;
    }

    // Handle file upload for presentation, document, file, or video (upload/record/screen)
    $uploadTypes = ['presentation', 'document', 'file'];
    $isVideoUpload = ($content_type === 'video' && in_array($video_source_type, ['upload', 'record', 'screen']));
    
    if ((in_array($content_type, $uploadTypes) || $isVideoUpload) && isset($_FILES['content_file']) && $_FILES['content_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/';
        
        // Use videos subdirectory for video files
        if ($content_type === 'video') {
            $upload_dir = '../uploads/videos/';
        }
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_name = basename($_FILES['content_file']['name']);
        $file_path = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file_name);
        
        // For video content, prepend 'videos/' to path for proper retrieval
        if ($content_type === 'video') {
            $target_path = $upload_dir . $file_path;
            $file_path = 'videos/' . $file_path;
        } else {
            $target_path = $upload_dir . $file_path;
        }

        if (move_uploaded_file($_FILES['content_file']['tmp_name'], $target_path)) {
            // File uploaded successfully
        } else {
            $error = "Failed to upload file.";
        }
    }

    if (empty($error) && !empty($title)) {
        $stmt = $conn->prepare("INSERT INTO vle_weekly_content (course_id, week_number, title, description, content_type, file_path, file_name, is_mandatory) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissssii", $course_id, $week, $title, $description, $content_type, $file_path, $file_name, $is_mandatory);

        if ($stmt->execute()) {
            // Send notification to enrolled students
            if (isEmailEnabled()) {
                // Get enrolled students
                $students_stmt = $conn->prepare("
                    SELECT s.full_name, s.email 
                    FROM students s 
                    INNER JOIN vle_enrollments ve ON s.student_id = ve.student_id 
                    WHERE ve.course_id = ?
                ");
                $students_stmt->bind_param("i", $course_id);
                $students_stmt->execute();
                $students_result = $students_stmt->get_result();
                
                // Get lecturer name
                $lecturer_name = $user['display_name'] ?? 'Your Instructor';
                
                while ($student = $students_result->fetch_assoc()) {
                    sendDocumentUploadedEmail(
                        $student['email'],
                        $student['full_name'],
                        $lecturer_name,
                        $course['course_name'],
                        $title,
                        $content_type,
                        $description,
                        $course_id
                    );
                }
            }
            
            header("Location: dashboard.php?course_id=$course_id");
            exit();
        } else {
            $error = "Failed to add content: " . $conn->error;
        }
    } elseif (empty($title)) {
        $error = "Title is required.";
    }
}

$user = getCurrentUser();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Content - VLE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/global-theme.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <style>
        .video-option-card {
            border: 2px solid #dee2e6;
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            background: #fff;
        }
        .video-option-card:hover {
            border-color: #667eea;
            background: #f8f9ff;
        }
        .video-option-card.active {
            border-color: #667eea;
            background: linear-gradient(135deg, rgba(102,126,234,0.1) 0%, rgba(118,75,162,0.1) 100%);
        }
        .video-option-card i {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        .video-recorder-container {
            background: #1a1a2e;
            border-radius: 12px;
            padding: 20px;
            margin-top: 15px;
        }
        #videoPreview {
            width: 100%;
            max-height: 400px;
            border-radius: 8px;
            background: #000;
        }
        .recording-controls {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 15px;
        }
        .recording-controls .btn {
            min-width: 120px;
        }
        .recording-indicator {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #ff4757;
            font-weight: 600;
            margin-top: 10px;
        }
        .recording-indicator.active {
            display: flex;
        }
        .recording-dot {
            width: 12px;
            height: 12px;
            background: #ff4757;
            border-radius: 50%;
            animation: blink 1s infinite;
        }
        @keyframes blink {
            0%, 50% { opacity: 1; }
            51%, 100% { opacity: 0; }
        }
        .timer-display {
            font-family: 'Courier New', monospace;
            font-size: 1.5rem;
            color: #fff;
            text-align: center;
            margin-top: 10px;
        }
        .recorded-preview {
            margin-top: 15px;
        }
        .upload-progress {
            display: none;
            margin-top: 15px;
        }
        .video-source-section {
            display: none;
        }
        .video-source-section.active {
            display: block;
        }
        /* Screen recording with camera overlay */
        .screen-recorder-wrapper {
            position: relative;
            width: 100%;
        }
        .camera-overlay {
            position: absolute;
            bottom: 20px;
            left: 20px;
            width: 180px;
            height: 135px;
            border-radius: 12px;
            overflow: hidden;
            border: 3px solid #667eea;
            box-shadow: 0 4px 15px rgba(0,0,0,0.5);
            z-index: 10;
            background: #000;
            display: none;
        }
        .camera-overlay.active {
            display: block;
        }
        .camera-overlay video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .camera-overlay-controls {
            position: absolute;
            top: 5px;
            right: 5px;
            z-index: 11;
        }
        .camera-overlay-controls .btn {
            padding: 2px 6px;
            font-size: 0.7rem;
        }
    </style>
</head>
<body>
    <?php 
    $currentPage = 'add_content';
    $pageTitle = 'Add Content';
    include 'header_nav.php'; 
    ?>

    <div class="container-fluid px-3 px-lg-4 mt-3 mt-lg-4">
        <div class="mb-3">
            <button class="btn btn-outline-secondary" onclick="window.history.back();"><i class="bi bi-arrow-left"></i> Back</button>
        </div>
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow-sm">
                    <div class="card-header py-3" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-0 text-white"><i class="bi bi-folder-plus me-2"></i> Add Content</h4>
                                <p class="mb-0 mt-1 text-white-50"><i class="bi bi-book"></i> <?php echo htmlspecialchars($course['course_name']); ?> - Week <?php echo $week; ?></p>
                            </div>
                            <a href="dashboard.php?course_id=<?php echo $course_id; ?>" class="btn btn-light btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" id="contentForm">
                            <div class="mb-3">
                                <label for="title" class="form-label">Title *</label>
                                <input type="text" class="form-control" id="title" name="title" required>
                            </div>

                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="content_type" class="form-label">Content Type</label>
                                <select class="form-select" id="content_type" name="content_type">
                                    <option value="text">Text</option>
                                    <option value="presentation">Presentation</option>
                                    <option value="video">Video</option>
                                    <option value="document">Document</option>
                                    <option value="file">File</option>
                                    <option value="audio">Audio</option>
                                    <option value="link">Supplementary Resource</option>
                                </select>
                            </div>

                            <!-- Video Source Options (shown when video type selected) -->
                            <div id="video_options" style="display: none;">
                                <label class="form-label fw-bold mb-3"><i class="bi bi-camera-video me-2"></i>Choose Video Source</label>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-4">
                                        <div class="video-option-card" data-source="url" onclick="selectVideoSource('url')">
                                            <i class="bi bi-link-45deg text-primary"></i>
                                            <h6 class="mb-1">URL/Embed</h6>
                                            <small class="text-muted">YouTube, Vimeo, etc.</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="video-option-card" data-source="upload" onclick="selectVideoSource('upload')">
                                            <i class="bi bi-upload text-success"></i>
                                            <h6 class="mb-1">Upload Video</h6>
                                            <small class="text-muted">From your computer</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="video-option-card" data-source="record" onclick="selectVideoSource('record')">
                                            <i class="bi bi-record-circle text-danger"></i>
                                            <h6 class="mb-1">Record Lecture</h6>
                                            <small class="text-muted">Camera + Screen Share</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- URL Input Section -->
                                <div id="video_url_section" class="video-source-section">
                                    <div class="mb-3">
                                        <label for="video_url" class="form-label">Video URL</label>
                                        <input type="url" class="form-control" id="video_url" name="content_url" placeholder="https://www.youtube.com/watch?v=...">
                                        <small class="form-text text-muted">Paste YouTube, Vimeo, or other video platform URL</small>
                                    </div>
                                </div>
                                
                                <!-- Upload Video Section -->
                                <div id="video_upload_section" class="video-source-section">
                                    <div class="mb-3">
                                        <label for="video_file" class="form-label">Select Video File</label>
                                        <input type="file" class="form-control" id="video_file" name="content_file" accept="video/*">
                                        <small class="form-text text-muted">Supported formats: MP4, WebM, MOV, AVI (Max: 200MB)</small>
                                    </div>
                                    <div id="video_upload_preview" style="display: none;" class="mt-3">
                                        <label class="form-label text-success"><i class="bi bi-check-circle me-2"></i>Video Preview</label>
                                        <video id="uploadedVideoPreview" controls style="width: 100%; max-height: 300px; border-radius: 8px; background: #000;"></video>
                                    </div>
                                </div>
                                
                                <!-- Unified Record Lecture Section -->
                                <div id="video_record_section" class="video-source-section">
                                    <!-- Screen Share Toggle -->
                                    <div class="mb-3">
                                        <div class="form-check form-switch d-flex align-items-center gap-3 p-3 rounded" style="background: linear-gradient(135deg, rgba(102,126,234,0.1) 0%, rgba(118,75,162,0.1) 100%); border: 1px solid #667eea;">
                                            <input class="form-check-input" type="checkbox" id="enableScreenShare" style="width: 50px; height: 25px; cursor: pointer;">
                                            <label class="form-check-label" for="enableScreenShare" style="cursor: pointer;">
                                                <i class="bi bi-display me-2 text-warning"></i>
                                                <strong>Include Screen Sharing</strong>
                                                <small class="d-block text-muted">Share your presentation/slides with camera overlay</small>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="video-recorder-container">
                                        <div class="screen-recorder-wrapper">
                                            <!-- Main video preview (screen or camera) -->
                                            <video id="videoPreview" autoplay muted playsinline></video>
                                            <!-- Camera overlay (shown when screen sharing is enabled) -->
                                            <div class="camera-overlay" id="cameraOverlay">
                                                <video id="cameraOverlayVideo" autoplay muted playsinline></video>
                                            </div>
                                        </div>
                                        <div class="timer-display" id="timerDisplay">00:00:00</div>
                                        <div class="recording-indicator" id="recordingIndicator">
                                            <span class="recording-dot"></span>
                                            <span id="recordingText">Recording...</span>
                                        </div>
                                        <div class="recording-controls">
                                            <button type="button" class="btn btn-outline-light" id="btnStartCapture" onclick="startLectureCapture()">
                                                <i class="bi bi-camera-video me-2"></i><span id="startCaptureText">Start Camera</span>
                                            </button>
                                            <button type="button" class="btn btn-danger" id="btnStartRecording" onclick="startRecording()" style="display: none;">
                                                <i class="bi bi-record-circle me-2"></i>Start Recording
                                            </button>
                                            <button type="button" class="btn btn-warning" id="btnStopRecording" onclick="stopRecording()" style="display: none;">
                                                <i class="bi bi-stop-fill me-2"></i>Stop Recording
                                            </button>
                                            <button type="button" class="btn btn-secondary" id="btnRetake" onclick="retakeRecording()" style="display: none;">
                                                <i class="bi bi-arrow-repeat me-2"></i>Retake
                                            </button>
                                        </div>
                                    </div>
                                    <div class="recorded-preview mt-3" id="recordedPreview" style="display: none;">
                                        <label class="form-label text-success"><i class="bi bi-check-circle me-2"></i>Recorded Lecture Preview</label>
                                        <video id="recordedVideo" controls style="width: 100%; max-height: 300px; border-radius: 8px; background: #000;"></video>
                                    </div>
                                </div>
                                <input type="hidden" id="video_source_type" name="video_source_type" value="">
                            </div>

                            <!-- Non-video URL input (for audio/link) -->
                            <div class="mb-3" id="url_input" style="display: none;">
                                <label for="content_url" class="form-label">URL</label>
                                <input type="url" class="form-control" id="content_url" name="content_url" placeholder="https://example.com">
                                <small class="form-text text-muted">For audio: SoundCloud, podcast URLs, etc.</small>
                            </div>

                            <!-- Non-video file upload -->
                            <div class="mb-3" id="file_upload" style="display: none;">
                                <label for="content_file" class="form-label">Upload File</label>
                                <input type="file" class="form-control" id="content_file" name="content_file">
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="is_mandatory" name="is_mandatory" checked>
                                <label class="form-check-label" for="is_mandatory">Mandatory content</label>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="bi bi-plus-circle me-2"></i>Add Content
                                </button>
                                <a href="dashboard.php?course_id=<?php echo $course_id; ?>" class="btn btn-outline-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
    <script>
        // Video recording variables
        let mediaRecorder;
        let recordedChunks = [];
        let stream;
        let cameraStream;
        let screenStream;
        let combinedStream;
        let timerInterval;
        let seconds = 0;
        let recordedBlob;
        let canvas;
        let canvasCtx;
        let animationId;

        $(document).ready(function() {
            // Initialize Summernote rich text editor
            $('#description').summernote({
                height: 200,
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'italic', 'underline', 'clear']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['insert', ['link', 'picture']],
                    ['view', ['fullscreen', 'codeview', 'help']]
                ]
            });
        });

        // Show/hide sections based on content type
        document.getElementById('content_type').addEventListener('change', function() {
            const fileUpload = document.getElementById('file_upload');
            const urlInput = document.getElementById('url_input');
            const videoOptions = document.getElementById('video_options');
            const contentType = this.value;
            
            // Hide all first
            fileUpload.style.display = 'none';
            urlInput.style.display = 'none';
            videoOptions.style.display = 'none';
            
            // Reset video options
            document.querySelectorAll('.video-option-card').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('.video-source-section').forEach(s => s.classList.remove('active'));
            stopCamera();
            
            if (['presentation', 'document', 'file'].includes(contentType)) {
                fileUpload.style.display = 'block';
            } else if (contentType === 'video') {
                videoOptions.style.display = 'block';
            } else if (['audio', 'link'].includes(contentType)) {
                urlInput.style.display = 'block';
            }
        });

        // Select video source
        function selectVideoSource(source) {
            // Update cards
            document.querySelectorAll('.video-option-card').forEach(c => c.classList.remove('active'));
            document.querySelector(`.video-option-card[data-source="${source}"]`).classList.add('active');
            
            // Update sections
            document.querySelectorAll('.video-source-section').forEach(s => s.classList.remove('active'));
            document.getElementById(`video_${source}_section`).classList.add('active');
            
            // Set source type
            document.getElementById('video_source_type').value = source;
            
            // Stop camera/screen if switching away
            if (source !== 'record') {
                stopCamera();
            }
            
            // Update button text based on screen share toggle
            if (source === 'record') {
                updateCaptureButtonText();
            }
        }
        
        // Update capture button text based on toggle
        function updateCaptureButtonText() {
            const enableScreenShare = document.getElementById('enableScreenShare');
            const startCaptureText = document.getElementById('startCaptureText');
            const recordingText = document.getElementById('recordingText');
            
            if (enableScreenShare && enableScreenShare.checked) {
                startCaptureText.textContent = 'Share Screen + Camera';
                recordingText.textContent = 'Recording Screen + Camera...';
            } else {
                startCaptureText.textContent = 'Start Camera';
                recordingText.textContent = 'Recording...';
            }
        }
        
        // Add toggle event listener
        document.addEventListener('DOMContentLoaded', function() {
            const enableScreenShare = document.getElementById('enableScreenShare');
            if (enableScreenShare) {
                enableScreenShare.addEventListener('change', function() {
                    updateCaptureButtonText();
                    // Stop current capture if switching modes
                    stopCamera();
                });
            }
        });

        // Video file upload preview
        document.getElementById('video_file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const videoPreview = document.getElementById('uploadedVideoPreview');
                const previewContainer = document.getElementById('video_upload_preview');
                videoPreview.src = URL.createObjectURL(file);
                previewContainer.style.display = 'block';
            }
        });
        
        // Unified lecture capture function
        async function startLectureCapture() {
            const enableScreenShare = document.getElementById('enableScreenShare');
            const withScreenShare = enableScreenShare && enableScreenShare.checked;
            
            if (withScreenShare) {
                await startScreenWithCamera();
            } else {
                await startCameraOnly();
            }
        }
        
        // Camera only function
        async function startCameraOnly() {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        facingMode: 'user'
                    }, 
                    audio: true 
                });
                const videoPreview = document.getElementById('videoPreview');
                videoPreview.srcObject = stream;
                document.getElementById('btnStartCapture').style.display = 'none';
                document.getElementById('btnStartRecording').style.display = 'inline-block';
            } catch (err) {
                console.error('Camera error:', err);
                alert('Unable to access camera and microphone. Please ensure you have granted permissions.\n\nError: ' + err.message);
            }
        }

        // Screen share with camera overlay function
        async function startScreenWithCamera() {
            try {
                // Get screen stream
                screenStream = await navigator.mediaDevices.getDisplayMedia({ 
                    video: { width: { ideal: 1920 }, height: { ideal: 1080 } }, 
                    audio: true 
                });
                
                // Get camera stream
                cameraStream = await navigator.mediaDevices.getUserMedia({ 
                    video: { width: { ideal: 320 }, height: { ideal: 240 }, facingMode: 'user' }, 
                    audio: true 
                });
                
                // Show screen preview in main video element
                const videoPreview = document.getElementById('videoPreview');
                videoPreview.srcObject = screenStream;
                
                // Show camera overlay
                const cameraOverlay = document.getElementById('cameraOverlay');
                const cameraOverlayVideo = document.getElementById('cameraOverlayVideo');
                cameraOverlayVideo.srcObject = cameraStream;
                cameraOverlay.classList.add('active');
                
                // Create canvas for combining streams
                canvas = document.createElement('canvas');
                canvas.width = 1280;
                canvas.height = 720;
                canvasCtx = canvas.getContext('2d');
                
                // Start drawing combined video
                const screenVideo = videoPreview;
                const camVideo = cameraOverlayVideo;
                
                function drawFrame() {
                    // Draw screen (full canvas)
                    canvasCtx.drawImage(screenVideo, 0, 0, canvas.width, canvas.height);
                    
                    // Draw camera overlay (bottom-left corner)
                    const camWidth = 200;
                    const camHeight = 150;
                    const camX = 20;
                    const camY = canvas.height - camHeight - 20;
                    
                    // Draw border/background for camera
                    canvasCtx.fillStyle = '#667eea';
                    canvasCtx.fillRect(camX - 3, camY - 3, camWidth + 6, camHeight + 6);
                    
                    // Draw camera feed
                    canvasCtx.drawImage(camVideo, camX, camY, camWidth, camHeight);
                    
                    animationId = requestAnimationFrame(drawFrame);
                }
                drawFrame();
                
                // Create combined stream from canvas + audio
                const canvasStream = canvas.captureStream(30);
                
                // Get audio tracks from both streams
                const audioTracks = [];
                if (screenStream.getAudioTracks().length > 0) {
                    audioTracks.push(...screenStream.getAudioTracks());
                }
                if (cameraStream.getAudioTracks().length > 0) {
                    audioTracks.push(...cameraStream.getAudioTracks());
                }
                
                // Combine canvas video with audio
                combinedStream = new MediaStream([
                    ...canvasStream.getVideoTracks(),
                    ...audioTracks
                ]);
                
                // Set combined stream for recording
                stream = combinedStream;
                
                // Update UI
                document.getElementById('btnStartCapture').style.display = 'none';
                document.getElementById('btnStartRecording').style.display = 'inline-block';
                
                // Handle screen share stop
                screenStream.getVideoTracks()[0].onended = () => {
                    stopScreenWithCamera();
                };
                
            } catch (err) {
                console.error('Screen + Camera error:', err);
                alert('Unable to start screen sharing with camera. Please ensure you have granted permissions.\n\nError: ' + err.message);
                stopScreenWithCamera();
            }
        }
        
        function stopScreenWithCamera() {
            // Stop animation
            if (animationId) {
                cancelAnimationFrame(animationId);
                animationId = null;
            }
            
            // Stop streams
            if (screenStream) {
                screenStream.getTracks().forEach(track => track.stop());
                screenStream = null;
            }
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }
            if (combinedStream) {
                combinedStream.getTracks().forEach(track => track.stop());
                combinedStream = null;
            }
            
            // Hide camera overlay
            const cameraOverlay = document.getElementById('cameraOverlay');
            if (cameraOverlay) cameraOverlay.classList.remove('active');
        }

        function stopCamera() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            // Stop screen with camera if active
            stopScreenWithCamera();
            
            // Reset video preview
            const videoPreview = document.getElementById('videoPreview');
            if (videoPreview) videoPreview.srcObject = null;
            const cameraOverlayVideo = document.getElementById('cameraOverlayVideo');
            if (cameraOverlayVideo) cameraOverlayVideo.srcObject = null;
            
            // Hide camera overlay
            const cameraOverlay = document.getElementById('cameraOverlay');
            if (cameraOverlay) cameraOverlay.classList.remove('active');
            
            // Reset controls
            if (document.getElementById('btnStartCapture')) document.getElementById('btnStartCapture').style.display = 'inline-block';
            if (document.getElementById('btnStartRecording')) document.getElementById('btnStartRecording').style.display = 'none';
            if (document.getElementById('btnStopRecording')) document.getElementById('btnStopRecording').style.display = 'none';
            if (document.getElementById('btnRetake')) document.getElementById('btnRetake').style.display = 'none';
            if (document.getElementById('recordingIndicator')) document.getElementById('recordingIndicator').classList.remove('active');
            stopTimer();
        }

        function startRecording() {
            recordedChunks = [];
            const options = { mimeType: 'video/webm;codecs=vp9,opus' };
            if (!MediaRecorder.isTypeSupported(options.mimeType)) {
                options.mimeType = 'video/webm;codecs=vp8,opus';
            }
            if (!MediaRecorder.isTypeSupported(options.mimeType)) {
                options.mimeType = 'video/webm';
            }
            try {
                mediaRecorder = new MediaRecorder(stream, options);
            } catch (e) {
                console.error('MediaRecorder error:', e);
                alert('Recording not supported in this browser. Please try Chrome or Firefox.');
                return;
            }
            mediaRecorder.ondataavailable = (e) => {
                if (e.data.size > 0) {
                    recordedChunks.push(e.data);
                }
            };
            mediaRecorder.onstop = () => {
                recordedBlob = new Blob(recordedChunks, { type: 'video/webm' });
                const recordedVideo = document.getElementById('recordedVideo');
                recordedVideo.src = URL.createObjectURL(recordedBlob);
                document.getElementById('recordedPreview').style.display = 'block';
                document.getElementById('btnRetake').style.display = 'inline-block';
                document.getElementById('recordingIndicator').classList.remove('active');
                // Stop the canvas animation if screen sharing was used
                if (animationId) {
                    cancelAnimationFrame(animationId);
                    animationId = null;
                }
                stopTimer();
            };
            mediaRecorder.start(1000);
            
            // Update UI
            document.getElementById('btnStartRecording').style.display = 'none';
            document.getElementById('btnStopRecording').style.display = 'inline-block';
            document.getElementById('recordingIndicator').classList.add('active');
            startTimer();
        }

        function stopRecording() {
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.stop();
            }
            document.getElementById('btnStopRecording').style.display = 'none';
        }

        function retakeRecording() {
            recordedChunks = [];
            recordedBlob = null;
            document.getElementById('recordedPreview').style.display = 'none';
            document.getElementById('btnRetake').style.display = 'none';
            document.getElementById('btnStartCapture').style.display = 'inline-block';
            document.getElementById('timerDisplay').textContent = '00:00:00';
            // Stop current streams
            stopScreenWithCamera();
            seconds = 0;
        }

        // Timer functions
        function startTimer() {
            seconds = 0;
            timerInterval = setInterval(() => {
                seconds++;
                document.getElementById('timerDisplay').textContent = formatTime(seconds);
            }, 1000);
        }

        function stopTimer() {
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }
            document.getElementById('timerDisplay').textContent = '00:00:00';
        }

        function formatTime(totalSeconds) {
            const hrs = Math.floor(totalSeconds / 3600);
            const mins = Math.floor((totalSeconds % 3600) / 60);
            const secs = totalSeconds % 60;
            return `${String(hrs).padStart(2, '0')}:${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        }

        // Form submission handler for recorded video
        document.getElementById('contentForm').addEventListener('submit', async function(e) {
            const contentType = document.getElementById('content_type').value;
            const videoSourceType = document.getElementById('video_source_type').value;
            if (contentType === 'video' && videoSourceType === 'record' && recordedBlob) {
                e.preventDefault();
                const submitBtn = document.getElementById('submitBtn');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Uploading...';
                // Create FormData and append the recorded video
                const formData = new FormData(this);
                // Remove old file if any and add recorded blob
                formData.delete('content_file');
                const enableScreenShare = document.getElementById('enableScreenShare');
                let filename = (enableScreenShare && enableScreenShare.checked ? 'lecture_recording_' : 'camera_recording_') + Date.now() + '.webm';
                formData.append('content_file', recordedBlob, filename);
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    if (response.ok) {
                        window.location.href = 'dashboard.php?course_id=<?php echo $course_id; ?>';
                    } else {
                        throw new Error('Upload failed');
                    }
                } catch (error) {
                    console.error('Upload error:', error);
                    alert('Failed to upload recorded video. Please try again.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-plus-circle me-2"></i>Add Content';
                }
            }
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', () => {
            stopCamera();
        });
    </script>
</body>
</html>