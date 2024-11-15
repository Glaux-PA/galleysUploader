<?php

use APP\facades\Repo;
class GalleyManager {

    public function createGalley($publication, $extension, $locale) {
        $articleGalley = Repo::galley()->newDataObject();
        $articleGalley->setData('publicationId', $publication->getId());
        $articleGalley->setLabel(strtoupper($extension));
        $articleGalley->setLocale($locale);
        $articleGalley->setData('urlPath', null);
        $articleGalley->setData('urlRemote', null);
        $articleGalley->setSequence($this->getSequence($extension));
        return $articleGalley;
    }

    public function deleteExistingGalleys($idSubmission, $extension, $locale) {
        $currentGalleys = Repo::galley()
            ->getCollector()
            ->filterByPublicationIds([$idSubmission])
            ->getMany()
            ->toArray();

        foreach ($currentGalleys as $galley) {
            if ($galley->getLabel() === strtoupper($extension) && $galley->getLocale() === $locale) {
                Repo::galley()->delete($galley);
            }
        }
    }

    private function getSequence($extension) {
        $sequences = [
            'pdf' => 0,
            'html' => 1,
            'xml' => 2,
            'epub' => 3,
        ];
        return isset($sequences[$extension]) ? $sequences[$extension] : 0;
    }
}
