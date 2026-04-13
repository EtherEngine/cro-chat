import { describe, it, expect } from 'vitest';
import { getMessagePolicy } from '../messagePolicy';
import { fakeMessage, ME, OTHER } from './helpers';

const CTX_ME = { userId: ME.id, spaceRole: 'member' };
const CTX_OTHER = { userId: OTHER.id, spaceRole: 'member' };
const CTX_ADMIN = { userId: OTHER.id, spaceRole: 'admin' };
const CTX_NULL = { userId: null, spaceRole: null };

describe('getMessagePolicy — deleted message', () => {
  it('disables all interactions for a deleted message', () => {
    const msg = fakeMessage({ deleted_at: new Date().toISOString() });
    const policy = getMessagePolicy(msg, CTX_ME);
    expect(policy.canReact).toBe(false);
    expect(policy.canReply).toBe(false);
    expect(policy.canEdit).toBe(false);
    expect(policy.canDelete).toBe(false);
    expect(policy.canPin).toBe(false);
    expect(policy.canSave).toBe(false);
    expect(policy.canOpenThread).toBe(false);
  });
});

describe('getMessagePolicy — normal text message (own)', () => {
  const msg = fakeMessage({ user_id: ME.id, type: 'text' });

  it('allows all interactions for own message', () => {
    const p = getMessagePolicy(msg, CTX_ME);
    expect(p.canReact).toBe(true);
    expect(p.canReply).toBe(true);
    expect(p.canEdit).toBe(true);
    expect(p.canDelete).toBe(true);
    expect(p.canPin).toBe(true);
    expect(p.canSave).toBe(true);
    expect(p.canOpenThread).toBe(true);
  });

  it('disallows edit for other user', () => {
    const p = getMessagePolicy(msg, CTX_OTHER);
    expect(p.canEdit).toBe(false);
  });

  it('disallows delete for other member', () => {
    const p = getMessagePolicy(msg, CTX_OTHER);
    expect(p.canDelete).toBe(false);
  });

  it('allows delete for admin even if not own', () => {
    const p = getMessagePolicy(msg, CTX_ADMIN);
    expect(p.canDelete).toBe(true);
  });

  it('allows delete for owner', () => {
    const p = getMessagePolicy(msg, { userId: OTHER.id, spaceRole: 'owner' });
    expect(p.canDelete).toBe(true);
  });
});

describe('getMessagePolicy — call-type message', () => {
  const msg = fakeMessage({ user_id: ME.id, type: 'call' });

  it('disallows edit for call messages even if own', () => {
    const p = getMessagePolicy(msg, CTX_ME);
    expect(p.canEdit).toBe(false);
  });
});

describe('getMessagePolicy — thread reply', () => {
  const msg = fakeMessage({ thread_id: 50 });

  it('disallows opening a nested thread', () => {
    const p = getMessagePolicy(msg, CTX_ME);
    expect(p.canOpenThread).toBe(false);
  });
});

describe('getMessagePolicy — unauthenticated (userId null)', () => {
  const msg = fakeMessage({ user_id: ME.id });

  it('allows react/reply/pin/save for any viewer', () => {
    const p = getMessagePolicy(msg, CTX_NULL);
    expect(p.canReact).toBe(true);
    expect(p.canReply).toBe(true);
    expect(p.canPin).toBe(true);
    expect(p.canSave).toBe(true);
  });

  it('denies edit and delete when userId is null', () => {
    const p = getMessagePolicy(msg, CTX_NULL);
    expect(p.canEdit).toBe(false);
    expect(p.canDelete).toBe(false);
  });
});
