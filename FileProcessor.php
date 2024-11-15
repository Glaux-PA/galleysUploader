<?php
use APP\facades\Repo;
use APP\core\Application;

class FileProcessor {

    private $zipArchive;
    private $temporaryFilePath;

    public function __construct($zipArchive, $temporaryFilePath) {
        $this->zipArchive = $zipArchive;
        $this->temporaryFilePath = $temporaryFilePath;
    }

    public function isAZip($zipOpened) {
        return $zipOpened !== ZipArchive::ER_NOZIP;
    }

    public function getFileInfo($fileName) {
        $pathParts = pathinfo($fileName);
        return [
            "fileName" => $pathParts['filename'],
            "fileBase" => $pathParts['basename'],
            "extension" => $pathParts['extension'],
            "dirName" => $pathParts['dirname']
        ];
    }

   public function saveFileToRepo($submission, $fileInfo, $currentFileName) {
   
        $temporaryFileManager = new TemporaryFileManager();
        $temporaryFilename = tempnam($temporaryFileManager->getBasePath(), 'src');
        file_put_contents($temporaryFilename, file_get_contents("zip://" . $this->temporaryFilePath . "#" . $currentFileName));
        $submissionDir = Repo::submissionFile()->getSubmissionDir(Application::get()->getRequest()->getContext()->getId(), $submission->getId());
        return Services::get('file')->add(
                    $temporaryFilename,
                    $submissionDir . '/' . uniqid() . '.' . $fileInfo["extension"]
                );
    }

    public function isFolder($zipFileStat) {
        return $zipFileStat['size'] == 0;
    }
}