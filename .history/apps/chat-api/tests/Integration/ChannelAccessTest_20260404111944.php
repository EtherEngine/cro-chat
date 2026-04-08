<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ApiException;
use App\Services\ChannelService;
use Tests\TestCase;

final class ChannelAccessTest extends TestCase
{
    private array $owner;
    private array $member;
    private array $outsider;
    private array $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->createUser(['display_name' => 'Owner']);
        $this->member = $this->createUser(['display_name' => 'Member']);
        $this->outsider = $this->createUser(['display_name' => 'Outsider']);

        $this->space = $this->createSpace($this->owner['id']);
        $this->addSpaceMember($this->space['id'], $this->member['id']);
        // outsider is NOT added to the space
    }

    // ── List channels ─────────────────────────

    public function test_space_member_can_list_channels(): void
    {
        $ch = $this->createChannel($this->space['id'], $this->owner['id'], ['name' => 'general']);

        $channels = ChannelService::listForSpace($this->space['id'], $this->member['id']);

        $this->assertCount(1, $channels);
        $this->assertSame('general', $channels[0]['name']);
    }

    public function test_outsider_cannot_list_channels(): void
    {
        $this->createChannel($this->space['id'], $this->owner['id']);

        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () {
            ChannelService::listForSpace($this->space['id'], $this->outsider['id']);
        });
    }

    // ── Show channel ──────────────────────────

    public function test_space_member_can_view_public_channel(): void
    {
        $ch = $this->createChannel($this->space['id'], $this->owner['id']);

        $result = ChannelService::show($ch['id'], $this->member['id']);

        $this->assertSame($ch['id'], $result['id']);
    }

    public function test_outsider_cannot_view_channel(): void
    {
        $ch = $this->createChannel($this->space['id'], $this->owner['id']);

        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () use ($ch) {
            ChannelService::show($ch['id'], $this->outsider['id']);
        });
    }

    public function test_nonexistent_channel_returns_404(): void
    {
        $this->assertApiException(404, 'CHANNEL_NOT_FOUND', function () {
            ChannelService::show(9999, $this->owner['id']);
        });
    }

    // ── Private channel access ────────────────

    public function test_nonmember_cannot_view_private_channel(): void
    {
        $ch = $this->createChannel($this->space['id'], $this->owner['id'], ['is_private' => 1]);

        $this->assertApiException(403, 'CHANNEL_ACCESS_DENIED', function () use ($ch) {
            ChannelService::show($ch['id'], $this->member['id']);
        });
    }

    public function test_member_of_private_channel_can_view(): void
    {
        $ch = $this->createChannel($this->space['id'], $this->owner['id'], ['is_private' => 1]);
        $this->addChannelMember($ch['id'], $this->member['id']);

        $result = ChannelService::show($ch['id'], $this->member['id']);
        $this->assertSame($ch['id'], $result['id']);
    }

    // ── Join channel ──────────────────────────

    public function test_space_member_can_join_public_channel(): void
    {
        $ch = $this->createChannel($this->space['id'], $this->owner['id']);

        ChannelService::join($ch['id'], $this->member['id']);

        $members = ChannelService::members($ch['id'], $this->member['id']);
        $memberIds = array_column($members, 'id');
        $this->assertContains($this->member['id'], $memberIds);
    }

    public function test_cannot_join_private_channel(): void
    {
        $ch = $this->createChannel($this->space['id'], $this->owner['id'], ['is_private' => 1]);

        $this->assertApiException(403, 'CHANNEL_PRIVATE', function () use ($ch) {
            ChannelService::join($ch['id'], $this->member['id']);
        });
    }

    public function test_outsider_cannot_join_channel(): void
    {
        $ch = $this->createChannel($this->space['id'], $this->owner['id']);

        $this->assertApiException(403, 'SPACE_MEMBER_REQUIRED', function () use ($ch) {
            ChannelService::join($ch['id'], $this->outsider['id']);
        });
    }

    // ── Admin operations ──────────────────────

    public function test_admin_can_add_member_to_private_channel(): void
    {
        $ch = $this->createChannel($this->space['id'], $this->owner['id'], ['is_private' => 1]);

        ChannelService::addMember($ch['id'], $this->member['id'], $this->owner['id']);

        $members = ChannelService::members($ch['id'], $this->member['id']);
        $memberIds = array_column($members, 'id');
        $this->assertContains($this->member['id'], $memberIds);
    }

    public function test_nonadmin_cannot_add_member(): void
    {
        $ch = $this->createChannel($this->space['id'], $this->owner['id']);
        $this->addChannelMember($ch['id'], $this->member['id']);

        $newUser = $this->createUser();
        $this->addSpaceMember($this->space['id'], $newUser['id']);

        $this->assertApiException(403, 'CHANNEL_ADMIN_REQUIRED', function () use ($ch, $newUser) {
            ChannelService::addMember($ch['id'], $newUser['id'], $this->member['id']);
        });
    }

    public function test_member_can_remove_self(): void
    {
        $ch = $this->createChannel($this->space['id'], $this->owner['id']);
        $this->addChannelMember($ch['id'], $this->member['id']);

        ChannelService::removeMember($ch['id'], $this->member['id'], $this->member['id']);

        // member should no longer be in channel — accessing private would fail,
        // but for public channel we just check member list
        $members = ChannelService::members($ch['id'], $this->owner['id']);
        $memberIds = array_column($members, 'id');
        $this->assertNotContains($this->member['id'], $memberIds);
    }

    public function test_nonadmin_cannot_remove_others(): void
    {
        $ch = $this->createChannel($this->space['id'], $this->owner['id']);
        $this->addChannelMember($ch['id'], $this->member['id']);

        $this->assertApiException(403, 'CHANNEL_ADMIN_REQUIRED', function () use ($ch) {
            ChannelService::removeMember($ch['id'], $this->owner['id'], $this->member['id']);
        });
    }

    // ── Space admin override ──────────────────

    public function test_space_admin_can_update_channel(): void
    {
        $admin = $this->createUser(['display_name' => 'Admin']);
        $this->addSpaceMember($this->space['id'], $admin['id'], 'admin');

        $ch = $this->createChannel($this->space['id'], $this->owner['id']);

        $result = ChannelService::update($ch['id'], ['name' => 'renamed'], $admin['id']);
        $this->assertSame('renamed', $result['name']);
    }
}
