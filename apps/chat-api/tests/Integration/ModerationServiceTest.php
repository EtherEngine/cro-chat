<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ModerationService;
use App\Services\MessageService;
use App\Repositories\ChannelRepository;
use App\Repositories\ModerationRepository;
use App\Repositories\SpaceRepository;
use Tests\TestCase;

final class ModerationServiceTest extends TestCase
{
    private array $owner;
    private array $admin;
    private array $moderator;
    private array $member;
    private array $guest;
    private array $space;
    private array $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->createUser();
        $this->admin = $this->createUser();
        $this->moderator = $this->createUser();
        $this->member = $this->createUser();
        $this->guest = $this->createUser();

        $this->space = $this->createSpace($this->owner['id']);
        $this->addSpaceMember($this->space['id'], $this->admin['id'], 'admin');
        $this->addSpaceMember($this->space['id'], $this->moderator['id'], 'moderator');
        $this->addSpaceMember($this->space['id'], $this->member['id'], 'member');
        $this->addSpaceMember($this->space['id'], $this->guest['id'], 'guest');

        $this->channel = $this->createChannel($this->space['id'], $this->owner['id']);
        $this->addChannelMember($this->channel['id'], $this->admin['id']);
        $this->addChannelMember($this->channel['id'], $this->moderator['id']);
        $this->addChannelMember($this->channel['id'], $this->member['id']);
        $this->addChannelMember($this->channel['id'], $this->guest['id'], 'guest');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ── Message deletion ──────────────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════════

    public function testOwnerCanDeleteAnyMessage(): void
    {
        $msg = $this->createMessage($this->member['id'], $this->channel['id'], null);
        ModerationService::deleteMessage($msg['id'], $this->owner['id'], 'Spam');
        $this->assertNotNull(
            \App\Support\Database::connection()->query("SELECT deleted_at FROM messages WHERE id = {$msg['id']}")->fetchColumn()
        );
    }

    public function testAdminCanDeleteAnyMessage(): void
    {
        $msg = $this->createMessage($this->member['id'], $this->channel['id'], null);
        ModerationService::deleteMessage($msg['id'], $this->admin['id']);
        $this->assertNotNull(
            \App\Support\Database::connection()->query("SELECT deleted_at FROM messages WHERE id = {$msg['id']}")->fetchColumn()
        );
    }

    public function testModeratorCanDeleteMessage(): void
    {
        $msg = $this->createMessage($this->member['id'], $this->channel['id'], null);
        ModerationService::deleteMessage($msg['id'], $this->moderator['id'], 'Regel verletzt');
        $this->assertNotNull(
            \App\Support\Database::connection()->query("SELECT deleted_at FROM messages WHERE id = {$msg['id']}")->fetchColumn()
        );
    }

    public function testMemberCanDeleteOwnMessage(): void
    {
        $msg = $this->createMessage($this->member['id'], $this->channel['id'], null);
        ModerationService::deleteMessage($msg['id'], $this->member['id']);
        $this->assertNotNull(
            \App\Support\Database::connection()->query("SELECT deleted_at FROM messages WHERE id = {$msg['id']}")->fetchColumn()
        );
    }

    public function testMemberCannotDeleteOtherMessage(): void
    {
        $msg = $this->createMessage($this->admin['id'], $this->channel['id'], null);
        $this->assertApiException(403, 'MODERATOR_REQUIRED', function () use ($msg) {
            ModerationService::deleteMessage($msg['id'], $this->member['id']);
        });
    }

    public function testGuestCannotDeleteOtherMessage(): void
    {
        $msg = $this->createMessage($this->member['id'], $this->channel['id'], null);
        $this->assertApiException(403, 'MODERATOR_REQUIRED', function () use ($msg) {
            ModerationService::deleteMessage($msg['id'], $this->guest['id']);
        });
    }

    public function testDeleteMessageCreatesAuditLog(): void
    {
        $msg = $this->createMessage($this->member['id'], $this->channel['id'], null, 'Bad message');
        ModerationService::deleteMessage($msg['id'], $this->moderator['id'], 'Spam');

        $log = ModerationRepository::forSpace($this->space['id']);
        $this->assertCount(1, $log);
        $this->assertSame('message_delete', $log[0]['action_type']);
        $this->assertSame($this->moderator['id'], (int) $log[0]['actor_id']);
        $this->assertSame($this->member['id'], (int) $log[0]['target_user_id']);
        $this->assertSame('Spam', $log[0]['reason']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ── Muting ────────────────────────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════════

    public function testModeratorCanMuteUser(): void
    {
        ModerationService::muteUser($this->channel['id'], $this->member['id'], $this->moderator['id'], 60, 'Spam');

        $mutedUntil = ChannelRepository::getMutedUntil($this->channel['id'], $this->member['id']);
        $this->assertNotNull($mutedUntil);
        $this->assertGreaterThan(time(), strtotime($mutedUntil));
    }

    public function testMutedUserCannotSendMessage(): void
    {
        ModerationService::muteUser($this->channel['id'], $this->member['id'], $this->moderator['id'], 60);

        $this->actingAs($this->member['id']);
        $this->assertApiException(403, 'WRITE_DENIED', function () {
            MessageService::createChannel($this->channel['id'], $this->member['id'], ['body' => 'Hello']);
        });
    }

    public function testUnmuteUserAllowsWriting(): void
    {
        ModerationService::muteUser($this->channel['id'], $this->member['id'], $this->moderator['id'], 60);
        ModerationService::unmuteUser($this->channel['id'], $this->member['id'], $this->moderator['id']);

        $mutedUntil = ChannelRepository::getMutedUntil($this->channel['id'], $this->member['id']);
        $this->assertNull($mutedUntil);
    }

    public function testMemberCannotMuteOther(): void
    {
        $this->assertApiException(403, 'MODERATOR_REQUIRED', function () {
            ModerationService::muteUser($this->channel['id'], $this->guest['id'], $this->member['id'], 60);
        });
    }

    public function testCannotMuteSelf(): void
    {
        $this->assertApiException(422, 'SELF_ACTION_DENIED', function () {
            ModerationService::muteUser($this->channel['id'], $this->moderator['id'], $this->moderator['id'], 60);
        });
    }

    public function testModeratorCannotMuteAdmin(): void
    {
        $this->assertApiException(403, 'TARGET_OUTRANKS', function () {
            ModerationService::muteUser($this->channel['id'], $this->admin['id'], $this->moderator['id'], 60);
        });
    }

    public function testMuteCreatesAuditLog(): void
    {
        ModerationService::muteUser($this->channel['id'], $this->member['id'], $this->moderator['id'], 30, 'Spam');

        $log = ModerationRepository::forChannel($this->channel['id']);
        $this->assertCount(1, $log);
        $this->assertSame('user_mute', $log[0]['action_type']);
        $this->assertSame('Spam', $log[0]['reason']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ── Kick from channel ─────────────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════════

    public function testModeratorCanKickMember(): void
    {
        ModerationService::kickFromChannel($this->channel['id'], $this->member['id'], $this->moderator['id'], 'Trolling');

        $this->assertFalse(ChannelRepository::isMember($this->channel['id'], $this->member['id']));
    }

    public function testKickCreatesAuditLog(): void
    {
        ModerationService::kickFromChannel($this->channel['id'], $this->member['id'], $this->moderator['id'], 'Trolling');

        $log = ModerationRepository::forChannel($this->channel['id']);
        $this->assertCount(1, $log);
        $this->assertSame('user_kick', $log[0]['action_type']);
        $this->assertSame('Trolling', $log[0]['reason']);
    }

    public function testMemberCannotKickOther(): void
    {
        $this->assertApiException(403, 'MODERATOR_REQUIRED', function () {
            ModerationService::kickFromChannel($this->channel['id'], $this->guest['id'], $this->member['id']);
        });
    }

    public function testModeratorCannotKickAdmin(): void
    {
        $this->assertApiException(403, 'TARGET_OUTRANKS', function () {
            ModerationService::kickFromChannel($this->channel['id'], $this->admin['id'], $this->moderator['id']);
        });
    }

    public function testCannotKickSelf(): void
    {
        $this->assertApiException(422, 'SELF_ACTION_DENIED', function () {
            ModerationService::kickFromChannel($this->channel['id'], $this->moderator['id'], $this->moderator['id']);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ── Space role changes ────────────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════════

    public function testOwnerCanPromoteMemberToAdmin(): void
    {
        ModerationService::changeSpaceRole($this->space['id'], $this->member['id'], 'admin', $this->owner['id']);

        $role = SpaceRepository::memberRole($this->space['id'], $this->member['id']);
        $this->assertSame('admin', $role);
    }

    public function testOwnerCanDemoteAdminToMember(): void
    {
        ModerationService::changeSpaceRole($this->space['id'], $this->admin['id'], 'member', $this->owner['id']);

        $role = SpaceRepository::memberRole($this->space['id'], $this->admin['id']);
        $this->assertSame('member', $role);
    }

    public function testAdminCanPromoteMemberToModerator(): void
    {
        ModerationService::changeSpaceRole($this->space['id'], $this->member['id'], 'moderator', $this->admin['id']);

        $role = SpaceRepository::memberRole($this->space['id'], $this->member['id']);
        $this->assertSame('moderator', $role);
    }

    public function testAdminCannotPromoteToAdmin(): void
    {
        $this->assertApiException(403, 'ROLE_CHANGE_DENIED', function () {
            ModerationService::changeSpaceRole($this->space['id'], $this->member['id'], 'admin', $this->admin['id']);
        });
    }

    public function testAdminCannotDemoteOtherAdmin(): void
    {
        $extraAdmin = $this->createUser();
        $this->addSpaceMember($this->space['id'], $extraAdmin['id'], 'admin');

        $this->assertApiException(403, 'ROLE_CHANGE_DENIED', function () use ($extraAdmin) {
            ModerationService::changeSpaceRole($this->space['id'], $extraAdmin['id'], 'member', $this->admin['id']);
        });
    }

    public function testCannotChangeOwnerRole(): void
    {
        $this->assertApiException(403, 'OWNER_PROTECTED', function () {
            ModerationService::changeSpaceRole($this->space['id'], $this->owner['id'], 'admin', $this->admin['id']);
        });
    }

    public function testCannotPromoteToOwner(): void
    {
        $this->assertApiException(403, 'OWNER_TRANSFER_DENIED', function () {
            ModerationService::changeSpaceRole($this->space['id'], $this->admin['id'], 'owner', $this->owner['id']);
        });
    }

    public function testModeratorCannotChangeRoles(): void
    {
        $this->assertApiException(403, 'ROLE_CHANGE_DENIED', function () {
            ModerationService::changeSpaceRole($this->space['id'], $this->member['id'], 'guest', $this->moderator['id']);
        });
    }

    public function testRoleChangeCreatesAuditLog(): void
    {
        ModerationService::changeSpaceRole($this->space['id'], $this->member['id'], 'moderator', $this->owner['id'], 'Beförderung');

        $log = ModerationRepository::forSpace($this->space['id']);
        $this->assertCount(1, $log);
        $this->assertSame('role_change', $log[0]['action_type']);
        $this->assertSame('Beförderung', $log[0]['reason']);
        $metadata = json_decode($log[0]['metadata'], true);
        $this->assertSame('member', $metadata['old_role']);
        $this->assertSame('moderator', $metadata['new_role']);
    }

    public function testInvalidRoleRejected(): void
    {
        $this->assertApiException(422, 'INVALID_ROLE', function () {
            ModerationService::changeSpaceRole($this->space['id'], $this->member['id'], 'superadmin', $this->owner['id']);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ── Channel role changes ──────────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════════

    public function testSpaceAdminCanPromoteChannelMemberToModerator(): void
    {
        ModerationService::changeChannelRole($this->channel['id'], $this->member['id'], 'moderator', $this->admin['id']);

        $role = ChannelRepository::memberRole($this->channel['id'], $this->member['id']);
        $this->assertSame('moderator', $role);
    }

    public function testChannelAdminCanPromoteMemberToModerator(): void
    {
        // Create a user who is channel admin but only space member
        $channelAdmin = $this->createUser();
        $this->addSpaceMember($this->space['id'], $channelAdmin['id'], 'member');
        $this->addChannelMember($this->channel['id'], $channelAdmin['id'], 'admin');

        ModerationService::changeChannelRole($this->channel['id'], $this->member['id'], 'moderator', $channelAdmin['id']);

        $role = ChannelRepository::memberRole($this->channel['id'], $this->member['id']);
        $this->assertSame('moderator', $role);
    }

    public function testChannelModeratorCannotManageRoles(): void
    {
        // Member with channel moderator role
        $chanMod = $this->createUser();
        $this->addSpaceMember($this->space['id'], $chanMod['id'], 'member');
        $this->addChannelMember($this->channel['id'], $chanMod['id'], 'moderator');

        $this->assertApiException(403, 'ROLE_CHANGE_DENIED', function () use ($chanMod) {
            ModerationService::changeChannelRole($this->channel['id'], $this->member['id'], 'guest', $chanMod['id']);
        });
    }

    public function testChannelRoleChangeCreatesAuditLog(): void
    {
        ModerationService::changeChannelRole($this->channel['id'], $this->member['id'], 'moderator', $this->admin['id']);

        $log = ModerationRepository::forChannel($this->channel['id']);
        $this->assertCount(1, $log);
        $this->assertSame('channel_role_change', $log[0]['action_type']);
    }

    public function testInvalidChannelRoleRejected(): void
    {
        $this->assertApiException(422, 'INVALID_ROLE', function () {
            ModerationService::changeChannelRole($this->channel['id'], $this->member['id'], 'owner', $this->admin['id']);
        });
    }

    public function testNonChannelMemberRejected(): void
    {
        $outsider = $this->createUser();
        $this->addSpaceMember($this->space['id'], $outsider['id'], 'member');
        // outsider is NOT a channel member

        $this->assertApiException(404, 'TARGET_NOT_MEMBER', function () use ($outsider) {
            ModerationService::changeChannelRole($this->channel['id'], $outsider['id'], 'moderator', $this->admin['id']);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ── Audit log retrieval ───────────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════════

    public function testAdminCanViewSpaceLog(): void
    {
        ModerationService::changeSpaceRole($this->space['id'], $this->member['id'], 'moderator', $this->owner['id']);

        $log = ModerationService::spaceLog($this->space['id'], $this->admin['id']);
        $this->assertCount(1, $log);
    }

    public function testModeratorCannotViewSpaceLog(): void
    {
        $this->assertApiException(403, 'ADMIN_REQUIRED', function () {
            ModerationService::spaceLog($this->space['id'], $this->moderator['id']);
        });
    }

    public function testModeratorCanViewChannelLog(): void
    {
        ModerationService::muteUser($this->channel['id'], $this->member['id'], $this->moderator['id'], 10);

        $log = ModerationService::channelLog($this->channel['id'], $this->moderator['id']);
        $this->assertCount(1, $log);
    }

    public function testMemberCannotViewChannelLog(): void
    {
        $this->assertApiException(403, 'MODERATOR_REQUIRED', function () {
            ModerationService::channelLog($this->channel['id'], $this->member['id']);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ── Guest restrictions ────────────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════════

    public function testGuestCannotSendMessage(): void
    {
        $this->actingAs($this->guest['id']);
        $this->assertApiException(403, 'WRITE_DENIED', function () {
            MessageService::createChannel($this->channel['id'], $this->guest['id'], ['body' => 'Hello']);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ── Space-level moderator via MessageService::delete ──────────────────────
    // ═══════════════════════════════════════════════════════════════════════════

    public function testModeratorCanDeleteMessageViaMessageService(): void
    {
        $msg = $this->createMessage($this->member['id'], $this->channel['id'], null);
        MessageService::delete($msg['id'], $this->moderator['id']);

        $this->assertNotNull(
            \App\Support\Database::connection()->query("SELECT deleted_at FROM messages WHERE id = {$msg['id']}")->fetchColumn()
        );
    }

    public function testChannelModeratorCanDeleteMessageViaMessageService(): void
    {
        $chanMod = $this->createUser();
        $this->addSpaceMember($this->space['id'], $chanMod['id'], 'member');
        $this->addChannelMember($this->channel['id'], $chanMod['id'], 'moderator');

        $msg = $this->createMessage($this->member['id'], $this->channel['id'], null);
        MessageService::delete($msg['id'], $chanMod['id']);

        $this->assertNotNull(
            \App\Support\Database::connection()->query("SELECT deleted_at FROM messages WHERE id = {$msg['id']}")->fetchColumn()
        );
    }
}
