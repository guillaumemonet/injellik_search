<?php
// Fichier: controllers/front/testembed.php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Test Controller pour vérifier la connexion à Ollama et l'embedding.
 * Accès via : http://votresite.com/module/injellik_aisearch/testembed
 */
class Injellik_AisearchTestembedModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $output = '<h2>Test de Connexion et Embedding Ollama</h2>';
        $output .= '<p>Modèle d\'Embedding : <strong>' . Configuration::get('INJELLIK_AI_OLLAMA_EMBED_MODEL') . '</strong></p>';
        
        $testPrompt = "Vecteur pour un produit de test avec une couleur bleue et une taille grande.";

        try {
            $client = new OllamaClient();

            $output .= '<hr><h3>Prompt Envoyé</h3>';
            $output .= '<pre>' . htmlspecialchars($testPrompt) . '</pre>';
            
            // CORRECTION 1 : Appel correct sans l'argument $params
            $vector = $client->embed($testPrompt); 

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

        } catch (Exception $e) {
            $output .= '<p class="alert alert-danger"><strong>ERREUR FATALE :</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
        }

        $this->context->smarty->assign([
            // CORRECTION ICI : Initialisation de body_classes
            'page' => [
                'title' => 'Test Embedding Ollama',
                'body_classes' => [], // Ajoutez ceci pour éviter le TypeError: null given
            ],
            'test_output' => $output,
        ]);

        $this->setTemplate('module:injellik_aisearch/views/templates/front/test-output.tpl');
    }
}