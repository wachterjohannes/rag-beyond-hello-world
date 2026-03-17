<?php

/**
 * Step 1: Naive RAG – Pure Vector Search
 *
 * Basic "embed + retrieve" approach. No query rewriting, no keyword matching, no reranking.
 * This is where most tutorials stop – and where most real-world RAG pipelines break.
 */

[$ollamaPlatform, $huggingFacePlatform, $store] = require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../helpers.php';

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Retriever;
use Symfony\Component\EventDispatcher\EventDispatcher;

$query = $argv[1] ?? 'send email';

echo "=== Naive RAG: Pure Vector Search ===\n\n";
echo "Query: \"{$query}\"\n\n";

// Set up event dispatcher
$dispatcher = new EventDispatcher();

// Retriever with just vectorizer – no query rewriting, no reranking
$vectorizer = new Vectorizer($huggingFacePlatform, EMBEDDING_MODEL);
$retriever = new Retriever($store, $vectorizer, $dispatcher);
$results = iterator_to_array($retriever->retrieve($query, [
    'maxItems' => 5,
    'semanticRatio' => 1.0, // 100% semantic = pure vector search
]));

echo "--- Retrieved Documents ---\n\n";
$context = displayResults($results);

// Generate answer with LLM
echo "--- Generated Answer ---\n\n";

$messages = new MessageBag(
    Message::forSystem('You are a helpful assistant. Answer the user\'s question based ONLY on the provided context. If the context contains conflicting information from different versions, mention all of them.'),
    Message::ofUser("Context:\n{$context}\n\nQuestion: {$query}"),
);
$response = $ollamaPlatform->invoke(LLM_MODEL, $messages);

echo $response->asText() . "\n";
