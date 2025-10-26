<?php

namespace App\Services\Uploads;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

final class ImageStorageService
{
    private const MAX_DIMENSION = 1600;
    private const THUMB_DIMENSION = 400;

    public function __construct(
        private readonly string $uploadsDirectory,
        private readonly string $thumbsDirectory,
    ) {
    }

    /**
     * @return array{file: string, thumb: string}
     */
    public function store(UploadedFile $file): array
    {
        $this->prepareDirectories();

        $contents = @file_get_contents($file->getRealPath());
        if ($contents === false || $contents === '') {
            throw new ImageStorageException('empty_upload', 'Uploaded file is empty.');
        }

        $imagesToDestroy = [];
        $createdPaths = [];

        try {
            $decoded = $this->decodeImage($contents);
            $imagesToDestroy[] = $decoded;

            $primary = $this->resizeToFit($decoded, self::MAX_DIMENSION);
            $imagesToDestroy[] = $primary;

            $thumb = $this->resizeToFit($decoded, self::THUMB_DIMENSION);
            $imagesToDestroy[] = $thumb;

            $fileId = (string) Str::uuid();
            $originalPath = $this->buildPath($this->uploadsDirectory, sprintf('%s.webp', $fileId));
            $thumbPath = $this->buildPath($this->thumbsDirectory, sprintf('%s.webp', $fileId));

            $this->saveWebp($primary, $originalPath, 85);
            $createdPaths[] = $originalPath;

            $this->saveWebp($thumb, $thumbPath, 80);
            $createdPaths[] = $thumbPath;
        } catch (ImageStorageException $exception) {
            $this->cleanupPaths($createdPaths);
            throw $exception;
        } catch (Throwable $exception) {
            $this->cleanupPaths($createdPaths);
            throw new ImageStorageException('processing_error', 'Unable to process uploaded image.');
        } finally {
            foreach ($imagesToDestroy as $image) {
                if ($image instanceof GdImage) {
                    imagedestroy($image);
                }
            }
        }

        return [
            'file' => $originalPath,
            'thumb' => $thumbPath,
        ];
    }

    private function prepareDirectories(): void
    {
        $this->ensureDirectory($this->uploadsDirectory);
        $this->ensureDirectory($this->thumbsDirectory);
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ImageStorageException('storage_error', 'Unable to prepare upload directories.');
        }
    }

    private function buildPath(string $directory, string $filename): string
    {
        return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    }

    private function decodeImage(string $contents): GdImage
    {
        $image = @imagecreatefromstring($contents);
        if (!($image instanceof GdImage)) {
            throw new ImageStorageException('invalid_image', 'Uploaded file is not a recognized image.');
        }

        return $this->ensureTrueColor($image);
    }

    private function ensureTrueColor(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);

        return $canvas;
    }

    private function resizeToFit(GdImage $source, int $maxDimension): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);

        $longest = max($width, $height);
        if ($longest <= $maxDimension) {
            return $this->cloneImage($source);
        }

        $scale = $maxDimension / $longest;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        return $this->resample($source, $targetWidth, $targetHeight);
    }

    private function cloneImage(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);

        return $this->resample($source, $width, $height);
    }

    private function resample(GdImage $source, int $targetWidth, int $targetHeight): GdImage
    {
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            imagesx($source),
            imagesy($source)
        );

        return $canvas;
    }

    private function saveWebp(GdImage $image, string $path, int $quality): void
    {
        if (!imagewebp($image, $path, $quality)) {
            throw new ImageStorageException('processing_error', 'Unable to write processed image.');
        }
    }

    /**
     * @param array<int, string> $paths
     */
    private function cleanupPaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }
    }
}
