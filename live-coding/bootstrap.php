<?php

require __DIR__ . '/vendor/autoload.php';

// One provider for everything: Cohere covers the chat model, embeddings and reranking,
// so the whole demo runs on a single COHERE_API_KEY (no local Ollama, no HuggingFace).
const LLM_MODEL = 'command-a-03-2025';
const EMBEDDING_MODEL = 'embed-english-v3.0';
const RERANKER_MODEL = 'rerank-v3.5';

use Psr\Log\AbstractLogger;
use Symfony\AI\Platform\Bridge\Cohere\Factory as CohereFactory;
use Symfony\AI\Store\Bridge\Sqlite\Store;
use Symfony\Component\HttpClient\HttpClient;

$platform = CohereFactory::createPlatform(
    $_SERVER['COHERE_API_KEY'] ?? '',
    HttpClient::create(['timeout' => 120]),
);

$logger = new class extends AbstractLogger {
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{' . $key . '}'] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        echo "[{$level}] " . strtr((string) $message, $replacements) . "\n";
    }
};

$pdo = new PDO('sqlite:' . __DIR__ . '/symfony_docs.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$store = Store::fromPdo($pdo, 'symfony_docs');
$store->setup();

return [
    $platform,
    $store,
    $logger,
];
