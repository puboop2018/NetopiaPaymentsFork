<?php

declare(strict_types=1);

namespace Netopia\CsCart\Key;

use Netopia\CsCart\Exception\KeyStorageException;
use Netopia\Payment2\Enum\PaymentMode;

/**
 * Handles reading, writing, and deleting NETOPIA key files on disk.
 *
 * Key files are stored per payment_id in a protected directory with
 * .htaccess Deny from all + blank index.html.
 */
final class KeyStorage
{
    /** Maximum allowed key file size (64 KB). */
    public const int MAX_KEY_FILE_SIZE = 65536;

    /** File permissions for uploaded key files. */
    private const int FILE_PERMISSIONS = 0640;

    /** Directory permissions for the keys directory. */
    private const int DIR_PERMISSIONS = 0750;

    /** @var list<string> */
    private const array ALLOWED_EXTENSIONS = ['pem', 'key', 'cer', 'crt', 'pub', 'txt'];

    public function __construct(
        private readonly string $baseDir,
    ) {
    }

    public function dirFor(int $paymentId): string
    {
        return rtrim($this->baseDir, '/') . '/' . $paymentId . '/';
    }

    /**
     * Load a key (public or private) using a priority cascade:
     *   1. Environment-specific uploaded file
     *   2. Environment-specific textarea content
     *   3. Legacy non-prefixed uploaded file
     *   4. Legacy non-prefixed textarea
     *
     * @param array<string, mixed> $processorParams
     * @param 'public_key'|'private_key' $keyType
     */
    public function load(array $processorParams, string $keyType, int $paymentId, PaymentMode $mode): string
    {
        $envKey  = $mode->value . '_' . $keyType;
        $envFile = $envKey . '_file';

        $dir = $this->dirFor($paymentId);

        $content = $this->readFile($dir, (string) ($processorParams[$envFile] ?? ''));
        if ($content !== '') {
            return $content;
        }

        if (!empty($processorParams[$envKey])) {
            return trim((string) $processorParams[$envKey]);
        }

        $legacyFile = $keyType . '_file';
        $content    = $this->readFile($dir, (string) ($processorParams[$legacyFile] ?? ''));
        if ($content !== '') {
            return $content;
        }

        return trim((string) ($processorParams[$keyType] ?? ''));
    }

    /**
     * Read a key file with path traversal protection (basename only).
     */
    public function readFile(string $dir, string $filename): string
    {
        if ($filename === '') {
            return '';
        }

        $filePath = $dir . basename($filename);
        if (!is_file($filePath) || !is_readable($filePath)) {
            return '';
        }

        $content = file_get_contents($filePath);
        return $content === false ? '' : trim($content);
    }

    /**
     * Validate and store an uploaded key file.
     *
     * @param array{name: string, tmp_name: string, size: int, error: int} $upload
     * @return string Stored filename
     * @throws KeyStorageException
     */
    public function storeUpload(array $upload, string $dir): string
    {
        $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new KeyStorageException('Invalid key file extension: ' . $ext);
        }

        if ($upload['size'] > self::MAX_KEY_FILE_SIZE) {
            throw new KeyStorageException('Key file too large');
        }

        $content = file_get_contents($upload['tmp_name']);
        if ($content === false || trim($content) === '') {
            throw new KeyStorageException('Key file is empty or unreadable');
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($upload['name'])) ?? '';
        if ($safeName === '' || !preg_match('/^[a-zA-Z0-9._\-]+\.(pem|key|cer|crt|pub|txt)$/', $safeName)) {
            throw new KeyStorageException('Invalid key filename');
        }

        $this->ensureDir($dir);
        $destPath = $dir . $safeName;

        if (!move_uploaded_file($upload['tmp_name'], $destPath)) {
            throw new KeyStorageException('Failed to move uploaded key file');
        }

        if (!chmod($destPath, self::FILE_PERMISSIONS)) {
            throw new KeyStorageException('Failed to set permissions on stored key file');
        }

        return $safeName;
    }

    /**
     * Delete a stored key file (basename only). Returns true if deleted.
     */
    public function delete(string $dir, string $filename): bool
    {
        if ($filename === '') {
            return true;
        }

        $filePath = $dir . basename($filename);
        if (!file_exists($filePath)) {
            return true;
        }

        return @unlink($filePath);
    }

    /**
     * Create directory if missing and secure it with .htaccess and blank index.html.
     */
    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, self::DIR_PERMISSIONS, true) && !is_dir($dir)) {
            throw new KeyStorageException('Failed to create keys directory: ' . $dir);
        }

        $htaccess = $dir . '.htaccess';
        if (!file_exists($htaccess) && file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n") === false) {
            throw new KeyStorageException('Failed to write .htaccess in keys directory: ' . $dir);
        }

        $index = $dir . 'index.html';
        if (!file_exists($index) && file_put_contents($index, '') === false) {
            throw new KeyStorageException('Failed to write index.html in keys directory: ' . $dir);
        }
    }

    public function secureDir(string $dir): void
    {
        $this->ensureDir($dir);
    }
}
