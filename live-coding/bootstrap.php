<?php

require __DIR__ . '/vendor/autoload.php';

const LLM_MODEL = 'kimi-k2.5:cloud';
const EMBEDDING_MODEL = 'BAAI/bge-base-en-v1.5?task=feature-extraction';
const RERANKER_MODEL = 'BAAI/bge-reranker-v2-m3?task=text-ranking';

use Psr\Log\AbstractLogger;
use Symfony\AI\Platform\Bridge\HuggingFace\PlatformFactory as HuggingFacePlatformFactory;
use Symfony\AI\Platform\Bridge\Ollama\PlatformFactory as OllamaPlatformFactory;
use Symfony\AI\Store\Bridge\Sqlite\Store;
use Symfony\Component\HttpClient\HttpClient;

$ollamaPlatform = OllamaPlatformFactory::create('http://127.0.0.1:11434', httpClient: HttpClient::create());
$huggingFacePlatform = HuggingFacePlatformFactory::create(
    $_SERVER['HUGGINGFACE_API_KEY'] ?? '',
    httpClient: HttpClient::create(['timeout' => 120]),
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
    $ollamaPlatform,
    $huggingFacePlatform,
    $store,
    $logger,
];
