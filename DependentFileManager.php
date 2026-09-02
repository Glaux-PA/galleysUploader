<?php

use APP\core\Application;
use APP\facades\Repo;
use PKP\db\DAORegistry;
use PKP\submissionFile\SubmissionFile;

class DependentFileManager
{
    public function createDependentFile(
        int $fileId,
        $submission,
        int $parentProofFileId,
        array $fileInfo,
        int $uploaderUserId,
        int $genreId
    ) {
        $submissionFile = Repo::submissionFile()->newDataObject();
        $submissionLocale = $submission->getData('locale');

        $submissionFile->setData('fileId', $fileId);
        $submissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_DEPENDENT);
        $submissionFile->setData('name', $fileInfo['fileBase'], $submissionLocale);
        $submissionFile->setData('submissionId', $submission->getId());
        $submissionFile->setData('submissionLocale', $submissionLocale);
        $submissionFile->setData('uploaderUserId', $uploaderUserId);
        $submissionFile->setData('assocType', Application::ASSOC_TYPE_SUBMISSION_FILE);
        $submissionFile->setData('assocId', $parentProofFileId);
        $submissionFile->setViewable(true);
        $submissionFile->setData('genreId', $genreId);

        $submissionFileId = Repo::submissionFile()->add($submissionFile);
        return Repo::submissionFile()->get($submissionFileId);
    }

    public function getGenreId(array $fileInfo, int $contextId): int
    {
        $genreKey = strtoupper($fileInfo['extension']) === 'JPG' ? 'IMAGE' : 'STYLE';
        $genre = DAORegistry::getDAO('GenreDAO')->getByKey($genreKey, $contextId);
        if (!$genre) {
            throw new RuntimeException(
                __('plugins.importexport.publicationFormatsUploader.error.dependentGenreMissing', [
                    'genre' => $genreKey,
                ])
            );
        }
        return $genre->getId();
    }

    /** @return array<int, SubmissionFile> */
    public function getDependentFiles(int $parentProofFileId, int $submissionId): array
    {
        return Repo::submissionFile()
            ->getCollector()
            ->includeDependentFiles(true)
            ->filterBySubmissionIds([$submissionId])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_DEPENDENT])
            ->filterByAssoc(Application::ASSOC_TYPE_SUBMISSION_FILE, [$parentProofFileId])
            ->getMany()
            ->all();
    }

    /** @param array<int, SubmissionFile> $dependentFiles */
    public function deleteDependentFiles(array $dependentFiles): void
    {
        foreach ($dependentFiles as $dependentFile) {
            Repo::submissionFile()->delete($dependentFile);
        }
    }

    public function deleteStagedDependentByFileId(
        int $fileId,
        int $parentProofFileId,
        int $submissionId
    ): void {
        $files = Repo::submissionFile()
            ->getCollector()
            ->includeDependentFiles(true)
            ->filterByFileIds([$fileId])
            ->filterBySubmissionIds([$submissionId])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_DEPENDENT])
            ->filterByAssoc(Application::ASSOC_TYPE_SUBMISSION_FILE, [$parentProofFileId])
            ->getMany();

        foreach ($files as $file) {
            Repo::submissionFile()->delete($file);
        }
    }
}
