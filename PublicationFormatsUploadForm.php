<?php

/**
 * @file PublicationFormatsUploadForm.php
 *
 * Copyright (c) 2022+ publicacionesacademicas.es
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PublicationFormatsUploadForm
 * @brief Handle publication format ZIP uploads.
 */

use APP\core\Application;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\file\TemporaryFileManager;
use PKP\form\Form;

require_once __DIR__ . '/PublicationFormatUploader.php';

class PublicationFormatsUploadForm extends Form
{
    public function __construct($plugin)
    {
        parent::__construct($plugin->getTemplateResource('index.tpl'));
    }

    /**
     * Store an uploaded ZIP as a user-owned temporary file.
     */
    public function uploadTemporaryFile($request): JSONMessage
    {
        $user = $request->getUser();
        $temporaryFileManager = new TemporaryFileManager();
        $temporaryFile = $temporaryFileManager->handleUpload('uploadedFile', $user->getId());

        if (!$temporaryFile) {
            return new JSONMessage(false, __('common.uploadFailed'));
        }

        $json = new JSONMessage(true);
        $json->setAdditionalAttributes([
            'temporaryFileId' => $temporaryFile->getId(),
        ]);
        return $json;
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars(['temporaryFileId']);
    }

    /**
     * Process the uploaded ZIP and return the result messages for the view.
     *
     * @return array{errors: array<string>, successMessages: array<string>}
     */
    public function execute(...$functionParams): array
    {
        $request = Application::get()->getRequest();
        if (!$request->checkCSRF()) {
            throw new Exception('CSRF mismatch!');
        }

        parent::execute(...$functionParams);

        $temporaryFileDao = DAORegistry::getDAO('TemporaryFileDAO');
        $user = $request->getUser();
        $temporaryFile = $temporaryFileDao->getTemporaryFile(
            $this->getData('temporaryFileId'),
            $user->getId()
        );

        if (!$temporaryFile) {
            return [
                'errors' => [
                    __('plugins.importexport.publicationFormatsUploader.error.selectFile'),
                ],
                'successMessages' => [],
            ];
        }

        $uploader = new PublicationFormatUploader(
            $temporaryFile->getFilePath(),
            new ZipArchive()
        );

        try {
            return $uploader->uploadFile();
        } finally {
            (new TemporaryFileManager())->deleteById(
                (int) $temporaryFile->getId(),
                (int) $user->getId()
            );
        }
    }
}
