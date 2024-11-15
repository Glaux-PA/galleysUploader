<?php

use PKP\core\JSONMessage;
use APP\facades\Repo;
use APP\template\TemplateManager;
use APP\core\Application;

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
      
        
        $mainHTMLGalley=$this->processMainFiles();

        if (!empty($mainHTMLGalleys)) {
          $this->processDependentFiles($mainHTMLGalley);
        }
      
		$this->zipArchive->close();
    }

    function processMainFiles(){
        $mainHTMLGalleys = [];
        for ($i = 0; $i < $this->zipArchive->numFiles; $i++) {

            $currentFileName = $this->zipArchive->getNameIndex($i);

            if ($currentFileName == '.' || $currentFileName == '..') continue;

            $currentFileStat = $this->zipArchive->statIndex($i);

            if ($this->fileProcessor->isFolder($currentFileStat))  continue;
    
            $fileInfo = $this->fileProcessor->getFileInfo($currentFileName);
            $label = strtoupper($fileInfo["extension"]);
    
            if ($label === 'JPG' || $label === 'CSS') continue;
    
            if (strrpos($fileInfo["fileName"], self::SEPARATOR) === false) continue;
    
            list($idSubmission, $galleyLocale) = $this->extractSubmissionData($fileInfo['fileName']);
    
            $submission = Repo::submission()->get($idSubmission);
            if (is_null($submission)) continue;
           

            $publication = $submission->getLatestPublication();

            if (is_null($publication)) continue;
       
            $locale = isset(self::LOCALE_MAP[$galleyLocale]) ? self::LOCALE_MAP[$galleyLocale] : $this->getContext()->getPrimaryLocale();
            $this->galleyManager->deleteExistingGalleys($idSubmission,$fileInfo["extension"], $locale);
            
    
            $articleGalley = $this->galleyManager->createGalley($publication, $fileInfo["extension"], $locale);
            $articleGalleyId = Repo::galley()->add($articleGalley);

            $fileId = $this->fileProcessor->saveFileToRepo($submission, $fileInfo, $currentFileName);
            $submissionFile=$this->createSubmissionFile($fileId,$submission,$fileInfo,  $articleGalleyId);

            Repo::galley()->edit($articleGalley, ['fileId' => $submissionFile->getId()]);
    
            if ($label === 'HTML' || $label === 'XML') { 
                $mainHTMLGalleys[$submission->getId()][] = $submissionFile->getId();
            }


        }
        return $mainHTMLGalleys;
    }

    function processDependentFiles($mainHTMLGalleys){
 
        for ($i = 0; $i < $this->zipArchive->numFiles; $i++) {
    
            $currentFileName = $this->zipArchive->getNameIndex($i);
            if ($currentFileName == '.' || $currentFileName == '..') continue;

            $currentFileStat = $this->zipArchive->statIndex($i);
            if ($this->fileProcessor->isFolder($currentFileStat))  continue;
          
            $fileInfo = pathinfo($currentFileName);

            if (strrpos($fileInfo["fileName"], self::SEPARATOR) === false) continue;
            
            list($idSubmission, $galleyLocale) = $this->extractSubmissionData($fileInfo['fileName']);

            $submission = Repo::submission()->get($idSubmission);

            if (is_null($submission)) continue;
            
            $locale = isset(self::LOCALE_MAP[$galleyLocale]) ? self::LOCALE_MAP[$galleyLocale] : $this->getContext()->getPrimaryLocale();


            /*	$articleGalleyDao = \DAORegistry::getDAO('ArticleGalleyDAO');
        $articleGalley = $articleGalleyDao->newDataObject();
        $articleGalley->setData('publicationId', $publication->getId());
        $articleGalley->setLabel(strtoupper($extension));
        $articleGalley->setLocale($locale);
            */
            import('lib.pkp.classes.file.TemporaryFileManager');

            foreach ($mainHTMLGalleys[$submission->getId()] as $mainHTMLGalley) {

                $fileId = $this->saveFileToRepo($submission, $fileInfo, $currentFileName);
                $this->dependencyFileManager->createDependentFile($fileId, $submission, $mainHTMLGalley, $fileInfo, $locale);
        
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

    private function createSubmissionFile($fileId, $submission, $fileInfo, $assocId) {
        $submissionFile = Repo::submissionFile()->newDataObject();
        $submissionFile->setData('fileId', $fileId);
        $submissionFile->setData('fileStage', SUBMISSION_FILE_PROOF);
        $submissionFile->setData('name', $fileInfo["fileBase"], $this->getContext()->getPrimaryLocale());
        $submissionFile->setData('submissionId', $submission->getId());
        $submissionFile->setSubmissionLocale($submission->getLocale());
        $submissionFile->setData('assocType', ASSOC_TYPE_REPRESENTATION); //TODO
        $submissionFile->setData('assocId',  $assocId);

        $submissionFile->setData('dateUploaded', Core::getCurrentDate());
        $submissionFile->setData('dateModified', Core::getCurrentDate());
        $submissionFile->setData('originalFileName', $fileInfo["fileBase"]); //TODO: non estour seguro
        $submissionFile->setViewable(true);

        $genreDao = \DAORegistry::getDAO('GenreDAO');
        $genre = $genreDao->getByKey('SUBMISSION', $this->getContext()->getId());
        $submissionFile->setData('genreId',  $genre->getId());
        Repo::submissionFile()->add($submissionFile);
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

