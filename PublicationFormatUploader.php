<?php

use APP\core\Application;
use APP\facades\Repo;
use PKP\db\DAORegistry;
use PKP\submissionFile\SubmissionFile;

require_once 'FileProcessor.php';
require_once 'PublicationFormatManager.php';
require_once 'DependentFileManager.php';

class PublicationFormatUploader
{
    private $zipArchive;
    private $temporaryFilePath;
    private const SEPARATOR = '-';
    private const EXCLUDED_PATHS = [
        '__MACOSX',
    ];

    private $fileProcessor;
    private $publicationFormatManager;
    private $dependentFileManager;

    public function __construct($temporaryFilePath, $zipArchive)
    {
        $this->zipArchive = $zipArchive;
        $this->temporaryFilePath = $temporaryFilePath;
        $this->fileProcessor = new FileProcessor($this->zipArchive, $this->temporaryFilePath);
        $this->publicationFormatManager = new PublicationFormatManager();
        $this->dependentFileManager = new DependentFileManager();
    }

    /**
     * @return array{errors: array<string>, successMessages: array<string>}
     */
    public function uploadFile(): array
    {
        $results = [
            'errors' => [],
            'successMessages' => [],
        ];

        $zipOpened = $this->zipArchive->open($this->temporaryFilePath);
        if ($zipOpened !== true) {
            $results['errors'][] = $zipOpened === ZipArchive::ER_NOZIP
                ? __('plugins.generic.publicationFormatsUploader.error.notZip')
                : __('plugins.generic.publicationFormatsUploader.error.cannotOpen');
            return $results;
        }

        try {
            $blockedEntryIndexes = $this->findDuplicateTargetIndexes($results);
            $mainProofs = $this->processMainFiles($results, $blockedEntryIndexes);
            $this->processDependentFiles($mainProofs, $results, $blockedEntryIndexes);
        } finally {
            $this->zipArchive->close();
        }

        return $results;
    }

    private function isValidFile($fileName): bool
    {
        if ($fileName === '.' || $fileName === '..') {
            return false;
        }

        foreach (self::EXCLUDED_PATHS as $excluded) {
            if (stripos($fileName, $excluded) !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Reject every archive entry involved in a duplicate logical target before
     * any proof or dependent file is changed.
     *
     * @param array{errors: array<string>, successMessages: array<string>} $results
     *
     * @return array<int, bool>
     */
    private function findDuplicateTargetIndexes(array &$results): array
    {
        $targets = [];

        for ($i = 0; $i < $this->zipArchive->numFiles; $i++) {
            try {
                $currentFileName = $this->zipArchive->getNameIndex($i);
                if (!is_string($currentFileName) || !$this->isValidFile($currentFileName)) {
                    continue;
                }

                if ($this->fileProcessor->isFolder($this->zipArchive->statIndex($i))) {
                    continue;
                }

                $fileInfo = $this->fileProcessor->getFileInfo($currentFileName);
                $label = strtoupper($fileInfo['extension']);
                if ($label === '' || strrpos($fileInfo['fileName'], self::SEPARATOR) === false) {
                    continue;
                }

                $submissionData = $this->extractSubmissionData($fileInfo['fileName']);
                $submissionId = $submissionData['submissionId'];
                $chapterId = $submissionData['chapterId'];
                $localeToken = $submissionData['explicitLocale'];
                $submission = $localeToken === null
                    ? Repo::submission()->get($submissionId, $this->getContext()->getId())
                    : null;
                $language = $this->resolveLanguage($localeToken, $submission);
                $proofTarget = $this->getParentProofTarget($submissionId, $chapterId, $language);
                if ($label === 'JPG' || $label === 'CSS') {
                    $target = 'dependent:' . $proofTarget . ':' . strtolower($fileInfo['fileBase']);
                } else {
                    $target = 'proof:' . $proofTarget . ':'
                        . $this->publicationFormatManager->normalizeIdentifier($fileInfo['extension']);
                }

                $targets[$target][] = ['index' => $i, 'file' => $currentFileName];
            } catch (Throwable $exception) {
                // The normal processing passes report malformed entries once.
            }
        }

        $blockedEntryIndexes = [];
        foreach ($targets as $entries) {
            if (count($entries) < 2) {
                continue;
            }

            foreach ($entries as $entry) {
                $blockedEntryIndexes[$entry['index']] = true;
            }
            $results['errors'][] = __(
                'plugins.generic.publicationFormatsUploader.error.duplicateTarget',
                ['files' => implode(', ', array_column($entries, 'file'))]
            );
        }

        return $blockedEntryIndexes;
    }

    /**
     * @param array{errors: array<string>, successMessages: array<string>} $results
     *
     * @return array<string, array<int, int>> HTML/XML proof file IDs keyed by
     *   submission, chapter and language
     */
    private function processMainFiles(array &$results, array $blockedEntryIndexes): array
    {
        $mainProofs = [];

        for ($i = 0; $i < $this->zipArchive->numFiles; $i++) {
            if (isset($blockedEntryIndexes[$i])) {
                continue;
            }

            $currentFileName = $this->zipArchive->getNameIndex($i);
            $submission = null;
            $format = null;
            $formatCreated = false;
            $fileId = null;

            try {
                if (!is_string($currentFileName) || !$this->isValidFile($currentFileName)) {
                    continue;
                }

                $currentFileStat = $this->zipArchive->statIndex($i);
                if ($this->fileProcessor->isFolder($currentFileStat)) {
                    continue;
                }

                $fileInfo = $this->fileProcessor->getFileInfo($currentFileName);
                $label = strtoupper($fileInfo['extension']);
                if ($label === '' || $label === 'JPG' || $label === 'CSS') {
                    continue;
                }
                if (strrpos($fileInfo['fileName'], self::SEPARATOR) === false) {
                    continue;
                }

                $submissionData = $this->extractSubmissionData($fileInfo['fileName']);
                $submissionId = $submissionData['submissionId'];
                $chapterId = $submissionData['chapterId'];
                $localeToken = $submissionData['explicitLocale'];
                $submission = Repo::submission()->get($submissionId, $this->getContext()->getId());
                if (!$submission) {
                    throw new RuntimeException(
                        __('plugins.generic.publicationFormatsUploader.error.submissionNotFound', [
                            'submissionId' => $submissionId,
                        ])
                    );
                }
                $language = $this->resolveLanguage($localeToken, $submission);

                $publication = $submission->getLatestPublication();
                if (!$publication) {
                    throw new RuntimeException(
                        __('plugins.generic.publicationFormatsUploader.error.publicationNotFound', [
                            'submissionId' => $submissionId,
                        ])
                    );
                }
                $this->validateChapter($chapterId, $publication, $submissionId);

                [$format, $formatCreated] = $this->publicationFormatManager->getOrCreateFormat(
                    $publication,
                    $fileInfo['extension'],
                    $submission->getData('locale')
                );

                $existingProof = $this->findExistingProof($submission, $format, $language, $chapterId);
                $fileId = $this->fileProcessor->saveFileToRepo($submission, $fileInfo, $currentFileName);
                [$proof, $proofCreated] = $this->createOrReviseProof(
                    $fileId,
                    $submission,
                    $format,
                    $fileInfo,
                    $language,
                    $chapterId,
                    $existingProof
                );

                if ($formatCreated) {
                    $results['successMessages'][] = __(
                        'plugins.generic.publicationFormatsUploader.result.formatCreated',
                        ['format' => $label, 'submissionId' => $submissionId]
                    );
                }
                $results['successMessages'][] = __(
                    $proofCreated
                        ? 'plugins.generic.publicationFormatsUploader.result.proofCreated'
                        : 'plugins.generic.publicationFormatsUploader.result.proofRevised',
                    [
                        'format' => $label,
                        'submissionId' => $submissionId,
                        'locale' => $language,
                    ]
                );

                if ($label === 'HTML' || $label === 'XML') {
                    $proofTarget = $this->getParentProofTarget(
                        $submission->getId(),
                        $chapterId,
                        $language
                    );
                    $mainProofs[$proofTarget][$proof->getId()] = $proof->getId();
                }
            } catch (Throwable $exception) {
                $cleanupErrors = [];
                if ($fileId) {
                    try {
                        $this->fileProcessor->deleteStoredFileIfUnreferenced($fileId);
                    } catch (Throwable $cleanupException) {
                        $cleanupErrors[] = $cleanupException->getMessage();
                    }
                }
                if ($formatCreated && $format && $submission) {
                    try {
                        $this->publicationFormatManager->deleteCreatedFormatIfUnused(
                            $format,
                            $submission,
                            $this->getContext()
                        );
                    } catch (Throwable $cleanupException) {
                        $cleanupErrors[] = $cleanupException->getMessage();
                    }
                }

                $message = $exception->getMessage();
                if ($cleanupErrors) {
                    $message .= ' Cleanup failed: ' . implode('; ', $cleanupErrors);
                }
                $results['errors'][] = __(
                    'plugins.generic.publicationFormatsUploader.error.file',
                    ['file' => (string) $currentFileName, 'message' => $message]
                );
            }
        }

        return $mainProofs;
    }

    private function findExistingProof($submission, $format, string $language, ?int $chapterId)
    {
        $exactMatches = [];
        $proofs = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PROOF])
            ->filterByAssoc(
                Application::ASSOC_TYPE_PUBLICATION_FORMAT,
                [$format->getId()]
            )
            ->getMany();

        foreach ($proofs as $proof) {
            if ($this->proofMatchesTarget($proof, $language, $chapterId)) {
                $exactMatches[] = $proof;
            }
        }

        if (count($exactMatches) > 1) {
            throw new RuntimeException(
                __('plugins.generic.publicationFormatsUploader.error.ambiguousProof', [
                    'format' => $this->getFormatLabel($format),
                    'locale' => $language,
                ])
            );
        }
        return count($exactMatches) === 1 ? $exactMatches[0] : null;
    }

    /**
     * @return array{0: SubmissionFile, 1: bool}
     */
    private function createOrReviseProof(
        int $fileId,
        $submission,
        $format,
        array $fileInfo,
        string $language,
        ?int $chapterId,
        $existingProof
    ): array {
        if ($existingProof) {
            Repo::submissionFile()->edit($existingProof, [
                'fileId' => $fileId,
                'language' => $language,
                'chapterId' => $chapterId,
                'viewable' => true,
            ]);
            $proof = Repo::submissionFile()->get($existingProof->getId());
            $this->assertProofMatchesTarget($proof, $language, $chapterId);
            return [$proof, false];
        }

        $submissionLocale = $submission->getData('locale');
        $submissionFile = Repo::submissionFile()->newDataObject();
        $submissionFile->setData('fileId', $fileId);
        $submissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_PROOF);
        $submissionFile->setData('name', $fileInfo['fileBase'], $submissionLocale);
        $submissionFile->setData('submissionId', $submission->getId());
        $submissionFile->setData('submissionLocale', $submissionLocale);
        $submissionFile->setData('language', $language);
        $submissionFile->setData('chapterId', $chapterId);
        $submissionFile->setData('uploaderUserId', $this->getCurrentUserId());
        $submissionFile->setData('assocType', Application::ASSOC_TYPE_PUBLICATION_FORMAT);
        $submissionFile->setData('assocId', $format->getId());
        $submissionFile->setViewable(true);
        $submissionFile->setDirectSalesPrice(0);
        $submissionFile->setSalesType('openAccess');

        $genreDao = DAORegistry::getDAO('GenreDAO');
        $genre = $genreDao->getByKey('MANUSCRIPT', $this->getContext()->getId());
        if (!$genre) {
            throw new RuntimeException(
                __('plugins.generic.publicationFormatsUploader.error.manuscriptGenreMissing')
            );
        }
        $submissionFile->setData('genreId', $genre->getId());

        $submissionFileId = Repo::submissionFile()->add($submissionFile);
        $proof = Repo::submissionFile()->get($submissionFileId);
        try {
            $this->assertProofMatchesTarget($proof, $language, $chapterId);
        } catch (Throwable $exception) {
            if ($proof) {
                Repo::submissionFile()->delete($proof);
            }
            throw $exception;
        }
        return [$proof, true];
    }

    private function proofMatchesTarget($proof, string $language, ?int $chapterId): bool
    {
        if (!$proof) {
            return false;
        }

        $proofChapterId = $proof->getData('chapterId');
        $proofHasChapter = $proofChapterId !== null && $proofChapterId !== '';
        if ($chapterId === null) {
            if ($proofHasChapter) {
                return false;
            }
        } elseif (!$proofHasChapter || (int) $proofChapterId !== $chapterId) {
            return false;
        }

        $proofLanguage = $proof->getData('language');
        return is_string($proofLanguage) && strcasecmp($proofLanguage, $language) === 0;
    }

    private function assertProofMatchesTarget($proof, string $language, ?int $chapterId): void
    {
        if (!$this->proofMatchesTarget($proof, $language, $chapterId)) {
            throw new RuntimeException(
                __('plugins.generic.publicationFormatsUploader.error.proofTargetMismatch')
            );
        }
    }

    private function processDependentFiles(
        array $mainProofs,
        array &$results,
        array $blockedEntryIndexes
    ): void
    {
        $replacementStates = [];

        for ($i = 0; $i < $this->zipArchive->numFiles; $i++) {
            if (isset($blockedEntryIndexes[$i])) {
                continue;
            }

            $currentFileName = $this->zipArchive->getNameIndex($i);
            $submissionId = null;
            $proofTarget = null;

            try {
                if (!is_string($currentFileName) || !$this->isValidFile($currentFileName)) {
                    continue;
                }

                $currentFileStat = $this->zipArchive->statIndex($i);
                if ($this->fileProcessor->isFolder($currentFileStat)) {
                    continue;
                }

                $fileInfo = $this->fileProcessor->getFileInfo($currentFileName);
                $label = strtoupper($fileInfo['extension']);
                if ($label === '' || ($label !== 'JPG' && $label !== 'CSS')) {
                    continue;
                }
                if (strrpos($fileInfo['fileName'], self::SEPARATOR) === false) {
                    continue;
                }

                $submissionData = $this->extractSubmissionData($fileInfo['fileName']);
                $submissionId = $submissionData['submissionId'];
                $chapterId = $submissionData['chapterId'];
                $localeToken = $submissionData['explicitLocale'];
                $submission = Repo::submission()->get($submissionId, $this->getContext()->getId());
                if (!$submission) {
                    throw new RuntimeException(
                        __('plugins.generic.publicationFormatsUploader.error.submissionNotFound', [
                            'submissionId' => $submissionId,
                        ])
                    );
                }
                $language = $this->resolveLanguage($localeToken, $submission);
                $publication = $submission->getLatestPublication();
                if (!$publication) {
                    throw new RuntimeException(
                        __('plugins.generic.publicationFormatsUploader.error.publicationNotFound', [
                            'submissionId' => $submissionId,
                        ])
                    );
                }
                $this->validateChapter($chapterId, $publication, $submissionId);

                $proofTarget = $this->getParentProofTarget($submissionId, $chapterId, $language);
                $parentProofIds = $mainProofs[$proofTarget] ?? [];
                if (empty($parentProofIds)) {
                    $results['errors'][] = __(
                        'plugins.generic.publicationFormatsUploader.error.dependentWithoutParent',
                        ['file' => $currentFileName, 'submissionId' => $submissionId]
                    );
                    continue;
                }

                if (!isset($replacementStates[$proofTarget])) {
                    $oldDependentFiles = [];
                    $stagedFileIds = [];
                    foreach ($parentProofIds as $parentProofId) {
                        $oldDependentFiles[$parentProofId] = $this->dependentFileManager
                            ->getDependentFiles($parentProofId, $submissionId);
                        $stagedFileIds[$parentProofId] = [];
                    }
                    $replacementStates[$proofTarget] = [
                        'submissionId' => $submissionId,
                        'failed' => false,
                        'oldDependentFiles' => $oldDependentFiles,
                        'stagedFileIds' => $stagedFileIds,
                        'successMessages' => [],
                    ];
                }

                if ($replacementStates[$proofTarget]['failed']) {
                    continue;
                }

                $genreId = $this->dependentFileManager->getGenreId(
                    $fileInfo,
                    $this->getContext()->getId()
                );
                foreach ($parentProofIds as $parentProofId) {
                    $fileId = $this->fileProcessor->saveFileToRepo(
                        $submission,
                        $fileInfo,
                        $currentFileName
                    );
                    $replacementStates[$proofTarget]['stagedFileIds'][$parentProofId][] = $fileId;
                    $this->dependentFileManager->createDependentFile(
                        $fileId,
                        $submission,
                        $parentProofId,
                        $fileInfo,
                        $this->getCurrentUserId(),
                        $genreId
                    );

                    $replacementStates[$proofTarget]['successMessages'][] = __(
                        'plugins.generic.publicationFormatsUploader.result.dependentCreated',
                        ['file' => $fileInfo['fileBase'], 'submissionId' => $submissionId]
                    );
                }
            } catch (Throwable $exception) {
                if ($proofTarget !== null && isset($replacementStates[$proofTarget])) {
                    $replacementStates[$proofTarget]['failed'] = true;
                }
                $results['errors'][] = __(
                    'plugins.generic.publicationFormatsUploader.error.file',
                    ['file' => (string) $currentFileName, 'message' => $exception->getMessage()]
                );
            }
        }

        foreach ($replacementStates as $state) {
            $submissionId = $state['submissionId'];
            if ($state['failed']) {
                foreach ($state['stagedFileIds'] as $parentProofId => $fileIds) {
                    foreach ($fileIds as $fileId) {
                        $cleanupErrors = [];
                        try {
                            $this->dependentFileManager->deleteStagedDependentByFileId(
                                $fileId,
                                $parentProofId,
                                $submissionId
                            );
                        } catch (Throwable $exception) {
                            $cleanupErrors[] = $exception->getMessage();
                        }
                        try {
                            $this->fileProcessor->deleteStoredFileIfUnreferenced($fileId);
                        } catch (Throwable $exception) {
                            $cleanupErrors[] = $exception->getMessage();
                        }
                        if ($cleanupErrors) {
                            $results['errors'][] = __(
                                'plugins.generic.publicationFormatsUploader.error.dependentReplacement',
                                ['submissionId' => $submissionId, 'message' => implode('; ', $cleanupErrors)]
                            );
                        }
                    }
                }
                continue;
            }

            try {
                foreach ($state['oldDependentFiles'] as $dependentFiles) {
                    $this->dependentFileManager->deleteDependentFiles($dependentFiles);
                }
            } catch (Throwable $exception) {
                $results['errors'][] = __(
                    'plugins.generic.publicationFormatsUploader.error.dependentReplacement',
                    ['submissionId' => $submissionId, 'message' => $exception->getMessage()]
                );
            }

            array_push($results['successMessages'], ...$state['successMessages']);
        }
    }

    /**
     * Parse the filename suffix without constraining hyphens in the name.
     *
     * @return array{submissionId: int, chapterId: int|null, explicitLocale: string|null}
     */
    private function extractSubmissionData(string $fileName): array
    {
        $parts = explode(self::SEPARATOR, $fileName);
        if (count($parts) < 2) {
            throw new RuntimeException(
                __('plugins.generic.publicationFormatsUploader.error.invalidFileName')
            );
        }

        $lastPart = (string) array_pop($parts);
        $explicitLocale = null;
        if (preg_match('/^[1-9][0-9]*$/D', $lastPart)) {
            $submissionIdToken = $lastPart;
            if (count($parts) >= 2 && $this->isLocaleToken((string) end($parts))) {
                $explicitLocale = (string) array_pop($parts);
            }
        } else {
            if (!$this->isLocaleToken($lastPart) || !$parts) {
                throw new RuntimeException(
                    __('plugins.generic.publicationFormatsUploader.error.invalidSubmissionId', [
                        'submissionId' => $lastPart,
                    ])
                );
            }
            $explicitLocale = $lastPart;
            $submissionIdToken = (string) array_pop($parts);
        }

        if (
            !preg_match('/^[1-9][0-9]*$/D', $submissionIdToken)
            || (string) (int) $submissionIdToken !== $submissionIdToken
        ) {
            throw new RuntimeException(
                __('plugins.generic.publicationFormatsUploader.error.invalidSubmissionId', [
                    'submissionId' => $submissionIdToken,
                ])
            );
        }

        $chapterId = null;
        if ($parts && preg_match('/^cap([0-9]+)$/D', (string) end($parts), $matches)) {
            array_pop($parts);
            if (
                !preg_match('/^[1-9][0-9]*$/D', $matches[1])
                || (string) (int) $matches[1] !== $matches[1]
            ) {
                throw new RuntimeException(
                    __('plugins.generic.publicationFormatsUploader.error.invalidChapterId', [
                        'chapterId' => $matches[1],
                    ])
                );
            }
            $chapterId = (int) $matches[1];
        }

        if (!$parts || implode(self::SEPARATOR, $parts) === '') {
            throw new RuntimeException(
                __('plugins.generic.publicationFormatsUploader.error.invalidFileName')
            );
        }

        return [
            'submissionId' => (int) $submissionIdToken,
            'chapterId' => $chapterId,
            'explicitLocale' => $explicitLocale,
        ];
    }

    private function isLocaleToken(string $token): bool
    {
        return strcasecmp($token, 'cap') !== 0
            && preg_match('/^[a-z]{2,3}(?:_[a-z0-9]{2,8})*$/iD', $token) === 1;
    }

    private function resolveLanguage(?string $localeToken, $submission = null): string
    {
        $language = $localeToken === null
            ? (string) ($submission ? $submission->getData('locale') : '')
            : $localeToken;
        $supportedLocales = (array) $this->getContext()->getSupportedSubmissionLocales();

        if ($language === '' || !in_array($language, $supportedLocales, true)) {
            throw new RuntimeException(
                __('plugins.generic.publicationFormatsUploader.error.invalidLocale', [
                    'locale' => $language,
                ])
            );
        }

        return $language;
    }

    private function validateChapter(?int $chapterId, $publication, int $submissionId): void
    {
        if ($chapterId === null) {
            return;
        }

        $chapterDao = DAORegistry::getDAO('ChapterDAO');
        $chapter = $chapterDao->getChapter($chapterId);
        if (!$chapter) {
            throw new RuntimeException(
                __('plugins.generic.publicationFormatsUploader.error.chapterNotFound', [
                    'chapterId' => $chapterId,
                ])
            );
        }
        if (!$chapterDao->getChapter($chapterId, (int) $publication->getId())) {
            throw new RuntimeException(
                __('plugins.generic.publicationFormatsUploader.error.chapterNotInPublication', [
                    'chapterId' => $chapterId,
                    'submissionId' => $submissionId,
                ])
            );
        }
    }

    private function getParentProofTarget(int $submissionId, ?int $chapterId, string $language): string
    {
        return $submissionId . ':'
            . ($chapterId === null ? 'book' : 'chapter-' . $chapterId) . ':'
            . strtolower($language);
    }

    private function getContext()
    {
        return Application::get()->getRequest()->getContext();
    }

    private function getFormatLabel($format): string
    {
        $name = $format->getLocalizedName();
        return is_string($name) && $name !== '' ? strtoupper($name) : (string) $format->getId();
    }

    private function getCurrentUserId(): int
    {
        return Application::get()->getRequest()->getUser()->getId();
    }
}
