# Live-Coding Demo

A progressive RAG pipeline built on [symfony/ai](https://github.com/symfony/ai), querying Symfony documentation across multiple versions. Each step adds one technique on top of the previous.

## Requirements

- PHP 8.2+
- [Ollama](https://ollama.com) running locally at `http://127.0.0.1:11434` with `kimi-k2.5:cloud`
- HuggingFace API key with Inference API access

## Setup

```bash
composer install
export HUGGINGFACE_API_KEY=hf_...

# Clone and index Symfony docs (4.4, 5.4, 7.4, 8.0) — takes a few minutes
php index.php
```

The indexer clones the Symfony docs repo for each version, embeds all pages using `BAAI/bge-base-en-v1.5` via HuggingFace, and stores them in a local SQLite database.

## Steps

### Step 1 — Naive RAG

Pure vector search. Embed the query, find the nearest documents, generate an answer.

```bash
php 01/query.php "send email"
```

Results are mixed across versions with similar scores — the retriever has no understanding of intent or version preference.

### Step 2 — Query Analysis

Adds a `PreQueryEvent` listener that rewrites the query using an LLM before retrieval. Abbreviations get expanded, version context is added, typos are fixed.

```bash
php 02/query.php "send email"
```

The rewritten query ("Symfony 8.0 Mailer component") pulls in significantly more relevant results with better scores.

### Step 3 — Hybrid Retrieval

Combines vector search with full-text search via Reciprocal Rank Fusion (RRF). Controlled by `semanticRatio` — `1.0` is pure vector, `0.0` is pure full-text, `0.5` is equal weight.

```bash
php 03/query.php "Mailer"
```

Exact keyword matches like `"Mailer"` now surface alongside semantic results — something pure vector search misses.

### Step 4 — Reranking

Adds a `PostQueryEvent` listener with a cross-encoder reranker (`BAAI/bge-reranker-v2-m3`). Fetches a larger candidate pool (10 docs), then rescores all candidates by reading query and document together — much more accurate than embedding similarity alone.

```bash
php 04/query.php "send email"
```

The most relevant document jumps to #1 with a high relevance score. The reranker surfaces results that were buried in the initial retrieval.

## Models

| Purpose | Model | Provider |
|---------|-------|----------|
| LLM (query rewriting + answering) | `kimi-k2.5:cloud` | Ollama |
| Embeddings | `BAAI/bge-base-en-v1.5` | HuggingFace Inference API |
| Reranking | `BAAI/bge-reranker-v2-m3` | HuggingFace Inference API |
