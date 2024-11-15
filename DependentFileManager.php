<?php

class DependentFileManager {

    public function createDependentFile($fileId, $submission, $mainHTMLGalley, $fileInfo, $locale) {
        $submissionFileDao = \DAORegistry::getDAO('SubmissionFileDAO');
        $submissionFile = $submissionFileDao->newDataObject();

        $submissionFile->setData('fileId', $fileId);
        $submissionFile->setData('fileStage', SUBMISSION_FILE_DEPENDENT);
        $submissionFile->setData('name', $fileInfo['fileBase'], Application::get()->getRequest()->getContext()->getPrimaryLocale());
        $submissionFile->setData('submissionId', $submission->getId());
        $submissionFile->setData('assocType', ASSOC_TYPE_SUBMISSION_FILE);
        $submissionFile->setData('assocId', $mainHTMLGalley);
        $submissionFile->setViewable(true);

        $genreDao = \DAORegistry::getDAO('GenreDAO');
        $galleyGenreKey = strtoupper($fileInfo['extension']) === 'JPG' ? 'IMAGE' : 'STYLE';
        $genre = $genreDao->getByKey($galleyGenreKey, Application::get()->getRequest()->getContext()->getId());
        $submissionFile->setData('genreId', $genre->getId());

        $submissionFile = \Services::get('submissionFile')->add($submissionFile, Application::get()->getRequest());
    }
}