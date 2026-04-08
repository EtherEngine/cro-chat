import { useApp } from '../store';
import { SidebarLeft } from '../components/layout/SidebarLeft';
import { SidebarRight } from '../components/layout/SidebarRight';
import { ChatHeader } from '../components/layout/ChatHeader';
import { MessageList } from '../components/messages/MessageList';
import { MessageComposer } from '../components/messages/MessageComposer';
import { EmptyState } from '../components/chat/EmptyState';

export function AppLayout() {
  const { state } = useApp();
  const hasActive = state.activeChannelId !== null || state.activeConversationId !== null;

  return (
    <div className="app-layout">
      <SidebarLeft />
      <main className="main-content">
        {hasActive ? (
          <>
            <ChatHeader />
            <MessageList />
            <MessageComposer />
          </>
        ) : (
          <EmptyState />
        )}
      </main>
      {state.showMembers && state.activeChannelId && <SidebarRight />}
    </div>
  );
}

