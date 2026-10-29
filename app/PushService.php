<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class PushService
{
    public function __construct(private array $config, private PDO $db)
    {
    }

    public function send(string $title, string $body): int
    {
        return $this->sendDetailed($title, $body)['sent'];
    }

    public function sendDetailed(string $title, string $body, ?string $url = null, ?int $subscriptionId = null): array
    {
        $publicKey = trim((string)$this->config['push']['public_key']);
        $privateKey = trim((string)$this->config['push']['private_key']);
        $subject = trim((string)$this->config['push']['subject']);

        if ($publicKey === '' || $privateKey === '' || $subject === '') {
            throw new RuntimeException('VAPID credentials are not configured.');
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url ?? (rtrim((string)$this->config['app']['base_url'], '/') . '/'),
        ], JSON_THROW_ON_ERROR);

        if ($subscriptionId !== null) {
            $subscriptionQuery = $this->db->prepare(
                'SELECT id, subscription_json FROM gaugeiq_push_subscriptions WHERE id = ? ORDER BY id ASC'
            );
            $subscriptionQuery->execute([$subscriptionId]);
            $subscriptions = $subscriptionQuery->fetchAll();
            if (!$subscriptions) {
                throw new RuntimeException('That device subscription no longer exists. Refresh the settings page and try again.');
            }
        } else {
            $subscriptions = $this->db->query(
                'SELECT id, subscription_json FROM gaugeiq_push_subscriptions ORDER BY id ASC'
            )->fetchAll();
        }

        $sent = 0;
        $results = [];

        foreach ($subscriptions as $row) {
            $id = (int)$row['id'];

            try {
                $subscription = Subscription::create(
                    json_decode((string)$row['subscription_json'], true, 512, JSON_THROW_ON_ERROR)
                );

                $report = $webPush->sendOneNotification($subscription, $payload);

                if ($report->isSuccess()) {
                    $sent++;
                    $mark = $this->db->prepare('UPDATE gaugeiq_push_subscriptions SET last_push_at = ?, last_push_status = ?, last_push_error = NULL WHERE id = ?');
                    $mark->execute([gmdate('c'), 'sent', $id]);
                    $results[] = [
                        'id' => $id,
                        'ok' => true,
                        'status' => $report->getResponse()?->getStatusCode(),
                    ];
                    continue;
                }

                $status = $report->getResponse()?->getStatusCode();
                $reason = trim((string)$report->getReason());

                $mark = $this->db->prepare('UPDATE gaugeiq_push_subscriptions SET last_push_at = ?, last_push_status = ?, last_push_error = ? WHERE id = ?');
                $mark->execute([gmdate('c'), 'failed', $reason !== '' ? $reason : 'Push service rejected the notification.', $id]);

                $results[] = [
                    'id' => $id,
                    'ok' => false,
                    'status' => $status,
                    'reason' => $reason !== '' ? $reason : 'Push service rejected the notification.',
                ];

                if ($status === 404 || $status === 410) {
                    $delete = $this->db->prepare('DELETE FROM gaugeiq_push_subscriptions WHERE id = ?');
                    $delete->execute([$id]);
                }
            } catch (Throwable $e) {
                $mark = $this->db->prepare('UPDATE gaugeiq_push_subscriptions SET last_push_at = ?, last_push_status = ?, last_push_error = ? WHERE id = ?');
                $mark->execute([gmdate('c'), 'failed', $e->getMessage(), $id]);
                $results[] = [
                    'id' => $id,
                    'ok' => false,
                    'status' => null,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            'sent' => $sent,
            'subscriptions' => count($subscriptions),
            'results' => $results,
        ];
    }
}
