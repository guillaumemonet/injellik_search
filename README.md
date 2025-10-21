Injellik AI Search - module

Install:
1. Upload injellik_aisearch folder into modules/ and enable module in PrestaShop.
2. Go to Modules > injellik_aisearch > Configure.
3. Set Ollama URL, Model, Timeout, and Postgres DSN/user/pass.
4. Click 'Test & Initialize Postgres' to create extensions/tables.
5. Run CLI indexer: php modules/injellik_aisearch/cli/build_embeddings.php
