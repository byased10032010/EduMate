<?php
/**
 * EduMate - File upload helper.
 *
 * Handles video / image / generic file uploads with size + mime checks.
 * Files are stored under /uploads/<bucket>/<random>.<ext>.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const UPLOAD_ROOT          = __DIR__ . '/../uploads';
const UPLOAD_MAX_VIDEO     = 200 * 1024 * 1024;   // 200 MB
const UPLOAD_MAX_FILE      = 25  * 1024 * 1024;   // 25 MB
const UPLOAD_MAX_IMAGE     = 8   * 1024 * 1024;   // 8 MB

/**
 * Returns a list of allowed MIME types per bucket.
 */
function upload_allowed_mimes(string $bucket): array
{
    switch ($bucket) {
        case 'video':
            return [
                'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime',
                'application/octet-stream', // some browsers report this for .mp4
            ];
        case 'image':
            return ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        case 'file':
        default:
            return [
                'application/pdf',
                'application/zip',
                'application/x-zip-compressed',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain',
                'text/csv',
                'text/markdown',
            ];
    }
}

/**
 * Max size per bucket.
 */
function upload_max_size(string $bucket): int
{
    return match ($bucket) {
        'video' => UPLOAD_MAX_VIDEO,
        'image' => UPLOAD_MAX_IMAGE,
        default => UPLOAD_MAX_FILE,
    };
}

/**
 * Process a single uploaded file from $_FILES.
 *
 * Returns:
 *   ['ok' => true,  'path' => 'relative/path', 'name' => 'original', 'size' => bytes, 'mime' => '...']
 *   ['ok' => false, 'error' => 'message']
 */
function handle_upload(array $file, string $bucket = 'file'): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file uploaded.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server has no temp directory.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write file.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
        ];
        return ['ok' => false, 'error' => $map[$file['error']] ?? 'Unknown upload error.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Not an uploaded file.'];
    }

    $size = (int)$file['size'];
    $max  = upload_max_size($bucket);
    if ($size <= 0)             return ['ok' => false, 'error' => 'Empty file.'];
    if ($size > $max)           return ['ok' => false, 'error' => 'File too large. Max ' . round($max / 1048576, 0) . ' MB.'];

    $allowed = upload_allowed_mimes($bucket);
    $mime    = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: ($file['type'] ?? 'application/octet-stream');
    if (!in_array($mime, $allowed, true)) {
        // Be lenient: octet-stream is fine if extension is acceptable.
        if ($mime !== 'application/octet-stream') {
            return ['ok' => false, 'error' => 'Unsupported file type (' . htmlspecialchars($mime, ENT_QUOTES) . ').'];
        }
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', (string)$ext);
    if ($ext === '') $ext = 'bin';

    $dir = UPLOAD_ROOT . '/' . $bucket;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Could not create upload directory.'];
    }
    // .htaccess to disable script execution in uploads
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Options -ExecCGI -Indexes\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh)$\">\n  Require all denied\n</FilesMatch>\n");
    }
    $safeName = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
    $abs      = $dir . '/' . $safeName;
    if (!@move_uploaded_file($file['tmp_name'], $abs)) {
        return ['ok' => false, 'error' => 'Failed to save uploaded file.'];
    }
    @chmod($abs, 0644);
    $rel = 'uploads/' . $bucket . '/' . $safeName;

    return [
        'ok'           => true,
        'path'         => $rel,
        'abs_path'     => $abs,
        'name'         => $file['name'],
        'size'         => $size,
        'mime'         => $mime,
        'extension'    => $ext,
    ];
}

/**
 * Return an absolute path on disk for a relative upload path.
 * Refuses to escape the uploads root.
 */
function upload_abs_path(string $rel): ?string
{
    $abs = realpath(__DIR__ . '/../' . ltrim($rel, '/'));
    if ($abs === false) return null;
    $root = realpath(UPLOAD_ROOT);
    if ($root === false) return null;
    if (strpos($abs, $root) !== 0) return null;
    return $abs;
}
