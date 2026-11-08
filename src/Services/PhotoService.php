<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Contact profile photos. Same security model as AttachmentService (files
 * outside the web root under random names, finfo-checked), plus GD
 * processing: every accepted image is decoded, EXIF-rotated, center-cropped
 * square, resized to SIZE and re-encoded as JPEG. Re-encoding strips EXIF
 * and GPS metadata and means nothing a user uploads is ever served
 * byte-for-byte.
 */
final class PhotoService
{
    public const MAX_BYTES = 8_388_608; // 8 MB
    public const MAX_PIXELS = 16_000_000; // decoded-bitmap guard (~4000x4000)
    public const SIZE = 400;

    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(private readonly string $storageDir)
    {
    }

    public static function forApp(): self
    {
        return new self(dirname(__DIR__, 2) . '/storage/photos');
    }

    /**
     * @throws InvalidArgumentException with a user-presentable message
     */
    public function validate(string $tmpPath, int $size, int $errorCode): void
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('Image processing (GD) is not available on this server.');
        }

        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(sprintf('The photo failed to upload (code %d). Try again.', $errorCode));
        }

        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('The photo is too large; the limit is 8 MB.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        if (!in_array((string) $finfo->file($tmpPath), self::ALLOWED_MIME, true)) {
            throw new InvalidArgumentException('That file is not a JPEG, PNG, WebP, or GIF image.');
        }

        // Guard the DECODED size, not just the bytes: a small compressed
        // file can need hundreds of MB as a GD bitmap and would die as an
        // out-of-memory 500 instead of this friendly error.
        $dims = @getimagesize($tmpPath);

        if ($dims === false) {
            throw new InvalidArgumentException('That image could not be read; try a different file.');
        }

        if ((int) $dims[0] * (int) $dims[1] > self::MAX_PIXELS) {
            throw new InvalidArgumentException(
                'That image has too many pixels to process here; crop or resize it first.'
            );
        }
    }

    /**
     * Decode, orient, square-crop, resize, re-encode. Returns the stored
     * filename.
     *
     * @throws InvalidArgumentException when the bytes do not decode
     */
    public function store(string $tmpPath): string
    {
        $data = (string) file_get_contents($tmpPath);
        $image = @imagecreatefromstring($data);

        if ($image === false) {
            throw new InvalidArgumentException('That image could not be read; try a different file.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $image = $this->applyExifOrientation($image, $tmpPath, (string) $finfo->file($tmpPath));
        $image = $this->cropSquare($image);

        // Flatten onto white (PNG/WebP transparency) at the final size.
        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled(
            $canvas,
            $image,
            0,
            0,
            0,
            0,
            self::SIZE,
            self::SIZE,
            imagesx($image),
            imagesy($image),
        );

        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0750, true) && !is_dir($this->storageDir)) {
            throw new RuntimeException('Could not create the photo storage directory.');
        }

        $storedName = bin2hex(random_bytes(16)) . '.jpg';

        if (!imagejpeg($canvas, $this->storageDir . '/' . $storedName, 85)) {
            throw new RuntimeException('Could not save the processed photo.');
        }

        return $storedName;
    }

    /**
     * Downsized copy of a stored photo for email embedding, written to a
     * temp file the caller deletes after sending. Null when the stored
     * file is missing.
     */
    public function thumbnail(string $storedName, int $size = 48): ?string
    {
        $source = $this->path($storedName);

        if (!is_file($source)) {
            return null;
        }

        $image = @imagecreatefromjpeg($source);

        if ($image === false) {
            return null;
        }

        // imagecopyresampled rather than imagescale: the latter fails
        // outright with IMG_BICUBIC on some GD builds (caught by tests).
        $small = imagecreatetruecolor($size, $size);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $size, $size, imagesx($image), imagesy($image));

        $tmp = (string) tempnam(sys_get_temp_dir(), 'rolo-thumb-');
        imagejpeg($small, $tmp, 82);

        return $tmp;
    }

    public function path(string $storedName): string
    {
        return $this->storageDir . '/' . basename($storedName);
    }

    public function delete(string $storedName): void
    {
        $path = $this->path($storedName);

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Honor the EXIF orientation of JPEGs (phone photos are often stored
     * rotated), so the crop works on the image as the user sees it.
     */
    private function applyExifOrientation(\GdImage $image, string $tmpPath, string $mime): \GdImage
    {
        if (!function_exists('exif_read_data') || $mime !== 'image/jpeg') {
            return $image;
        }

        $exif = @exif_read_data($tmpPath);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated === false ? $image : $rotated;
    }

    private function cropSquare(\GdImage $image): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);

        if ($w === $h) {
            return $image;
        }

        $side = min($w, $h);
        $cropped = imagecrop($image, [
            'x' => (int) (($w - $side) / 2),
            'y' => (int) (($h - $side) / 2),
            'width' => $side,
            'height' => $side,
        ]);

        return $cropped === false ? $image : $cropped;
    }
}
