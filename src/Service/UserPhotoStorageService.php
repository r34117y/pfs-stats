<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class UserPhotoStorageService
{
    private const TARGET_SIZE = 512;
    private const JPEG_QUALITY = 82;
    private const PUBLIC_PREFIX = '/uploads/user-photos/';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Stores compressed user photo in public/uploads/user-photos and returns public path.
     */
    public function storeCompressedPhoto(UploadedFile $uploadedFile, int $userId): string
    {
        if (!extension_loaded('gd')) {
            throw new BadRequestHttpException('Image processing extension is not available.');
        }

        $content = @file_get_contents($uploadedFile->getPathname());
        if ($content === false) {
            throw new BadRequestHttpException('Unable to read uploaded file.');
        }

        $sourceImage = @imagecreatefromstring($content);
        if ($sourceImage === false) {
            throw new BadRequestHttpException('Unsupported image format.');
        }

        $sourceImage = $this->applyExifOrientation($sourceImage, $uploadedFile);
        $targetImage = $this->createSquareImage($sourceImage);

        $uploadDir = $this->getUploadDirectoryPath();
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            imagedestroy($sourceImage);
            imagedestroy($targetImage);
            throw new BadRequestHttpException('Failed to create upload directory.');
        }

        $fileName = sprintf('user-%d-%s.jpg', $userId, bin2hex(random_bytes(8)));
        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

        $saved = imagejpeg($targetImage, $targetPath, self::JPEG_QUALITY);

        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        if (!$saved) {
            throw new BadRequestHttpException('Failed to save uploaded photo.');
        }

        return self::PUBLIC_PREFIX . $fileName;
    }

    public function deleteManagedPhoto(?string $photoPath): void
    {
        if (!is_string($photoPath) || !str_starts_with($photoPath, self::PUBLIC_PREFIX)) {
            return;
        }

        $fullPath = $this->projectDir . '/public' . $photoPath;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    private function getUploadDirectoryPath(): string
    {
        return $this->projectDir . '/public/uploads/user-photos';
    }

    private function createSquareImage(\GdImage $sourceImage): \GdImage
    {
        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);
        if ($width <= 0 || $height <= 0) {
            throw new BadRequestHttpException('Invalid image dimensions.');
        }

        $cropSize = min($width, $height);
        $sourceX = (int) floor(($width - $cropSize) / 2);
        $sourceY = (int) floor(($height - $cropSize) / 2);

        $targetImage = imagecreatetruecolor(self::TARGET_SIZE, self::TARGET_SIZE);
        imagefill($targetImage, 0, 0, imagecolorallocate($targetImage, 255, 255, 255));

        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            $sourceX,
            $sourceY,
            self::TARGET_SIZE,
            self::TARGET_SIZE,
            $cropSize,
            $cropSize
        );

        return $targetImage;
    }

    private function applyExifOrientation(\GdImage $sourceImage, UploadedFile $uploadedFile): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $sourceImage;
        }

        $mimeType = $uploadedFile->getMimeType();
        if (!is_string($mimeType) || !str_starts_with($mimeType, 'image/jpeg')) {
            return $sourceImage;
        }

        $exifData = @exif_read_data($uploadedFile->getPathname());
        if (!is_array($exifData)) {
            return $sourceImage;
        }

        $orientation = (int) ($exifData['Orientation'] ?? 1);

        return match ($orientation) {
            3 => $this->rotateImage($sourceImage, 180),
            6 => $this->rotateImage($sourceImage, -90),
            8 => $this->rotateImage($sourceImage, 90),
            default => $sourceImage,
        };
    }

    private function rotateImage(\GdImage $sourceImage, int $degrees): \GdImage
    {
        $rotated = imagerotate($sourceImage, $degrees, 0);
        if (!$rotated instanceof \GdImage) {
            return $sourceImage;
        }

        imagedestroy($sourceImage);

        return $rotated;
    }
}
