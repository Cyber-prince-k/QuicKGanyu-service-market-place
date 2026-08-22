<?php
// Provider portfolio media upload. Only the logged-in provider can add evidence.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();
    $user = current_user($pdo);
    if ($user === null || $user['role'] !== 'provider') {
        respond(['ok' => false, 'error' => 'Provider access required.'], 403);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['media'])) {
        respond(['ok' => false, 'error' => 'Choose an image or video to upload.'], 422);
    }

    $file = $_FILES['media'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        respond(['ok' => false, 'error' => 'The upload failed. Please try a smaller file.'], 422);
    }
    if ($file['size'] > 25 * 1024 * 1024) {
        respond(['ok' => false, 'error' => 'Files must be 25 MB or smaller.'], 422);
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'],
        'image/webp' => ['image', 'webp'], 'video/mp4' => ['video', 'mp4'],
        'video/webm' => ['video', 'webm'],
    ];
    if (!isset($allowed[$mime])) {
        respond(['ok' => false, 'error' => 'Use a JPG, PNG, WEBP, MP4, or WebM file.'], 422);
    }

    [$mediaType, $extension] = $allowed[$mime];
    $folder = __DIR__ . '/../uploads/provider-portfolio';
    if (!is_dir($folder) && !mkdir($folder, 0755, true)) {
        respond(['ok' => false, 'error' => 'Could not prepare the upload folder.'], 500);
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $filename)) {
        respond(['ok' => false, 'error' => 'Could not save the uploaded file.'], 500);
    }

    $caption = str_field($_POST, 'caption');
    $path = 'uploads/provider-portfolio/' . $filename;
    $pdo->prepare('INSERT INTO provider_portfolio (provider_id, media_type, file_path, caption) VALUES (?, ?, ?, ?)')
        ->execute([(int)$user['id'], $mediaType, $path, $caption ?: null]);
    respond(['ok' => true, 'message' => 'Portfolio evidence uploaded.', 'profile_complete' => provider_profile_complete($pdo, (int)$user['id'])], 201);
} catch (Throwable $e) {
    error_log('QuickGanyu portfolio upload error: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'Could not upload portfolio evidence.'], 500);
}
