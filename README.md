# Enhanced RAG: Beyond Hello World

Talk given at **VLBGWebDev | 18.03.2026** by Johannes Wachter.

From naive vector search to a production-ready RAG pipeline — step by step, live-coded in PHP with [symfony/ai](https://github.com/symfony/ai).

**[View Slides](https://wachterjohannes.github.io/rag-beyond-hello-world)**

## What's in this repo

```
index.html         # Slide deck (Marp)
assets/            # Slide images
live-coding/
  bootstrap.php    # Shared setup: Cohere platform (LLM + embeddings + reranker), SQLite store
  helpers.php      # Display helpers
  index.php        # Indexer – loads Symfony docs into SQLite
  01/query.php     # Step 1: Naive RAG – pure vector search
  02/query.php     # Step 2: + Query Analysis (PreQueryEvent)
  03/query.php     # Step 3: + Hybrid Retrieval (semanticRatio)
  04/query.php     # Step 4: + Reranking (PostQueryEvent)
```

## The Pipeline

Each step adds one technique to `01/query.php`:

| Step | Technique | Key change | Score shown |
|------|-----------|------------|-------------|
| 01 | Naive RAG | Baseline: embed + retrieve | cosine distance (lower = closer) |
| 02 | Query Analysis | `PreQueryEvent` rewrites the query with an LLM | cosine distance |
| 03 | Hybrid Retrieval | `semanticRatio: 0.5` combines vector + full-text (RRF) | RRF rank score |
| 04 | Reranking | `PostQueryEvent` + cross-encoder rescores candidates | reranker relevance (higher = better) |

The three modes report scores on different scales, so compare numbers *within* a step, never
across steps. RRF scores in particular are rank-based (`1/(60+rank)`), so they are always tiny
and nearly identical regardless of retrieval quality.

Step 03 deliberately drops the query rewriting from step 02. Full-text search ORs every term
together, so rewriting `"Mailer"` into a longer phrase buries the exact keyword the step exists
to demonstrate. Step 04 brings the rewriter back, where the reranker can clean up after it.

## Status

> **Note:** Hybrid retrieval (step 03) uses Reciprocal Rank Fusion in the SQLite store, added in [symfony/ai#1787](https://github.com/symfony/ai/pull/1787) (merged 2026-04-08). It ships in the pinned `symfony/ai ^0.12`, so no extra setup is needed.

> **Note:** Cohere embeds documents and search queries in different modes, and `Retriever` currently
> calls the vectorizer without options, so the query mode cannot be passed per call. The steps work
> around this with `forQueries()` in `live-coding/helpers.php`; see [Document mode vs query mode](live-coding/README.md#document-mode-vs-query-mode).

## Running the Demo

The demo runs on a single provider: **Cohere** supplies the chat model, the embeddings and the reranker, so all you need is one `COHERE_API_KEY`.

### Requirements

- PHP 8.2+
- A [Cohere](https://cohere.com) API key

```bash
cd live-coding
composer install

# Add your Cohere key (from https://dashboard.cohere.com/api-keys).
# .env.local is git-ignored, so the key stays out of the repo.
cp .env.local.dist .env.local
# then edit .env.local and set COHERE_API_KEY=...

# Index the docs (once). Embeddings are Cohere's, so start from a fresh
# symfony_docs.sqlite if you previously indexed with another provider.
php index.php

# Run each step
php 01/query.php "send email"
php 02/query.php "send email"
php 03/query.php "Mailer"
php 04/query.php "send email"
```

## Resources

- [symfony/ai](https://github.com/symfony/ai)
- [ai-mate](https://github.com/modelflow-ai/ai-mate)
- [Model Context Protocol](https://modelcontextprotocol.io)
