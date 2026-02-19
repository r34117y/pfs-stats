<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class UserPhotoStorageService
{
    private const MAX_WIDTH = 512;
    private const MAX_HEIGHT = 512;
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

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);

        [$targetWidth, $targetHeight] = $this->calculateTargetSize($sourceWidth, $sourceHeight);

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

    /**
     * @return array{0: int, 1: int}
     */
    private function calculateTargetSize(int $width, int $height): array
    {
        if ($width <= 0 || $height <= 0) {
            throw new BadRequestHttpException('Invalid image dimensions.');
        }

        $ratio = min(
            self::MAX_WIDTH / $width,
            self::MAX_HEIGHT / $height,
            1.0
        );

        return [
            max(1, (int) round($width * $ratio)),
            max(1, (int) round($height * $ratio)),
        ];
    }
}
