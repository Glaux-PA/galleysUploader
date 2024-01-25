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
		$context = $request->getContext();

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

				AppLocale::requireComponents(LOCALE_COMPONENT_PKP_SUBMISSION);
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
				$zipOpened = $zipArchive->open($temporaryFilePath);

				if ($zipOpened === ZipArchive::ER_NOZIP) {
					$validationErrors = ["File is not a ZIP"];
					$templateMgr->assign('validationErrors', $validationErrors);
					$json = new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('results.tpl')));
					header('Content-Type: application/json');
					return $json->getString();
				} elseif ($zipOpened !== true) {
					$validationErrors = ["File can't be opened"];
					$templateMgr->assign('validationErrors', $validationErrors);
					$json = new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('results.tpl')));
					header('Content-Type: application/json');
					return $json->getString();
				}




				define('SEPARATOR', '-'); //TODO: Quitar de aquí
				$mainHTMLGalleys = [];
				for ($i = 0; $i < $zipArchive->numFiles; $i++) {
					$currentFileName = $zipArchive->getNameIndex($i);
					if ($currentFileName == '.' || $currentFileName == '..') continue;
					$currentFileStat = $zipArchive->statIndex($i);
					if ($this->isFolder($currentFileStat)) { //TODO: a función terá que ir noutra clase
						continue;
					}

					$pathParts = pathinfo($currentFileName);
					$extension = $pathParts['extension'];
					$fileName = $pathParts['filename'];
					$fileBase = $pathParts['basename'];
					$dirName = $pathParts['dirname'];

					$label = strtoupper($extension);

					if (strtoupper($extension) === 'JPG' || strtoupper($extension) === 'CSS') { //TODO
						continue;
					}



					if (strrpos($fileName, SEPARATOR) === false) {
						continue;
					}

					$lastPart = substr($fileName, 1 + strrpos($fileName, SEPARATOR));
					if (is_numeric($lastPart)) {
						$idSubmission = $lastPart;
						$galleyLocale = -1;
					} else {
						$galleyLocale = strtolower($lastPart);
						$fileNameWithoutLastPart = substr($fileName, 0, strrpos($fileName, SEPARATOR));
						$idSubmission = substr($fileNameWithoutLastPart, 1 + strrpos($fileNameWithoutLastPart, SEPARATOR));
					}



					$submissionDAO = \DAORegistry::getDAO('SubmissionDAO');
					$submission = $submissionDAO->getById($idSubmission);
					if (is_null($submission)) {
						continue;
					}

					//TODO: comprobar que existe a submission con ese id


					$publication = $submission->getLatestPublication();

					$articleGalleyDao = \DAORegistry::getDAO('ArticleGalleyDAO');



					//TODO

					$localeList = [
						'es' => 'es_ES',
						'en' => 'en_US'
					];
					$locale = isset($localeList[$galleyLocale]) ? $localeList[$galleyLocale] : $context->getPrimaryLocale();

					//TODO: que non se recuperen, sempre as galeradas da publicación
					$currentGalleys = $articleGalleyDao->getByPublicationId($publication->getId())->toArray();

					foreach ($currentGalleys as $galley) {
						if ($galley->getLabel() === $label && $galley->getLocale() === $locale) {
							$articleGalleyDao->deleteObject($galley);
						}
					}


					$articleGalley = $articleGalleyDao->newDataObject();
					$articleGalley->setData('publicationId', $publication->getId());
					$articleGalley->setLabel($label);
					$articleGalley->setLocale($locale);
					$articleGalley->setData('urlPath', null);
					$articleGalley->setData('urlRemote', null);
					$articleGalley->setSequence($this->getSequence($extension));
					$articleGalleyId = $articleGalleyDao->insertObject($articleGalley);

					import('lib.pkp.classes.file.TemporaryFileManager');
					$temporaryFileManager = new \TemporaryFileManager();
					$temporaryFilename = tempnam($temporaryFileManager->getBasePath(), 'src');

					file_put_contents($temporaryFilename, file_get_contents("zip://" . $temporaryFilePath . "#" . $currentFileName));



					$submissionFileDao = \DAORegistry::getDAO('SubmissionFileDAO');
					$submissionFile = $submissionFileDao->newDataObject();


					$submissionDir = \Services::get('submissionFile')->getSubmissionDir($context->getId(), $submission->getId());
					$fileId = \Services::get('file')->add(
						$temporaryFilename,
						$submissionDir . '/' . uniqid() . '.' . $extension
					);

					$submissionFile->setData('fileId', $fileId);
					$submissionFile->setData('fileStage', SUBMISSION_FILE_PROOF);
					$submissionFile->setData('name', $fileBase, $context->getPrimaryLocale());
					$submissionFile->setData('submissionId', $submission->getId());
					$submissionFile->setSubmissionLocale($submission->getLocale());
					$submissionFile->setData('assocType', ASSOC_TYPE_REPRESENTATION); //TODO
					$submissionFile->setData('assocId',  $articleGalleyId);

					$submissionFile->setData('dateUploaded', Core::getCurrentDate());
					$submissionFile->setData('dateModified', Core::getCurrentDate());
					$submissionFile->setData('originalFileName', $fileBase); //TODO: non estour seguro
					$submissionFile->setViewable(true);

					$genreDao = \DAORegistry::getDAO('GenreDAO');
					$genre = $genreDao->getByKey('SUBMISSION', $context->getId());
					$submissionFile->setData('genreId',  $genre->getId());


					$submissionFile = \Services::get('submissionFile')->add($submissionFile, $request);

					$articleGalley->setFileId($submissionFile->getId());
					$articleGalleyDao->updateObject($articleGalley);

					if (strtoupper($extension) === 'HTML' || strtoupper($extension) === 'XML') { //TODO
						$mainHTMLGalleys[$submission->getId()][] = $submissionFile->getId();
					}
				}

				if (!empty($mainHTMLGalleys)) {
					for ($i = 0; $i < $zipArchive->numFiles; $i++) {

						$currentFileName = $zipArchive->getNameIndex($i);
						if ($currentFileName == '.' || $currentFileName == '..') continue;
						$currentFileStat = $zipArchive->statIndex($i);
						if ($this->isFolder($currentFileStat)) { //TODO: a función terá que ir noutra clase
							continue;
						}

						$pathParts = pathinfo($currentFileName);
						$fileName = $pathParts['filename'];
						$fileBase = $pathParts['basename'];
						$extension = $pathParts['extension'];
						$dirName = $pathParts['dirname'];



						if (strtoupper($extension) !== 'JPG' && strtoupper($extension) !== 'CSS') { //TODO //DIFF
							continue;
						}

						if (strrpos($fileName, SEPARATOR) === false) {
							continue;
						}

						$lastPart = substr($fileName, 1 + strrpos($fileName, SEPARATOR));
						if (is_numeric($lastPart)) {
							$idSubmission = $lastPart;
							$galleyLocale = -1;
						} else {
							$galleyLocale = strtolower($lastPart);
							$fileNameWithoutLastPart = substr($fileName, 0, strrpos($fileName, SEPARATOR));
							$idSubmission = substr($fileNameWithoutLastPart, 1 + strrpos($fileNameWithoutLastPart, SEPARATOR));
						}

						$submissionDAO = \DAORegistry::getDAO('SubmissionDAO');
						$submission = $submissionDAO->getById($idSubmission);
						if (is_null($submission)) {
							continue;
						}
						$publication = $submission->getLatestPublication();
						//TODO: Sobreescribir galeradas existentes

						//TODO
						$localeList = [
							'es' => 'es_ES',
							'en' => 'en_US'
						];
						$locale = isset($localeList[$galleyLocale]) ? $localeList[$galleyLocale] : $context->getPrimaryLocale();


						/*	$articleGalleyDao = \DAORegistry::getDAO('ArticleGalleyDAO');
					$articleGalley = $articleGalleyDao->newDataObject();
					$articleGalley->setData('publicationId', $publication->getId());
					$articleGalley->setLabel(strtoupper($extension));
					$articleGalley->setLocale($locale);
						*/
						import('lib.pkp.classes.file.TemporaryFileManager');

						foreach ($mainHTMLGalleys[$submission->getId()] as $mainHTMLGalley) {
							$temporaryFileManager = new \TemporaryFileManager();
							$temporaryFilename = tempnam($temporaryFileManager->getBasePath(), 'src');
							file_put_contents($temporaryFilename, file_get_contents("zip://" . $temporaryFilePath . "#" . $currentFileName));


							$submissionFileDao = \DAORegistry::getDAO('SubmissionFileDAO');
							$submissionFile = $submissionFileDao->newDataObject();


							$submissionDir = \Services::get('submissionFile')->getSubmissionDir($context->getId(), $submission->getId());
							$fileId = \Services::get('file')->add(
								$temporaryFilename,
								$submissionDir . '/' . uniqid() . '.' . $extension
							);

							$submissionFile->setData('fileId', $fileId);
							$submissionFile->setData('fileStage', SUBMISSION_FILE_DEPENDENT);
							$submissionFile->setData('name', $fileBase, $context->getPrimaryLocale());
							$submissionFile->setData('submissionId', $submission->getId());
							$submissionFile->setData('assocType', ASSOC_TYPE_SUBMISSION_FILE);
							$submissionFile->setData('assocId',  $mainHTMLGalley);

							$submissionFile->setViewable(true);


							$genreDao = \DAORegistry::getDAO('GenreDAO');
							$galleyGenreKey = strtoupper($extension) === 'JPG' ? 'IMAGE' : 'STYLE';
							$genre = $genreDao->getByKey($galleyGenreKey, $context->getId());
							$submissionFile->setData('genreId',  $genre->getId()); //TODO


							$submissionFile = \Services::get('submissionFile')->add($submissionFile, $request);
						}
						/*$articleGalley->setFileId($submissionFile->getId());
					$articleGalleyDao->insertObject($articleGalley);*/
					}
				}

				$zipArchive->close();





				$json = new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('results.tpl')));
				header('Content-Type: application/json');
				return $json->getString();
		}
		return '';
	}
	function isFolder($zipFileStat)
	{
		if ($zipFileStat['size'] == 0) {
			return true;
		}
		return false;
	}
	function getSequence($extension)
	{
		$sequences = [
			'pdf' => 0,
			'html' => 1,
			'xml' => 2,
			'epub' => 3,
		];

		return isset($sequences[$extension]) ? $sequences[$extension] : 0;
	}
}
