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
    private const LOCALE_MAP = [
        'es' => 'es',
        'en' => 'en',
    ];
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
                ? __('plugins.importexport.publicationFormatsUploader.error.notZip')
                : __('plugins.importexport.publicationFormatsUploader.error.cannotOpen');
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

                [$submissionId, $localeToken] = $this->extractSubmissionData($fileInfo['fileName']);
                if ($label === 'JPG' || $label === 'CSS') {
                    $target = 'dependent:' . $submissionId . ':' . strtolower($fileInfo['fileBase']);
                } else {
                    $target = 'proof:' . $submissionId . ':'
                        . $this->publicationFormatManager->normalizeIdentifier($fileInfo['extension']) . ':'
                        . strtolower($this->resolveLanguage($localeToken));
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
                'plugins.importexport.publicationFormatsUploader.error.duplicateTarget',
                ['files' => implode(', ', array_column($entries, 'file'))]
            );
        }

        return $blockedEntryIndexes;
    }

    /**
     * @param array{errors: array<string>, successMessages: array<string>} $results
     *
     * @return array<int, array<int, int>> Proof file IDs keyed by submission ID
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

                [$submissionId, $localeToken] = $this->extractSubmissionData($fileInfo['fileName']);
                $language = $this->resolveLanguage($localeToken);
                $submission = Repo::submission()->get($submissionId, $this->getContext()->getId());
                if (!$submission) {
                    throw new RuntimeException(
                        __('plugins.importexport.publicationFormatsUploader.error.submissionNotFound', [
                            'submissionId' => $submissionId,
                        ])
                    );
                }

                $publication = $submission->getLatestPublication();
                if (!$publication) {
                    throw new RuntimeException(
                        __('plugins.importexport.publicationFormatsUploader.error.publicationNotFound', [
                            'submissionId' => $submissionId,
                        ])
                    );
                }

                [$format, $formatCreated] = $this->publicationFormatManager->getOrCreateFormat(
                    $publication,
                    $fileInfo['extension'],
                    $submission->getData('locale')
                );

                $existingProof = $this->findExistingProof($submission, $format, $language);
                $fileId = $this->fileProcessor->saveFileToRepo($submission, $fileInfo, $currentFileName);
                [$proof, $proofCreated] = $this->createOrReviseProof(
                    $fileId,
                    $submission,
                    $format,
                    $fileInfo,
                    $language,
                    $existingProof
                );

                if ($formatCreated) {
                    $results['successMessages'][] = __(
                        'plugins.importexport.publicationFormatsUploader.result.formatCreated',
                        ['format' => $label, 'submissionId' => $submissionId]
                    );
                }
                $results['successMessages'][] = __(
                    $proofCreated
                        ? 'plugins.importexport.publicationFormatsUploader.result.proofCreated'
                        : 'plugins.importexport.publicationFormatsUploader.result.proofRevised',
                    [
                        'format' => $label,
                        'submissionId' => $submissionId,
                        'locale' => $language,
                    ]
                );

                if ($label === 'HTML' || $label === 'XML') {
                    $mainProofs[$submission->getId()][$proof->getId()] = $proof->getId();
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
                    'plugins.importexport.publicationFormatsUploader.error.file',
                    ['file' => (string) $currentFileName, 'message' => $message]
                );
            }
        }

        return $mainProofs;
    }

    private function findExistingProof($submission, $format, string $language)
    {
        $exactMatches = [];
        $unspecifiedLanguage = [];
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
            if ($proof->getData('chapterId')) {
                continue;
            }

            $proofLanguage = $proof->getData('language');
            if (is_string($proofLanguage) && strcasecmp($proofLanguage, $language) === 0) {
                $exactMatches[] = $proof;
            } elseif (!$proofLanguage) {
                $unspecifiedLanguage[] = $proof;
            }
        }

        if (count($exactMatches) > 1) {
            throw new RuntimeException(
                __('plugins.importexport.publicationFormatsUploader.error.ambiguousProof', [
                    'format' => $this->getFormatLabel($format),
                    'locale' => $language,
                ])
            );
        }
        if (count($exactMatches) === 1) {
            return $exactMatches[0];
        }

        if ($language === $submission->getData('locale')) {
            if (count($unspecifiedLanguage) > 1) {
                throw new RuntimeException(
                    __('plugins.importexport.publicationFormatsUploader.error.ambiguousProof', [
                        'format' => $this->getFormatLabel($format),
                        'locale' => $language,
                    ])
                );
            }
            if (count($unspecifiedLanguage) === 1) {
                return $unspecifiedLanguage[0];
            }
        }

        return null;
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
        $existingProof
    ): array {
        if ($existingProof) {
            Repo::submissionFile()->edit($existingProof, [
                'fileId' => $fileId,
                'language' => $language,
            ]);
            return [Repo::submissionFile()->get($existingProof->getId()), false];
        }

        $submissionLocale = $submission->getData('locale');
        $submissionFile = Repo::submissionFile()->newDataObject();
        $submissionFile->setData('fileId', $fileId);
        $submissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_PROOF);
        $submissionFile->setData('name', $fileInfo['fileBase'], $submissionLocale);
        $submissionFile->setData('submissionId', $submission->getId());
        $submissionFile->setData('submissionLocale', $submissionLocale);
        $submissionFile->setData('language', $language);
        $submissionFile->setData('uploaderUserId', $this->getCurrentUserId());
        $submissionFile->setData('assocType', Application::ASSOC_TYPE_PUBLICATION_FORMAT);
        $submissionFile->setData('assocId', $format->getId());
        $submissionFile->setViewable(false);
        $submissionFile->setDirectSalesPrice(0);
        $submissionFile->setSalesType('openAccess');

        $genreDao = DAORegistry::getDAO('GenreDAO');
        $genre = $genreDao->getByKey('MANUSCRIPT', $this->getContext()->getId());
        if (!$genre) {
            throw new RuntimeException(
                __('plugins.importexport.publicationFormatsUploader.error.manuscriptGenreMissing')
            );
        }
        $submissionFile->setData('genreId', $genre->getId());

        $submissionFileId = Repo::submissionFile()->add($submissionFile);
        return [Repo::submissionFile()->get($submissionFileId), true];
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

                [$submissionId, $localeToken] = $this->extractSubmissionData($fileInfo['fileName']);
                $this->resolveLanguage($localeToken);
                $submission = Repo::submission()->get($submissionId, $this->getContext()->getId());
                if (!$submission) {
                    throw new RuntimeException(
                        __('plugins.importexport.publicationFormatsUploader.error.submissionNotFound', [
                            'submissionId' => $submissionId,
                        ])
                    );
                }

                $parentProofIds = $mainProofs[$submission->getId()] ?? [];
                if (empty($parentProofIds)) {
                    $results['errors'][] = __(
                        'plugins.importexport.publicationFormatsUploader.error.dependentWithoutParent',
                        ['file' => $currentFileName, 'submissionId' => $submissionId]
                    );
                    continue;
                }

                if (!isset($replacementStates[$submissionId])) {
                    $oldDependentFiles = [];
                    $stagedFileIds = [];
                    foreach ($parentProofIds as $parentProofId) {
                        $oldDependentFiles[$parentProofId] = $this->dependentFileManager
                            ->getDependentFiles($parentProofId, $submissionId);
                        $stagedFileIds[$parentProofId] = [];
                    }
                    $replacementStates[$submissionId] = [
                        'failed' => false,
                        'oldDependentFiles' => $oldDependentFiles,
                        'stagedFileIds' => $stagedFileIds,
                        'successMessages' => [],
                    ];
                }

                if ($replacementStates[$submissionId]['failed']) {
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
                    $replacementStates[$submissionId]['stagedFileIds'][$parentProofId][] = $fileId;
                    $this->dependentFileManager->createDependentFile(
                        $fileId,
                        $submission,
                        $parentProofId,
                        $fileInfo,
                        $this->getCurrentUserId(),
                        $genreId
                    );

                    $replacementStates[$submissionId]['successMessages'][] = __(
                        'plugins.importexport.publicationFormatsUploader.result.dependentCreated',
                        ['file' => $fileInfo['fileBase'], 'submissionId' => $submissionId]
                    );
                }
            } catch (Throwable $exception) {
                if ($submissionId !== null && isset($replacementStates[$submissionId])) {
                    $replacementStates[$submissionId]['failed'] = true;
                }
                $results['errors'][] = __(
                    'plugins.importexport.publicationFormatsUploader.error.file',
                    ['file' => (string) $currentFileName, 'message' => $exception->getMessage()]
                );
            }
        }

        foreach ($replacementStates as $submissionId => $state) {
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
                                'plugins.importexport.publicationFormatsUploader.error.dependentReplacement',
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
                    'plugins.importexport.publicationFormatsUploader.error.dependentReplacement',
                    ['submissionId' => $submissionId, 'message' => $exception->getMessage()]
                );
            }

            array_push($results['successMessages'], ...$state['successMessages']);
        }
    }

    /**
     * @return array{0: int, 1: string|null}
     */
    private function extractSubmissionData(string $fileName): array
    {
        $parts = explode(self::SEPARATOR, $fileName);
        if (count($parts) < 2) {
            throw new RuntimeException(
                __('plugins.importexport.publicationFormatsUploader.error.invalidFileName')
            );
        }

        $lastPart = array_pop($parts);
        $localeToken = null;
        if (!preg_match('/^[1-9][0-9]*$/', $lastPart)) {
            $localeToken = strtolower($lastPart);
            if (!$parts) {
                throw new RuntimeException(
                    __('plugins.importexport.publicationFormatsUploader.error.invalidFileName')
                );
            }
            $lastPart = array_pop($parts);
        }

        if (!preg_match('/^[1-9][0-9]*$/', $lastPart)) {
            throw new RuntimeException(
                __('plugins.importexport.publicationFormatsUploader.error.invalidSubmissionId', [
                    'submissionId' => $lastPart,
                ])
            );
        }

        return [(int) $lastPart, $localeToken];
    }

    private function resolveLanguage(?string $localeToken): string
    {
        if ($localeToken === null) {
            $language = (string) $this->getContext()->getPrimaryLocale();
            if ($language === '') {
                throw new RuntimeException(
                    __('plugins.importexport.publicationFormatsUploader.error.invalidLocale', [
                        'locale' => '',
                    ])
                );
            }
            return $language;
        }

        if (!isset(self::LOCALE_MAP[$localeToken])) {
            throw new RuntimeException(
                __('plugins.importexport.publicationFormatsUploader.error.invalidLocale', [
                    'locale' => $localeToken,
                ])
            );
        }

        return self::LOCALE_MAP[$localeToken];
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
