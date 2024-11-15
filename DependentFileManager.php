<?php

use APP\facades\Repo;
class DependentFileManager {

    public function createDependentFile($fileId, $submission, $mainHTMLGalley, $fileInfo, $locale) {
        $submissionFile = Repo::submissionFile()->newDataObject();
        $submissionFile->setData('fileId', $fileId);
        $submissionFile->setData('fileStage', SUBMISSION_FILE_DEPENDENT);
        $submissionFile->setData('name', $fileInfo['basename'], $this->getContext()->getPrimaryLocale());
        $submissionFile->setData('submissionId', $submission->getId());
        $submissionFile->setData('assocType', ASSOC_TYPE_SUBMISSION_FILE);
        $submissionFile->setData('assocId', $mainHTMLGalley);
        $submissionFile->setLocale($locale);
        $submissionFile->setViewable(true);

        $genreDao = \DAORegistry::getDAO('GenreDAO');
        $genreKey = strtoupper($fileInfo['extension']) === 'JPG' ? 'IMAGE' : 'STYLE';
        $genre = $genreDao->getByKey($genreKey, $this->getContext()->getId());
        $submissionFile->setData('genreId', $genre->getId());

        Repo::submissionFile()->add($submissionFile);
    }
}