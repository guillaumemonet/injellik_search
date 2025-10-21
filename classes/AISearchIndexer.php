<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * AISearchIndexer - index product into Postgres pgvector
 */
class AISearchIndexer
{
    private PDO $pdo;
    private OllamaClient $client;

    public function __construct()
    {
        $dsn = Configuration::get('INJELLIK_AI_PG_DSN');
        $user = Configuration::get('INJELLIK_AI_PG_USER');
        $pass = Configuration::get('INJELLIK_AI_PG_PASS');
        
        // Initialisation du client Ollama (on suppose que cette classe est correcte)
        $this->client = new OllamaClient(); 

        if (empty($dsn)) {
            throw new Exception('Configuration DSN PostgreSQL non trouvée.');
        }
        
        // Connexion à la base de données PostgreSQL (PDO standard)
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => false
        ]);
    }

    /**
     * Indexe tous les produits pour toutes les langues actives.
     * @param int $batch Taille du lot de produits à traiter à la fois.
     */
    public function indexAll(int $batch = 200)
    {
        // 1. Récupérer toutes les langues actives de PrestaShop
        $languages = Language::getLanguages(true, false, true);

        if (empty($languages)) {
            throw new Exception('Aucune langue active trouvée. Impossible d\'indexer.'); 
        }

        $offset = 0;
        while (true) {
            // CORRECTION: Utilisation de query() pour les requêtes avec LIMIT/OFFSET sans besoin de binding complexe
            $sql = 'SELECT p.id_product FROM ' . _DB_PREFIX_ . 'product p ORDER BY p.id_product LIMIT ' . (int)$batch . ' OFFSET ' . (int)$offset;
            
            // Exécution de la requête via le wrapper Db de PrestaShop
            $rows = Db::getInstance()->executeS($sql);

            if (empty($rows)) break;

            foreach ($rows as $r) {
                $id_product = (int)$r['id_product'];
                
                // Indexer le produit pour chaque langue active
                foreach ($languages as $lang) {
                    $this->indexProduct($id_product, (int)$lang['id_lang']);
                }
            }
            $offset += $batch;
        }
    }

    /**
     * Indexe un seul produit pour une langue donnée.
     */
    public function indexProduct(int $id_product, int $id_lang)
    {
        $prod = new Product($id_product, true, $id_lang);
        
        if (empty($prod->name) || empty($prod->description_short)) {
             return; 
        }

        // 1. Collecte et Nettoyage du contenu textuel
        $name = $prod->name;
        $desc = $prod->description_short ?: $prod->description; 
        
        $desc = strip_tags(html_entity_decode($desc, ENT_QUOTES, 'UTF-8'));
        
        $features = $this->getFeatures($id_product, $id_lang);
        $attributes = $this->getAttributes($id_product, $id_lang);

        $text = trim(
            "Nom: $name\n" . 
            "Description: $desc\n" . 
            "Caractéristiques: " . json_encode($features, JSON_UNESCAPED_UNICODE) . "\n" . 
            "Attributs: " . json_encode($attributes, JSON_UNESCAPED_UNICODE)
        );

        // 2. Génération du vecteur via OllamaClient
        try {
            $vector = $this->client->embed($text);
        } catch (Exception $e) {
            error_log("Erreur Ollama pour produit $id_product / lang $id_lang: " . $e->getMessage());
            return;
        }
        
        if (empty($vector) || !is_array($vector)) {
            return; 
        }

        // 3. Mise en forme du vecteur pour pgvector (PostgreSQL standard PDO, pas de changement)
        $vecLiteral = '[' . implode(',', array_map(function($v){ return str_replace(',', '.', (string)$v); }, $vector)) . ']';

        // 4. Insertion/Mise à jour dans PostgreSQL
        $sql = 'INSERT INTO product_vectors (id_product, id_lang, content, vector)
                VALUES (:id_product, :id_lang, :content, :vector)
                ON CONFLICT (id_product, id_lang) 
                DO UPDATE SET content = EXCLUDED.content, vector = EXCLUDED.vector';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id_product' => $id_product,
            ':id_lang' => $id_lang,
            ':content' => $text,
            ':vector' => $vecLiteral
        ]);
    }

    /**
     * Récupère les caractéristiques (features) du produit pour la langue donnée.
     */
    private function getFeatures(int $id_product, int $id_lang): array
    {
        // CORRECTION: Utilisation de executeS() qui gère l'exécution et le fetch des résultats
        $sql = '
            SELECT 
                f.name AS feature_name, 
                fvl.value AS feature_value 
            FROM ' . _DB_PREFIX_ . 'feature_product fp
            LEFT JOIN ' . _DB_PREFIX_ . 'feature_lang f ON (f.id_feature = fp.id_feature AND f.id_lang = ' . (int)$id_lang . ')
            LEFT JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl ON (fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = ' . (int)$id_lang . ')
            WHERE fp.id_product = ' . (int)$id_product;

        // executeS retourne un tableau de résultats ou false/array vide
        $results = Db::getInstance()->executeS($sql); 
        return is_array($results) ? $results : [];
    }

    /**
     * Récupère les attributs (combinations) du produit pour la langue donnée.
     * Correction de l'erreur SQL en introduisant la table ps_attribute (a)
     */
    private function getAttributes(int $id_product, int $id_lang): array
    {
        $sql = 'SELECT agl.name AS group_name, al.name AS attribute_name
                FROM ' . _DB_PREFIX_ . 'product_attribute pa
                JOIN ' . _DB_PREFIX_ . 'product_attribute_combination pac ON pac.id_product_attribute = pa.id_product_attribute
                JOIN ' . _DB_PREFIX_ . 'attribute a ON a.id_attribute = pac.id_attribute  /* NOUVEAU: Table ps_attribute pour récupérer l\'ID du groupe */
                JOIN ' . _DB_PREFIX_ . 'attribute_lang al ON al.id_attribute = pac.id_attribute AND al.id_lang = ' . (int)$id_lang . '
                JOIN ' . _DB_PREFIX_ . 'attribute_group_lang agl ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = ' . (int)$id_lang . ' /* CORRECTION: Utilisation de a.id_attribute_group */
                WHERE pa.id_product = ' . (int)$id_product . '
                GROUP BY agl.name, al.name';

        $results = Db::getInstance()->executeS($sql);
        return is_array($results) ? $results : [];
    }
}