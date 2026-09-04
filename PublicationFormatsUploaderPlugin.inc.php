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
use PKP\plugins\ImportExportPlugin;

require_once __DIR__ . '/PublicationFormatsUploadForm.php';

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
        $form = new PublicationFormatsUploadForm($this);

        switch (array_shift($args)) {
            case 'index':
            case '':
                $form->display($request);
                break;

            case 'publicationFormatsUploadTempFile':
                $json = $form->uploadTemporaryFile($request);
                header('Content-Type: application/json');
                return $json->getString();

            case 'publicationFormatsUploadFile':
                $form->readInputData();
                $results = $form->execute();
                $templateMgr->assign($results);

                $json = new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('results.tpl')));
                header('Content-Type: application/json');
                return $json->getString();
        }

        return '';
    }
}
