<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Repositories\SearchRepository;
use App\Repositories\SpaceRepository;

final class SearchService
{
    private const MAX_QUERY_LENGTH = 500;
    private const MAX_SAVED_SEARCHES = 50;

    // ── Advanced Search ──────────────────────────

    /**
     * Faceted search with ranking, highlighting, and pagination.
     * Records to search history.
     */
    public static function advancedSearch(int $userId, string $query, array $filters = []): array
    {
        $query = self::validateQuery($query);

        // Date filter validation
        if (isset($filters['after'])) {
            self::validateDate($filters['after'], 'after');
        }
        if (isset($filters['before'])) {
            self::validateDate($filters['before'], 'before');
        }

        $result = SearchRepository::advancedSearch($userId, $query, $filters);

        // Record to history (async-friendly, but we do it inline for simplicity)
        SearchRepository::recordHistory($userId, $query, $filters ?: null, $result['total']);

        return $result;
    }

    // ── Saved Searches ───────────────────────────

    public static function createSavedSearch(int $userId, int $spaceId, array $input): array
    {
        if (!SpaceRepository::isMember($spaceId, $userId)) {
            throw ApiException::forbidden('Kein Mitglied dieses Space', 'SPACE_MEMBER_REQUIRED');
        }

        $name = trim($input['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            throw ApiException::validation('Name ist erforderlich (max 100 Zeichen)', 'SAVED_SEARCH_NAME_INVALID');
        }

        $query = self::validateQuery($input['query'] ?? '');

        $filters = isset($input['filters']) && is_array($input['filters']) ? $input['filters'] : null;
        $notify = !empty($input['notify']);

        // Limit per user
        $existing = SearchRepository::listSavedSearches($userId, $spaceId);
        if (count($existing) >= self::MAX_SAVED_SEARCHES) {
            throw ApiException::validation('Maximum ' . self::MAX_SAVED_SEARCHES . ' gespeicherte Suchen', 'SAVED_SEARCH_LIMIT');
        }

        return SearchRepository::createSavedSearch($userId, $spaceId, $name, $query, $filters, $notify);
    }

    public static function getSavedSearch(int $id, int $userId): array
    {
        $saved = SearchRepository::findSavedSearch($id);
        if (!$saved || $saved['user_id'] !== $userId) {
            throw ApiException::notFound('Gespeicherte Suche nicht gefunden', 'SAVED_SEARCH_NOT_FOUND');
        }
        return $saved;
    }

    public static function listSavedSearches(int $userId, ?int $spaceId = null): array
    {
        return SearchRepository::listSavedSearches($userId, $spaceId);
    }

    public static function updateSavedSearch(int $id, int $userId, array $input): array
    {
        $saved = SearchRepository::findSavedSearch($id);
        if (!$saved || $saved['user_id'] !== $userId) {
            throw ApiException::notFound('Gespeicherte Suche nicht gefunden', 'SAVED_SEARCH_NOT_FOUND');
        }

        $data = [];
        if (isset($input['name'])) {
            $name = trim($input['name']);
            if ($name === '' || mb_strlen($name) > 100) {
                throw ApiException::validation('Name ist erforderlich (max 100 Zeichen)', 'SAVED_SEARCH_NAME_INVALID');
            }
            $data['name'] = $name;
        }
        if (isset($input['query'])) {
            $data['query'] = self::validateQuery($input['query']);
        }
        if (array_key_exists('filters', $input)) {
            $data['filters'] = is_array($input['filters']) ? $input['filters'] : null;
        }
        if (isset($input['notify'])) {
            $data['notify'] = (bool) $input['notify'];
        }

        return SearchRepository::updateSavedSearch($id, $data);
    }

    public static function deleteSavedSearch(int $id, int $userId): void
    {
        $saved = SearchRepository::findSavedSearch($id);
        if (!$saved || $saved['user_id'] !== $userId) {
            throw ApiException::notFound('Gespeicherte Suche nicht gefunden', 'SAVED_SEARCH_NOT_FOUND');
        }
        SearchRepository::deleteSavedSearch($id);
    }

    /**
     * Execute a saved search: runs advancedSearch with stored query+filters, updates last_run_at.
     */
    public static function executeSavedSearch(int $id, int $userId, array $overrides = []): array
    {
        $saved = self::getSavedSearch($id, $userId);

        $filters = $saved['filters'] ?? [];
        // Allow overriding page/sort at execution time
        if (isset($overrides['page'])) {
            $filters['page'] = $overrides['page'];
        }
        if (isset($overrides['sort'])) {
            $filters['sort'] = $overrides['sort'];
        }

        SearchRepository::touchSavedSearch($id);

        return self::advancedSearch($userId, $saved['query'], $filters);
    }

    // ── History ──────────────────────────────────

    public static function history(int $userId, int $limit = 20): array
    {
        return SearchRepository::searchHistory($userId, min(50, max(1, $limit)));
    }

    public static function clearHistory(int $userId): void
    {
        SearchRepository::clearHistory($userId);
    }

    public static function suggest(int $userId, string $prefix): array
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return [];
        }
        return SearchRepository::suggest($userId, $prefix);
    }

    // ── Validation helpers ───────────────────────

    private static function validateQuery(string $query): string
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) < 2) {
            throw ApiException::validation('Suchbegriff muss mindestens 2 Zeichen lang sein', 'SEARCH_QUERY_TOO_SHORT');
        }
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            throw ApiException::validation('Suchbegriff darf maximal ' . self::MAX_QUERY_LENGTH . ' Zeichen lang sein', 'SEARCH_QUERY_TOO_LONG');
        }
        return $query;
    }

    private static function validateDate(string $date, string $field): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            throw ApiException::validation("{$field} muss ein gültiges Datum sein (YYYY-MM-DD)", 'SEARCH_INVALID_DATE');
        }
    }
}
