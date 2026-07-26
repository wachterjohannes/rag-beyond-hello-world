<?php

/**
 * Step 1: Naive RAG – Pure Vector Search
 *
 * Basic "embed + retrieve" approach. No query rewriting, no keyword matching, no reranking.
 * This is where most tutorials stop – and where most real-world RAG pipelines break.
 */

[$platform, $store] = require __DIR__ . '/../bootstrap.php';
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

// Retriever with just vectorizer – no query rewriting, no reranking.
// vectorOnly() keeps this genuinely pure vector search: the store also supports hybrid
// queries, and the Retriever would prefer those even at semanticRatio 1.0 (see helpers.php).
// forQueries() embeds the question in Cohere's search_query mode, the counterpart to the
// search_document mode the indexer used – same model, but the two sides have to match.
$vectorizer = new Vectorizer($platform, EMBEDDING_MODEL);
$retriever = new Retriever(vectorOnly($store), forQueries($vectorizer), $dispatcher);
$results = iterator_to_array($retriever->retrieve($query, [
    'maxItems' => 5,
]));

echo "--- Retrieved Documents ---\n\n";
$context = displayResults($results, 'cosine distance, lower = closer');

// Generate answer with LLM
echo "--- Generated Answer ---\n\n";

$messages = new MessageBag(
    Message::forSystem('You are a helpful assistant. Answer the user\'s question based ONLY on the provided context. If the context contains conflicting information from different versions, mention all of them.'),
    Message::ofUser("Context:\n{$context}\n\nQuestion: {$query}"),
);
$response = $platform->invoke(LLM_MODEL, $messages);

echo $response->asText() . "\n";
