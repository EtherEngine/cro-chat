<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Services\DevCallService;
use App\Support\Env;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

/**
 * Dev-only controller for audio call simulation.
 *
 * Every public method calls requireDevEnv() first, which throws
 * ApiException::forbidden if APP_ENV !== 'local'.  This means the routes
 * can exist in api.php unconditionally; the guard fires at runtime.
 *
 * Endpoints:
 *   GET  /api/dev/calls/scenarios              — list available scenarios
 *   POST /api/dev/calls/simulate               — bot initiates incoming call
 *   POST /api/dev/calls/{callId}/bot-action    — bot performs cancel/hangup
 *
 * ⚠️  These routes MUST NOT be deployed to production.
 *     They are blocked by the APP_ENV guard, but the defence-in-depth policy
 *     is: APP_ENV must never be set to 'local' on production infrastructure.
 */
final class DevCallController
{
    // ── Routes ────────────────────────────────────────────────

    /**
     * GET /api/dev/calls/scenarios
     *
     * Returns the catalog of simulation scenarios and their available
     * bot-action follow-ups.  The frontend CallSimulatorPanel uses this
     * to populate its scenario selector.
     */
    public function scenarios(): void
    {
        self::requireDevEnv();
        Request::requireUserId();
        Response::json(['scenarios' => DevCallService::scenarios()]);
    }

    /**
     * POST /api/dev/calls/simulate
     *
     * Body:
     *   { scenario: string, target_user_id?: number }
     *
     * Creates a real call record where the dev bot is the caller and
     * target_user_id (defaults to the authenticated user) is the callee.
     * All production side-effects fire: domain event, presence, notifications.
     */
    public function simulate(): void
    {
        self::requireDevEnv();
        $userId = Request::requireUserId();
        $input = Request::json();

        (new Validator($input))->required('scenario')->validate();

        $scenario = (string) $input['scenario'];
        $targetUserId = isset($input['target_user_id'])
            ? (int) $input['target_user_id']
            : $userId;

        $result = DevCallService::simulateIncomingCall($targetUserId, $scenario);
        Response::json($result, 201);
    }

    /**
     * POST /api/dev/calls/{callId}/bot-action
     *
     * Body:
     *   { action: 'cancel' | 'hangup' }
     *
     * Executes the action on behalf of the bot (the call's caller).
     * The authenticated user must be the callee of a call that was
     * initiated by the dev bot.
     */
    public function botAction(array $params): void
    {
        self::requireDevEnv();
        Request::requireUserId();

        $callId = (int) ($params['callId'] ?? 0);
        if ($callId <= 0) {
            throw ApiException::validation('Ungültige Anruf-ID', 'INVALID_CALL_ID');
        }

        $input = Request::json();
        (new Validator($input))->required('action')->validate();

        $call = DevCallService::botAction($callId, (string) $input['action']);
        Response::json(['call' => $call]);
    }

    // ── Guard ─────────────────────────────────────────────────

    /**
     * Hard guard: throw 403 if not running in local development environment.
     *
     * Defence-in-depth: even if these routes are accidentally reachable on a
     * non-production server, the call is rejected unless APP_ENV=local.
     */
    private static function requireDevEnv(): void
    {
        $env = Env::get('APP_ENV', 'production');
        if ($env !== 'local') {
            throw ApiException::forbidden(
                'Dev-Endpunkte sind nur in lokaler Umgebung (APP_ENV=local) verfügbar.',
                'DEV_DISABLED'
            );
        }
    }
}
