<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\ActivityLogger;
use App\Models\TemplateModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Submit lab test templates for supported types (default / carousel).
 *
 * php spark templates:create-test
 * php spark templates:create-test all
 * php spark templates:create-test default [name]
 * php spark templates:create-test cta [name]
 * php spark templates:create-test carousel [name]
 */
class CreateTestTemplate extends BaseCommand
{
    protected $group       = 'Templates';
    protected $name        = 'templates:create-test';
    protected $description = 'Submit lab testing templates (default / CTA / carousel) via the active WhatsApp provider.';
    protected $usage       = 'templates:create-test [type=all|default|cta|carousel] [name]';

    public function run(array $params)
    {
        $typeArg = strtolower(trim((string) ($params[0] ?? 'all')));
        $nameArg = isset($params[1]) ? strtolower(trim((string) $params[1])) : '';

        // Back-compat: `templates:create-test my_name` treated as default name.
        if ($typeArg !== '' && ! in_array($typeArg, ['all', 'default', 'cta', 'carousel'], true)) {
            $nameArg = $typeArg;
            $typeArg = 'default';
        }

        $types = $typeArg === 'all' ? ['default', 'cta', 'carousel'] : [$typeArg];
        $suffix = date('mdHi');
        $created = [];
        $failed  = [];

        foreach ($types as $type) {
            $baseName = $nameArg !== ''
                ? $nameArg
                : match ($type) {
                    'cta'       => 'lab_test_cta_' . $suffix,
                    'carousel'  => 'lab_test_carousel_' . $suffix,
                    default     => 'lab_test_hello_' . $suffix,
                };
            $baseName = preg_replace('/[^a-z0-9_]/', '_', $baseName) ?: ('lab_test_' . $type . '_' . $suffix);

            // When creating multiple types with one custom name, suffix the type.
            $name = ($typeArg === 'all' && $nameArg !== '')
                ? preg_replace('/[^a-z0-9_]/', '_', $nameArg . '_' . $type)
                : $baseName;

            try {
                $result = $this->createOne((string) $name, $type, $suffix);
                $created[] = $result;
                CLI::write(
                    "OK [{$type}] {$result['name']} → {$result['status']} (id: " . ($result['meta_id'] ?: 'n/a') . ')',
                    'green'
                );
            } catch (Throwable $e) {
                $failed[] = ['type' => $type, 'name' => $name, 'error' => $e->getMessage()];
                CLI::error("FAIL [{$type}] {$name}: " . $e->getMessage());
            }
        }

        CLI::newLine();
        CLI::write('Created: ' . count($created) . ' | Failed: ' . count($failed), count($failed) > 0 ? 'yellow' : 'green');
        CLI::write('After APPROVED → php spark templates:sync, then cheerio:test-template <name> <lang> <to>');

        return $failed === [] ? EXIT_SUCCESS : EXIT_ERROR;
    }

    /**
     * @return array{name: string, status: string, meta_id: string, template_type: string}
     */
    protected function createOne(string $name, string $type, string $suffix): array
    {
        $spec = match ($type) {
            'cta'      => $this->buildCtaSpec($name, $suffix),
            'carousel' => $this->buildCarouselSpec($name, $suffix),
            default    => $this->buildDefaultSpec($name, $suffix),
        };

        CLI::write(
            "Submitting [{$type}] {$spec['name']} ({$spec['language']} / {$spec['category']})...",
            'yellow'
        );

        $api      = service('whatsApp');
        $response = $api->createTemplate([
            'name'                  => $spec['name'],
            'language'              => $spec['language'],
            'category'              => $spec['category'],
            'components'            => $spec['components'],
            'allow_category_change' => true,
        ]);

        $responseData = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $metaId = (string) ($responseData['id'] ?? $response['id'] ?? '');
        $status = strtoupper((string) ($responseData['status'] ?? $response['status'] ?? 'PENDING'));
        if ($status === '') {
            $status = 'PENDING';
        }

        $storedPayload = is_array($responseData) ? $responseData : [];
        $storedPayload['name']       = $spec['name'];
        $storedPayload['language']   = $spec['language'];
        $storedPayload['category']   = $spec['category'];
        $storedPayload['components'] = $spec['components'];

        model(TemplateModel::class)->insert([
            'meta_id'        => $metaId !== '' ? $metaId : null,
            'name'           => $spec['name'],
            'language'       => $spec['language'],
            'category'       => $spec['category'],
            'template_type'  => $spec['template_type'],
            'status'         => $status,
            'header_type'    => $spec['header_type'],
            'header_content' => $spec['header_content'],
            'body'           => $spec['body'],
            'footer'         => $spec['footer'],
            'buttons'        => $spec['buttons'],
            'variables'      => $spec['variables'],
            'raw_payload'    => $storedPayload,
            'synced_at'      => date('Y-m-d H:i:s'),
        ]);

        (new ActivityLogger())->log('create', 'templates', "Lab test template {$spec['name']} submitted", [
            'meta_id'       => $metaId,
            'status'        => $status,
            'template_type' => $spec['template_type'],
            'variant'       => $type,
        ]);

        return [
            'name'          => $spec['name'],
            'status'        => $status,
            'meta_id'       => $metaId,
            'template_type' => $spec['template_type'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildDefaultSpec(string $name, string $suffix): array
    {
        $header = 'Update';
        $body   = 'Hello {{1}}, your request has been received. Reference ID: {{2}}. We will get back to you shortly.';
        $footer = 'Lab test template';

        return [
            'name'           => $name,
            'language'       => 'en_US',
            'category'       => 'UTILITY',
            'template_type'  => 'default',
            'header_type'    => 'TEXT',
            'header_content' => $header,
            'body'           => $body,
            'footer'         => $footer,
            'buttons'        => null,
            'variables'      => ['1', '2'],
            'components'     => [
                [
                    'type'   => 'HEADER',
                    'format' => 'TEXT',
                    'text'   => $header,
                ],
                [
                    'type'    => 'BODY',
                    'text'    => $body,
                    'example' => [
                        'body_text' => [['Ravi', 'LAB-' . $suffix]],
                    ],
                ],
                [
                    'type' => 'FOOTER',
                    'text' => $footer,
                ],
            ],
        ];
    }

    /**
     * Default-type template with a URL CTA button.
     *
     * @return array<string, mixed>
     */
    protected function buildCtaSpec(string $name, string $suffix): array
    {
        $body = 'Hi {{1}}, thanks for your interest. Tap below to continue with booking LAB-' . $suffix . '.';
        $buttons = [[
            'type' => 'URL',
            'text' => 'Open link',
            'url'  => 'https://elintom.io/',
        ]];

        return [
            'name'           => $name,
            'language'       => 'en_US',
            'category'       => 'MARKETING',
            'template_type'  => 'default',
            'header_type'    => null,
            'header_content' => null,
            'body'           => $body,
            'footer'         => 'Lab CTA test',
            'buttons'        => $buttons,
            'variables'      => ['1'],
            'components'     => [
                [
                    'type'    => 'BODY',
                    'text'    => $body,
                    'example' => [
                        'body_text' => [['Ravi']],
                    ],
                ],
                [
                    'type' => 'FOOTER',
                    'text' => 'Lab CTA test',
                ],
                [
                    'type'    => 'BUTTONS',
                    'buttons' => $buttons,
                ],
            ],
        ];
    }

    /**
     * Carousel marketing template with 2 image cards.
     *
     * @return array<string, mixed>
     */
    protected function buildCarouselSpec(string $name, string $suffix): array
    {
        $mediaSource = $this->prepareCarouselMedia();
        // Keep body long enough for Meta variable/word ratio checks.
        $body = 'Browse our curated lab product picks specially selected for {{1}} and discover limited offers today.';

        $cards = [];
        foreach (['Offer A', 'Offer B'] as $index => $label) {
            $cards[] = [
                'components' => [
                    [
                        'type'    => 'HEADER',
                        'format'  => 'IMAGE',
                        'example' => [
                            'header_handle' => [$mediaSource],
                        ],
                    ],
                    [
                        'type' => 'BODY',
                        'text' => $label . ' - limited lab deal available now for customers in batch ' . $suffix,
                    ],
                    [
                        'type'    => 'BUTTONS',
                        'buttons' => [[
                            'type' => 'URL',
                            'text' => 'View deal',
                            'url'  => 'https://elintom.io/',
                        ]],
                    ],
                ],
            ];
        }

        return [
            'name'           => $name,
            'language'       => 'en_US',
            'category'       => 'MARKETING',
            'template_type'  => 'carousel',
            'header_type'    => 'image',
            'header_content' => $mediaSource,
            'body'           => $body,
            'footer'         => null,
            'buttons'        => ['carousel_cards' => 2],
            'variables'      => ['1'],
            'components'     => [
                [
                    'type'    => 'BODY',
                    'text'    => $body,
                    'example' => [
                        'body_text' => [['today']],
                    ],
                ],
                [
                    'type'  => 'CAROUSEL',
                    'cards' => $cards,
                ],
            ],
        ];
    }

    /**
     * Cheerio rejects messaging media IDs as template header_handle.
     * Reuse a public HTTPS sample from an already-approved image template.
     */
    protected function prepareCarouselMedia(): string
    {
        $rows = \Config\Database::connect()->table('templates')
            ->select('header_content, raw_payload')
            ->where('status', 'APPROVED')
            ->groupStart()
                ->where('header_type', 'image')
                ->orWhere('header_type', 'IMAGE')
            ->groupEnd()
            ->orderBy('id', 'DESC')
            ->limit(10)
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $direct = trim((string) ($row['header_content'] ?? ''));
            if ($this->isUsableTemplateMediaHandle($direct)) {
                CLI::write('  Reusing approved template media sample URL.', 'white');

                return $direct;
            }

            $raw = $row['raw_payload'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $raw     = is_array($decoded) ? $decoded : null;
            }
            if (! is_array($raw)) {
                continue;
            }

            foreach (($raw['components'] ?? []) as $component) {
                if (! is_array($component) || strtoupper((string) ($component['type'] ?? '')) !== 'HEADER') {
                    continue;
                }
                $handle = $component['example']['header_handle'][0]
                    ?? $component['example']['header_url']
                    ?? $component['example']['link']
                    ?? null;
                if (is_string($handle) && $this->isUsableTemplateMediaHandle($handle)) {
                    CLI::write('  Reusing approved template media sample URL.', 'white');

                    return $handle;
                }
            }
        }

        throw new \RuntimeException(
            'No approved image template sample found for carousel header_handle. '
            . 'Approve one image template first, or paste a public HTTPS media URL in the UI.'
        );
    }

    protected function isUsableTemplateMediaHandle(string $value): bool
    {
        if ($value === '' || ! preg_match('#^https?://#i', $value)) {
            return false;
        }

        return ! preg_match('#^https?://(localhost|127\.0\.0\.1)(:|/|$)#i', $value);
    }
}
