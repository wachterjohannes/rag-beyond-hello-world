<?php

/**
 * Shared helpers for the live-coding demo.
 */

use Symfony\AI\Store\Document\VectorDocument;

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
 * @param array<VectorDocument> $results
 */
function displayResults(array $results): string
{
    $context = '';

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
