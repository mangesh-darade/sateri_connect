<?php

namespace App\Libraries;

use CodeIgniter\Encryption\EncrypterInterface;
use Config\Services;
use RuntimeException;
use Throwable;

/**
 * Encrypts and decrypts sensitive values (Cheerio API keys, webhook secrets)
 * using CodeIgniter's encryption service for database storage.
 */
class EncryptionService
{
    protected EncrypterInterface $encrypter;

    /**
     * Prefix used to detect already-encrypted payloads.
     */
    protected string $prefix = 'enc:';

    public function __construct(?EncrypterInterface $encrypter = null)
    {
        $this->encrypter = $encrypter ?? Services::encrypter();
    }

    /**
     * Encrypt a plaintext string. Returns prefixed ciphertext for storage.
     *
     * @throws RuntimeException
     */
    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        try {
            $cipher = $this->encrypter->encrypt($plaintext);

            return $this->prefix . base64_encode($cipher);
        } catch (Throwable $e) {
            log_message('error', 'EncryptionService::encrypt failed: {msg}', ['msg' => $e->getMessage()]);

            throw new RuntimeException('Failed to encrypt value: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Decrypt a previously encrypted value. Accepts prefixed or raw ciphertext.
     *
     * @throws RuntimeException
     */
    public function decrypt(string $ciphertext): string
    {
        if ($ciphertext === '') {
            return '';
        }

        try {
            $raw = $ciphertext;

            if (str_starts_with($ciphertext, $this->prefix)) {
                $raw = base64_decode(substr($ciphertext, strlen($this->prefix)), true);
                if ($raw === false) {
                    throw new RuntimeException('Invalid encrypted payload encoding.');
                }
            }

            return $this->encrypter->decrypt($raw);
        } catch (Throwable $e) {
            log_message('error', 'EncryptionService::decrypt failed: {msg}', ['msg' => $e->getMessage()]);

            throw new RuntimeException('Failed to decrypt value: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Encrypt only if the value is not already encrypted.
     * Useful when saving settings that may already be stored encrypted.
     */
    public function encryptIfNeeded(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($this->isEncrypted($value)) {
            return $value;
        }

        return $this->encrypt($value);
    }

    /**
     * Decrypt if encrypted; otherwise return the value as-is.
     */
    public function decryptIfNeeded(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (! $this->isEncrypted($value)) {
            return $value;
        }

        return $this->decrypt($value);
    }

    /**
     * Whether the value appears to be encrypted by this service.
     */
    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, $this->prefix);
    }
}
