import type { Channel } from '../../types';

type Props = {
  channel: Channel;
  active: boolean;
  unreadCount: number;
  onClick: () => void;
};

export function ChannelListItem({ channel, active, unreadCount, onClick }: Props) {
  return (
    <li className={`channel-item${active ? ' active' : ''}${unreadCount > 0 ? ' unread' : ''}`} onClick={onClick}>
      <div className="channel-avatar" style={{ background: channel.color }}>
        #
      </div>
      <div className="channel-info">
        <div className="channel-name">{channel.name}</div>
        {channel.description && (
          <div className="channel-desc">{channel.description}</div>
        )}
      </div>
      {unreadCount > 0 && (
        <span className="unread-badge">{unreadCount > 99 ? '99+' : unreadCount}</span>
      )}
    </li>
  );
}

