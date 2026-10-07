<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Libraries\Email\SesEmailDriver;

/**
 * One-click Amazon SES → SNS → app webhook wiring for delivery / bounce / complaint tracking:
 * SNS topic + HTTPS subscription, SES configuration set + SNS event destination, saved in settings.
 * The subscription is confirmed automatically by SesNotificationService when SNS calls the webhook.
 */
class SesBounceTrackingService
{
    protected const DESTINATION = 'sateri-sns';
    protected const EVENT_TYPES = ['DELIVERY', 'BOUNCE', 'COMPLAINT'];

    protected SettingsService $settings;
    protected SesEmailDriver $ses;

    public function __construct(?SettingsService $settings = null, ?SesEmailDriver $ses = null)
    {
        $this->settings = $settings ?? new SettingsService();
        $this->ses      = $ses ?? new SesEmailDriver($this->settings);
    }

    /**
     * @return array{ok: bool, status: int, message: string, data?: array<string, mixed>}
     */
    public function connect(): array
    {
        $cfg = $this->settings->getSesConfig();
        if (trim($cfg['access_key']) === '' || trim($cfg['secret_key']) === '') {
            return $this->fail(400, 'Save the Amazon SES Access Key, Secret Key and Region first.');
        }

        $webhook = EmailTracking::sesWebhookUrl();
        if (! $this->isPublicHttps($webhook)) {
            return $this->fail(422, 'Bounce tracking needs a public HTTPS address so Amazon can reach the app. Open this screen from the live https:// site (current webhook: ' . $webhook . ').');
        }

        $suffix = $this->slug(TenantContext::get() ?? 'default');

        $topic = $this->ses->snsRequest(['Action' => 'CreateTopic', 'Name' => 'sateri-ses-events-' . $suffix]);
        $arn   = (string) ($topic['decoded']['CreateTopicResponse']['CreateTopicResult']['TopicArn'] ?? '');
        if (! $topic['ok'] || $arn === '') {
            return $this->fail(502, $this->permissionHint($topic['error'] ?: 'Could not create the Amazon SNS topic.', 'sns:CreateTopic'));
        }

        // Pin before subscribing so the confirmation request is accepted by the webhook.
        $this->settings->setSesConfig(['sns_topic_arn' => $arn]);

        $sub = $this->ses->snsRequest(['Action' => 'Subscribe', 'TopicArn' => $arn, 'Protocol' => 'https', 'Endpoint' => $webhook]);
        if (! $sub['ok']) {
            return $this->fail(502, $this->permissionHint($sub['error'] ?: 'Could not subscribe the webhook to Amazon SNS.', 'sns:Subscribe'));
        }

        $setName = trim($cfg['configuration_set']) !== '' ? trim($cfg['configuration_set']) : 'sateri-' . $suffix;
        $created = $this->ses->apiRequest('POST', '/v2/email/configuration-sets', ['ConfigurationSetName' => $setName]);
        if (! $created['ok'] && ! $this->isAlreadyExists($created)) {
            return $this->fail(502, $this->permissionHint($created['error'], 'ses:CreateConfigurationSet'));
        }

        $policy = $this->ses->snsRequest([
            'Action'         => 'SetTopicAttributes',
            'TopicArn'       => $arn,
            'AttributeName'  => 'Policy',
            'AttributeValue' => $this->topicPolicy($arn, $setName),
        ]);
        if (! $policy['ok']) {
            return $this->fail(502, $this->permissionHint($policy['error'], 'sns:SetTopicAttributes'));
        }

        $destination = ['Enabled' => true, 'MatchingEventTypes' => self::EVENT_TYPES, 'SnsDestination' => ['TopicArn' => $arn]];
        $path        = '/v2/email/configuration-sets/' . rawurlencode($setName) . '/event-destinations';
        $dest        = $this->ses->apiRequest('POST', $path, ['EventDestinationName' => self::DESTINATION, 'EventDestination' => $destination]);
        if (! $dest['ok'] && $this->isAlreadyExists($dest)) {
            $dest = $this->ses->apiRequest('PUT', $path . '/' . self::DESTINATION, ['EventDestination' => $destination]);
        }
        if (! $dest['ok']) {
            return $this->fail(502, $this->permissionHint($dest['error'], 'ses:CreateConfigurationSetEventDestination'));
        }

        $this->settings->setSesConfig(['configuration_set' => $setName, 'sns_topic_arn' => $arn]);

        log_activity('ses_bounce_tracking_connected', 'settings', 'Amazon SES bounce & delivery tracking connected', [
            'topic_arn'         => $arn,
            'configuration_set' => $setName,
            'webhook'           => $webhook,
        ]);

        return [
            'ok'      => true,
            'status'  => 200,
            'message' => 'Bounce tracking connected. Amazon will confirm the webhook within a minute — use "Check status" to confirm.',
            'data'    => $this->status(false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(bool $live = true): array
    {
        $cfg     = $this->settings->getSesConfig();
        $arn     = trim($cfg['sns_topic_arn']);
        $webhook = EmailTracking::sesWebhookUrl();
        $state   = $arn === '' ? 'not_connected' : 'unknown';

        if ($live && $arn !== '') {
            $res = $this->ses->snsRequest(['Action' => 'ListSubscriptionsByTopic', 'TopicArn' => $arn]);
            if ($res['ok']) {
                $state = 'not_subscribed';
                foreach ((array) ($res['decoded']['ListSubscriptionsByTopicResponse']['ListSubscriptionsByTopicResult']['Subscriptions'] ?? []) as $s) {
                    if (($s['Endpoint'] ?? '') === $webhook) {
                        $state = ($s['SubscriptionArn'] ?? '') === 'PendingConfirmation' ? 'pending' : 'connected';
                        break;
                    }
                }
            }
        }

        return [
            'state'             => $state,
            'topic_arn'         => $arn,
            'configuration_set' => trim($cfg['configuration_set']),
            'webhook'           => $webhook,
            'webhook_public'    => $this->isPublicHttps($webhook),
        ];
    }

    /**
     * Topic access policy: account owner keeps full control, SES may publish only for this configuration set.
     */
    protected function topicPolicy(string $topicArn, string $configurationSet): string
    {
        [, , , $region, $account] = array_pad(explode(':', $topicArn), 6, '');

        return (string) json_encode([
            'Version'   => '2012-10-17',
            'Statement' => [
                [
                    'Sid'       => 'OwnerAccess',
                    'Effect'    => 'Allow',
                    'Principal' => ['AWS' => 'arn:aws:iam::' . $account . ':root'],
                    'Action'    => ['sns:Publish', 'sns:Subscribe', 'sns:GetTopicAttributes', 'sns:SetTopicAttributes', 'sns:ListSubscriptionsByTopic'],
                    'Resource'  => $topicArn,
                ],
                [
                    'Sid'       => 'AllowSesPublish',
                    'Effect'    => 'Allow',
                    'Principal' => ['Service' => 'ses.amazonaws.com'],
                    'Action'    => 'sns:Publish',
                    'Resource'  => $topicArn,
                    'Condition' => ['StringEquals' => [
                        'AWS:SourceAccount' => $account,
                        'AWS:SourceArn'     => 'arn:aws:ses:' . $region . ':' . $account . ':configuration-set/' . $configurationSet,
                    ]],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    protected function isPublicHttps(string $url): bool
    {
        $parts = parse_url($url);
        $host  = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.test')) {
            return false;
        }

        return ! filter_var($host, FILTER_VALIDATE_IP)
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    protected function slug(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($value)), '-') ?: 'default';
    }

    /**
     * @param array{status: int, decoded: mixed, error?: string} $res
     */
    protected function isAlreadyExists(array $res): bool
    {
        $raw = (is_array($res['decoded']) ? json_encode($res['decoded']) : '') . ' ' . ($res['error'] ?? '');

        return $res['status'] === 409 || preg_match('/AlreadyExists|already exist/i', $raw) === 1;
    }

    protected function permissionHint(string $error, string $action): string
    {
        return preg_match('/not authorized|AccessDenied|AuthorizationError/i', $error) === 1
            ? $error . ' — add the "' . $action . '" permission to the AWS IAM user used for SES.'
            : $error;
    }

    /**
     * @return array{ok: false, status: int, message: string}
     */
    protected function fail(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
