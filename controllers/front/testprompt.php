<?php

/**
 * Test Controller pour vérifier la connexion à Ollama et la génération de prompt.
 * * Accès via : http://votresite.com/module/injellik_aisearch/testprompt
 */
class Injellik_AisearchTestpromptModuleFrontController extends ModuleFrontController {

    public function initContent() {
        parent::initContent();

        $output = '<h2>Test de Connexion et Génération Ollama</h2>';
        $output .= '<p>Modèle utilisé : <strong>' . Configuration::get('INJELLIK_AI_MODEL') . '</strong></p>';

        $testPrompt = "Quel est le produit le plus populaire sur PrestaShop ? Réponds en une seule phrase.";

        try {
            // Assurez-vous que la classe OllamaClient est incluse (normalement faite par le module.php)
            // Sinon, décommentez la ligne suivante :
            // require_once _PS_MODULE_DIR_ . 'injellik_aisearch/classes/OllamaClient.php'; 

            $client = new OllamaClient();

            // Paramètres optionnels pour une réponse rapide et concise
            $params = [
                'options' => [
                    'temperature' => 0.0,
                    'num_predict' => 256,
                ],
            ];

            $output .= '<hr><h3>Prompt Envoyé</h3>';
            $output .= '<pre>' . htmlspecialchars($testPrompt) . '</pre>';

            // Appel à la génération
            $responseText = $client->generate($testPrompt, $params);

            if (empty($responseText)) {
                $output .= '<p class="alert alert-danger"><strong>ÉCHEC :</strong> La réponse est vide. Vérifiez l\'URL Ollama, le modèle et les logs d\'erreur PHP/cURL.</p>';
            } else {
                $output .= '<p class="alert alert-success"><strong>SUCCÈS :</strong> Réponse reçue.</p>';
                $output .= '<hr><h3>Réponse Ollama</h3>';
                $output .= '<pre>' . htmlspecialchars($responseText) . '</pre>';
            }
        } catch (Exception $e) {
            $output .= '<p class="alert alert-danger"><strong>ERREUR FATALE :</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
        }

        $this->context->smarty->assign([
            'page' => [
                'title' => 'Test Prompt Ollama',
                'body_classes' => ['page-test-prompt'],
            ],
            'test_output' => $output,
        ]);

        // Afficher directement le contenu (sans utiliser de template complexe)
        $this->setTemplate('module:injellik_aisearch/views/templates/front/test-output.tpl');
    }
}
