import type { Channel } from '../../types';

type Props = {
  channel: Channel;
  active: boolean;
  onClick: () => void;
};

export function ChannelListItem({ channel, active, onClick }: Props) {
  return (
    <li className={`channel-item${active ? ' active' : ''}`} onClick={onClick}>
      <div className="channel-avatar" style={{ background: channel.color }}>
        #
      </div>
      <div className="channel-info">
        <div className="channel-name">{channel.name}</div>
        {channel.description && (
          <div className="channel-desc">{channel.description}</div>
        )}
      </div>
    </li>
  );
}

