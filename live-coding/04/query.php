<?php

/**
 * Step 4: Add Reranking
 *
 * We have the right candidates now – but not in the right order.
 * A cross-encoder reranker sees (query, document) pairs jointly and scores
 * them much more accurately than embedding similarity alone.
 * Outdated docs (SwiftMailer, mail()) drop to the bottom.
 */

[$ollamaPlatform, $huggingFacePlatform, $store] = require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../helpers.php';

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Event\PostQueryEvent;
use Symfony\AI\Store\Event\PreQueryEvent;
use Symfony\AI\Store\EventListener\RerankerListener;
use Symfony\AI\Store\Reranker\Reranker;
use Symfony\AI\Store\Retriever;
use Symfony\Component\EventDispatcher\EventDispatcher;

$query = $argv[1] ?? 'send email';

echo "=== Enhanced RAG: Full Pipeline ===\n\n";
echo "Original Query: \"{$query}\"\n\n";

// Set up event dispatcher
$dispatcher = new EventDispatcher();

// 1. PreQueryEvent: Query Analysis / Rewriting
$dispatcher->addListener(PreQueryEvent::class, function (PreQueryEvent $event) use ($ollamaPlatform) {
    $messages = new MessageBag(
        Message::forSystem(<<<'PROMPT'
            You are a query rewriter for a Symfony documentation search engine.
            Rewrite the user's query to improve retrieval quality.

            Rules:
            - Expand abbreviations (e.g. "auth" → "authentication")
            - Add the framework name "Symfony" if not present
            - The system is built with Symfony 8.0 – prefer this version
            - Add version context if missing (default to Symfony 8.0)
            - Fix typos and normalize terminology
            - Keep it concise – this is a search query, not a question

            Respond with ONLY the rewritten query, nothing else.
            PROMPT),
        Message::ofUser($event->getQuery()),
    );

    $rewritten = $ollamaPlatform->invoke(LLM_MODEL, $messages)->asText();

    echo "Rewritten Query: \"{$rewritten}\"\n\n";

    $event->setQuery($rewritten);
});

// 2. PostQueryEvent: Cross-Encoder Reranking
$reranker = new Reranker($huggingFacePlatform, RERANKER_MODEL);
$dispatcher->addListener(PostQueryEvent::class, new RerankerListener($reranker, topK: 5));

// Full pipeline: Query Analysis → Hybrid Retrieval → Reranking
$vectorizer = new Vectorizer($huggingFacePlatform, EMBEDDING_MODEL);
$retriever = new Retriever($store, $vectorizer, $dispatcher);
$results = iterator_to_array($retriever->retrieve($query, [
    'maxItems' => 10, // Fetch more candidates for the reranker
    'semanticRatio' => 0.5, // Hybrid search
]));

echo "--- Retrieved Documents (Hybrid + Reranked) ---\n\n";
$context = displayResults($results);

// Generate answer with LLM
echo "--- Generated Answer ---\n\n";

$messages = new MessageBag(
    Message::forSystem('You are a helpful assistant. Answer the user\'s question based ONLY on the provided context. If the context contains conflicting information from different versions, mention all of them.'),
    Message::ofUser("Context:\n{$context}\n\nQuestion: {$query}"),
);

$response = $ollamaPlatform->invoke(LLM_MODEL, $messages);

echo $response->asText() . "\n";
