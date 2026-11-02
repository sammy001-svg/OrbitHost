<?php
/**
 * Orbit Cloud — SMS: client notifications and campaigns over Shanfix SMS.
 *
 * Every text goes through sms_messages, which is both the outbox and the
 * log: queued → sent (accepted by Shanfix SMS) → delivered | failed.
 *
 *   Sms::notify($type, $client_id, $text)
 *       From Notifier::send() for notification types that carry an 'sms'
 *       template. Tried immediately; a rate-limit or network failure stays
 *       queued for cron/sms.php to retry.
 *
 *   Sms::createCampaign([...])
 *       Snapshots the audience into queued rows. Sent by processQueue(),
 *       called once inline on "send now" and every minute by cron/sms.php.
 *       A campaign with no personal placeholders goes out through the bulk
 *       endpoint (1,000 numbers a call); a personalised one is one message
 *       per client, paced under the platform's 60-a-minute limit.
 *
 *   Sms::syncStatuses()
 *       Polls delivery reports for recently sent messages.
 *
 * Marketing campaigns skip clients who turned SMS offers off in the portal
 * (clients.sms_marketing = 0) unless the campaign is marked a service
 * notice. Notifications are transactional and always go out.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/providers/Provider.php';
require_once __DIR__ . '/providers/SmsClient.php';

final class Sms
{
    public const MAX_ATTEMPTS   = 5;
    /** Per processQueue() run — under the platform's 60 sends / 10 bulk calls a minute. */
    public const SINGLE_PER_RUN = 50;
    public const BULK_PER_RUN   = 8;

    /** Placeholders a campaign may use. */
    public const PLACEHOLDERS = ['{first_name}', '{last_name}', '{full_name}', '{company}'];

    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS sms_campaigns (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name            VARCHAR(150) NOT NULL,
                message         TEXT NOT NULL,
                audience        VARCHAR(30)  NOT NULL,
                audience_meta   TEXT DEFAULT NULL,
                service_notice  TINYINT(1)   NOT NULL DEFAULT 0,
                personalized    TINYINT(1)   NOT NULL DEFAULT 0,
                status          ENUM('scheduled','sending','completed','cancelled') NOT NULL DEFAULT 'scheduled',
                scheduled_at    DATETIME     NOT NULL,
                total           INT UNSIGNED NOT NULL DEFAULT 0,
                created_by      INT UNSIGNED DEFAULT NULL,
                created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at    DATETIME DEFAULT NULL,
                INDEX idx_smsc_status (status, scheduled_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            db()->exec("CREATE TABLE IF NOT EXISTS sms_messages (
                id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                campaign_id      INT UNSIGNED DEFAULT NULL,
                client_id        INT UNSIGNED DEFAULT NULL,
                phone            VARCHAR(20)  NOT NULL,
                message          TEXT NOT NULL,
                type             VARCHAR(60)  NOT NULL DEFAULT 'manual',
                status           ENUM('queued','sent','delivered','failed','cancelled') NOT NULL DEFAULT 'queued',
                segments         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                units            DECIMAL(10,4) DEFAULT NULL,
                provider_msg_id  BIGINT UNSIGNED DEFAULT NULL,
                attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
                next_attempt_at  DATETIME NOT NULL,
                error            VARCHAR(255) DEFAULT NULL,
                created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at          DATETIME DEFAULT NULL,
                delivered_at     DATETIME DEFAULT NULL,
                INDEX idx_smsm_queue (status, next_attempt_at),
                INDEX idx_smsm_campaign (campaign_id, status),
                INDEX idx_smsm_client (client_id),
                INDEX idx_smsm_provider (provider_msg_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) { /* no CREATE privilege — SMS stays unavailable */ }
        try {
            if (!db()->query("SHOW COLUMNS FROM clients LIKE 'sms_marketing'")->fetch()) {
                db()->exec('ALTER TABLE clients ADD COLUMN sms_marketing TINYINT(1) NOT NULL DEFAULT 1');
            }
        } catch (\Throwable $e) {}
    }

    // ── Provider ─────────────────────────────────────────────────
    /** The active, configured SMS provider key, or null. */
    public static function provider(): ?string
    {
        foreach (ProviderRegistry::byCategory('sms') as $key => $_) {
            try {
                if (Provider::isActive($key) && Provider::isConfigured($key)) return $key;
            } catch (\Throwable $e) {}
        }
        return null;
    }

    public static function client(): ?SmsClient
    {
        $key = self::provider();
        return $key ? Provider::sms($key) : null;
    }

    public static function notificationsEnabled(): bool
    {
        $key = self::provider();
        return $key && !empty(Provider::config($key)['notify']);
    }

    /** Notification HTML/vars → one clean line of SMS text. */
    public static function plain(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text); // nbsp from format_money
        $text = trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\R{3,}/', "\n\n", $text)));
        return mb_substr($text, 0, SmsClient::MAX_LENGTH);
    }

    // ── Sending one message ──────────────────────────────────────
    /** Transactional notification to a client, if SMS notifications are on and they have a phone. */
    public static function notify(string $type, int $client_id, string $text): ?array
    {
        if (!self::notificationsEnabled()) return null;
        $s = db()->prepare('SELECT id, phone FROM clients WHERE id = ?');
        $s->execute([$client_id]);
        $c = $s->fetch();
        if (!$c || !SmsClient::normalizePhone($c['phone'] ?? '')) return null;
        return self::sendTo($client_id, (string) $c['phone'], $text, 'notify:' . $type);
    }

    /**
     * Record and send one message now. Rate-limited or network failures
     * stay queued for the cron; anything else is marked failed.
     * @return array{ok:bool, id?:int, status:string, message:string}
     */
    public static function sendTo(?int $client_id, string $phone, string $text, string $type = 'manual'): array
    {
        self::ensureSchema();
        $text  = self::plain($text);
        $phone_n = SmsClient::normalizePhone($phone);
        if ($text === '') return ['ok' => false, 'status' => 'failed', 'message' => 'The message is empty.'];

        // next_attempt_at is a minute out so the cron never picks this row
        // up while we're still sending it ourselves.
        db()->prepare("INSERT INTO sms_messages (client_id, phone, message, type, status, segments, next_attempt_at, error)
                       VALUES (?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL 1 MINUTE), ?)")
            ->execute([$client_id, $phone_n ?? $phone, $text, $type, $phone_n ? 'queued' : 'failed',
                       SmsClient::segments($text), $phone_n ? null : 'Invalid phone number']);
        $id = (int) db()->lastInsertId();
        if (!$phone_n) return ['ok' => false, 'id' => $id, 'status' => 'failed', 'message' => 'Invalid phone number: ' . $phone];

        $s = db()->prepare('SELECT * FROM sms_messages WHERE id = ?');
        $s->execute([$id]);
        return self::dispatchOne($s->fetch());
    }

    /** Send one queued row and record the outcome. */
    private static function dispatchOne(array $row): array
    {
        $client = self::client();
        if (!$client) {
            self::markRetry([(int) $row['id']], (int) $row['attempts'], 'No SMS provider is enabled.');
            return ['ok' => false, 'id' => (int) $row['id'], 'status' => 'queued', 'message' => 'No SMS provider is enabled — queued.'];
        }
        $r = $client->send($row['phone'], $row['message']);
        if ($r['success']) {
            db()->prepare("UPDATE sms_messages SET status = 'sent', provider_msg_id = ?, units = ?, sent_at = NOW(), attempts = attempts + 1, error = NULL WHERE id = ?")
                ->execute([$r['message_id'], $r['units'], (int) $row['id']]);
            return ['ok' => true, 'id' => (int) $row['id'], 'status' => 'sent', 'message' => 'Sent.'];
        }
        if (!empty($r['retry'])) {
            $final = self::markRetry([(int) $row['id']], (int) $row['attempts'], $r['message']);
            return ['ok' => false, 'id' => (int) $row['id'], 'status' => $final ? 'failed' : 'queued', 'message' => $r['message'],
                    'rate_limited' => ($r['http'] ?? 0) === 429];
        }
        self::markFailed([(int) $row['id']], $r['message']);
        return ['ok' => false, 'id' => (int) $row['id'], 'status' => 'failed', 'message' => $r['message']];
    }

    /** Back off and retry later; gives up after MAX_ATTEMPTS. @return bool true when it gave up */
    private static function markRetry(array $ids, int $attempts, string $error): bool
    {
        if (!$ids) return false;
        $attempts++;
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($attempts >= self::MAX_ATTEMPTS) {
            self::markFailed($ids, $error);
            return true;
        }
        $wait = min(60, 2 ** $attempts); // minutes: 2, 4, 8, 16
        db()->prepare("UPDATE sms_messages SET status = 'queued', attempts = ?, error = ?, next_attempt_at = DATE_ADD(NOW(), INTERVAL $wait MINUTE) WHERE id IN ($in)")
            ->execute(array_merge([$attempts, mb_substr($error, 0, 255)], $ids));
        return false;
    }

    private static function markFailed(array $ids, string $error): void
    {
        if (!$ids) return;
        $in = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("UPDATE sms_messages SET status = 'failed', attempts = attempts + 1, error = ? WHERE id IN ($in)")
            ->execute(array_merge([mb_substr($error, 0, 255)], $ids));
    }

    // ── Campaigns ────────────────────────────────────────────────
    public static function audiences(): array
    {
        return [
            'all'             => 'All active clients',
            'active_services' => 'Clients with an active service',
            'overdue'         => 'Clients with an unpaid overdue invoice',
            'expiring'        => 'Clients with a service due in the next 30 days',
            'selected'        => 'Specific clients',
        ];
    }

    /**
     * Who a campaign would reach.
     * @return array{recipients:array<int,array>, no_phone:int, opted_out:int}
     */
    public static function resolveAudience(string $audience, array $meta, bool $service_notice): array
    {
        self::ensureSchema();
        $where = "c.status = 'active'";
        $params = [];
        switch ($audience) {
            case 'active_services':
                $where .= " AND (EXISTS (SELECT 1 FROM orders o WHERE o.client_id = c.id AND o.status = 'active')"
                        . " OR EXISTS (SELECT 1 FROM client_services cs WHERE cs.client_id = c.id AND cs.status = 'active'))";
                break;
            case 'overdue':
                $where .= " AND EXISTS (SELECT 1 FROM invoices i WHERE i.client_id = c.id AND (i.status = 'overdue' OR (i.status = 'sent' AND i.due_date < CURDATE())))";
                break;
            case 'expiring':
                $where .= " AND (EXISTS (SELECT 1 FROM orders o WHERE o.client_id = c.id AND o.status IN ('active','suspended') AND o.next_due BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))"
                        . " OR EXISTS (SELECT 1 FROM client_services cs WHERE cs.client_id = c.id AND cs.status IN ('active','suspended') AND cs.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)))";
                break;
            case 'selected':
                $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($meta['client_ids'] ?? [])))));
                if (!$ids) return ['recipients' => [], 'no_phone' => 0, 'opted_out' => 0];
                $where = 'c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; // explicit pick: any status
                $params = $ids;
                break;
            case 'all':
                break;
            default:
                return ['recipients' => [], 'no_phone' => 0, 'opted_out' => 0];
        }

        try {
            $s = db()->prepare("SELECT c.id, c.first_name, c.last_name, c.company, c.phone, COALESCE(c.sms_marketing, 1) sms_marketing FROM clients c WHERE $where ORDER BY c.id");
            $s->execute($params);
            $rows = $s->fetchAll();
        } catch (\Throwable $e) {
            // client_services may not exist on an older install
            $s = db()->prepare("SELECT c.id, c.first_name, c.last_name, c.company, c.phone, 1 sms_marketing FROM clients c WHERE " . ($audience === 'selected' ? $where : "c.status = 'active'") . ' ORDER BY c.id');
            $s->execute($params);
            $rows = $s->fetchAll();
        }

        $out = []; $no_phone = 0; $opted_out = 0; $seen = [];
        foreach ($rows as $r) {
            $phone = SmsClient::normalizePhone($r['phone'] ?? '');
            if (!$phone) { $no_phone++; continue; }
            if (!$service_notice && !(int) $r['sms_marketing']) { $opted_out++; continue; }
            if (isset($seen[$phone])) continue; // one text per number, even if two accounts share it
            $seen[$phone] = true;
            $r['phone_n'] = $phone;
            $out[] = $r;
        }
        return ['recipients' => $out, 'no_phone' => $no_phone, 'opted_out' => $opted_out];
    }

    public static function personalize(string $tpl, array $c): string
    {
        return strtr($tpl, [
            '{first_name}' => trim((string) ($c['first_name'] ?? '')),
            '{last_name}'  => trim((string) ($c['last_name'] ?? '')),
            '{full_name}'  => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
            '{company}'    => trim((string) ($c['company'] ?? '')) ?: trim((string) ($c['first_name'] ?? '')),
        ]);
    }

    public static function isPersonalized(string $tpl): bool
    {
        foreach (self::PLACEHOLDERS as $p) if (str_contains($tpl, $p)) return true;
        return false;
    }

    /**
     * Create a campaign and queue a message for every recipient.
     * @param array $d name, message, audience, client_ids[], service_notice, scheduled_at (Y-m-d H:i or '')
     * @return array{ok:bool, message:string, campaign_id?:int, total?:int}
     */
    public static function createCampaign(array $d, int $admin_id): array
    {
        self::ensureSchema();
        $name    = trim((string) ($d['name'] ?? ''));
        $message = trim(str_replace("\r\n", "\n", (string) ($d['message'] ?? '')));
        $aud     = (string) ($d['audience'] ?? '');
        $notice  = !empty($d['service_notice']);
        if ($name === '')    return ['ok' => false, 'message' => 'Give the campaign a name.'];
        if ($message === '') return ['ok' => false, 'message' => 'Write the message.'];
        if (!isset(self::audiences()[$aud])) return ['ok' => false, 'message' => 'Choose who to send to.'];
        if (mb_strlen($message) > SmsClient::MAX_LENGTH) return ['ok' => false, 'message' => 'The message is longer than ' . SmsClient::MAX_LENGTH . ' characters.'];

        $when = trim((string) ($d['scheduled_at'] ?? ''));
        if ($when !== '') {
            $ts = strtotime($when);
            if (!$ts) return ['ok' => false, 'message' => 'That schedule time is not a valid date.'];
            if ($ts < time() - 60) return ['ok' => false, 'message' => 'The schedule time is in the past.'];
            $when = date('Y-m-d H:i:s', $ts);
        }

        $meta = ['client_ids' => array_values(array_filter(array_map('intval', (array) ($d['client_ids'] ?? []))))];
        $res  = self::resolveAudience($aud, $meta, $notice);
        if (!$res['recipients']) return ['ok' => false, 'message' => 'Nobody in that audience can receive this SMS (no valid phone number' . ($notice ? '' : ' or opted out') . ').'];

        $personal = self::isPersonalized($message);
        $pdo = db();
        // "Now" from the database itself — the queue compares against MySQL's
        // NOW(), so a PHP timestamp in a different time zone would leave a
        // send-now campaign "not due yet" for hours.
        if ($when === '') $when_db = (string) $pdo->query('SELECT NOW()')->fetchColumn();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO sms_campaigns (name, message, audience, audience_meta, service_notice, personalized, status, scheduled_at, total, created_by)
                           VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$name, $message, $aud, json_encode($meta), $notice ? 1 : 0, $personal ? 1 : 0,
                           $when === '' ? 'sending' : 'scheduled', $when === '' ? $when_db : $when,
                           count($res['recipients']), $admin_id]);
            $cid = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO sms_messages (campaign_id, client_id, phone, message, type, status, segments, next_attempt_at)
                                  VALUES (?,?,?,?, 'campaign', 'queued', ?, ?)");
            foreach ($res['recipients'] as $r) {
                $text = self::plain(self::personalize($message, $r));
                $ins->execute([$cid, (int) $r['id'], $r['phone_n'], $text, SmsClient::segments($text), $when === '' ? $when_db : $when]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Could not create the campaign: ' . $e->getMessage()];
        }

        log_activity('sms_campaign_create', 'sms_campaign', $cid,
            sprintf('"%s" — %d recipient(s), %s', $name, count($res['recipients']), $when === '' ? 'sending now' : 'scheduled for ' . $when));
        return ['ok' => true, 'campaign_id' => $cid, 'total' => count($res['recipients']),
                'message' => sprintf('Campaign "%s" %s for %d client(s).', $name, $when === '' ? 'is sending' : 'scheduled', count($res['recipients']))];
    }

    public static function cancelCampaign(int $id): array
    {
        self::ensureSchema();
        $u = db()->prepare("UPDATE sms_campaigns SET status = 'cancelled', completed_at = NOW() WHERE id = ? AND status IN ('scheduled','sending')");
        $u->execute([$id]);
        if (!$u->rowCount()) return ['ok' => false, 'message' => 'That campaign has already finished.'];
        $m = db()->prepare("UPDATE sms_messages SET status = 'cancelled' WHERE campaign_id = ? AND status = 'queued'");
        $m->execute([$id]);
        log_activity('sms_campaign_cancel', 'sms_campaign', $id, $m->rowCount() . ' unsent message(s) cancelled');
        return ['ok' => true, 'message' => 'Campaign cancelled — ' . $m->rowCount() . ' unsent message(s) will not go out.'];
    }

    // ── Queue ────────────────────────────────────────────────────
    /**
     * Send whatever is due. Safe to call from several places at once —
     * a named DB lock lets only one run work the queue.
     * @return array{sent:int, failed:int, retrying:int, locked?:bool, message?:string}
     */
    public static function processQueue(int $single_budget = self::SINGLE_PER_RUN, int $bulk_budget = self::BULK_PER_RUN): array
    {
        self::ensureSchema();
        $stats = ['sent' => 0, 'failed' => 0, 'retrying' => 0];
        $client = self::client();
        if (!$client) return $stats + ['message' => 'No SMS provider is enabled.'];
        if ((int) db()->query("SELECT GET_LOCK('orbit_sms_queue', 0)")->fetchColumn() !== 1) {
            return $stats + ['locked' => true];
        }

        try {
            db()->exec("UPDATE sms_campaigns SET status = 'sending' WHERE status = 'scheduled' AND scheduled_at <= NOW()");

            // 1. Plain (non-personalised) campaigns: one bulk call per 1,000 numbers.
            $campaigns = db()->query("SELECT id, message FROM sms_campaigns WHERE status = 'sending' AND personalized = 0 ORDER BY id")->fetchAll();
            foreach ($campaigns as $cmp) {
                while ($bulk_budget > 0) {
                    $q = db()->prepare("SELECT id, phone, attempts FROM sms_messages WHERE campaign_id = ? AND status = 'queued' AND next_attempt_at <= NOW() ORDER BY id LIMIT " . SmsClient::BULK_LIMIT);
                    $q->execute([(int) $cmp['id']]);
                    $rows = $q->fetchAll();
                    if (!$rows) break;
                    $bulk_budget--;

                    $text = self::plain($cmp['message']);
                    $r = $client->bulk(array_column($rows, 'phone'), $text);
                    $ids = array_map('intval', array_column($rows, 'id'));
                    if ($r['success']) {
                        $bad = array_flip($r['invalid'] ?? []);
                        $ok_ids = []; $bad_ids = [];
                        foreach ($rows as $row) {
                            if (isset($bad[$row['phone']])) $bad_ids[] = (int) $row['id']; else $ok_ids[] = (int) $row['id'];
                        }
                        if ($ok_ids) {
                            $per = count($ok_ids) ? round((float) $r['units'] / count($ok_ids), 4) : null;
                            $in = implode(',', array_fill(0, count($ok_ids), '?'));
                            db()->prepare("UPDATE sms_messages SET status = 'sent', units = ?, sent_at = NOW(), attempts = attempts + 1, error = NULL WHERE id IN ($in)")
                                ->execute(array_merge([$per], $ok_ids));
                        }
                        self::markFailed($bad_ids, 'Rejected by Shanfix SMS as an invalid number');
                        $stats['sent'] += count($ok_ids); $stats['failed'] += count($bad_ids);
                    } elseif (!empty($r['retry'])) {
                        $gave_up = self::markRetry($ids, (int) $rows[0]['attempts'], $r['message']);
                        $stats[$gave_up ? 'failed' : 'retrying'] += count($ids);
                        break; // rate-limited or unreachable — leave the rest for the next run
                    } else {
                        self::markFailed($ids, $r['message']);
                        $stats['failed'] += count($ids);
                    }
                }
            }

            // 2. Notifications, retries and personalised campaigns: one at a time.
            if ($single_budget > 0) {
                $rows = db()->query("SELECT m.* FROM sms_messages m LEFT JOIN sms_campaigns c ON c.id = m.campaign_id
                                     WHERE m.status = 'queued' AND m.next_attempt_at <= NOW()
                                       AND (m.campaign_id IS NULL
                                            OR (c.status = 'sending' AND c.personalized = 1)
                                            OR c.status = 'completed') -- a manual retry after the campaign finished
                                     ORDER BY m.campaign_id IS NOT NULL, m.id LIMIT " . (int) $single_budget)->fetchAll();
                foreach ($rows as $row) {
                    $r = self::dispatchOne($row);
                    if ($r['status'] === 'sent') $stats['sent']++;
                    elseif ($r['status'] === 'failed') $stats['failed']++;
                    else $stats['retrying']++;
                    if (!empty($r['rate_limited'])) break;
                }
            }

            // 3. Campaigns with nothing left to send are done.
            db()->exec("UPDATE sms_campaigns c SET status = 'completed', completed_at = NOW()
                        WHERE c.status = 'sending' AND NOT EXISTS (SELECT 1 FROM sms_messages m WHERE m.campaign_id = c.id AND m.status = 'queued')");
        } finally {
            db()->query("SELECT RELEASE_LOCK('orbit_sms_queue')");
        }
        return $stats;
    }

    /**
     * Pull delivery reports for messages sent in the last three days.
     * Bulk-sent messages have no platform id and stay 'sent'.
     */
    public static function syncStatuses(int $max_calls = 5): array
    {
        self::ensureSchema();
        $client = self::client();
        $out = ['delivered' => 0, 'failed' => 0];
        if (!$client) return $out;

        $last = 0;
        for ($i = 0; $i < $max_calls; $i++) {
            $q = db()->prepare("SELECT id, provider_msg_id FROM sms_messages
                                WHERE status = 'sent' AND provider_msg_id IS NOT NULL AND sent_at > DATE_SUB(NOW(), INTERVAL 3 DAY) AND id > ?
                                ORDER BY id LIMIT 200");
            $q->execute([$last]);
            $rows = $q->fetchAll();
            if (!$rows) break;
            $last = (int) end($rows)['id'];

            $r = $client->status(array_column($rows, 'provider_msg_id'));
            if (!$r['success']) break;
            foreach ($rows as $row) {
                $m = $r['messages'][(int) $row['provider_msg_id']] ?? null;
                if (!$m) continue;
                if ($m['status'] === 'delivered') {
                    db()->prepare("UPDATE sms_messages SET status = 'delivered', delivered_at = COALESCE(?, NOW()) WHERE id = ?")
                        ->execute([$m['delivered_at'] ?? null, (int) $row['id']]);
                    $out['delivered']++;
                } elseif (in_array($m['status'], ['failed', 'undelivered'], true)) {
                    db()->prepare("UPDATE sms_messages SET status = 'failed', error = ? WHERE id = ?")
                        ->execute([mb_substr((string) ($m['failed_reason'] ?: ($m['delivery_status'] ?: 'Not delivered')), 0, 255), (int) $row['id']]);
                    $out['failed']++;
                }
            }
            if (count($rows) < 200) break;
        }
        return $out;
    }

    /** Record that the SMS cron ran (shown on the SMS page so a dead cron is obvious). */
    public static function heartbeat(): void
    {
        try {
            db()->exec('CREATE TABLE IF NOT EXISTS cron_heartbeats (name VARCHAR(40) NOT NULL PRIMARY KEY, ran_at DATETIME NOT NULL)');
            db()->prepare("REPLACE INTO cron_heartbeats (name, ran_at) VALUES ('sms', NOW())")->execute();
        } catch (\Throwable $e) {}
    }

    /** Minutes since the SMS cron last ran, or null if it never has. */
    public static function cronMinutesAgo(): ?int
    {
        try {
            $v = db()->query("SELECT TIMESTAMPDIFF(MINUTE, ran_at, NOW()) FROM cron_heartbeats WHERE name = 'sms'")->fetchColumn();
            return $v === false ? null : (int) $v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Counts per status for one campaign. */
    public static function campaignStats(int $campaign_id): array
    {
        $s = db()->prepare('SELECT status, COUNT(*) n, COALESCE(SUM(units),0) u FROM sms_messages WHERE campaign_id = ? GROUP BY status');
        $s->execute([$campaign_id]);
        $out = ['queued' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'cancelled' => 0, 'units' => 0.0];
        foreach ($s->fetchAll() as $r) { $out[$r['status']] = (int) $r['n']; $out['units'] += (float) $r['u']; }
        return $out;
    }
}
