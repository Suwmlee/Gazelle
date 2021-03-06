<?php

namespace Gazelle;

class Report extends Base {
    public function openCount(): int {
        if (($count = $this->cache->get_value('num_torrent_reportsv2')) === false) {
            $count = $this->db->scalar("
                SELECT count(*)
                FROM reportsv2
                WHERE Status = 'New'
            ");
            $this->cache->cache_value('num_torrent_reportsv2', $count, 3600 * 6);
        }
        return $count;
    }

    public function otherCount(): int {
        if (($count = $this->cache->get_value('num_other_reports')) === false) {
            $count = $this->db->scalar("
                SELECT count(*)
                FROM reports
                WHERE Status = 'New'
            ");
            $this->cache->cache_value('num_other_reports', $count, 3600 * 6);
        }
        return $count;
    }

    public function forumCount(): int {
        if (($count = $this->cache->get_value('num_forum_reports')) === false) {
            $count = $this->db->scalar("
                SELECT count(*)
                FROM reports
                WHERE Status = 'New'
                    AND Type IN ('artist_comment', 'collages_comment', 'post', 'requests_comment', 'thread', 'torrents_comment')
            ");
            $this->cache->cache_value('num_forum_reports', $count, 3600 * 6);
        }
        return $count;
    }

    public function search(array $filter): array {
        $cond = [];
        $args = [];
        $delcond = [];
        $delargs = [];
        if (array_key_exists('reporter', $filter) && $filter['reporter']) {
            $cond[] = 'r.ReporterID = ?';
            $args[] = $this->username2id($filter['reporter']);
        }
        if (array_key_exists('handler', $filter) && $filter['handler']) {
            $cond[] = 'r.ResolverID = ?';
            $args[] = $this->username2id($filter['handler']);
        }
        if (array_key_exists('report-type', $filter)) {
            $cond[] = 'r.Type in (' . placeholders($filter['report-type']) . ')';
            $args = array_merge($args, $filter['report-type']);
        }
        if (array_key_exists('dt-from', $filter)) {
            $cond[] = 'r.ReportedTime >= ?';
            $args[] = $filter['dt-from'];
        }
        if (array_key_exists('dt-until', $filter)) {
            $delcond[] = 'r.ReportedTime <= ? + INTERVAL 1 DAY';
            $delargs[] = $filter['dt-until'];
        }
        if (array_key_exists('torrent', $filter)) {
            $delcond[] = 'r.TorrentID = ?';
            $delargs[] = $filter['torrent'];
        }
        if (array_key_exists('uploader', $filter) && $filter['uploader']) {
            $cond[] = 't.UserID = ?';
            $args[] = $this->username2id($filter['uploader']);
            $delcond[] = 'dt.UserID = ?';
            $delargs[] = $this->username2id($filter['uploader']);
        }
        if (array_key_exists('group', $filter)) {
            $cond[] = 't.GroupID = ?';
            $args[] = $filter['group'];
            $delcond[] = 'dt.GroupID = ?';
            $delargs[] = $filter['group'];
        }
        if (count($cond) == 0 && count($delcond) == 0) {
            $cond = ['1 = 1'];
        }
        $conds = implode(' AND ', $cond);
        /* The construct below is pretty sick: we alias the group_log table to t
         * which means that t.GroupID in a condition refers to the same thing in
         * the `torrents` table as well. I am not certain this is entirely sane.
         */
        $sql_where = implode("\n\t\tAND ", array_merge($cond, $delcond));
        $sql = "
            SELECT SQL_CALC_FOUND_ROWS
                r.ID, r.ReporterID, r.ResolverID, r.TorrentID,
                coalesce(t.UserID, dt.UserID) as UserID,
                coalesce(t.GroupID, dt.GroupID) as GroupID,
                coalesce(t.Media, dt.Media) as Media,
                coalesce(t.Format, dt.Format) as Format,
                coalesce(t.Encoding, dt.Encoding) as Encoding,
                coalesce(g.Name, gl.Info) as Name, g.Year, r.Type, r.ReportedTime
            FROM reportsv2 r
            LEFT JOIN torrents t ON (t.ID = r.TorrentID)
            LEFT JOIN deleted_torrents dt ON (dt.ID = r.TorrentID)
            LEFT JOIN torrents_group g on (g.ID = t.GroupID)
            LEFT JOIN (
                SELECT max(t.ID) AS ID, t.TorrentID
                FROM group_log t
                INNER JOIN reportsv2 r using (TorrentID)
                WHERE t.Info NOT LIKE 'uploaded%'
                AND $conds
                GROUP BY t.TorrentID
            ) LASTLOG USING (TorrentID)
            LEFT JOIN group_log gl ON (gl.ID = LASTLOG.ID)
            WHERE $sql_where
            ORDER BY r.ReportedTime DESC LIMIT ? OFFSET ?
        ";
        $args = array_merge(
            $args,
            $args,
            $delargs,
            [
                TORRENTS_PER_PAGE, // LIMIT
                TORRENTS_PER_PAGE * (max($filter['page'], 1) - 1), // OFFSET
            ]
        );
        $this->db->prepared_query($sql, ...$args);
        $result = $this->db->to_array();
        return [$result, $this->db->scalar('SELECT FOUND_ROWS()')];
    }

    protected function username2id (string $name): ?int {
        return $this->db->scalar('SELECT ID FROM users_main WHERE Username = ?', $name);
    }
}
