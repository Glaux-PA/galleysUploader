<?php

class GalleyManager {

    public function createGalley($publication, $extension, $locale) {
        $articleGalleyDao=\DAORegistry::getDAO('ArticleGalleyDAO');
        $articleGalley = $articleGalleyDao->newDataObject();
        $articleGalley->setData('publicationId', $publication->getId());
        $articleGalley->setLabel(strtoupper($extension));
        $articleGalley->setLocale($locale);
        $articleGalley->setData('urlPath', null);
        $articleGalley->setData('urlRemote', null);
        $articleGalley->setSequence($this->getSequence($extension));
        return $articleGalley;
    }

    public function deleteExistingGalleys($publication, $label, $locale) {
        $articleGalleyDao=\DAORegistry::getDAO('ArticleGalleyDAO');
        $currentGalleys = $articleGalleyDao->getByPublicationId($publication->getId())->toArray();

	    foreach ($currentGalleys as $galley) {
            if ($galley->getLabel() === $label && $galley->getLocale() === $locale) {
                $articleGalleyDao->deleteObject($galley);
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
