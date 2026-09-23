<?php

// Vehicle condition at intake — the browser draws the C/D/S marks the
// mechanic places on the SVG vehicle map (see public/js/damage-diagram.js)
// and exports the marked illustration as a single image, POSTed as a data:
// URL in damage_image_data. The exact part/type is stored separately in
// job_card_damage_marks. The image data is never trusted as-is: same
// principle as upload_organization_logo()
// in Logo.php — it's decoded and re-encoded server-side, and the client's
// claimed size/type mean nothing. The output is JPEG (not PNG) because
// the composited image is always fully opaque (the skeleton's own
// transparency is flattened onto white first), and JPEG compresses flat
// line-art-on-white far smaller than PNG — the whole point of this
// bounding box + quality setting is keeping per-job-card storage small,
// since this is a file every job card with a marked-up diagram will have.

const DAMAGE_IMAGE_MAX_BASE64_BYTES = 8 * 1024 * 1024;
const DAMAGE_IMAGE_MAX_DIMENSION = 900;
const DAMAGE_IMAGE_JPEG_QUALITY = 78;
const DAMAGE_IMAGE_UPLOAD_DIR = __DIR__ . '/../../public/uploads/damage';
const DAMAGE_IMAGE_URL_PREFIX = '/uploads/damage/';

// Returns the saved file's URL, or null if there was nothing worth
// saving (no marks placed — an unmarked skeleton isn't worth storing)
// or the data couldn't be decoded as an image. Malformed input is
// skipped rather than raised: a broken image shouldn't block the rest
// of the job card from being created.
function save_damage_image(int $jobCardId, ?string $dataUrl): ?string
{
    if (!$dataUrl || strlen($dataUrl) > DAMAGE_IMAGE_MAX_BASE64_BYTES) {
        return null;
    }

    if (!preg_match('/^data:image\/(?:png|jpeg);base64,(.+)$/', $dataUrl, $matches)) {
        return null;
    }

    $bytes = base64_decode($matches[1], true);

    if ($bytes === false) {
        return null;
    }

    $source = @imagecreatefromstring($bytes);

    if ($source === false) {
        return null;
    }

    $flattened = flatten_onto_white($source);
    imagedestroy($source);

    $resized = resize_damage_image_within_bounds($flattened, DAMAGE_IMAGE_MAX_DIMENSION, DAMAGE_IMAGE_MAX_DIMENSION);
    imagedestroy($flattened);

    if (!is_dir(DAMAGE_IMAGE_UPLOAD_DIR)) {
        mkdir(DAMAGE_IMAGE_UPLOAD_DIR, 0755, true);
    }

    $filename = "job-card-{$jobCardId}.jpg";
    imagejpeg($resized, DAMAGE_IMAGE_UPLOAD_DIR . '/' . $filename, DAMAGE_IMAGE_JPEG_QUALITY);
    imagedestroy($resized);

    return DAMAGE_IMAGE_URL_PREFIX . $filename;
}

// The canvas export can carry transparent pixels (anywhere the skeleton
// itself is transparent) — flatten those onto white before JPEG encoding,
// since JPEG has no alpha channel at all.
function flatten_onto_white($source)
{
    $width = imagesx($source);
    $height = imagesy($source);

    $target = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefill($target, 0, 0, $white);
    imagecopy($target, $source, 0, 0, 0, 0, $width, $height);

    return $target;
}

// Scales down to fit within the box — never up, so a small canvas
// export doesn't get blurrily stretched. Named distinctly from Logo.php's
// own resize_within_bounds() (same idea, alpha-preserving there instead
// of white-background) since both files can end up required in the same
// request and PHP has no per-file function scoping.
function resize_damage_image_within_bounds($source, int $maxWidth, int $maxHeight)
{
    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min($maxWidth / $width, $maxHeight / $height, 1);

    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));

    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

    return $target;
}

// Refuses to touch anything outside the expected directory, as defense
// in depth even if damage_image_url somehow held an unexpected value —
// same pattern as delete_old_logo_file() in Logo.php.
function delete_old_damage_image(?string $path): void
{
    if (!$path || !str_starts_with($path, DAMAGE_IMAGE_URL_PREFIX)) {
        return;
    }

    $full = DAMAGE_IMAGE_UPLOAD_DIR . '/' . basename($path);

    if (is_file($full)) {
        unlink($full);
    }
}
