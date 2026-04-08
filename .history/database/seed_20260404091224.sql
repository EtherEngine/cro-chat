-- Seed-Daten für cro_chat

USE cro_chat;

-- ── Users (27) ─────────────────────────────────────────────────────────────────
-- Passwort für alle: "password" (bcrypt)
INSERT INTO users (id, email, password_hash, display_name, title, avatar_color, status) VALUES
(1,  'heather.mason@cro.dev',   '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Heather Mason',    'Mechanical Systems Engineer', '#7C3AED', 'online'),
(2,  'tom.martinez@cro.dev',    '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Tom Martinez',     'Software Developer',          '#EF4444', 'online'),
(3,  'jon.warren@cro.dev',      '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Jon Warren',       'Registered Nurse',            '#3B82F6', 'online'),
(4,  'anna.gordon@cro.dev',     '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Anna Gordon',      'Marketing Specialist',        '#EC4899', 'online'),
(5,  'ethel.barnett@cro.dev',   '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Ethel Barnett',    'Help Desk Technician',        '#F59E0B', 'offline'),
(6,  'marie.harper@cro.dev',    '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Marie Harper',     'Technical Writer',            '#8B5CF6', 'offline'),
(7,  'meghan.franklin@cro.dev', '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Meghan Franklin',  'VP Product Management',       '#06B6D4', 'offline'),
(8,  'victoria.torres@cro.dev', '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Victoria Torres',  'Professor',                   '#10B981', 'offline'),
(9,  'armando.coleman@cro.dev', '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Armando Coleman',  'Pharmacist',                  '#F97316', 'offline'),
(10, 'bradley.chapman@cro.dev', '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Bradley Chapman',  'Registered Nurse',            '#6366F1', 'offline'),
(11, 'caleb.nichols@cro.dev',   '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Caleb Nichols',    'Environmental Tech',          '#14B8A6', 'offline'),
(12, 'caleb.tucker@cro.dev',    '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Caleb Tucker',     'Sales Representative',        '#8B5CF6', 'offline'),
(13, 'cathy.grant@cro.dev',     '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Cathy Grant',      'Registered Nurse',            '#EC4899', 'offline'),
(14, 'courtney.miles@cro.dev',  '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Courtney Miles',   'Account Executive',           '#EF4444', 'offline'),
(15, 'ethan.murray@cro.dev',    '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Ethan Murray',     'Actuary',                     '#3B82F6', 'offline'),
(16, 'jenny.porter@cro.dev',    '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Jenny Porter',     'Engineer',                    '#10B981', 'offline'),
(17, 'david.kim@cro.dev',       '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'David Kim',        'UX Designer',                 '#F59E0B', 'offline'),
(18, 'sarah.mitchell@cro.dev',  '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Sarah Mitchell',   'Data Analyst',                '#7C3AED', 'offline'),
(19, 'marcus.johnson@cro.dev',  '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Marcus Johnson',   'Project Manager',             '#06B6D4', 'offline'),
(20, 'lisa.chen@cro.dev',       '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Lisa Chen',        'DevOps Engineer',             '#F97316', 'offline'),
(21, 'robert.taylor@cro.dev',   '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Robert Taylor',    'Business Analyst',            '#6366F1', 'offline'),
(22, 'jessica.davis@cro.dev',   '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Jessica Davis',    'HR Coordinator',              '#14B8A6', 'offline'),
(23, 'michael.brown@cro.dev',   '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Michael Brown',    'Security Analyst',            '#EF4444', 'offline'),
(24, 'amanda.wilson@cro.dev',   '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Amanda Wilson',    'Content Strategist',          '#EC4899', 'offline'),
(25, 'daniel.lee@cro.dev',      '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Daniel Lee',       'Backend Developer',           '#3B82F6', 'offline'),
(26, 'rachel.green@cro.dev',    '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Rachel Green',     'Product Designer',            '#8B5CF6', 'offline'),
(27, 'kevin.white@cro.dev',     '$2y$10$wc3NZ6ILDZHVRE9gNBkHg.RjUMbi655IPiFg74MxgipgxeNS3F6r6', 'Kevin White',      'QA Engineer',                 '#10B981', 'offline');

-- ── Space ──────────────────────────────────────────────────────────────────────
INSERT INTO spaces (id, name, slug, description, owner_id) VALUES
(1, 'crø HQ', 'cro-hq', 'Haupt-Workspace', 1);

INSERT INTO space_members (space_id, user_id, role) VALUES
(1,1,'owner'),(1,2,'member'),(1,3,'member'),(1,4,'member'),(1,5,'member'),
(1,6,'member'),(1,7,'admin'),(1,8,'member'),(1,9,'member'),(1,10,'member'),
(1,11,'member'),(1,12,'member'),(1,13,'member'),(1,14,'member'),(1,15,'member'),
(1,16,'member'),(1,17,'member'),(1,18,'member'),(1,19,'member'),(1,20,'member'),
(1,21,'member'),(1,22,'member'),(1,23,'member'),(1,24,'member'),(1,25,'member'),
(1,26,'member'),(1,27,'member');

-- ── Channels ───────────────────────────────────────────────────────────────────
INSERT INTO channels (id, space_id, name, description, color, is_private, created_by) VALUES
(1, 1, 'Company Culture', 'Company culture space',                              '#7C3AED', 0, 1),
(2, 1, 'Movies',          'Everything about movies',                            '#3B82F6', 0, 1),
(3, 1, 'Off Topic',       'Non-work banter and water cooler conversation',      '#14B8A6', 0, 1),
(4, 1, 'Running',         'soc running space',                                  '#10B981', 0, 1);

-- All 27 users in Company Culture
INSERT INTO channel_members (channel_id, user_id) VALUES
(1,1),(1,2),(1,3),(1,4),(1,5),(1,6),(1,7),(1,8),(1,9),(1,10),
(1,11),(1,12),(1,13),(1,14),(1,15),(1,16),(1,17),(1,18),(1,19),(1,20),
(1,21),(1,22),(1,23),(1,24),(1,25),(1,26),(1,27);

-- Subsets in other channels
INSERT INTO channel_members (channel_id, user_id) VALUES
(2,1),(2,2),(2,4),(2,6),(2,8),(2,11),(2,17),(2,19),(2,23),(2,26),
(3,1),(3,2),(3,3),(3,4),(3,5),(3,7),(3,10),(3,14),(3,18),(3,22),(3,25),
(4,1),(4,3),(4,8),(4,16),(4,20),(4,27);

-- ── DM conversation: Heather <-> Anna ──────────────────────────────────────────
INSERT INTO conversations (id, space_id) VALUES (1, 1);
INSERT INTO conversation_members (conversation_id, user_id) VALUES (1, 1), (1, 4);

-- ── Messages in Company Culture ────────────────────────────────────────────────
INSERT INTO messages (body, user_id, channel_id, created_at) VALUES
('Hello!', 2, 1, '2026-04-04 10:31:00'),
('How are you? I just found an excellent chat solution that will fit our needs!', 2, 1, '2026-04-04 10:32:00'),
('That sounds great! Let me know more about it.', 3, 1, '2026-04-04 10:33:00');
