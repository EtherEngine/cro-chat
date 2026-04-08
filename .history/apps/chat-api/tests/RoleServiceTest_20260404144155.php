<?php

declare(strict_types=1);

namespace Tests;

use App\Services\RoleService;

final class RoleServiceTest extends TestCase
{
    // ── Space level helpers ──────────────────

    public function testSpaceLevelHierarchy(): void
    {
        $this->assertGreaterThan(RoleService::spaceLevel('admin'), RoleService::spaceLevel('owner'));
        $this->assertGreaterThan(RoleService::spaceLevel('moderator'), RoleService::spaceLevel('admin'));
        $this->assertGreaterThan(RoleService::spaceLevel('member'), RoleService::spaceLevel('moderator'));
        $this->assertGreaterThan(RoleService::spaceLevel('guest'), RoleService::spaceLevel('member'));
        $this->assertSame(0, RoleService::spaceLevel('invalid'));
    }

    public function testIsSpaceAdminOrAbove(): void
    {
        $this->assertTrue(RoleService::isSpaceAdminOrAbove('owner'));
        $this->assertTrue(RoleService::isSpaceAdminOrAbove('admin'));
        $this->assertFalse(RoleService::isSpaceAdminOrAbove('moderator'));
        $this->assertFalse(RoleService::isSpaceAdminOrAbove('member'));
        $this->assertFalse(RoleService::isSpaceAdminOrAbove('guest'));
    }

    public function testIsSpaceModeratorOrAbove(): void
    {
        $this->assertTrue(RoleService::isSpaceModeratorOrAbove('owner'));
        $this->assertTrue(RoleService::isSpaceModeratorOrAbove('admin'));
        $this->assertTrue(RoleService::isSpaceModeratorOrAbove('moderator'));
        $this->assertFalse(RoleService::isSpaceModeratorOrAbove('member'));
        $this->assertFalse(RoleService::isSpaceModeratorOrAbove('guest'));
    }

    // ── Space role management ────────────────

    public function testOwnerCanManageAdminToModerator(): void
    {
        $this->assertTrue(RoleService::canManageSpaceRole('owner', 'admin', 'moderator'));
    }

    public function testOwnerCanManageMemberToAdmin(): void
    {
        $this->assertTrue(RoleService::canManageSpaceRole('owner', 'member', 'admin'));
    }

    public function testAdminCanManageMemberToModerator(): void
    {
        $this->assertTrue(RoleService::canManageSpaceRole('admin', 'member', 'moderator'));
    }

    public function testAdminCannotPromoteToAdmin(): void
    {
        $this->assertFalse(RoleService::canManageSpaceRole('admin', 'member', 'admin'));
    }

    public function testAdminCannotDemoteAdmin(): void
    {
        $this->assertFalse(RoleService::canManageSpaceRole('admin', 'admin', 'member'));
    }

    public function testAdminCannotChangeOwner(): void
    {
        $this->assertFalse(RoleService::canManageSpaceRole('admin', 'owner', 'member'));
    }

    public function testModeratorCannotManageRoles(): void
    {
        $this->assertFalse(RoleService::canManageSpaceRole('moderator', 'member', 'guest'));
    }

    public function testMemberCannotManageRoles(): void
    {
        $this->assertFalse(RoleService::canManageSpaceRole('member', 'guest', 'member'));
    }

    // ── Channel permission checks ────────────

    public function testChannelLevelHierarchy(): void
    {
        $this->assertGreaterThan(RoleService::channelLevel('moderator'), RoleService::channelLevel('admin'));
        $this->assertGreaterThan(RoleService::channelLevel('member'), RoleService::channelLevel('moderator'));
        $this->assertGreaterThan(RoleService::channelLevel('guest'), RoleService::channelLevel('member'));
    }

    public function testEffectiveChannelLevel_SpaceAdminBecomesChannelAdmin(): void
    {
        $this->assertSame(
            RoleService::channelLevel('admin'),
            RoleService::effectiveChannelLevel('admin', 'member')
        );
    }

    public function testEffectiveChannelLevel_SpaceModeratorBecomesChannelModerator(): void
    {
        $this->assertSame(
            RoleService::channelLevel('moderator'),
            RoleService::effectiveChannelLevel('moderator', null)
        );
    }

    public function testEffectiveChannelLevel_ChannelAdminOutranksSpaceMember(): void
    {
        $this->assertSame(
            RoleService::channelLevel('admin'),
            RoleService::effectiveChannelLevel('member', 'admin')
        );
    }

    public function testCanModerateChannel(): void
    {
        $this->assertTrue(RoleService::canModerateChannel('owner', null));
        $this->assertTrue(RoleService::canModerateChannel('admin', null));
        $this->assertTrue(RoleService::canModerateChannel('moderator', null));
        $this->assertTrue(RoleService::canModerateChannel('member', 'moderator'));
        $this->assertFalse(RoleService::canModerateChannel('member', 'member'));
        $this->assertFalse(RoleService::canModerateChannel('guest', null));
    }

    public function testCanAdminChannel(): void
    {
        $this->assertTrue(RoleService::canAdminChannel('owner', null));
        $this->assertTrue(RoleService::canAdminChannel('admin', null));
        $this->assertTrue(RoleService::canAdminChannel('member', 'admin'));
        $this->assertFalse(RoleService::canAdminChannel('moderator', null));
        $this->assertFalse(RoleService::canAdminChannel('member', 'moderator'));
    }

    // ── Channel role management ──────────────

    public function testCanManageChannelRole_AdminCanPromoteMemberToModerator(): void
    {
        $this->assertTrue(RoleService::canManageChannelRole('owner', null, 'member', 'moderator'));
    }

    public function testCanManageChannelRole_CannotPromoteToOwnLevel(): void
    {
        $this->assertFalse(RoleService::canManageChannelRole('member', 'admin', 'moderator', 'admin'));
    }

    public function testCanManageChannelRole_ModeratorCannotManageRoles(): void
    {
        $this->assertFalse(RoleService::canManageChannelRole('member', 'moderator', 'member', 'guest'));
    }

    // ── Write permission (mute / guest) ──────

    public function testCanWrite_NormalMember(): void
    {
        $this->assertTrue(RoleService::canWrite('member', null));
    }

    public function testCanWrite_GuestCannotWrite(): void
    {
        $this->assertFalse(RoleService::canWrite('guest', null));
    }

    public function testCanWrite_MutedUserCannotWrite(): void
    {
        $future = date('Y-m-d H:i:s', time() + 3600);
        $this->assertFalse(RoleService::canWrite('member', $future));
    }

    public function testCanWrite_ExpiredMuteCanWrite(): void
    {
        $past = date('Y-m-d H:i:s', time() - 3600);
        $this->assertTrue(RoleService::canWrite('member', $past));
    }
}
