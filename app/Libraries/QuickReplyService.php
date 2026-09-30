<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\QuickReplyModel;
use InvalidArgumentException;

/**
 * Inbox quick replies (canned responses). Message may use {{name}}, {{mobile}}, {{email}}
 * or any contact attribute key — filled for the open chat when inserted.
 */
class QuickReplyService
{
    protected ?bool $ready = null;

    public function tableReady(): bool
    {
        if ($this->ready === null) {
            try {
                $this->ready = db_connect()->tableExists('quick_replies');
            } catch (\Throwable) {
                $this->ready = false;
            }
        }

        return $this->ready;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->tableReady() ? model(QuickReplyModel::class)->orderBy('shortcut', 'ASC')->findAll() : [];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function save(array $input, ?int $id, int $userId = 0): array
    {
        if (! $this->tableReady()) {
            throw new InvalidArgumentException('Quick replies table is missing. Run "php spark migrate" first.');
        }
        $model = model(QuickReplyModel::class);
        if ($id !== null && $model->find($id) === null) {
            throw new InvalidArgumentException('Quick reply not found.');
        }

        $shortcut = strtolower(ltrim(trim((string) ($input['shortcut'] ?? '')), '/'));
        $title    = trim((string) ($input['title'] ?? ''));
        $message  = trim((string) ($input['message'] ?? ''));

        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', $shortcut)) {
            throw new InvalidArgumentException('Shortcut must use letters, numbers, - or _ (no spaces), e.g. price.');
        }
        $dupe = $model->where('shortcut', $shortcut);
        if ($id !== null) {
            $dupe->where('id !=', $id);
        }
        if ($dupe->first() !== null) {
            throw new InvalidArgumentException("Shortcut /{$shortcut} is already used.");
        }
        if ($message === '') {
            throw new InvalidArgumentException('Enter the reply message.');
        }
        if (mb_strlen($message) > 4096) {
            throw new InvalidArgumentException('Message is too long (WhatsApp limit is 4096 characters).');
        }
        if ($title === '') {
            $title = ucfirst(str_replace(['_', '-'], ' ', $shortcut));
        }

        $data = ['shortcut' => $shortcut, 'title' => mb_substr($title, 0, 100), 'message' => $message];
        if ($id !== null) {
            $model->update($id, $data);
        } else {
            $data['created_by'] = $userId > 0 ? $userId : null;
            $id = (int) $model->insert($data);
        }

        return ['id' => $id] + $data;
    }

    public function delete(int $id): bool
    {
        return $this->tableReady() && model(QuickReplyModel::class)->find($id) !== null && model(QuickReplyModel::class)->delete($id);
    }

    /**
     * Fill {{placeholders}} from a contact; unknown placeholders become empty.
     *
     * @param array<string, mixed> $contact
     */
    public function render(string $message, array $contact): string
    {
        $values = ContactAttributes::flatten($contact);

        return (string) preg_replace_callback('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', static function (array $m) use ($values): string {
            $key = str_starts_with($m[1], 'contact.') ? substr($m[1], 8) : $m[1];
            $value = $values[$key] ?? '';

            return is_scalar($value) ? (string) $value : '';
        }, $message);
    }
}
