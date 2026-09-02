<?php

use APP\core\Application;
use APP\facades\Repo;
use APP\services\PublicationFormatService;
use PKP\submissionFile\SubmissionFile;

class PublicationFormatManager
{
    /**
     * Find the single publication format for an extension or create it.
     *
     * Existing formats are returned unchanged so that their metadata is
     * preserved. A stable urlPath is preferred, with a localized-name fallback
     * only for local digital formats that do not already have a urlPath.
     *
     * @return array{0: \APP\publicationFormat\PublicationFormat, 1: bool}
     */
    public function getOrCreateFormat($publication, string $extension, string $locale): array
    {
        $identifier = $this->normalizeIdentifier($extension);
        $label = strtoupper($identifier);
        $matches = $this->findMatchingFormats($publication->getId(), $identifier, $label);

        if (count($matches) > 1) {
            throw new RuntimeException(
                __('plugins.importexport.publicationFormatsUploader.error.ambiguousFormat', [
                    'extension' => $label,
                ])
            );
        }

        if (count($matches) === 1) {
            return [reset($matches), false];
        }

        $publicationFormatDao = Application::getRepresentationDAO();
        $format = $publicationFormatDao->newDataObject();
        $format->setData('publicationId', $publication->getId());
        $format->setPhysicalFormat(false);
        $format->setIsApproved(true);
        $format->setIsAvailable(true);
        $format->setProductAvailabilityCode('20');
        $format->setEntryKey('DA');
        $format->setName($label, $locale);
        $format->setData('urlPath', $identifier);
        $format->setSequence(REALLY_BIG_NUMBER);
        $publicationFormatDao->insertObject($format);

        return [$format, true];
    }

    /**
     * @throws RuntimeException When the extension cannot safely identify a format
     */
    public function normalizeIdentifier(string $extension): string
    {
        $identifier = strtolower(trim($extension));
        if (!preg_match('/^[a-z0-9]+([.\-_][a-z0-9]+)*$/', $identifier)) {
            throw new RuntimeException(
                __('plugins.importexport.publicationFormatsUploader.error.invalidFormatIdentifier', [
                    'extension' => $extension,
                ])
            );
        }
        return $identifier;
    }

    public function deleteCreatedFormatIfUnused($format, $submission, $context): void
    {
        $proofCount = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PROOF])
            ->filterByAssoc(Application::ASSOC_TYPE_PUBLICATION_FORMAT, [$format->getId()])
            ->getCount();

        if (!$proofCount) {
            (new PublicationFormatService())->deleteFormat($format, $submission, $context);
        }
    }

    /**
     * @return array<int, \APP\publicationFormat\PublicationFormat>
     */
    private function findMatchingFormats(int $publicationId, string $identifier, string $label): array
    {
        $pathMatches = [];
        $nameMatches = [];
        $formats = Application::getRepresentationDAO()->getByPublicationId($publicationId);

        foreach ($formats as $format) {
            $urlPath = trim((string) $format->getData('urlPath'));
            $isPhysical = (bool) $format->getPhysicalFormat();
            $isRemote = trim((string) $format->getData('urlRemote')) !== '';

            if ($urlPath !== '') {
                if (strcasecmp($urlPath, $identifier) !== 0) {
                    continue;
                }

                if ($isPhysical) {
                    throw new RuntimeException(
                        __('plugins.importexport.publicationFormatsUploader.error.physicalFormat', [
                            'extension' => $label,
                        ])
                    );
                }
                if ($isRemote) {
                    throw new RuntimeException(
                        __('plugins.importexport.publicationFormatsUploader.error.remoteFormat', [
                            'extension' => $label,
                        ])
                    );
                }

                $pathMatches[$format->getId()] = $format;
                continue;
            }

            if ($isPhysical || $isRemote) {
                continue;
            }

            $names = $format->getName(null);
            if (!is_array($names)) {
                $names = [$names];
            }

            foreach ($names as $name) {
                if (is_string($name) && strcasecmp(trim($name), $label) === 0) {
                    $nameMatches[$format->getId()] = $format;
                    break;
                }
            }
        }

        return $pathMatches ?: $nameMatches;
    }
}
