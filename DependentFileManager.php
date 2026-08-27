<?php

use APP\core\Application;
use APP\facades\Repo;
use PKP\submissionFile\SubmissionFile;

class DependentFileManager
{
    public function createDependentFile($fileId, $submission, $mainHTMLGalley, $fileInfo)
    {
        $submissionFile = Repo::submissionFile()->newDataObject();

        $submissionLocale = $submission->getData('locale');

        $submissionFile->setData('fileId', $fileId);
        $submissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_DEPENDENT);
        $submissionFile->setData('name', $fileInfo['fileBase'], $submissionLocale);
        $submissionFile->setData('submissionId', $submission->getId());
        $submissionFile->setData('submissionLocale', $submissionLocale);
        $submissionFile->setData('assocType', Application::ASSOC_TYPE_SUBMISSION_FILE);
        $submissionFile->setData('assocId', $mainHTMLGalley);
        $submissionFile->setViewable(true);

        $genreDao = \DAORegistry::getDAO('GenreDAO');
        $galleyGenreKey = strtoupper($fileInfo['extension']) === 'JPG' ? 'IMAGE' : 'STYLE';
        $genre = $genreDao->getByKey(
            $galleyGenreKey,
            Application::get()->getRequest()->getContext()->getId()
        );

        $submissionFile->setData('genreId', $genre->getId());

        Repo::submissionFile()->add($submissionFile);
    }
}