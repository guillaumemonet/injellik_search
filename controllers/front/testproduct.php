<?php
/**
 * Test Controller - Recherche IA complète avec reformulation du prompt par LLaMA3.
 * Accès via : http://votresite.com/module/injellik_aisearch/testproduct
 */
class Injellik_AisearchTestproductModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $output = '<h2>Test complet – Recherche IA (Reformulation + RAG)</h2>';

        $model = Configuration::get('INJELLIK_AI_MODEL');
        $output .= '<p>Modèle utilisé pour la génération : <strong>' . htmlspecialchars($model) . '</strong></p>';

        $originalPrompt = "tasse en céramique blanche";
        $id_lang = (int)$this->context->language->id;

        try {
            require_once _PS_MODULE_DIR_ . 'injellik_aisearch/classes/OllamaClient.php';
            require_once _PS_MODULE_DIR_ . 'injellik_aisearch/classes/AISearchRepository.php';

            $client = new OllamaClient();
            $provider = new AISearchRepository();

            // Étape 1 : Reformulation du prompt par LLaMA3
            $reformulationPrompt = sprintf(
                "Réécris la requête utilisateur suivante de manière claire et complète, en francais, pour une recherche e-commerce : \"%s\". Ne donne aucune explication, renvoie uniquement la requête reformulée",
                $originalPrompt
            );

            $output .= '<hr><h3>Prompt initial</h3><pre>' . htmlspecialchars($originalPrompt) . '</pre>';
            $output .= '<h3>Prompt de reformulation envoyé à LLaMA3</h3><pre>' . htmlspecialchars($reformulationPrompt) . '</pre>';

            $reformulated = trim($client->generate($reformulationPrompt, [
                'options' => [
                    'temperature' => 0.2,
                    'num_predict' => 64,
                ],
            ]));

            if (empty($reformulated)) {
                $reformulated = $originalPrompt;
                $output .= '<p class="alert alert-warning"><strong>⚠️ Reformulation échouée.</strong> Utilisation du prompt original.</p>';
            } else {
                $output .= '<p class="alert alert-success"><strong>Reformulation réussie :</strong></p>';
                $output .= '<pre>' . htmlspecialchars($reformulated) . '</pre>';
            }


			$vector = $client->embed($reformulated); 

            if (empty($vector) || !is_array($vector)) {
                 $output .= '<p class="alert alert-danger"><strong>ÉCHEC :</strong> Le vecteur est vide ou invalide. Vérifiez la configuration du modèle dans l\'admin.</p>';
            } else {
                 $vector_dim = count($vector);
                 $output .= '<p class="alert alert-success"><strong>SUCCÈS :</strong> Vecteur reçu.</p>';
                 $output .= '<p>Dimension du vecteur : <strong>' . $vector_dim . '</strong></p>';
                 
                 // CORRECTION 2 : Affichage des premières valeurs du tableau
                 $output .= '<hr><h3>Premières valeurs du Vecteur ('. $vector_dim .'D)</h3>';
                 $output .= '<pre>' . htmlspecialchars(json_encode(array_slice($vector, 0, 10), JSON_PRETTY_PRINT)) . '...</pre>';
            }


            // Étape 3 : Recherche sémantique via AISearchRepository
            $ids = $provider->search($reformulated, $id_lang, 50);

            if (empty($ids)) {
                $output .= '<p class="alert alert-warning"><strong>Aucun produit trouvé.</strong></p>';
            } else {
                $output .= '<hr><h3>Résultats RAG</h3>';
                $output .= '<p><strong>' . count($ids) . '</strong> produits correspondants.</p>';
                $output .= '<pre>' . htmlspecialchars(implode(', ', $ids)) . '</pre>';

                // Charger les produits
                $products = [];
                foreach ($ids as $id) {
                    $p = new Product((int)$id, false, $id_lang);
                    if (Validate::isLoadedObject($p)) {
                        $products[] = [
                            'id' => $id,
                            'name' => $p->name,
                            'link' => $this->context->link->getProductLink($p),
                        ];
                    }
                }

                if (!empty($products)) {
                    $output .= '<hr><h3>Détails Produits</h3><ul>';
                    foreach ($products as $p) {
                        $output .= '<li><a href="' . htmlspecialchars($p['link']) . '" target="_blank">'
                                 . htmlspecialchars($p['name']) . ' (#' . $p['id'] . ')</a></li>';
                    }
                    $output .= '</ul>';
                }
            }
        } catch (Exception $e) {
            $output .= '<p class="alert alert-danger"><strong>Erreur :</strong> '
                     . htmlspecialchars($e->getMessage()) . '</p>';
        }

        $this->context->smarty->assign([
            'page' => [
                'title' => 'Test Recherche IA avec Reformulation',
                'body_classes' => ['page-test-ai-rag'],
            ],
            'test_output' => $output,
        ]);

        $this->setTemplate('module:injellik_aisearch/views/templates/front/test-output.tpl');
    }
}
