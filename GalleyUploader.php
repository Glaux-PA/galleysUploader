<?php

require_once 'FileProcessor.php';
require_once 'GalleyManager.php';
require_once 'DependentFileManager.php';

class GalleyUploader {

    private $zipArchive;
    private $temporaryFilePath;
    private $plugin;
    private const SEPARATOR = "-";
    private const LOCALE_MAP = [
        'es' => 'es_ES',
        'en' => 'en_US'
    ];
    private const EXCLUDED_PATHS = [
        '__MACOSX'
    ];

    private $fileProcessor;
    private $galleyManager;
    private $dependentFileManager;

    function __construct($temporaryFilePath, $zipArchive, $plugin) {
        $this->zipArchive = $zipArchive;
        $this->temporaryFilePath = $temporaryFilePath;
        $this->plugin = $plugin;

        $this->fileProcessor = new FileProcessor($this->zipArchive, $this->temporaryFilePath);
        $this->galleyManager = new GalleyManager();
        $this->dependentFileManager = new DependentFileManager();
    }


    public function uploadFile(){
        $zipOpened = $this->zipArchive->open($this->temporaryFilePath);

        if (!$this->fileProcessor->isAZip($zipOpened)){
            return $this->generateJsonValidationError(["File is not a ZIP"]);

        }elseif(!$zipOpened){
            return $this->generateJsonValidationError(["File can't be opened"]);
        }

        $mainGalleys=$this->processMainFiles();

        if (!empty($mainGalleys)) {
          $this->processDependentFiles($mainGalleys);
        }

		$this->zipArchive->close();
    }

    private function isValidFile($fileName) {
        if ($fileName == '.' || $fileName == '..') return false;

        foreach(self::EXCLUDED_PATHS as $excluded) {
            if (stripos($fileName, $excluded) !== false) return false;
        }
        return true;
    }

    function processMainFiles(){
        $mainGalleys = [];
        for ($i = 0; $i < $this->zipArchive->numFiles; $i++) {

            $currentFileName = $this->zipArchive->getNameIndex($i);

            if( !$this->isValidFile($currentFileName)) continue;

            $currentFileStat = $this->zipArchive->statIndex($i);

            if ($this->fileProcessor->isFolder($currentFileStat))  continue;

            $fileInfo = $this->fileProcessor->getFileInfo($currentFileName);
            $label = strtoupper($fileInfo["extension"]);

            if ($label === 'JPG' || $label === 'CSS') continue;

            if (strrpos($fileInfo["fileName"], self::SEPARATOR) === false) continue;

            list($idSubmission, $galleyLocale) = $this->extractSubmissionData($fileInfo['fileName']);

            $submissionDAO = \DAORegistry::getDAO('SubmissionDAO');
			$submission = $submissionDAO->getById($idSubmission);
            if (is_null($submission)) continue;

            $publication = $submission->getLatestPublication();

            if (is_null($publication)) continue;
            $articleGalleyDao = \DAORegistry::getDAO('ArticleGalleyDAO');

            $locale = isset(self::LOCALE_MAP[$galleyLocale]) ? self::LOCALE_MAP[$galleyLocale] : $this->getContext()->getPrimaryLocale();
            $this->galleyManager->deleteExistingGalleys($publication,$label, $locale);


            $articleGalley = $this->galleyManager->createGalley($publication, $fileInfo["extension"], $locale);
            $articleGalleyId = $articleGalleyDao->insertObject($articleGalley);

            $fileId = $this->fileProcessor->saveFileToRepo($submission, $fileInfo, $currentFileName,$this->temporaryFilePath);
            $submissionFile=$this->createSubmissionFile($fileId,$submission,$fileInfo,  $articleGalleyId);


            $articleGalley->setFileId($submissionFile->getId());
            $articleGalleyDao->updateObject($articleGalley);

            if ($label === 'HTML' || $label === 'XML') {
                $mainGalleys[$submission->getId()][] = $submissionFile->getId();
            }
        }
        return $mainGalleys;
    }

    function processDependentFiles($mainGalleys){

        for ($i = 0; $i < $this->zipArchive->numFiles; $i++) {

            $currentFileName = $this->zipArchive->getNameIndex($i);

            if( !$this->isValidFile($currentFileName)) continue;

            $currentFileStat = $this->zipArchive->statIndex($i);
            if ($this->fileProcessor->isFolder($currentFileStat))  continue;

            $fileInfo = $this->fileProcessor->getFileInfo($currentFileName);
            $label = strtoupper($fileInfo["extension"]);

            if ($label !== 'JPG' && $label !== 'CSS') continue;

            if (strrpos($fileInfo["fileName"], self::SEPARATOR) === false) continue;


            list($idSubmission, $galleyLocale) = $this->extractSubmissionData($fileInfo['fileName']);

            $submissionDAO = \DAORegistry::getDAO('SubmissionDAO');
            $submission = $submissionDAO->getById($idSubmission);

            if (is_null($submission)) continue;

            $locale = isset(self::LOCALE_MAP[$galleyLocale]) ? self::LOCALE_MAP[$galleyLocale] : $this->getContext()->getPrimaryLocale();

            /*	$articleGalleyDao = \DAORegistry::getDAO('ArticleGalleyDAO');
        $articleGalley = $articleGalleyDao->newDataObject();
        $articleGalley->setData('publicationId', $publication->getId());
        $articleGalley->setLabel(strtoupper($extension));
        $articleGalley->setLocale($locale);
            */
            import('lib.pkp.classes.file.TemporaryFileManager');

            foreach ($mainGalleys[$submission->getId()] as $mainHTMLGalley) {

                $fileId = $this->fileProcessor->saveFileToRepo($submission, $fileInfo, $currentFileName,$this->temporaryFilePath);
                $this->dependentFileManager->createDependentFile($fileId, $submission, $mainHTMLGalley, $fileInfo, $locale);

            }
            /*$articleGalley->setFileId($submissionFile->getId());
        $articleGalleyDao->insertObject($articleGalley);*/
        }

    }
    private function extractSubmissionData($fileName) {
        $lastPart = substr($fileName, strrpos($fileName, self::SEPARATOR) + 1);
        if (is_numeric($lastPart)) {
            return [$lastPart, -1];
        }
        $fileNameWithoutLastPart = substr($fileName, 0, strrpos($fileName, self::SEPARATOR));
        return [substr($fileNameWithoutLastPart, strrpos($fileNameWithoutLastPart, self::SEPARATOR) + 1), strtolower($lastPart)];
    }

    private function getContext() {
        return Application::get()->getRequest()->getContext();
    }

    private function createSubmissionFile($fileId, $submission, $fileInfo, $articleGalleyId) {
        $submissionFileDao = \DAORegistry::getDAO('SubmissionFileDAO');
        $submissionFile = $submissionFileDao->newDataObject();
        $submissionFile->setData('fileId', $fileId);
        $submissionFile->setData('fileStage', SUBMISSION_FILE_PROOF);
        $submissionFile->setData('name', $fileInfo["fileBase"], $this->getContext()->getPrimaryLocale());
        $submissionFile->setData('submissionId', $submission->getId());
        $submissionFile->setSubmissionLocale($submission->getLocale());
        $submissionFile->setData('assocType', ASSOC_TYPE_REPRESENTATION); //TODO
        $submissionFile->setData('assocId',  $articleGalleyId);

        $submissionFile->setData('dateUploaded', Core::getCurrentDate());
        $submissionFile->setData('dateModified', Core::getCurrentDate());
        $submissionFile->setData('originalFileName', $fileInfo["fileBase"]); //TODO: non estour seguro
        $submissionFile->setViewable(true);

        $genreDao = \DAORegistry::getDAO('GenreDAO');
        $genre = $genreDao->getByKey('SUBMISSION', $this->getContext()->getId());
        $submissionFile->setData('genreId',  $genre->getId());

        $submissionFile = \Services::get('submissionFile')->add($submissionFile, Application::get()->getRequest());

        return $submissionFile;
    }
    private function generateJsonValidationError($validationErrors){
        $request=Application::get()->getRequest();
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('validationErrors', $validationErrors);
        $json = new JSONMessage(true, $templateMgr->fetch($this->plugin->getTemplateResource('results.tpl')));
        header('Content-Type: application/json');
        return $json->getString();

    }

}

