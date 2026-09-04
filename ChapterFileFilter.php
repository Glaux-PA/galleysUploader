<?php

/**
 * @file ChapterFileFilter.php
 *
 * Copyright (c) 2022+ publicacionesacademicas.es
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ChapterFileFilter
 * @brief Hide dependent files from OMP chapter forms without changing their assignments.
 */

use APP\controllers\grid\users\chapter\form\ChapterForm;
use APP\core\Application;
use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\plugins\Hook;
use PKP\submissionFile\SubmissionFile;

class ChapterFileFilter
{
    /**
     * Hide dependent submission files from Edit Chapter > Files.
     *
     * Form hooks in OMP 3.5 are invoked through Hook::call(), so their
     * arguments arrive wrapped in the second callback argument.
     */
    public function filterChapterFileOptions(string $hookName, array $args): bool
    {
        $form = $args[0] ?? null;
        if (!$form instanceof ChapterForm) {
            return Hook::CONTINUE;
        }

        $request = Application::get()->getRequest();
        $templateMgr = TemplateManager::getManager($request);
        $chapterFileOptions = (array) $templateMgr->getTemplateVars('chapterFileOptions');
        $selectedChapterFiles = (array) $templateMgr->getTemplateVars('selectedChapterFiles');

        if (!$chapterFileOptions) {
            return Hook::CONTINUE;
        }

        [$chapterFileOptions, $selectedChapterFiles] = $this->filterOptions(
            $chapterFileOptions,
            $selectedChapterFiles,
            $this->getDependentFiles($form)
        );

        $templateMgr->assign([
            'chapterFileOptions' => $chapterFileOptions,
            'selectedChapterFiles' => $selectedChapterFiles,
        ]);

        return Hook::CONTINUE;
    }

    /**
     * Preserve pre-existing chapter assignments on hidden dependent files.
     *
     * OMP replaces all file assignments for the chapter with the submitted
     * selection. Add hidden dependents back before that replacement occurs.
     */
    public function preserveHiddenDependentAssignments(string $hookName, array $args): bool
    {
        $form = $args[0] ?? null;
        if (!$form instanceof ChapterForm) {
            return Hook::CONTINUE;
        }

        $chapter = $form->getChapter();
        if (!$chapter) {
            return Hook::CONTINUE;
        }

        $form->setData(
            'files',
            $this->preserveAssignments(
                (array) $form->getData('files'),
                $this->getDependentFiles($form),
                (int) $chapter->getId()
            )
        );

        return Hook::CONTINUE;
    }

    /**
     * Remove dependent files from the options and selected values shown by the form.
     *
     * @param array<int|string, string> $chapterFileOptions
     * @param array<int, int|string> $selectedChapterFiles
     * @param iterable<int, SubmissionFile> $submissionFiles
     * @return array{0: array<int|string, string>, 1: array<int, int|string>}
     */
    public function filterOptions(
        array $chapterFileOptions,
        array $selectedChapterFiles,
        iterable $submissionFiles
    ): array {
        $dependentIds = [];

        foreach ($submissionFiles as $submissionFile) {
            if (
                (int) $submissionFile->getData('fileStage')
                !== SubmissionFile::SUBMISSION_FILE_DEPENDENT
            ) {
                continue;
            }

            $dependentIds[(int) $submissionFile->getId()] = true;
            unset($chapterFileOptions[$submissionFile->getId()]);
        }

        $selectedChapterFiles = array_values(
            array_filter(
                $selectedChapterFiles,
                fn ($fileId) => !isset($dependentIds[(int) $fileId])
            )
        );

        return [$chapterFileOptions, $selectedChapterFiles];
    }

    /**
     * Add hidden dependent files already assigned to this chapter to its submitted IDs.
     *
     * @param array<int, int|string> $selectedFiles
     * @param iterable<int, SubmissionFile> $submissionFiles
     * @return array<int, int>
     */
    public function preserveAssignments(
        array $selectedFiles,
        iterable $submissionFiles,
        int $chapterId
    ): array {
        $selectedFiles = array_map('intval', $selectedFiles);

        foreach ($submissionFiles as $submissionFile) {
            if (
                (int) $submissionFile->getData('fileStage')
                    === SubmissionFile::SUBMISSION_FILE_DEPENDENT
                && (int) $submissionFile->getData('chapterId') === $chapterId
            ) {
                $selectedFiles[] = (int) $submissionFile->getId();
            }
        }

        return array_values(array_unique($selectedFiles));
    }

    /** @return iterable<int, SubmissionFile> */
    private function getDependentFiles(ChapterForm $form): iterable
    {
        return Repo::submissionFile()
            ->getCollector()
            ->includeDependentFiles(true)
            ->filterBySubmissionIds([$form->getMonograph()->getId()])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_DEPENDENT])
            ->getMany();
    }
}
