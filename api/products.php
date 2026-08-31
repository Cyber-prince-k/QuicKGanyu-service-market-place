<?php
// Provider product/service listings with supporting images or videos.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();
    $user = current_user($pdo);
    if ($user === null || $user['role'] !== 'provider') {
        respond(['ok' => false, 'error' => 'Provider access required.'], 403);
    }
    $providerId = (int)$user['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(['ok' => true, 'products' => provider_products($pdo, $providerId)]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $title = str_field($_POST, 'title');
    $description = str_field($_POST, 'description');
    $errors = [];
    if (mb_strlen($title) < 3 || mb_strlen($title) > 140) $errors['title'] = 'Enter a product or service name between 3 and 140 characters.';
    if (mb_strlen($description) < 10) $errors['description'] = 'Describe the product or service in at least 10 characters.';

    $files = $_FILES['media'] ?? null;
    if (!$files || !is_array($files['name'])) {
        $errors['media'] = 'Add at least one image or video.';
    } elseif (count($files['name']) > 5) {
        $errors['media'] = 'You can upload up to five files per listing.';
    }
    if ($errors) respond(['ok' => false, 'errors' => $errors], 422);

    $allowed = [
        'image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'],
        'image/webp' => ['image', 'webp'], 'video/mp4' => ['video', 'mp4'],
        'video/webm' => ['video', 'webm'],
    ];
    $checked = [];
    foreach ($files['name'] as $i => $_) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) respond(['ok' => false, 'error' => 'Every selected file must upload successfully.'], 422);
        if ($files['size'][$i] > 25 * 1024 * 1024) respond(['ok' => false, 'error' => 'Each file must be 25 MB or smaller.'], 422);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($files['tmp_name'][$i]);
        if (!isset($allowed[$mime])) respond(['ok' => false, 'error' => 'Use JPG, PNG, WEBP, MP4, or WebM files only.'], 422);
        $checked[] = [$i, $allowed[$mime][0], $allowed[$mime][1]];
    }

    $folder = __DIR__ . '/../uploads/provider-products';
    if (!is_dir($folder) && !mkdir($folder, 0755, true)) respond(['ok' => false, 'error' => 'Could not prepare the upload folder.'], 500);
    $saved = [];
    try {
        foreach ($checked as [$i, $mediaType, $extension]) {
            $filename = bin2hex(random_bytes(16)) . '.' . $extension;
            $diskPath = $folder . '/' . $filename;
            if (!move_uploaded_file($files['tmp_name'][$i], $diskPath)) throw new RuntimeException('Could not save an uploaded file.');
            $saved[] = [$mediaType, 'uploads/provider-products/' . $filename, $diskPath];
        }
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO provider_products (provider_id, title, description) VALUES (?, ?, ?)')
            ->execute([$providerId, $title, $description]);
        $productId = (int)$pdo->lastInsertId();
        $mediaStmt = $pdo->prepare('INSERT INTO provider_product_media (product_id, media_type, file_path) VALUES (?, ?, ?)');
        foreach ($saved as [$mediaType, $path]) $mediaStmt->execute([$productId, $mediaType, $path]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($saved as $file) if (is_file($file[2])) @unlink($file[2]);
        throw $e;
    }
    respond(['ok' => true, 'message' => 'Listing published.', 'products' => provider_products($pdo, $providerId)], 201);
} catch (Throwable $e) {
    error_log('QuickGanyu product upload error: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'Could not publish the listing.'], 500);
}
