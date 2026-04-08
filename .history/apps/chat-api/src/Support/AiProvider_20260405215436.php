<?php

declare(strict_types=1);

namespace App\Support;

/**
 * AI Provider abstraction – pluggable backend for LLM operations.
 *
 * Implementations:
 *  - OpenAiProvider  – HTTP calls to OpenAI-compatible APIs
 *  - HeuristicProvider – Local pattern-based fallback (no API key needed)
 *
 * All methods return structured arrays, never raw API responses.
 */
interface AiProvider
{
    /**
     * Generate a summary from a list of messages.
     *
     * @param array  $messages  [{body, user_id, display_name, created_at}, ...]
     * @param string $scope     'thread' | 'channel'
     * @param array  $config    Provider config (model, max_tokens, temperature)
     * @return array {summary: string, key_points: string[], action_items: [{title, assignee_hint?, due_hint?, confidence}], title: string, model: string, tokens_used: int}
     */
    public function summarize(array $messages, string $scope, array $config = []): array;

    /**
     * Extract action items from messages.
     *
     * @param array $messages [{body, user_id, display_name, created_at}, ...]
     * @param array $config   Provider config
     * @return array {items: [{title, description?, assignee_hint?, due_hint?, confidence, source_index}], model: string, tokens_used: int}
     */
    public function extractActions(array $messages, array $config = []): array;

    /**
     * Generate embeddings for a text.
     *
     * @param string $text    Text to embed
     * @param array  $config  Provider config
     * @return array {embedding: float[], model: string, dimensions: int, tokens_used: int}
     */
    public function embed(string $text, array $config = []): array;

    /**
     * Generate reply suggestions for a conversation context.
     *
     * @param array  $messages     Recent context messages
     * @param string $userName     Current user's display name
     * @param array  $config       Provider config
     * @return array {suggestions: string[], model: string, tokens_used: int}
     */
    public function suggest(array $messages, string $userName, array $config = []): array;
}
