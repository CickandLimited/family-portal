<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Intervention\Image\Constraint;
use Intervention\Image\Exception\NotReadableException;
use Intervention\Image\Image;
use Intervention\Image\ImageManager;
use Throwable;

final class ImageProcessor
{
    private const MAX_DIMENSION = 1600;
    private const THUMB_DIMENSION = 400;

    public function __construct(
        private readonly ImageManager $imageManager,
        private readonly string $originalDirectory,
        private readonly string $thumbDirectory,
    ) {
    }

    /**
     * @return array{file: string, thumb: string}
     */
    public function process(UploadedFile $file): array
    {
        $this->prepareDirectories();

        $size = $file->getSize();
        if ($size === null || $size <= 0) {
            throw new ImageProcessingException('empty_upload', 'Uploaded file is empty.');
        }

        $fileId = (string) Str::uuid();
        $originalPath = $this->buildPath($this->originalDirectory, sprintf('%s.webp', $fileId));
        $thumbPath = $this->buildPath($this->thumbDirectory, sprintf('%s.webp', $fileId));

        $createdPaths = [];
        $baseImage = null;
        $primary = null;
        $thumb = null;

        try {
            $baseImage = $this->readImage($file);
            $baseImage->orientate();

            $primary = $this->resizeToFit(clone $baseImage, self::MAX_DIMENSION);
            $thumb = $this->resizeToFit(clone $baseImage, self::THUMB_DIMENSION);

            $primary->encode('webp', 85)->save($originalPath);
            $createdPaths[] = $originalPath;

            $thumb->encode('webp', 80)->save($thumbPath);
            $createdPaths[] = $thumbPath;
        } catch (ImageProcessingException $exception) {
            $this->cleanupPaths($createdPaths);
            throw $exception;
        } catch (Throwable $exception) {
            $this->cleanupPaths($createdPaths);
            throw new ImageProcessingException('processing_error', 'Unable to process uploaded image.');
        } finally {
            $this->destroyImage($thumb);
            $this->destroyImage($primary);
            $this->destroyImage($baseImage);
        }

        return [
            'file' => $originalPath,
            'thumb' => $thumbPath,
        ];
    }

    private function prepareDirectories(): void
    {
        $this->ensureDirectory($this->originalDirectory);
        $this->ensureDirectory($this->thumbDirectory);
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new ImageProcessingException('storage_error', 'Unable to prepare upload directories.');
        }
    }

    private function buildPath(string $directory, string $filename): string
    {
        return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($filename, DIRECTORY_SEPARATOR);
    }

    private function readImage(UploadedFile $file): Image
    {
        try {
            return $this->imageManager->make($file->getRealPath());
        } catch (NotReadableException $exception) {
            throw new ImageProcessingException('invalid_image', 'Uploaded file is not a recognized image.');
        }
    }

    private function resizeToFit(Image $image, int $maxDimension): Image
    {
        $image->resize($maxDimension, $maxDimension, function (Constraint $constraint): void {
            $constraint->aspectRatio();
            $constraint->upsize();
        });

        return $image;
    }

    private function destroyImage(?Image $image): void
    {
        if ($image instanceof Image) {
            $image->destroy();
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
