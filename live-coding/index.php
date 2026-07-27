<?php

[$platform, $store, $logger] = require __DIR__ . '/bootstrap.php';

use Symfony\AI\Store\Document\EmbeddableDocumentInterface;
use Symfony\AI\Store\Document\FilterInterface;
use Symfony\AI\Store\Document\Loader\RstToctreeLoader;
use Symfony\AI\Store\Document\TransformerInterface;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Indexer\DocumentProcessor;
use Symfony\AI\Store\Indexer\SourceIndexer;

// RST has no explicit heading syntax: a line counts as a heading when the next line is a row
// of punctuation at least as long. Inside a long code block that happens by accident, so the
// loader cuts it into fragments headed by a stray brace or two. Those chunks carry no
// retrievable meaning but happily land in the top 5 and end up in the LLM's context. Filters
// run before embedding, so dropping them here also saves the embedding calls.
$junkFilter = new class implements FilterInterface {
    private const MIN_LENGTH = 80;

    public function filter(iterable $documents, array $options = []): iterable
    {
        foreach ($documents as $document) {
            $content = trim($document->getContent());
            $title = trim($document->getMetadata()->getTitle() ?? '');

            // Needs a real word in the title and enough prose to be worth embedding.
            if (!preg_match('/\p{L}{3}/u', $title) || mb_strlen($content) < self::MIN_LENGTH) {
                continue;
            }

            // Headings that are really template or code lines, e.g. "Welcome {{ email.toName }}!".
            if (preg_match('/\{\{|\{%|<\?php/', $title)) {
                continue;
            }

            yield $document;
        }
    }
};

// Transformer that prepends the Symfony version to each document's content
// so that vector search can distinguish between versions.
$versionTagger = new class implements TransformerInterface {
    public string $version = '';

    public function transform(iterable $documents, array $options = []): iterable
    {
        foreach ($documents as $document) {
            yield $document->withContent(
                "Symfony {$this->version} documentation.\n\n" . $document->getContent(),
            );
        }
    }
};

// Prepare indexing pipeline. Cohere embeds documents and search queries in different modes;
// `search_document` is the bridge's default, so the index side needs nothing extra here.
// The query side does: see forQueries() in helpers.php.
$vectorizer = new Vectorizer($platform, EMBEDDING_MODEL);
$processor = new DocumentProcessor($vectorizer, $store, filters: [$junkFilter], transformers: [$versionTagger]);
$loader = new RstToctreeLoader(logger: $logger);
$indexer = new SourceIndexer($loader, $processor);

// Clone and index full Symfony docs for multiple versions
$versions = ['4.4', '5.4', '7.4', '8.0'];

foreach ($versions as $version) {
    $dir = __DIR__ . "/.symfony-docs-{$version}";

    if (!is_dir($dir)) {
        echo "Cloning symfony-docs {$version} (shallow)...\n";
        exec('git clone --depth 1 --branch ' . escapeshellarg($version)
            . ' https://github.com/symfony/symfony-docs.git '
            . escapeshellarg($dir), $out, $code);

        if (0 !== $code) {
            echo "Failed to clone version {$version}, skipping.\n";
            continue;
        }
    }

    echo "Indexing full {$version} docs...\n";
    $versionTagger->version = $version;
    $indexer->index("{$dir}/index.rst");
}

echo "\nDone! Indexed Symfony docs for versions: " . implode(', ', $versions) . "\n";
