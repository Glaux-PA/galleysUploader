<?php

require_once dirname(__DIR__) . '/PublicationFormatUploader.php';

final class TestProof
{
    private $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getData(string $key)
    {
        return $this->data[$key] ?? null;
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true)
        );
    }
}

function invokePrivate($object, string $method, array $arguments = [])
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $arguments);
}

$uploader = (new ReflectionClass(PublicationFormatUploader::class))->newInstanceWithoutConstructor();

$expectedChapterTarget = [
    'submissionId' => 1,
    'chapterId' => 1,
    'explicitLocale' => null,
];
foreach (['capitulo-cap1-1', 'style-cap1-1', 'imagen-cap1-1'] as $filename) {
    assertSameValue(
        $expectedChapterTarget,
        invokePrivate($uploader, 'extractSubmissionData', [$filename]),
        $filename . ' must retain submission and chapter IDs with no explicit locale.'
    );
}

assertSameValue(
    '1:chapter-1:es',
    invokePrivate($uploader, 'getParentProofTarget', [1, 1, 'es']),
    'Chapter HTML/XML proofs and dependents must share an exact target key.'
);
assertSameValue(
    '1:book:es',
    invokePrivate($uploader, 'getParentProofTarget', [1, null, 'es']),
    'Book-level proofs must use a target distinct from chapter proofs.'
);

$bookProof = new TestProof(['chapterId' => null, 'language' => 'es']);
$chapterProof = new TestProof(['chapterId' => 1, 'language' => 'es']);
assertSameValue(
    false,
    invokePrivate($uploader, 'proofMatchesTarget', [$bookProof, 'es', 1]),
    'A book-level HTML proof must never satisfy a chapter-level lookup.'
);
assertSameValue(
    true,
    invokePrivate($uploader, 'proofMatchesTarget', [$chapterProof, 'es', 1]),
    'The exact chapter and language proof must satisfy the lookup.'
);
assertSameValue(
    false,
    invokePrivate($uploader, 'proofMatchesTarget', [$chapterProof, 'es', null]),
    'A chapter proof must never satisfy a book-level lookup.'
);

$fileProcessor = new FileProcessor(null, null);
assertSameValue(
    'style-cap1-1.css',
    $fileProcessor->getFileInfo('style-cap1-1.css')['fileBase'],
    'Dependent CSS names must preserve the uploaded ZIP basename.'
);
assertSameValue(
    'imagen-cap1-1.jpg',
    $fileProcessor->getFileInfo('imagen-cap1-1.jpg')['fileBase'],
    'Dependent image names must preserve the uploaded ZIP basename.'
);

echo 'PublicationFormatUploader focused tests passed.' . PHP_EOL;