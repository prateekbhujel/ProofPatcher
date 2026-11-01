<?php

declare(strict_types=1);

namespace ProofPatch\Support;

/** Shared validation for task screenshots. Images stay local and are never sent to a model. */
final class TaskImageValidation
{
    public const MAX_BYTES = 2_000_000;
    public const MAX_PIXELS = 16_000_000;

    /** @return array{bytes: string, mime: string} */
    public static function validateUpload(mixed $file): array
    {
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
            || !$file->isValid() || !is_int($file->getSize()) || $file->getSize() < 1 || $file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Choose a PNG or JPEG image under 2 MB.');
        }
        $bytes = file_get_contents($file->getPathname(), false, null, 0, self::MAX_BYTES + 1);
        if ($bytes === false || strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Could not read the image.');
        }
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? ($info['mime'] ?? null) : null;
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)
            || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1
            || $info[0] * $info[1] > self::MAX_PIXELS
            || !function_exists('imagecreatefromstring')
            || ($decoded = @imagecreatefromstring($bytes)) === false) {
            throw new \InvalidArgumentException('Choose a valid PNG or JPEG image.');
        }
        imagedestroy($decoded);

        return ['bytes' => $bytes, 'mime' => $mime];
    }
}
