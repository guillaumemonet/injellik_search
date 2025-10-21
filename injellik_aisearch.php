<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

// Assurez-vous que ces fichiers existent dans le dossier 'classes/'
require_once __DIR__ . '/classes/OllamaClient.php';
require_once __DIR__ . '/classes/AISearchIndexer.php';
require_once __DIR__ . '/classes/AISearchRepository.php';
require_once __DIR__ . '/classes/AISearchProvider.php'; // NOUVELLE LIGNE

class Injellik_Aisearch extends Module {

    public function __construct() {
        $this->name = 'injellik_aisearch';
        $this->tab = 'search_filter';
        $this->version = '1.3.4'; // Nouvelle version après correction d'affichage du formulaire
        $this->author = 'Guillaume Monet';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Injellik AI Search (Ollama + RAG)');
        $this->description = $this->l('Recherche conversationnelle locale via Ollama + RAG (pgvector).');
        $this->ps_versions_compliancy = ['min' => '8.0', 'max' => _PS_VERSION_];
    }

    public function install() {
        $this->createDefaultConfiguration();
        return parent::install() && $this->registerHook('displayTop') && $this->registerHook('displayHeader') && $this->registerHook('actionProductSave') && $this->registerHook('actionProductAdd') && $this->registerHook('actionProductDelete') && $this->registerHook('actionProductSearchProviderRunQueryBefore');
    }

    public function uninstall() {
        $this->deleteConfiguration();
        return parent::uninstall();
    }

    private function createDefaultConfiguration() {
        // Uniformisation des clés de configuration pour tous les besoins
        Configuration::updateValue('INJELLIK_AI_OLLAMA_URL', 'http://192.168.122.1:11434');
        Configuration::updateValue('INJELLIK_AI_MODEL', 'llama3');
        Configuration::updateValue('INJELLIK_AI_TIMEOUT', 30);
        // AJOUT du modèle d'embedding manquant, essentiel pour le RAG
        Configuration::updateValue('INJELLIK_AI_OLLAMA_EMBED_MODEL', 'mxbai-embed-large');

        // Clés DB basées sur le DSN pour la cohérence PDO
        Configuration::updateValue('INJELLIK_AI_PG_DSN', 'pgsql:host=192.168.122.1;port=5432;dbname=aisearch');
        Configuration::updateValue('INJELLIK_AI_PG_USER', 'aiuser');
        Configuration::updateValue('INJELLIK_AI_PG_PASS', 'aipass');
    }

    private function deleteConfiguration() {
        Configuration::deleteByName('INJELLIK_AI_OLLAMA_URL');
        Configuration::deleteByName('INJELLIK_AI_MODEL');
        Configuration::deleteByName('INJELLIK_AI_TIMEOUT');
        Configuration::deleteByName('INJELLIK_AI_OLLAMA_EMBED_MODEL');
        Configuration::deleteByName('INJELLIK_AI_PG_DSN');
        Configuration::deleteByName('INJELLIK_AI_PG_USER');
        Configuration::deleteByName('INJELLIK_AI_PG_PASS');
    }

    public function getContent() {
        $output = '';

        // 1. Sauvegarde des paramètres de configuration (bouton 'submitInjellikAiConfig')
        if (Tools::isSubmit('submitInjellikAiConfig')) {
            $ollama_url = trim(Tools::getValue('INJELLIK_AI_OLLAMA_URL'));
            $model = trim(Tools::getValue('INJELLIK_AI_MODEL'));
            $embed_model = trim(Tools::getValue('INJELLIK_AI_OLLAMA_EMBED_MODEL')); // Nouvelle clé
            $timeout = (int) Tools::getValue('INJELLIK_AI_TIMEOUT');
            $pgdsn = trim(Tools::getValue('INJELLIK_AI_PG_DSN'));
            $pguser = trim(Tools::getValue('INJELLIK_AI_PG_USER'));
            $pgpass = Tools::getValue('INJELLIK_AI_PG_PASS');

            if (!$ollama_url || !$model || !$embed_model || !$pgdsn || !$pguser) {
                $output .= $this->displayError($this->l('Veuillez remplir tous les champs de configuration LLM et PostgreSQL obligatoires.'));
            } else {
                Configuration::updateValue('INJELLIK_AI_OLLAMA_URL', $ollama_url);
                Configuration::updateValue('INJELLIK_AI_MODEL', $model);
                Configuration::updateValue('INJELLIK_AI_OLLAMA_EMBED_MODEL', $embed_model);
                Configuration::updateValue('INJELLIK_AI_TIMEOUT', $timeout);
                Configuration::updateValue('INJELLIK_AI_PG_DSN', $pgdsn);
                Configuration::updateValue('INJELLIK_AI_PG_USER', $pguser);

                if ($pgpass !== false && $pgpass !== '') {
                    Configuration::updateValue('INJELLIK_AI_PG_PASS', $pgpass);
                }

                $output .= $this->displayConfirmation($this->l('Paramètres de configuration enregistrés.'));
            }
        }

        // 2. Initialisation PostgreSQL (bouton 'submitInjellikInitDb')
        if (Tools::isSubmit('submitInjellikInitDb')) {
            $output .= $this->installPgsql();
        }

        // 3. Ré-indexation (bouton 'submitInjellikReindex')
        if (Tools::isSubmit('submitInjellikReindex')) {
            try {
                // Vérifiez que la classe est disponible avant d'essayer de l'instancier
                if (!class_exists('AISearchIndexer')) {
                    throw new Exception("La classe AISearchIndexer est introuvable. Vérifiez l'inclusion des fichiers.");
                }
                $indexer = new AISearchIndexer();

                $indexer->indexAll();
                $output .= $this->displayConfirmation($this->l('Ré-indexation des produits lancée.'));
            } catch (Exception $e) {
                $output .= $this->displayError($this->l('Erreur lors de la ré-indexation: ') . $e->getMessage());
            }
        }

        // Afficher le formulaire unique, contenant tous les blocs
        return $output . $this->renderForm();
    }

    /**
     * Create pgvector extension and embeddings table if connection available.
     */
    private function installPgsql() {
        $dsn = Configuration::get('INJELLIK_AI_PG_DSN');
        $user = Configuration::get('INJELLIK_AI_PG_USER');
        $pass = Configuration::get('INJELLIK_AI_PG_PASS');

        if (empty($dsn) || empty($user)) {
            return $this->displayError($this->l('Postgres DSN and user must be configured before initialization.'));
        }

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => false
            ]);

            // 1. Créer l'extension pgvector
            $pdo->exec("CREATE EXTENSION IF NOT EXISTS vector;");

            // 2. CRÉER LA TABLE AVEC LA DIMENSION 1024 (pour correspondre à l'output actuel d'Ollama)
            // Note: Si vous corrigez Ollama plus tard, vous devrez repasser à VECTOR(1536)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS product_vectors (
                    id SERIAL PRIMARY KEY,
                    id_product INT NOT NULL,  
                    id_lang INT NOT NULL,     
                    content TEXT NOT NULL,
                    vector VECTOR(1024) NOT NULL
                );
            ");

            // 3. CRÉER L'INDEX UNIQUE sur la clé composite (produit + langue)
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS product_vectors_product_lang_idx ON product_vectors (id_product, id_lang);");

            // 4. CRÉER L'INDEX IVFFlat (avec l'opérateur explicite pour la distance L2)
            $pdo->exec("CREATE INDEX IF NOT EXISTS product_vectors_vector_idx ON product_vectors 
                USING ivfflat (vector vector_l2_ops) 
                WITH (lists = 100);");

            return $this->displayConfirmation($this->l('Postgres initialized (extensions/tables created with 1024D schema).'));
        } catch (PDOException $e) {
            return $this->displayError($this->l('Postgres error: ') . $e->getMessage());
        }
    }

    public function renderForm() {
        // Définition de tous les blocs de formulaire
        $fields_form = [];

        // --- BLOC 1: Configuration LLM et DB ---
        $fields_form[0]['form'] = [
            'legend' => [
                'title' => $this->l('1. Configuration LLM et PostgreSQL'),
                'icon' => 'icon-cogs'
            ],
            'input' => [
                // Paramètres Ollama / LLM
                [
                    'type' => 'html',
                    'name' => 'html_llm_info',
                    'html_content' => '<h4 style="margin-top: 10px;">' . $this->l('Paramètres Ollama (LLM/IA)') . '</h4>'
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Ollama URL'),
                    'name' => 'INJELLIK_AI_OLLAMA_URL',
                    'required' => true,
                    'desc' => $this->l('Ex: http://localhost:11434. Adresse du serveur Ollama.')
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Modèle de Chat (LLM)'),
                    'name' => 'INJELLIK_AI_MODEL',
                    'required' => true,
                    'desc' => $this->l('Modèle pour la conversation (Ex: llama3).')
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Modèle d\'Embedding'),
                    'name' => 'INJELLIK_AI_OLLAMA_EMBED_MODEL', // Clé corrigée
                    'required' => true,
                    'desc' => $this->l('Modèle pour la vectorisation (Ex: mxbai-embed-large).')
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Timeout (s)'),
                    'name' => 'INJELLIK_AI_TIMEOUT',
                    'required' => false,
                    'desc' => $this->l('Délai d\'attente des requêtes Ollama.')
                ],
                // Paramètres PostgreSQL
                [
                    'type' => 'html',
                    'name' => 'html_db_info',
                    'html_content' => '<hr><h4 style="margin-top: 20px;">' . $this->l('Paramètres PostgreSQL / pgvector') . '</h4>'
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('PostgreSQL DSN'),
                    'name' => 'INJELLIK_AI_PG_DSN',
                    'required' => true,
                    'desc' => $this->l('Ex: pgsql:host=localhost;port=5432;dbname=votre_db')
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Utilisateur de la Base'),
                    'name' => 'INJELLIK_AI_PG_USER',
                    'required' => true
                ],
                [
                    'type' => 'password',
                    'label' => $this->l('Mot de passe de la Base'),
                    'name' => 'INJELLIK_AI_PG_PASS',
                    'required' => false,
                    'desc' => $this->l('Laissez vide pour ne pas modifier le mot de passe existant.')
                ],
            ],
            'submit' => [
                'title' => $this->l('Enregistrer la Configuration'),
                'name' => 'submitInjellikAiConfig', // Action pour la sauvegarde
                'class' => 'btn btn-success pull-right'
            ],
        ];

        // --- BLOC 2: Initialisation DB ---
        $fields_form[1]['form'] = [
            'legend' => [
                'title' => $this->l('2. Initialisation PostgreSQL / pgvector'),
                'icon' => 'icon-database'
            ],
            'input' => [
                [
                    'type' => 'html',
                    'name' => 'pg_init_info',
                    'html_content' => $this->l('Cliquez pour activer l\'extension **pgvector** et créer la table de vecteurs. **Assurez-vous d\'avoir ENREGISTRÉ la configuration PostgreSQL ci-dessus avant de lancer.**')
                ]
            ],
            'submit' => [
                'title' => $this->l('Initialiser la Base de Données (pgvector)'),
                'name' => 'submitInjellikInitDb', // Action pour l'initialisation
                'class' => 'btn btn-danger'
            ]
        ];

        // --- BLOC 3: Ré-indexation ---
        $fields_form[2]['form'] = [
            'legend' => [
                'title' => $this->l('3. Ré-indexation Complète des Produits'),
                'icon' => 'icon-refresh'
            ],
            'input' => [
                [
                    'type' => 'html',
                    'name' => 'reindex_info',
                    'html_content' => $this->l('Lancez une ré-indexation complète de tous les produits. Nécessaire après l\'initialisation ou un changement majeur des données. **Peut prendre du temps.**')
                ]
            ],
            'submit' => [
                'title' => $this->l('Lancer la Ré-indexation'),
                'name' => 'submitInjellikReindex', // Action pour la ré-indexation
                'class' => 'btn btn-warning'
            ]
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;

        $helper->identifier = $this->identifier;
        // Définir l'action de soumission sur le premier bouton de sauvegarde
        $helper->submit_action = 'submitInjellikAiConfig';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        // --- Assignation des valeurs (Clés harmonisées) ---
        $helper->fields_value['INJELLIK_AI_OLLAMA_URL'] = Configuration::get('INJELLIK_AI_OLLAMA_URL');
        $helper->fields_value['INJELLIK_AI_MODEL'] = Configuration::get('INJELLIK_AI_MODEL');
        $helper->fields_value['INJELLIK_AI_OLLAMA_EMBED_MODEL'] = Configuration::get('INJELLIK_AI_OLLAMA_EMBED_MODEL');
        $helper->fields_value['INJELLIK_AI_TIMEOUT'] = Configuration::get('INJELLIK_AI_TIMEOUT');
        $helper->fields_value['INJELLIK_AI_PG_DSN'] = Configuration::get('INJELLIK_AI_PG_DSN');
        $helper->fields_value['INJELLIK_AI_PG_USER'] = Configuration::get('INJELLIK_AI_PG_USER');
        $helper->fields_value['INJELLIK_AI_PG_PASS'] = ''; // Toujours vide pour la sécurité

        return $helper->generateForm($fields_form);
    }

    /**
     * Hook pour charger les fichiers CSS et JS sur le frontend.
     */
    public function hookDisplayHeader($params) {
        // Charger les assets (CSS et JS)
        $this->context->controller->addCSS($this->_path . 'views/css/front.css');
        $this->context->controller->addJS($this->_path . 'views/js/front.js');
    }

    /**
     * Hook pour afficher le formulaire de recherche (widget) dans l'entête.
     */
    public function hookDisplayTop($params) {
        // Passer l'URL AJAX pour la conversation RAG Chat
        $this->context->smarty->assign([
            'injellik_ai_ajax' => $this->context->link->getModuleLink($this->name, 'converseajax', [], true),
            'injellik_ai_placeholder' => $this->l('Rechercher (AI)...')
        ]);

        return $this->display(__FILE__, 'views/templates/hook/search.tpl');
    }

    public function hookActionProductSearchProviderRunQueryBefore($params) {
        try {
            /** @var ProductSearchQuery $query */
            $query = $params['query'];
            $search = $query->getSearchString();

            if (empty($search)) {
                return;
            }

            // Instanciation avec le contexte PrestaShop
            $aiProvider = new AISearchProvider(Context::getContext());

            // Récupération des résultats (retourne un tableau d'ID produits)
            $ids = $aiProvider->repository->search(
                    $search,
                    (int) Context::getContext()->language->id,
                    200
            );

            // Debug provisoire
            print_r($ids);
            exit;

            if (!empty($ids)) {
                // Injection des résultats dans la query
                $query->setResultsIds($ids);
                $query->setQueryType('ai');
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog('[AI Search] Erreur : ' . $e->getMessage(), 3);
        }
    }

    // product hooks for incremental indexing
    public function hookActionProductAdd($params) {
        if (!isset($params['id_product']))
            return;
        $indexer = new AISearchIndexer();
        $indexer->indexProduct((int) $params['id_product']);
    }

    public function hookActionProductSave($params) {
        if (!isset($params['id_product']))
            return;
        $indexer = new AISearchIndexer();
        $indexer->indexProduct((int) $params['id_product']);
    }

    public function hookActionProductDelete($params) {
        if (!isset($params['id_product']))
            return;
        $repo = new AISearchRepository();
        $repo->deleteProduct((int) $params['id_product']);
    }
}
