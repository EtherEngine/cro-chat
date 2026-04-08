<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Jobs\Handlers\AiEmbedHandler;
use App\Jobs\Handlers\AiExtractHandler;
use App\Jobs\Handlers\AiSummarizeChannelHandler;
use App\Jobs\Handlers\AiSummarizeThreadHandler;
use App\Repositories\AiRepository;
use App\Services\AiService;
use App\Support\Database;
use App\Support\HeuristicAiProvider;
use Tests\TestCase;

final class AiFeatureTest extends TestCase
{
    private array $admin;
    private array $user;
    private array $space;
    private array $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser(['display_name' => 'Admin']);
        $this->user = $this->createUser(['display_name' => 'Member']);
        $this->space = $this->createSpace($this->admin['id']);
        $this->addSpaceMember($this->space['id'], $this->user['id']);
        $this->channel = $this->createChannel($this->space['id'], $this->admin['id']);
        $this->addChannelMember($this->channel['id'], $this->user['id']);
        AiService::setProvider(new HeuristicAiProvider());
    }

    protected function tearDown(): void
    {
        AiService::setProvider(null);
        parent::tearDown();
    }

    // ── Config ───────────────────────────────────────────

    public function test_get_default_config(): void
    {
        $this->actingAs($this->user['id']);
        $config = AiService::getConfig($this->space['id'], $this->user['id']);
        $this->assertFalse($config['is_enabled']);
        $this->assertFalse($config['has_api_key']);
    }

    public function test_update_config_requires_admin(): void
    {
        $this->actingAs($this->user['id']);
        $this->assertApiException(403, 'FORBIDDEN', function () {
            AiService::updateConfig($this->space['id'], $this->user['id'], ['is_enabled' => true]);
        });
    }

    public function test_admin_can_update_config(): void
    {
        $this->actingAs($this->admin['id']);
        $config = AiService::updateConfig($this->space['id'], $this->admin['id'], [
            'provider' => 'openai',
            'model_summary' => 'gpt-4o',
            'is_enabled' => true,
            'temperature' => 0.5,
        ]);
        $this->assertTrue($config['is_enabled']);
        $this->assertSame('gpt-4o', $config['model_summary']);
        $this->assertArrayNotHasKey('api_key_enc', $config);
    }

    public function test_invalid_provider_rejected(): void
    {
        $this->actingAs($this->admin['id']);
        $this->assertApiException(400, 'INVALID_PROVIDER', function () {
            AiService::updateConfig($this->space['id'], $this->admin['id'], ['provider' => 'evil']);
        });
    }

    // ── PLACEHOLDER_THREAD_TESTS ──
}
