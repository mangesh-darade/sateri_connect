<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * AI caption + hook + hashtag suggestions for social posts, using the same AI provider and key as the
 * rest of the app (Settings → AI). Suggestions only: nothing is posted without the user choosing it.
 * Returns ['ok','status','message','data'? => ['options' => list<array{hook, caption, hashtags}>]].
 */
class SocialCaptionService
{
    private const PLATFORM_HINTS = [
        'instagram' => 'Instagram Reel: hook in the first line, 5-8 hashtags mixing niche and broad, caption under 300 characters.',
        'facebook'  => 'Facebook Page: friendly and conversational, 1-3 hashtags, one clear call to action.',
        'youtube'   => 'YouTube Short: keyword-rich, curiosity hook, 3-5 hashtags, under 200 characters.',
        'linkedin'  => 'LinkedIn: professional but human, a strong first line, 3-5 hashtags, no emojis overload.',
    ];

    private const LANGUAGES = ['English', 'Hindi', 'Marathi', 'Hinglish'];
    private const TONES     = ['friendly', 'professional', 'funny', 'inspiring', 'urgent'];

    private SocialAiClient $client;

    public function __construct(?SocialAiClient $client = null, private ?SettingsService $settings = null)
    {
        $this->settings ??= new SettingsService();
        $this->client   = $client ?? new SocialAiClient($this->settings);
    }

    public function providerLabel(): string
    {
        return $this->client->label();
    }

    private function businessName(): string
    {
        $config = $this->settings->getAiConfig();

        return trim((string) ($config['business_name'] ?: 'our business'));
    }

    private const HONESTY = "Be honest: no false claims, no fake urgency, no engagement bait like 'like and share to win', no invented prices or statistics. Use [placeholders] for facts you do not know.";

    /**
     * Three caption options for a topic (no picture).
     *
     * @return array<string, mixed>
     */
    public function suggest(string $topic, string $platform, string $tone, string $language): array
    {
        $topic = trim(mb_substr($topic, 0, 600));
        if ($topic === '') {
            return $this->err(422, 'Describe what the post is about.');
        }

        $platform = isset(self::PLATFORM_HINTS[$platform]) ? $platform : 'instagram';
        $tone     = in_array($tone, self::TONES, true) ? $tone : 'friendly';
        $language = in_array($language, self::LANGUAGES, true) ? $language : 'English';

        $system = 'You write social media captions for ' . $this->businessName() . ". Language: {$language}. Tone: {$tone}.\n"
            . 'Platform rules: ' . self::PLATFORM_HINTS[$platform] . "\n"
            . "Give exactly 3 different options. Each has: hook (a scroll-stopping first line, under 90 characters), caption (the full text starting with the hook), hashtags (a single string of relevant hashtags).\n"
            . self::HONESTY . "\n"
            . 'Return ONLY valid JSON: {"options":[{"hook":"...","caption":"...","hashtags":"#a #b"}]}. No markdown.';

        $res = $this->client->generate($system, 'Post is about: ' . $topic, null, 3000);
        if (! $res['ok']) {
            return $res;
        }

        $decoded = $this->decode((string) $res['text']);
        $options = [];
        foreach ((array) ($decoded['options'] ?? []) as $o) {
            if (! is_array($o) || trim((string) ($o['caption'] ?? '')) === '') {
                continue;
            }
            $options[] = [
                'hook'     => mb_substr(trim((string) ($o['hook'] ?? '')), 0, 150),
                'caption'  => mb_substr(trim((string) $o['caption']), 0, 2000),
                'hashtags' => mb_substr(trim((string) ($o['hashtags'] ?? '')), 0, 300),
            ];
        }
        if ($options === []) {
            return $this->err(502, 'The AI returned an unexpected answer. Please try again.');
        }

        return ['ok' => true, 'status' => 200, 'message' => 'OK', 'data' => ['options' => array_slice($options, 0, 3), 'provider' => $this->client->label()]];
    }

    /**
     * One caption per platform for one library file. The AI looks at the file's preview picture (a photo, or a
     * frame of a video) so each file gets its own text, written to each platform's rules.
     *
     * @param array<string, mixed> $asset social_media_assets row
     * @param list<string>         $platforms
     * @return array<string, mixed> data.captions[platform] = [caption, hashtags, text]
     */
    public function forFile(array $asset, array $platforms, string $tone, string $language, string $context): array
    {
        $platforms = array_values(array_intersect(array_unique($platforms), array_keys(self::PLATFORM_HINTS)));
        if ($platforms === []) {
            return $this->err(422, 'Choose at least one account that needs a caption (Facebook, Instagram, YouTube or LinkedIn).');
        }
        $tone     = in_array($tone, self::TONES, true) ? $tone : 'friendly';
        $language = in_array($language, self::LANGUAGES, true) ? $language : 'English';
        $context  = trim(mb_substr($context, 0, 600));
        $isVideo  = str_starts_with((string) ($asset['mime'] ?? ''), 'video/');

        // The picture: Google's preview of the file. Without it the AI only has the file name.
        $image = null;
        $thumb = (new GoogleDriveService())->thumbnail((string) $asset['drive_file_id'], 640);
        if ($thumb !== null && in_array($thumb['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            $image = ['mime' => $thumb['mime'], 'data' => base64_encode($thumb['body'])];
        }

        $rules = '';
        foreach ($platforms as $pf) {
            $rules .= '- ' . $pf . ': ' . self::PLATFORM_HINTS[$pf] . "\n";
        }
        $system = 'You write social media posts for ' . $this->businessName() . ". Language: {$language}. Tone: {$tone}.\n"
            . "First look at the picture and work out what it really shows (read any visible text). Then write one caption for each platform below, following its rules:\n" . $rules
            . "Each caption starts with a scroll-stopping first line and ends with a clear next step when it fits (visit, call, message us). Base the text on what is really visible in the picture or stated by the user; never invent facts, offers, prices or contact details.\n"
            . "Write natural, correct {$language}. Do not mix in another language except brand names, product names and a few English hashtags. Hashtags: relevant to the picture, no spaces inside a tag, mix a few English tags with {$language} ones.\n"
            . "Also return: title (a short post title, under 90 characters, for YouTube and the calendar), about (one plain English sentence: what the picture shows), warnings (0 to 3 short English notes if the picture may be a problem: blurry, tiny text, screenshot with a lot of small text, shows phone numbers or personal data, wrong shape for the platform; otherwise an empty list).\n"
            . self::HONESTY . "\n"
            . 'Return ONLY valid JSON: {"title":"...","about":"...","warnings":["..."],"captions":{"<platform>":{"caption":"...","hashtags":"#a #b"}}} with exactly these platform keys: ' . implode(', ', $platforms) . '. No markdown.';

        $name   = trim((string) preg_replace('/[_\-]+/', ' ', pathinfo((string) ($asset['filename'] ?? ''), PATHINFO_FILENAME)));
        $prompt = 'File name: ' . $name . ($image !== null ? ($isVideo ? '. The picture is one frame of a video.' : '. The picture is the photo to post.') : '. No picture is available.')
            . ($context !== '' ? "\nWhat the user says about these posts: " . $context : '');

        $res = $this->client->generate($system, $prompt, $image, 4000);
        if (! $res['ok']) {
            return $res;
        }

        $decoded  = $this->decode((string) $res['text']);
        $captions = [];
        foreach ($platforms as $pf) {
            $c = $decoded['captions'][$pf] ?? null;
            if (! is_array($c) || trim((string) ($c['caption'] ?? '')) === '') {
                continue;
            }
            $caption  = mb_substr(trim((string) $c['caption']), 0, 2000);
            $hashtags = mb_substr(trim((string) ($c['hashtags'] ?? '')), 0, 300);
            $captions[$pf] = [
                'caption'  => $caption,
                'hashtags' => $hashtags,
                'text'     => $hashtags !== '' && ! str_contains($caption, $hashtags) ? $caption . "\n\n" . $hashtags : $caption,
            ];
        }
        if ($captions === []) {
            return $this->err(502, 'The AI returned an unexpected answer. Please try again.');
        }

        $warnings = [];
        foreach ((array) ($decoded['warnings'] ?? []) as $w) {
            if (is_string($w) && trim($w) !== '') {
                $warnings[] = mb_substr(trim($w), 0, 160);
            }
        }

        return ['ok' => true, 'status' => 200, 'message' => 'OK', 'data' => [
            'captions'     => $captions,
            'title'        => mb_substr(trim((string) ($decoded['title'] ?? '')), 0, 90),
            'about'        => mb_substr(trim((string) ($decoded['about'] ?? '')), 0, 240),
            'warnings'     => array_slice($warnings, 0, 3),
            'provider'     => $this->client->label(),
            'with_picture' => $image !== null,
        ]];
    }

    /**
     * A shoot-ready script for a Reel / Short / video: hook, scene by scene directions, call to action,
     * plus the caption and hashtags to post with it. Suggestion only; nothing is posted.
     *
     * @return array<string, mixed> data.script = [title, hook, scenes[], cta, caption, hashtags]
     */
    public function reelScript(string $topic, string $platform, string $tone, string $language, int $seconds): array
    {
        $topic = trim(mb_substr($topic, 0, 600));
        if ($topic === '') {
            return $this->err(422, 'Describe what the video is about.');
        }

        $platform = isset(self::PLATFORM_HINTS[$platform]) ? $platform : 'instagram';
        $tone     = in_array($tone, self::TONES, true) ? $tone : 'friendly';
        $language = in_array($language, self::LANGUAGES, true) ? $language : 'English';
        $seconds  = in_array($seconds, [15, 30, 45, 60], true) ? $seconds : 30;

        $system = 'You are a short-video scriptwriter for ' . $this->businessName() . ". Voice-over and on-screen text language: {$language}. Tone: {$tone}.\n"
            . 'Platform rules: ' . self::PLATFORM_HINTS[$platform] . "\n"
            . "Write one script that runs about {$seconds} seconds, in 3 to 7 scenes whose seconds add up to about {$seconds}. The first scene is the hook and must grab attention in the first 3 seconds.\n"
            . "Each scene has: seconds (a whole number), visual (what the camera shows, one plain English sentence a small team can shoot with a phone), voiceover (what is said, may be empty), on_screen_text (a few big words, may be empty).\n"
            . "Also return: title (under 90 characters), hook (the opening line), cta (one clear next step), caption (post text starting with the hook), hashtags (a single string).\n"
            . self::HONESTY . "\n"
            . 'Return ONLY valid JSON: {"title":"...","hook":"...","scenes":[{"seconds":3,"visual":"...","voiceover":"...","on_screen_text":"..."}],"cta":"...","caption":"...","hashtags":"#a #b"}. No markdown.';

        $res = $this->client->generate($system, 'The video is about: ' . $topic, null, 3000);
        if (! $res['ok']) {
            return $res;
        }

        $d      = $this->decode((string) $res['text']);
        $scenes = [];
        foreach ((array) ($d['scenes'] ?? []) as $s) {
            if (! is_array($s) || trim((string) ($s['visual'] ?? '')) === '') {
                continue;
            }
            $scenes[] = [
                'seconds'        => max(1, min(60, (int) ($s['seconds'] ?? 3))),
                'visual'         => mb_substr(trim((string) $s['visual']), 0, 240),
                'voiceover'      => mb_substr(trim((string) ($s['voiceover'] ?? '')), 0, 300),
                'on_screen_text' => mb_substr(trim((string) ($s['on_screen_text'] ?? '')), 0, 120),
            ];
        }
        if ($scenes === [] || trim((string) ($d['caption'] ?? '')) === '') {
            return $this->err(502, 'The AI returned an unexpected answer. Please try again.');
        }

        return ['ok' => true, 'status' => 200, 'message' => 'Script ready.', 'data' => ['script' => [
            'title'    => mb_substr(trim((string) ($d['title'] ?? '')), 0, 90),
            'hook'     => mb_substr(trim((string) ($d['hook'] ?? '')), 0, 150),
            'scenes'   => array_slice($scenes, 0, 8),
            'cta'      => mb_substr(trim((string) ($d['cta'] ?? '')), 0, 200),
            'caption'  => mb_substr(trim((string) $d['caption']), 0, 2000),
            'hashtags' => mb_substr(trim((string) ($d['hashtags'] ?? '')), 0, 300),
        ], 'provider' => $this->client->label()]];
    }

    /**
     * Rework a caption the user already wrote: shorter, longer, fix mistakes, translate, change tone or sharpen the hook.
     *
     * @return array<string, mixed> data.text
     */
    public function rewrite(string $text, string $action, string $platform, string $tone, string $language): array
    {
        $text = trim(mb_substr($text, 0, 2000));
        if ($text === '') {
            return $this->err(422, 'Write or generate a caption first.');
        }

        $instructions = [
            'shorter'   => 'Make it clearly shorter, keep the main message and the call to action.',
            'longer'    => 'Expand it a little with useful detail already implied by the text. Do not invent new facts.',
            'fix'       => 'Fix spelling, grammar and punctuation only. Keep the wording and meaning.',
            'translate' => "Translate it into natural, correct {$language}. Keep brand names, product names and hashtags as they are.",
            'tone'      => "Rewrite it in a {$tone} tone, in the same language as the original.",
            'hook'      => 'Rewrite only the first line into a stronger scroll-stopping hook. Keep the rest as it is.',
        ];
        if (! isset($instructions[$action])) {
            return $this->err(422, 'Choose what to change in the caption.');
        }
        $platform = isset(self::PLATFORM_HINTS[$platform]) ? $platform : 'instagram';
        $language = in_array($language, self::LANGUAGES, true) ? $language : 'English';
        $tone     = in_array($tone, self::TONES, true) ? $tone : 'friendly';

        $system = 'You edit social media captions for ' . $this->businessName() . ".\n"
            . 'Platform rules: ' . self::PLATFORM_HINTS[$platform] . "\n"
            . 'Task: ' . $instructions[$action] . "\n"
            . "Keep every fact exactly as written; never add prices, offers or contact details that are not in the text.\n"
            . 'Return ONLY valid JSON: {"text":"the full rewritten caption"}. No markdown.';

        $res = $this->client->generate($system, $text, null, 2000);
        if (! $res['ok']) {
            return $res;
        }

        $out = trim((string) ($this->decode((string) $res['text'])['text'] ?? ''));
        if ($out === '') {
            return $this->err(502, 'The AI returned an unexpected answer. Please try again.');
        }

        return ['ok' => true, 'status' => 200, 'message' => 'Caption updated.', 'data' => ['text' => mb_substr($out, 0, 2000), 'provider' => $this->client->label()]];
    }

    /**
     * Small live check used by the Test button on Social Setup.
     *
     * @return array<string, mixed>
     */
    public function test(): array
    {
        $res = $this->client->generate('Reply with JSON only.', 'Return {"status":"ok"}.', null, 60);
        if (! $res['ok']) {
            return $res;
        }

        return ['ok' => true, 'status' => 200, 'message' => $this->client->label() . ' is working.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $text): array
    {
        $clean   = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)));
        $decoded = json_decode($clean, true);
        if (! is_array($decoded) && preg_match('/\{.*\}/s', $clean, $m) === 1) {
            $decoded = json_decode($m[0], true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function err(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }
}
