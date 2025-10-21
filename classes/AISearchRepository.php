<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * AISearchRepository - search product_vectors with pgvector and optional filters
 */
class AISearchRepository {

    private PDO $pdo;
    private OllamaClient $client;

    // La dimension doit être alignée sur ce qui fonctionne actuellement (1024)
    private const VECTOR_DIM = 1024;

    public function __construct() {
        $dsn = Configuration::get('INJELLIK_AI_PG_DSN');
        $user = Configuration::get('INJELLIK_AI_PG_USER');
        $pass = Configuration::get('INJELLIK_AI_PG_PASS');

        if (empty($dsn)) {
            throw new Exception('Postgres DSN not configured');
        }
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => false
        ]);
        $this->client = new OllamaClient();
    }

    /**
     * RAG Search: returns array of rows (id_product, content, distance).
     * @param string $query - La requête de recherche sémantique.
     * @param int $id_lang - La langue de recherche (essentiel).
     * @param string|null $content_filter - Filtre textuel optionnel sur la colonne 'content'.
     * @param int $limit - Nombre maximum de résultats à retourner.
     */
    public function searchWithWord(string $query, int $id_lang, string $content_filter = null, int $limit = 10): array {
        // 1. Génération du vecteur
        $vec = $this->client->embed($query);
        if (empty($vec) || !is_array($vec))
            return [];

        // Mise en forme du vecteur pour pgvector
        $vecLiteral = '[' . implode(',', array_map(function ($v) {
                            return str_replace(',', '.', (string) $v);
                        }, $vec)) . ']';

        // 2. Construction de la clause WHERE
        $where = [];
        $params = [
            ':qvec' => $vecLiteral,
            ':id_lang' => $id_lang
        ];

        // Filtre obligatoire par langue
        $where[] = 'id_lang = :id_lang';

        // Filtre textuel optionnel (combine recherche sémantique et textuelle)
        if (!empty($content_filter)) {
            $where[] = "content ILIKE :content_filter";
            $params[':content_filter'] = '%' . $content_filter . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // 3. Requête SQL: distance L2 (<->) et tri par pertinence
        $sql = "SELECT id_product, content, vector <-> :qvec AS distance
                FROM product_vectors
                $whereSql
                ORDER BY vector <-> :qvec
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);

        // 4. Bind et exécution
        $stmt->bindValue(':qvec', $vecLiteral);
        $stmt->bindValue(':id_lang', $id_lang, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        if (!empty($content_filter)) {
            $stmt->bindValue(':content_filter', $params[':content_filter'], PDO::PARAM_STR);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * RAG Search: returns array of product IDs (id_product) sorted by vector distance.
     * @param string $query - La requête de recherche sémantique.
     * @param int $id_lang - La langue de recherche.
     * @param int $limit - Nombre maximum de résultats. (Doit être grand pour le RAG)
     */
    public function search(string $query, int $id_lang, int $limit = 200): array {
        $vec = $this->client->embed($query);
        if (empty($vec) || !is_array($vec))
            return [];

        // Mise en forme du vecteur pour pgvector
        $vecLiteral = '[' . implode(',', array_map(function ($v) {
                            return str_replace(',', '.', (string) $v);
                        }, $vec)) . ']';

        // 1. Clause WHERE simplifiée (seulement la langue)
        $whereSql = 'WHERE id_lang = :id_lang';

        $sql = "SELECT id_product
                FROM product_vectors
                $whereSql
                ORDER BY vector <-> :qvec
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);

        $stmt->bindValue(':qvec', $vecLiteral);
        $stmt->bindValue(':id_lang', $id_lang, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        $stmt->execute();

        // On récupère uniquement les IDs des produits
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        return array_map('intval', $results);
    }

    public function deleteProduct(int $id_product) {
        // Delete for all languages
        $stmt = $this->pdo->prepare('DELETE FROM product_vectors WHERE id_product = :id');
        $stmt->execute([':id' => $id_product]);
    }

    public function deleteProductByLang(int $id_product, int $id_lang) {
        $stmt = $this->pdo->prepare('DELETE FROM product_vectors WHERE id_product = :id_product AND id_lang = :id_lang');
        $stmt->execute([':id_product' => $id_product, ':id_lang' => $id_lang]);
    }
}
