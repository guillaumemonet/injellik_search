<?php
require_once __DIR__ . '/../../config/config.inc.php';
require_once __DIR__ . '/../../init.php';

$indexer = new AISearchIndexer();
$indexer->indexAll(200);
echo "Indexation started\n";
