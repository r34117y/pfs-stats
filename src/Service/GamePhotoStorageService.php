<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class GamePhotoStorageService
{
    private const int MAX_DIMENSION = 2000;
    private const int JPEG_QUALITY = 84;
    private const string PUBLIC_PREFIX = '/uploads/game-photos/';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function storeCompressedPhoto(UploadedFile $uploadedFile, int $gameId): string
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
        $targetImage = $this->createResizedImage($sourceImage);

        $uploadDir = $this->getUploadDirectoryPath();
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            imagedestroy($sourceImage);
            imagedestroy($targetImage);
            throw new BadRequestHttpException('Failed to create upload directory.');
        }

        $fileName = sprintf('game-%d-%s.jpg', $gameId, bin2hex(random_bytes(8)));
        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
        $saved = imagejpeg($targetImage, $targetPath, self::JPEG_QUALITY);

        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        if (!$saved) {
            throw new BadRequestHttpException('Failed to save uploaded photo.');
        }

        return self::PUBLIC_PREFIX . $fileName;
    }

    private function getUploadDirectoryPath(): string
    {
        return $this->projectDir . '/public/uploads/game-photos';
    }

    private function createResizedImage(\GdImage $sourceImage): \GdImage
    {
        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            throw new BadRequestHttpException('Invalid image dimensions.');
        }

        $scale = min(
            1,
            self::MAX_DIMENSION / $sourceWidth,
            self::MAX_DIMENSION / $sourceHeight,
        );

        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));

        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($targetImage, 0, 0, imagecolorallocate($targetImage, 255, 255, 255));

        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
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
