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

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

require_once __DIR__ . '/ChapterFileFilter.php';

class PublicationFormatsUploaderPlugin extends GenericPlugin
{
    private ?\ChapterFileFilter $chapterFileFilter = null;

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('chapterform::display', [$this, 'filterChapterFileOptions']);
            Hook::add('chapterform::execute', [$this, 'preserveHiddenDependentAssignments']);
        }

        return $success;
    }

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
     * Delegate chapter-form display filtering while the plugin remains enabled.
     */
    public function filterChapterFileOptions(string $hookName, array $args): bool
    {
        if (!$this->isEnabledForCurrentContext()) {
            return Hook::CONTINUE;
        }

        return $this->getChapterFileFilter()->filterChapterFileOptions($hookName, $args);
    }

    /**
     * Delegate preservation of hidden legacy chapter-file associations.
     */
    public function preserveHiddenDependentAssignments(string $hookName, array $args): bool
    {
        if (!$this->isEnabledForCurrentContext()) {
            return Hook::CONTINUE;
        }

        return $this->getChapterFileFilter()->preserveHiddenDependentAssignments($hookName, $args);
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

    private function getChapterFileFilter(): \ChapterFileFilter
    {
        if ($this->chapterFileFilter === null) {
            $this->chapterFileFilter = new \ChapterFileFilter();
        }

        return $this->chapterFileFilter;
    }

    private function isEnabledForCurrentContext(): bool
    {
        $request = Application::get()->getRequest();
        $context = $request?->getContext();

        return $context !== null && $this->getEnabled($context->getId());
    }
}
