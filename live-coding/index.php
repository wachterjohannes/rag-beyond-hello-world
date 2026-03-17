<?php

[$ollamaPlatform, $huggingFacePlatform, $store, $logger] = require __DIR__ . '/bootstrap.php';

use Symfony\AI\Store\Document\EmbeddableDocumentInterface;
use Symfony\AI\Store\Document\Loader\RstToctreeLoader;
use Symfony\AI\Store\Document\TransformerInterface;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Indexer\DocumentProcessor;
use Symfony\AI\Store\Indexer\SourceIndexer;

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

// Prepare indexing pipeline
$vectorizer = new Vectorizer($huggingFacePlatform, EMBEDDING_MODEL);
$processor = new DocumentProcessor($vectorizer, $store, transformers: [$versionTagger]);
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
