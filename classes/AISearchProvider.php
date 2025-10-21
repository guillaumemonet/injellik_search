<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchProviderInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;

/**
 * Fournisseur de recherche AI - intègre le moteur sémantique basé sur Ollama + PostgreSQL (pgvector)
 */
class AISearchProvider implements ProductSearchProviderInterface {

    private Context $context;
    public AISearchRepository $repository;

    public function __construct(Context $context) {
        $this->context = $context;
        $this->repository = new AISearchRepository();
    }

    /**
     * Point d’entrée du moteur de recherche (hooké sur facetedsearch)
     */
    public function runQuery(ProductSearchContext $context, ProductSearchQuery $query): ProductSearchResult {
        $result = new ProductSearchResult();
        $searchTerm = trim($query->getSearchString());

        if (empty($searchTerm)) {
            return $result;
        }

        try {
            // Appel du repository (recherche vectorielle)
            $productIds = $this->repository->search($searchTerm, (int) $this->context->language->id, 200);

            if (empty($productIds)) {
                return $result;
            }

            // Récupération des produits correspondants
            $products = $this->getProductsFromIds($productIds);

            $result->setProducts($products);
            $result->setTotalResultsCount(count($products));

            return $result;
        } catch (Exception $e) {
            PrestaShopLogger::addLog('[AISearchProvider] Erreur : ' . $e->getMessage(), 3);
            return $result;
        }
    }

    /**
     * Transforme les IDs produits en objets PrestaShop enrichis (nom, image, lien)
     */
    private function getProductsFromIds(array $ids): array {
        $products = [];
        $id_lang = (int) $this->context->language->id;

        foreach ($ids as $id_product) {
            $product = new Product($id_product, false, $id_lang);

            if (!Validate::isLoadedObject($product)) {
                continue;
            }

            $products[] = [
                'id_product' => $product->id,
                'name' => $product->name,
                'link' => $this->context->link->getProductLink($product),
                'description_short' => strip_tags($product->description_short),
                'cover' => Product::getCover($product->id),
            ];
        }

        return $products;
    }
}
