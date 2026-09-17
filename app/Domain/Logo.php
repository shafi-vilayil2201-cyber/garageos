<?php

// Organization logo upload — the app's first file upload, so validation
// is deliberately layered (cheapest checks first) and the stored file
// is never a copy of what the client sent. Every upload is decoded and
// re-encoded as PNG server-side, so the bytes on disk are always
// server-generated pixel data, not client-controlled content — a
// polyglot file with valid-looking headers but a malicious payload
// can't survive imagecreatefromstring(), and there's no path for an
// SVG (or anything else GD can't decode as a raster image) to get
// through at all.

const LOGO_MAX_BYTES = 2 * 1024 * 1024;
const LOGO_MAX_DIMENSION = 4000;
const LOGO_BOUNDING_BOX = 480;
const LOGO_UPLOAD_DIR = __DIR__ . '/../../public/uploads/logos';
const LOGO_URL_PREFIX = '/uploads/logos/';

function upload_organization_logo(?array $file): array
{
    $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;

    // PHP itself rejects anything over php.ini's upload_max_filesize
    // before this function ever runs, setting error to INI_SIZE (or
    // FORM_SIZE for a MAX_FILE_SIZE form field) rather than OK — that's
    // still fundamentally a "too big" situation from the user's point
    // of view, not a generic "no file chosen" one, even though our own
    // LOGO_MAX_BYTES check below never got a chance to run.
    if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        return ['error' => 'Logo must be smaller than 2MB.', 'path' => null];
    }

    if (!$file || $uploadError !== UPLOAD_ERR_OK) {
        return ['error' => 'Choose a file to upload.', 'path' => null];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return ['error' => 'Upload failed.', 'path' => null];
    }

    if ($file['size'] > LOGO_MAX_BYTES) {
        return ['error' => 'Logo must be smaller than 2MB.', 'path' => null];
    }

    $info = @getimagesize($file['tmp_name']);

    if ($info === false) {
        return ['error' => 'That file is not a valid image.', 'path' => null];
    }

    [$width, $height] = $info;

    if ($width > LOGO_MAX_DIMENSION || $height > LOGO_MAX_DIMENSION) {
        return ['error' => 'Image dimensions are too large.', 'path' => null];
    }

    // The authoritative check: a full decode. getimagesize() only reads
    // header bytes, so this is what actually defeats a file whose
    // header looks like an image but whose body isn't one.
    $source = @imagecreatefromstring(file_get_contents($file['tmp_name']));

    if ($source === false) {
        return ['error' => 'That file is not a valid image.', 'path' => null];
    }

    $normalized = resize_within_bounds($source, LOGO_BOUNDING_BOX, LOGO_BOUNDING_BOX);
    imagedestroy($source);

    if (!is_dir(LOGO_UPLOAD_DIR)) {
        mkdir(LOGO_UPLOAD_DIR, 0755, true);
    }

    $filename = bin2hex(random_bytes(16)) . '.png';
    imagepng($normalized, LOGO_UPLOAD_DIR . '/' . $filename);
    imagedestroy($normalized);

    return ['error' => null, 'path' => LOGO_URL_PREFIX . $filename];
}

// Scales down to fit within the box (never up — a small logo stays
// small rather than getting blurrily stretched) and preserves
// transparency, since a logo with a transparent background is the
// common case.
function resize_within_bounds($source, int $maxWidth, int $maxHeight)
{
    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min($maxWidth / $width, $maxHeight / $height, 1);

    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));

    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    imagesavealpha($target, true);
    imagealphablending($target, false);
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefill($target, 0, 0, $transparent);
    imagealphablending($target, true);

    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

    return $target;
}

// Refuses to touch anything outside the expected directory, as
// defense in depth even if logo_url somehow held an unexpected value.
function delete_old_logo_file(?string $path): void
{
    if (!$path || !str_starts_with($path, LOGO_URL_PREFIX)) {
        return;
    }

    $full = LOGO_UPLOAD_DIR . '/' . basename($path);

    if (is_file($full)) {
        unlink($full);
    }
}
