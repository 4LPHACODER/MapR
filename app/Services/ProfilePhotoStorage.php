<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfilePhotoStorage
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_EDGE = 4000;

    public function store(User $user, UploadedFile $file): void
    {
        $bytes = $this->normalizedBytes($file);
        $extension = $this->extension($bytes['mime']);
        $path = 'profile-photos/'.Str::uuid().'.'.$extension;
        $previous = is_string($user->profile_photo_path) ? $user->profile_photo_path : null;

        Storage::disk('local')->put($path, $bytes['contents']);

        try {
            $user->forceFill(['profile_photo_path' => $path])->save();
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        if ($previous !== null && $previous !== $path) {
            $this->deleteOwned($previous);
        }
    }

    public function remove(User $user): void
    {
        $previous = is_string($user->profile_photo_path) ? $user->profile_photo_path : null;
        $user->forceFill(['profile_photo_path' => null])->save();
        if ($previous !== null) {
            $this->deleteOwned($previous);
        }
    }

    public function deleteOwned(?string $path): void
    {
        if (! is_string($path) || ! Str::startsWith($path, 'profile-photos/')) {
            return;
        }

        Storage::disk('local')->delete($path);
    }

    /**
     * @return array{mime: string, contents: string}
     */
    private function normalizedBytes(UploadedFile $file): array
    {
        $source = $file->getRealPath();
        $size = $source ? (int) filesize($source) : 0;
        if ($size < 1) {
            $this->fail('The profile photo is empty.');
        }
        if ($size > self::MAX_BYTES) {
            $this->fail('The profile photo must be 2 MB or smaller.');
        }

        $contents = (string) file_get_contents($source);
        if (preg_match('/<\s*(svg|script|html)\b|<\?xml/i', $contents) === 1) {
            $this->fail('SVG images are not accepted.');
        }

        $info = @getimagesize($source);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $this->fail('Choose a JPEG, PNG, or WEBP image.');
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > self::MAX_EDGE || $height > self::MAX_EDGE) {
            $this->fail('The profile photo must be 4000 pixels or smaller on each side.');
        }

        $normalized = $this->reencode($source, $contents, $mime);
        if ($normalized === null) {
            $this->fail('The file is not a valid image.');
        }

        return ['mime' => $mime, 'contents' => $normalized];
    }

    private function reencode(string $source, string $contents, string $mime): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $contents;
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            return null;
        }

        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            $orientation = (int) ($exif['Orientation'] ?? 1);
            $rotated = $this->orient($image, $orientation);
            if ($rotated !== false) {
                $image = $rotated;
            }
        }

        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        ob_start();
        $encoded = match ($mime) {
            'image/png' => imagepng($image),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, null, 90) : false,
            default => imagejpeg($image, null, 90),
        };
        $output = ob_get_clean();

        if ($encoded === false || ! is_string($output) || $output === '') {
            return $mime === 'image/webp' ? $contents : null;
        }

        return $output;
    }

    private function orient(\GdImage|false $image, int $orientation): \GdImage|false
    {
        if ($image === false) {
            return false;
        }

        return match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['photo' => $message]);
    }
}
