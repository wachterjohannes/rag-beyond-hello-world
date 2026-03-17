<?php

/**
 * Step 3: Add Hybrid Retrieval
 *
 * Vector search finds semantically similar docs – but misses exact keyword matches
 * like "Mailer" or "security.yaml". By switching to HybridQuery (vector + full-text),
 * we get both semantic understanding AND keyword precision.
 */

[$ollamaPlatform, $huggingFacePlatform, $store] = require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../helpers.php';

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Event\PreQueryEvent;
use Symfony\AI\Store\Retriever;
use Symfony\Component\EventDispatcher\EventDispatcher;

$query = $argv[1] ?? 'send email';

echo "=== Enhanced RAG: Hybrid Retrieval ===\n\n";
echo "Original Query: \"{$query}\"\n\n";

// Set up event dispatcher with query analysis listener
$dispatcher = new EventDispatcher();

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

// NOW: Hybrid retrieval – vector + full-text search combined
$vectorizer = new Vectorizer($huggingFacePlatform, EMBEDDING_MODEL);
$retriever = new Retriever($store, $vectorizer, $dispatcher);
$results = iterator_to_array($retriever->retrieve($query, [
    'maxItems' => 5,
    'semanticRatio' => 0.5, // 50% semantic + 50% keyword = hybrid search
]));

echo "--- Retrieved Documents (Hybrid: Vector + Full-Text) ---\n\n";
$context = displayResults($results);

// Generate answer with LLM
echo "--- Generated Answer ---\n\n";

$messages = new MessageBag(
    Message::forSystem('You are a helpful assistant. Answer the user\'s question based ONLY on the provided context. If the context contains conflicting information from different versions, mention all of them.'),
    Message::ofUser("Context:\n{$context}\n\nQuestion: {$query}"),
);

$response = $ollamaPlatform->invoke(LLM_MODEL, $messages);

echo $response->asText() . "\n";
