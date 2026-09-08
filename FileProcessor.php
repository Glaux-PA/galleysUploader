<?php

use APP\core\Application;
use APP\facades\Repo;
use PKP\file\TemporaryFileManager;

class FileProcessor
{
    private $zipArchive;
    private $temporaryFilePath;

    public function __construct($zipArchive, $temporaryFilePath)
    {
        $this->zipArchive = $zipArchive;
        $this->temporaryFilePath = $temporaryFilePath;
    }

    public function getFileInfo($fileName): array
    {
        $pathParts = pathinfo($fileName);
        return [
            'fileName' => $pathParts['filename'],
            'fileBase' => $pathParts['basename'],
            'extension' => $pathParts['extension'] ?? '',
            'dirName' => $pathParts['dirname'],
        ];
    }

    public function saveFileToRepo($submission, array $fileInfo, string $currentFileName): int
    {
        $temporaryFileManager = new TemporaryFileManager();
        $temporaryFilename = tempnam($temporaryFileManager->getBasePath(), 'src');
        if ($temporaryFilename === false) {
            throw new RuntimeException('Could not create a temporary extraction file.');
        }

        try {
            $stat = $this->zipArchive->statName($currentFileName);
            if ($stat === false || !isset($stat['size'])) {
                throw new RuntimeException('Could not read the ZIP entry metadata.');
            }

            $expectedSize = (int) $stat['size'];
            if ($expectedSize <= 0) {
                throw new RuntimeException('Zero-byte files cannot be uploaded as publication files.');
            }

            $contents = $this->zipArchive->getFromName($currentFileName);
            if ($contents === false) {
                throw new RuntimeException('Could not extract the ZIP entry.');
            }
            if (strlen($contents) !== $expectedSize) {
                throw new RuntimeException('The extracted ZIP entry size does not match its metadata.');
            }

            $writtenSize = file_put_contents($temporaryFilename, $contents);
            if ($writtenSize === false || $writtenSize !== $expectedSize) {
                throw new RuntimeException('Could not write the complete extracted file.');
            }

            $extension = strtolower((string) ($fileInfo['extension'] ?? ''));
            if (!preg_match('/^[a-z0-9]+$/', $extension)) {
                throw new RuntimeException('The file extension is not valid for storage.');
            }

            $submissionDir = Repo::submissionFile()->getSubmissionDir(
                Application::get()->getRequest()->getContext()->getId(),
                $submission->getId()
            );
            $storedPath = $submissionDir . '/' . uniqid() . '.' . $extension;
            $fileService = app()->get('file');
            $fileId = null;

            try {
                $fileId = $fileService->add($temporaryFilename, $storedPath);
                if ((!is_int($fileId) && !ctype_digit((string) $fileId)) || (int) $fileId <= 0) {
                    throw new RuntimeException('The file service did not return a valid file ID.');
                }

                $storedFile = $fileService->get((int) $fileId);
                if (!$storedFile || empty($storedFile->path)) {
                    throw new RuntimeException('The stored file could not be retrieved.');
                }

                $storedSize = $fileService->fs->fileSize($storedFile->path);
                if ($storedSize <= 0 || $storedSize !== $expectedSize) {
                    throw new RuntimeException('The stored file size does not match the extracted file.');
                }
            } catch (Throwable $exception) {
                if ($fileId && $fileService->get((int) $fileId)) {
                    $fileService->delete((int) $fileId);
                } elseif ($fileService->fs->has($storedPath)) {
                    $fileService->fs->delete($storedPath);
                }
                throw $exception;
            }

            return (int) $fileId;
        } finally {
            if (is_file($temporaryFilename)) {
                unlink($temporaryFilename);
            }
        }
    }

    public function deleteStoredFileIfUnreferenced(int $fileId): void
    {
        $referenceCount = Repo::submissionFile()
            ->getCollector()
            ->filterByFileIds([$fileId])
            ->includeDependentFiles(true)
            ->getCount();
        if ($referenceCount) {
            return;
        }

        $fileService = app()->get('file');
        if ($fileService->get($fileId)) {
            $fileService->delete($fileId);
        }
    }

    public function isFolder($zipFileStat): bool
    {
        if (!is_array($zipFileStat) || !isset($zipFileStat['name'])) {
            throw new RuntimeException('Could not read the ZIP entry metadata.');
        }
        return substr((string) $zipFileStat['name'], -1) === '/';
    }
}
