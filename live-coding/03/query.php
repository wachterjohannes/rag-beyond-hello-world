<?php

/**
 * Step 3: Add Hybrid Retrieval
 *
 * Vector search finds semantically similar docs – but misses exact keyword matches
 * like "Mailer" or "security.yaml". By switching to HybridQuery (vector + full-text),
 * we get both semantic understanding AND keyword precision.
 */

[$platform, $store] = require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../helpers.php';

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Retriever;
use Symfony\Component\EventDispatcher\EventDispatcher;

$query = $argv[1] ?? 'send email';

echo "=== Enhanced RAG: Hybrid Retrieval ===\n\n";
echo "Query: \"{$query}\"\n\n";

$dispatcher = new EventDispatcher();

// Deliberately no query rewriting here: this step changes exactly one thing versus step 1.
// Rewriting also works against full-text search – the store ORs every term together, so
// turning "Mailer" into "Symfony 8.0 mailer service configuration and usage" buries the one
// exact keyword under eight loose ones. Step 4 brings the rewriter back, where the reranker
// can clean up after it.

// NOW: Hybrid retrieval – vector + full-text search combined
$vectorizer = new Vectorizer($platform, EMBEDDING_MODEL);
$retriever = new Retriever($store, forQueries($vectorizer), $dispatcher);
$results = iterator_to_array($retriever->retrieve($query, [
    'maxItems' => 5,
    'semanticRatio' => 0.5, // 50% semantic + 50% keyword = hybrid search
]));

echo "--- Retrieved Documents (Hybrid: Vector + Full-Text) ---\n\n";
$context = displayResults($results, 'RRF rank score – rank-based, not similarity');

// Generate answer with LLM
echo "--- Generated Answer ---\n\n";

$messages = new MessageBag(
    Message::forSystem('You are a helpful assistant. Answer the user\'s question based ONLY on the provided context. If the context contains conflicting information from different versions, mention all of them.'),
    Message::ofUser("Context:\n{$context}\n\nQuestion: {$query}"),
);

$response = $platform->invoke(LLM_MODEL, $messages);

echo $response->asText() . "\n";
