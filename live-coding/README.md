# Live-Coding Demo

A progressive RAG pipeline built on [symfony/ai](https://github.com/symfony/ai), querying Symfony documentation across multiple versions. Each step adds one technique on top of the previous.

## Requirements

- PHP 8.2+
- A [Cohere](https://cohere.com) API key (covers the LLM, embeddings and reranker)

## Setup

```bash
composer install

# Add your Cohere key (from https://dashboard.cohere.com/api-keys)
cp .env.local.dist .env.local
# then edit .env.local and set COHERE_API_KEY=...

# Clone and index Symfony docs (4.4, 5.4, 7.4, 8.0) — takes a few minutes
php index.php
```

`.env.local` is git-ignored, so your key stays out of the repo. An exported
`COHERE_API_KEY` environment variable takes precedence over `.env.local` if you
prefer that instead.

The indexer clones the Symfony docs repo for each version, embeds all pages using Cohere's `embed-english-v3.0`, and stores them in a local SQLite database.

It also applies a filter that drops unusable chunks before embedding. The RST loader recognises a
heading by its shape, a line followed by a row of punctuation, and inside a long code block that
pattern shows up by accident, so a stray `}` or a Twig line like `Welcome {{ email.toName }}!`
becomes the heading of its own fragment. A noticeable share of chunks is that kind of
noise, and they otherwise reach the top 5 and end up in the LLM's context. Filters run before
vectorization, so this saves the embedding calls too.

## Steps

### Step 1 — Naive RAG

Pure vector search. Embed the query, find the nearest documents, generate an answer.

```bash
php 01/query.php "send email"
```

Results are mixed across versions with near-identical cosine distances — pages from different
Symfony versions land right next to each other, because the retriever has no understanding of
intent or version preference.

Scores here are cosine **distances**, so lower is closer. Steps 1 and 2 wrap the store in
`vectorOnly()` (see `helpers.php`) to keep this genuinely pure vector search: the SQLite store
also advertises hybrid queries, and the `Retriever` prefers those even at `semanticRatio: 1.0`,
which would report rank-based RRF scores instead of real distances.

### Step 2 — Query Analysis

Adds a `PreQueryEvent` listener that rewrites the query using an LLM before retrieval. Abbreviations get expanded, version context is added, typos are fixed.

```bash
php 02/query.php "send email"
```

The rewritten query ("Symfony 8.0 send email") pulls in more relevant results, and the cosine
distances drop noticeably compared to step 1 — the improvement is visible in the numbers, not
just in the ranking.

### Step 3 — Hybrid Retrieval

Combines vector search with full-text search via Reciprocal Rank Fusion (RRF). Controlled by `semanticRatio` — `1.0` is pure vector, `0.0` is pure full-text, `0.5` is equal weight.

```bash
php 03/query.php "Mailer"
```

Exact keyword matches like `"Mailer"` now surface alongside semantic results — something pure vector search misses.

Note this step runs the **raw** query, without step 2's rewriting, so it changes exactly one
thing versus step 1. Full-text search ORs every term together, so rewriting `"Mailer"` into
`"Symfony 8.0 mailer service configuration and usage"` dilutes the one exact keyword across
eight loose ones and pulls in unrelated pages. Step 4 brings the rewriter back, where the
reranker can clean up after it.

Scores switch to RRF rank scores here (`1/(60+rank)`), so they are tiny and near-identical by
construction — don't compare them to step 1's distances.

### Step 4 — Reranking

Adds a `PostQueryEvent` listener with a cross-encoder reranker (Cohere `rerank-v3.5`). Fetches a larger candidate pool (10 docs), then rescores all candidates by reading query and document together — much more accurate than embedding similarity alone.

```bash
php 04/query.php "send email"
```

The most relevant document jumps to #1 with a high relevance score — and these are the
reranker's own 0..1 relevance judgements, not rank artifacts, so higher is better here.
The reranker surfaces results that were buried in the initial retrieval.

Worth pointing out live if it shows up: the top hit can still be an older-version page even
though the rewriter asked for 8.0. The cross-encoder scores on content alone and doesn't know
about your version preference — a good lead-in to metadata filtering as the next technique.

## Models

| Purpose | Model | Provider |
|---------|-------|----------|
| LLM (query rewriting + answering) | `command-a-03-2025` | Cohere |
| Embeddings | `embed-english-v3.0` | Cohere |
| Reranking | `rerank-v3.5` | Cohere |

All three run through the same `symfony/ai` Cohere platform, so swapping the provider is a one-line change in `bootstrap.php` — a nice illustration that a common interface lets you move providers without touching the pipeline.

### Document mode vs query mode

Cohere's embedding models are asymmetric: the same text is embedded differently depending on
whether it is a stored document or a search query, so that short questions land next to the
longer passages that answer them. Both sides have to use the matching mode, otherwise the two
sets of vectors are subtly misaligned.

`index.php` gets this for free, because `search_document` is the bridge's default. Queries do
not: `Retriever::createQuery()` calls `$vectorizer->vectorize($query)` without options
(`symfony/ai-store` v0.12.0), so the mode cannot be passed per call. The steps therefore wrap
the vectorizer in `forQueries()` (see `helpers.php`), which pins `search_query` for the query
side.
