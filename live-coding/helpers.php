<?php

/**
 * Shared helpers for the live-coding demo.
 */

use Symfony\AI\Platform\Bridge\Cohere\InputType;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\EmbeddableDocumentInterface;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\Query\HybridQuery;
use Symfony\AI\Store\Query\QueryInterface;
use Symfony\AI\Store\StoreInterface;

/**
 * Hides the store's HybridQuery support so the Retriever falls back to a plain VectorQuery.
 *
 * Without this, steps 1 and 2 would silently run through the store's Reciprocal Rank Fusion
 * path even at `semanticRatio: 1.0` (the keyword half is just weighted to zero), and the
 * reported score would be an RRF rank score (~1/(60+rank)) instead of a real cosine distance.
 * The ranking would be identical, but the numbers on screen would be meaningless.
 */
function vectorOnly(StoreInterface $store): StoreInterface
{
    return new class($store) implements StoreInterface {
        public function __construct(private readonly StoreInterface $inner)
        {
        }

        public function add(VectorDocument|array $documents): void
        {
            $this->inner->add($documents);
        }

        public function remove(string|array $ids, array $options = []): void
        {
            $this->inner->remove($ids, $options);
        }

        public function clear(array $options = []): void
        {
            $this->inner->clear($options);
        }

        public function query(QueryInterface $query, array $options = []): iterable
        {
            return $this->inner->query($query, $options);
        }

        public function supports(string $queryClass): bool
        {
            return HybridQuery::class !== $queryClass && $this->inner->supports($queryClass);
        }
    };
}

/**
 * Wraps a vectorizer so the query side is embedded in Cohere's `search_query` mode.
 *
 * Cohere's embedding models are asymmetric: a document and a search query are embedded in
 * different modes so that short questions land next to the longer passages that answer them.
 * `index.php` embeds documents and gets the right mode for free, because `search_document` is
 * the bridge's default. Queries do not: `Retriever::createQuery()` calls
 * `$vectorizer->vectorize($query)` with no options (symfony/ai-store v0.12.0), so there is no
 * way to pass the mode per call, and the query would be embedded as if it were a document.
 *
 * Pinning the mode on the vectorizer itself is the way around that. Explicit options still win,
 * so a caller can override it.
 */
function forQueries(VectorizerInterface $vectorizer): VectorizerInterface
{
    return new class($vectorizer) implements VectorizerInterface {
        public function __construct(private readonly VectorizerInterface $inner)
        {
        }

        public function vectorize(string|\Stringable|EmbeddableDocumentInterface|array $values, array $options = []): Vector|VectorDocument|array
        {
            return $this->inner->vectorize($values, $options + ['input_type' => InputType::SearchQuery]);
        }
    };
}

/**
 * Extract the Symfony version from a document source path.
 */
function extractVersion(string $source): string
{
    if (preg_match('/\.symfony-docs-(\d+\.\d+)/', $source, $m)) {
        return $m[1];
    }

    return '?';
}

/**
 * Display retrieved documents and build context string for the LLM.
 *
 * The three retrieval modes in this demo report scores on completely different scales,
 * so each step says which one it is instead of printing a bare number:
 *   - cosine distance (steps 1-2): lower is closer
 *   - RRF rank score (step 3):     rank-based, always tiny and near-identical by construction
 *   - relevance (step 4):          the reranker's actual 0..1 judgement, higher is better
 *
 * @param array<VectorDocument> $results
 */
function displayResults(array $results, string $metric = 'score'): string
{
    $context = '';

    printf("(scores: %s)\n\n", $metric);

    foreach ($results as $i => $doc) {
        $metadata = $doc->getMetadata();
        $score = $doc->getScore() ?? 0.0;
        $title = $metadata->getTitle() ?? '(no title)';
        $source = $metadata->getSource() ?? '(unknown)';
        $text = $metadata->getText() ?? '';
        $version = extractVersion($source);

        // Show version prominently + first line of actual content
        $preview = strtok(trim($text), "\n");
        printf("#%d [%.4f] [v%s] %s\n", $i + 1, $score, $version, $title);
        printf("   %s\n\n", substr($preview, 0, 100));

        $context .= "Score: {$score}\nSource: {$source}\nTitle: {$title}\n{$text}\n\n---\n\n";
    }

    return $context;
}
