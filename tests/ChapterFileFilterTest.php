<?php

namespace PKP\submissionFile {
    if (!class_exists(SubmissionFile::class)) {
        class SubmissionFile
        {
            public const SUBMISSION_FILE_DEPENDENT = 11;
            public const SUBMISSION_FILE_PRODUCTION_READY = 10;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/ChapterFileFilter.php';

    final class ChapterFileFilterTestFile
    {
        private $id;
        private $data;

        public function __construct(int $id, int $fileStage, ?int $chapterId)
        {
            $this->id = $id;
            $this->data = [
                'fileStage' => $fileStage,
                'chapterId' => $chapterId,
            ];
        }

        public function getId(): int
        {
            return $this->id;
        }

        public function getData(string $key)
        {
            return $this->data[$key] ?? null;
        }
    }

    function assertChapterFilterSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
                . PHP_EOL . 'Actual: ' . var_export($actual, true)
            );
        }
    }

    $filter = new ChapterFileFilter();
    $normalStage = \PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY;
    $dependentStage = \PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_DEPENDENT;

    $files = [
        new ChapterFileFilterTestFile(101, $normalStage, 7), // PDF
        new ChapterFileFilterTestFile(102, $normalStage, null), // EPUB
        new ChapterFileFilterTestFile(103, $normalStage, 7), // HTML
        new ChapterFileFilterTestFile(104, $normalStage, null), // XML
        new ChapterFileFilterTestFile(201, $dependentStage, 7),
        new ChapterFileFilterTestFile(202, $dependentStage, null),
        new ChapterFileFilterTestFile(203, $dependentStage, 8),
    ];

    [$options, $selected] = $filter->filterOptions(
        [
            101 => 'chapter.pdf',
            102 => 'book.epub',
            103 => 'chapter.html',
            104 => 'book.xml',
            201 => 'image.jpg',
            202 => 'style.css',
            203 => 'other-chapter-image.jpg',
        ],
        ['101', '103', '201'],
        $files
    );

    assertChapterFilterSame(
        [
            101 => 'chapter.pdf',
            102 => 'book.epub',
            103 => 'chapter.html',
            104 => 'book.xml',
        ],
        $options,
        'Only dependent files must be removed from chapterFileOptions.'
    );
    assertChapterFilterSame(
        ['101', '103'],
        $selected,
        'Hidden dependent files must also be removed from the displayed selection.'
    );

    assertChapterFilterSame(
        [101, 103, 201],
        $filter->preserveAssignments(['101', '103'], $files, 7),
        'A dependent already assigned to the saved chapter must be preserved.'
    );
    assertChapterFilterSame(
        [101, 201],
        $filter->preserveAssignments([101, 201], $files, 7),
        'An already submitted dependent ID must not be duplicated.'
    );
    assertChapterFilterSame(
        [102],
        $filter->preserveAssignments([102], $files, 9),
        'Unassigned dependents and dependents assigned to other chapters must remain unchanged.'
    );

    echo 'ChapterFileFilter focused tests passed.' . PHP_EOL;
}
