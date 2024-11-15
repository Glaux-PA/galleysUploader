<?php

/**
 * @file GalleysUploaderPlugin.inc.php
 *
 * Copyright (c) 2022+ publicacionesacademicas.es
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class GalleysUploaderPlugin
 * @brief Plugin class for the Galleys Import plugin.
 */

import('lib.pkp.classes.plugins.ImportExportPlugin');

use PKP\core\JSONMessage;
use PKP\file\TemporaryFileManager;
use PKP\plugins\ImportExportPlugin;
use APP\template\TemplateManager;

require_once 'GalleyUploader.php';


class GalleysUploaderPlugin extends ImportExportPlugin
{


	function register($category, $path, $mainContextId = null)
	{
		$success = parent::register($category, $path, $mainContextId);
		$this->addLocaleData();
		return $success;
	}

	/**
	 * Provide a name for this plugin
	 */
	public function getDisplayName(): string
	{
		return __('plugins.importexport.galleysUploader.displayName');
	}

	/**
	 * Provide a description for this plugin
	 */
	public function getDescription(): string
	{
		return __('plugins.importexport.galleysUploader.description');
	}

	/**
	 * Get the name of this plugin. The name must be unique within
	 * its category, and should be suitable for part of a filename
	 * (ie short, no spaces, and no dependencies on cases being unique).
	 *
	 */
	public function getName(): string
	{
		return "GalleysUploaderPlugin";
	}

	/**
	 * Execute import/export tasks using the command-line interface.
	 */
	public function executeCLI($scriptName, &$args): void
	{
		fatalError('Not implemented');
	}

	/**
	 * Display the command-line usage information
	 */
	public function usage($scriptName): void
	{
		fatalError('Not implemented');
	}

	/**
	 * Display the import/export plugin.
	 */
	function display($args, $request): string
	{

		parent::display($args, $request);

		$templateMgr = TemplateManager::getManager($request);
		

		switch (array_shift($args)) {
			case 'index':
			case '':
				$templateMgr->display($this->getTemplateResource('index.tpl'));
				break;
			case 'galleysUploadTempFile':
				$user = $request->getUser();
				import('lib.pkp.classes.file.TemporaryFileManager');
				$temporaryFileManager = new TemporaryFileManager();
				$temporaryFile = $temporaryFileManager->handleUpload('uploadedFile', $user->getId());
				if ($temporaryFile) {
					$json = new JSONMessage(true);
					$json->setAdditionalAttributes(array(
						'temporaryFileId' => $temporaryFile->getId()
					));
				} else {
					$json = new JSONMessage(false, __('common.uploadFailed'));
				}
				header('Content-Type: application/json');
				return $json->getString();

			case 'galleysUploadFile':
				if (!$request->checkCSRF()) throw new Exception('CSRF mismatch!');
				
				//AppLocale::requireComponents(LOCALE_COMPONENT_PKP_SUBMISSION);
				$temporaryFileId = $request->getUserVar('temporaryFileId');
				$temporaryFileDao = DAORegistry::getDAO('TemporaryFileDAO'); /* @var $temporaryFileDao TemporaryFileDAO */
				$user = $request->getUser();
				$temporaryFile = $temporaryFileDao->getTemporaryFile($temporaryFileId, $user->getId());
				if (!$temporaryFile) {
					$validationErrors = ["Select a file"];
					$templateMgr->assign('validationErrors', $validationErrors);
					$json = new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('results.tpl')));
					header('Content-Type: application/json');
					return $json->getString();
				}
				
				$temporaryFilePath = $temporaryFile->getFilePath();
				$zipArchive = new ZipArchive();

				$galleyUploader=new GalleyUploader($temporaryFilePath,$zipArchive, $this);
				$galleyUploader->uploadFile();
				

				$json = new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('results.tpl')));
				header('Content-Type: application/json');
				return $json->getString();
		}
		return '';
	}

}
