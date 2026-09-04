<?php

/**
 * @file PublicationFormatsUploaderPlugin.php
 *
 * Copyright (c) 2022+ publicacionesacademicas.es
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PublicationFormatsUploaderPlugin
 * @brief Plugin class for bulk uploading OMP publication format proof files.
 */

namespace APP\plugins\generic\publicationFormatsUploader;

use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;

class PublicationFormatsUploaderPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.publicationFormatsUploader.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.publicationFormatsUploader.description');
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        $uploadUrl = $router->url(
            $request,
            null,
            null,
            'manage',
            null,
            [
                'verb' => 'upload',
                'plugin' => $this->getName(),
                'category' => $this->getCategory(),
            ]
        );

        array_unshift(
            $actions,
            new LinkAction(
                'uploadPublicationFormats',
                new AjaxModal($uploadUrl, __('plugins.generic.publicationFormatsUploader.settings')),
                __('plugins.generic.publicationFormatsUploader.settings')
            )
        );

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        if (!$this->getEnabled()) {
            return new JSONMessage(
                false,
                __('plugins.generic.publicationFormatsUploader.error.pluginDisabled')
            );
        }

        $form = new PublicationFormatsUploadForm($this);
        $form->setData([
            'pluginName' => $this->getName(),
            'pluginCategory' => $this->getCategory(),
        ]);

        switch ($request->getUserVar('verb')) {
            case 'upload':
                return new JSONMessage(true, $form->fetch($request));

            case 'uploadTemporaryFile':
                return $form->uploadTemporaryFile($request);

            case 'uploadFile':
                $form->readInputData();
                $results = $form->execute();

                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->assign($results);
                return new JSONMessage(
                    true,
                    $templateMgr->fetch($this->getTemplateResource('results.tpl'))
                );
        }

        return parent::manage($args, $request);
    }
}
