<?php

/**
 * @file PublicationFormatsUploaderPlugin.inc.php
 *
 * Copyright (c) 2022+ publicacionesacademicas.es
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PublicationFormatsUploaderPlugin
 * @brief Plugin class for bulk uploading OMP publication format proof files.
 */

use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\file\TemporaryFileManager;
use PKP\plugins\ImportExportPlugin;

require_once 'PublicationFormatUploader.php';

class PublicationFormatsUploaderPlugin extends ImportExportPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        $this->addLocaleData();
        return $success;
    }

    public function getDisplayName(): string
    {
        return __('plugins.importexport.publicationFormatsUploader.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.importexport.publicationFormatsUploader.description');
    }

    public function getName(): string
    {
        return 'PublicationFormatsUploaderPlugin';
    }

    public function supportsCLI(): bool
    {
        return false;
    }

    public function executeCLI($scriptName, &$args): void
    {
        fatalError('Not implemented');
    }

    public function usage($scriptName): void
    {
        fatalError('Not implemented');
    }

    public function display($args, $request): string
    {
        parent::display($args, $request);

        $templateMgr = TemplateManager::getManager($request);

        switch (array_shift($args)) {
            case 'index':
            case '':
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;

            case 'publicationFormatsUploadTempFile':
                $user = $request->getUser();
                $temporaryFileManager = new TemporaryFileManager();
                $temporaryFile = $temporaryFileManager->handleUpload('uploadedFile', $user->getId());
                if ($temporaryFile) {
                    $json = new JSONMessage(true);
                    $json->setAdditionalAttributes([
                        'temporaryFileId' => $temporaryFile->getId(),
                    ]);
                } else {
                    $json = new JSONMessage(false, __('common.uploadFailed'));
                }
                header('Content-Type: application/json');
                return $json->getString();

            case 'publicationFormatsUploadFile':
                if (!$request->checkCSRF()) {
                    throw new Exception('CSRF mismatch!');
                }

                $temporaryFileId = $request->getUserVar('temporaryFileId');
                $temporaryFileDao = DAORegistry::getDAO('TemporaryFileDAO');
                $user = $request->getUser();
                $temporaryFile = $temporaryFileDao->getTemporaryFile($temporaryFileId, $user->getId());
                if (!$temporaryFile) {
                    $templateMgr->assign('errors', [
                        __('plugins.importexport.publicationFormatsUploader.error.selectFile'),
                    ]);
                    $templateMgr->assign('successMessages', []);
                    $json = new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('results.tpl')));
                    header('Content-Type: application/json');
                    return $json->getString();
                }

                $uploader = new PublicationFormatUploader(
                    $temporaryFile->getFilePath(),
                    new ZipArchive()
                );
                try {
                    $results = $uploader->uploadFile();
                } finally {
                    (new TemporaryFileManager())->deleteById(
                        (int) $temporaryFile->getId(),
                        (int) $user->getId()
                    );
                }
                $templateMgr->assign($results);

                $json = new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('results.tpl')));
                header('Content-Type: application/json');
                return $json->getString();
        }

        return '';
    }
}
