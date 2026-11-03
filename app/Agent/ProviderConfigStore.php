<?php

declare(strict_types=1);

namespace ProofPatch\Agent;

/**
 * Manages encrypted local storage for Muse inference provider credentials (Baseten & Meta Model API)
 * and active provider/model selection.
 *
 * Security invariants:
 * - Credentials are encrypted at rest with AES-256-GCM and stored with 0600 owner-only permissions.
 * - Symlinks or group/world-accessible credential files fail closed.
 * - Never returns raw or partial secret values in status() or UI props.
 * - Rejects Meta Contributor Mode models to protect code privacy.
 * - Rejects Modal as an LLM inference provider (Modal is used strictly for gVisor microVM sandboxing).
 */
final class ProviderConfigStore
{
    public const PROVIDERS = [
        'baseten' => [
            'id' => 'baseten',
            'label' => 'Baseten Inference API',
            'endpoint' => 'https://inference.baseten.co/v1/chat/completions',
            'models' => [
                'meta-models/muse-spark-open-1026',
            ],
            'defaultModel' => 'meta-models/muse-spark-open-1026',
        ],
        'meta' => [
            'id' => 'meta',
            'label' => 'Meta Model API (Standard Mode)',
            'endpoint' => 'https://api.meta.ai/v1/chat/completions',
            'models' => [
                'muse-spark-1.3',
                'muse-spark-1.2',
                'muse-spark-1.1',
            ],
            'defaultModel' => 'muse-spark-1.3',
        ],
    ];

    public static function getStoragePath(): ?string
    {
        $override = getenv('PROOFPATCH_PROVIDER_CONFIG_FILE');
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        $home = getenv('HOME');
        if (!is_string($home) || trim($home) === '') {
            return null;
        }

        return rtrim($home, '/') . '/.config/proofpatch/provider_config.enc';
    }

    public static function validateProvider(string $provider): string
    {
        $normalized = strtolower(trim($provider));
        if ($normalized === 'modal') {
            throw new \InvalidArgumentException(
                'Modal is used strictly for isolated gVisor microVM sandbox execution, not hosted Muse model inference.'
            );
        }
        if (!isset(self::PROVIDERS[$normalized])) {
            throw new \InvalidArgumentException('Unsupported model inference provider.');
        }

        return $normalized;
    }

    public static function validateModel(string $provider, string $model): string
    {
        $provider = self::validateProvider($provider);
        $trimmed = trim($model);

        if (stripos($trimmed, 'contributor') !== false) {
            throw new \InvalidArgumentException(
                'Meta Contributor Mode models are blocked to protect code privacy. Choose a Standard Mode model.'
            );
        }

        if (!in_array($trimmed, self::PROVIDERS[$provider]['models'], true)) {
            throw new \InvalidArgumentException("Unsupported model '{$trimmed}' for provider '{$provider}'.");
        }

        return $trimmed;
    }

    public static function validateKeyFormat(string $rawKey): string
    {
        $key = trim($rawKey);
        if (strlen($key) < 16 || strlen($key) > 4096 || !preg_match('/\A[\x21-\x7e]+\z/', $key)) {
            throw new \InvalidArgumentException(
                'API key must be between 16 and 4096 printable non-whitespace characters.'
            );
        }

        return $key;
    }

    public static function saveKey(string $provider, string $rawKey): void
    {
        $provider = self::validateProvider($provider);
        $key = self::validateKeyFormat($rawKey);

        $data = self::readRawStore();
        $data['keys'][$provider] = [
            'ciphertext' => self::encrypt($key),
            'updatedAt' => gmdate(DATE_ATOM),
        ];

        if (!isset($data['activeProvider']) || !isset(self::PROVIDERS[$data['activeProvider']])) {
            $data['activeProvider'] = $provider;
            $data['activeModel'] = self::PROVIDERS[$provider]['defaultModel'];
        }

        self::writeRawStore($data);
    }

    public static function removeKey(string $provider): void
    {
        $provider = self::validateProvider($provider);
        $data = self::readRawStore();
        unset($data['keys'][$provider]);
        self::writeRawStore($data);
    }

    public static function selectActive(string $provider, ?string $model = null): void
    {
        $provider = self::validateProvider($provider);
        $resolvedModel = ($model !== null && trim($model) !== '')
            ? self::validateModel($provider, $model)
            : self::PROVIDERS[$provider]['defaultModel'];

        $data = self::readRawStore();
        $data['activeProvider'] = $provider;
        $data['activeModel'] = $resolvedModel;
        $data['updatedAt'] = gmdate(DATE_ATOM);

        self::writeRawStore($data);
    }

    /**
     * @return array{provider: string, label: string, endpoint: string, model: string}
     */
    public static function activeTarget(): array
    {
        $data = self::readRawStore();
        $provider = is_string($data['activeProvider'] ?? null) && isset(self::PROVIDERS[$data['activeProvider']])
            ? $data['activeProvider']
            : 'baseten';

        $spec = self::PROVIDERS[$provider];
        $model = is_string($data['activeModel'] ?? null) && in_array($data['activeModel'], $spec['models'], true)
            ? $data['activeModel']
            : $spec['defaultModel'];

        return [
            'provider' => $provider,
            'label' => $spec['label'],
            'endpoint' => $spec['endpoint'],
            'model' => $model,
        ];
    }

    /**
     * Resolves the decrypted API key for a given provider without logging or exposing it.
     *
     * @return array{key: string, source: 'settings_encrypted'|'env'|'config_file'}|null
     */
    public static function resolveProviderKey(string $provider): ?array
    {
        $provider = self::validateProvider($provider);
        $data = self::readRawStore();

        $encryptedEntry = $data['keys'][$provider]['ciphertext'] ?? null;
        if (is_string($encryptedEntry) && $encryptedEntry !== '') {
            $decrypted = self::decrypt($encryptedEntry);
            if ($decrypted !== null && strlen($decrypted) >= 16 && preg_match('/\A[\x21-\x7e]+\z/', $decrypted)) {
                return ['key' => $decrypted, 'source' => 'settings_encrypted'];
            }
        }

        if ($provider === 'baseten') {
            $envKey = getenv('BASETEN_API_KEY');
            if (is_string($envKey) && trim($envKey) !== '') {
                return ['key' => trim($envKey), 'source' => 'env'];
            }

            $home = getenv('HOME');
            $file = is_string($home) && $home !== '' ? rtrim($home, '/') . '/.config/proofpatch/baseten.key' : null;
            if ($file !== null && !is_link($file) && is_file($file) && (fileperms($file) & 0077) === 0) {
                $raw = @file_get_contents($file);
                if (is_string($raw) && trim($raw) !== '') {
                    return ['key' => trim($raw), 'source' => 'config_file'];
                }
            }
        }

        if ($provider === 'meta') {
            $envKey = getenv('META_MODEL_API_KEY') ?: getenv('MODEL_API_KEY');
            if (is_string($envKey) && trim($envKey) !== '') {
                return ['key' => trim($envKey), 'source' => 'env'];
            }

            $home = getenv('HOME');
            $file = is_string($home) && $home !== '' ? rtrim($home, '/') . '/.config/proofpatch/meta.key' : null;
            if ($file !== null && !is_link($file) && is_file($file) && (fileperms($file) & 0077) === 0) {
                $raw = @file_get_contents($file);
                if (is_string($raw) && trim($raw) !== '') {
                    return ['key' => trim($raw), 'source' => 'config_file'];
                }
            }
        }

        return null;
    }

    /**
     * Returns safe, secret-free metadata for the Settings UI.
     */
    public static function status(): array
    {
        $active = self::activeTarget();
        $data = self::readRawStore();
        $providersStatus = [];

        foreach (self::PROVIDERS as $id => $spec) {
            $resolved = self::resolveProviderKey($id);
            $hasStoredKey = isset($data['keys'][$id]['ciphertext']) && is_string($data['keys'][$id]['ciphertext']);
            $providersStatus[$id] = [
                'id' => $id,
                'label' => $spec['label'],
                'endpoint' => $spec['endpoint'],
                'models' => $spec['models'],
                'defaultModel' => $spec['defaultModel'],
                'configured' => $resolved !== null && strlen($resolved['key']) >= 16,
                'source' => $resolved['source'] ?? 'none',
                'canRemove' => $hasStoredKey,
                'updatedAt' => is_string($data['keys'][$id]['updatedAt'] ?? null) ? $data['keys'][$id]['updatedAt'] : null,
            ];
        }

        return [
            'activeProvider' => $active['provider'],
            'activeLabel' => $active['label'],
            'activeModel' => $active['model'],
            'activeEndpoint' => $active['endpoint'],
            'activeKeyAvailable' => $providersStatus[$active['provider']]['configured'] ?? false,
            'providers' => $providersStatus,
            'modalNote' => 'Modal runs the supported pinned test case. General generated-code verification is unavailable until the test oracle is isolated.',
        ];
    }

    /**
     * Internal helper for outbound secret-leak auditing. Never expose to responses.
     *
     * @return list<string>
     */
    public static function allConfiguredSecrets(): array
    {
        $secrets = [];
        foreach (array_keys(self::PROVIDERS) as $id) {
            try {
                $resolved = self::resolveProviderKey($id);
                if ($resolved !== null && strlen($resolved['key']) >= 10) {
                    $secrets[] = $resolved['key'];
                }
            } catch (\Throwable) {
            }
        }

        return array_values(array_unique($secrets));
    }

    private static function readRawStore(): array
    {
        $path = self::getStoragePath();
        if ($path === null || is_link($path) || !is_file($path)) {
            return ['activeProvider' => 'baseten', 'activeModel' => self::PROVIDERS['baseten']['defaultModel'], 'keys' => []];
        }

        clearstatcache(true, $path);
        $perms = @fileperms($path);
        if ($perms === false || ($perms & 0077) !== 0) {
            return ['activeProvider' => 'baseten', 'activeModel' => self::PROVIDERS['baseten']['defaultModel'], 'keys' => []];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return ['activeProvider' => 'baseten', 'activeModel' => self::PROVIDERS['baseten']['defaultModel'], 'keys' => []];
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return ['activeProvider' => 'baseten', 'activeModel' => self::PROVIDERS['baseten']['defaultModel'], 'keys' => []];
            }
            $decoded['keys'] = is_array($decoded['keys'] ?? null) ? $decoded['keys'] : [];
            return $decoded;
        } catch (\Throwable) {
            return ['activeProvider' => 'baseten', 'activeModel' => self::PROVIDERS['baseten']['defaultModel'], 'keys' => []];
        }
    }

    private static function writeRawStore(array $data): void
    {
        $path = self::getStoragePath();
        if ($path === null) {
            throw new \RuntimeException('Provider configuration storage path is unavailable.');
        }

        $dir = dirname($path);
        if (is_link($dir)) {
            throw new \RuntimeException('Refusing to write provider configuration into a symlinked directory.');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create provider configuration directory.');
        }
        @chmod($dir, 0700);

        if (file_exists($path) && is_link($path)) {
            throw new \RuntimeException('Refusing to overwrite symlinked provider configuration file.');
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmpPath, $encoded, LOCK_EX) === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('Failed to write encrypted provider configuration.');
        }
        @chmod($tmpPath, 0600);
        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException('Failed to commit encrypted provider configuration.');
        }
        @chmod($path, 0600);
    }

    private static function encryptionKey(): string
    {
        $rawAppKey = getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? '');
        if (!is_string($rawAppKey) || $rawAppKey === '') {
            if (function_exists('config')) {
                try {
                    $cfgKey = config('app.key');
                    if (is_string($cfgKey) && $cfgKey !== '') {
                        $rawAppKey = $cfgKey;
                    }
                } catch (\Throwable) {
                }
            }
        }
        if ($rawAppKey === '') {
            $envFile = dirname(__DIR__, 2) . '/.env';
            if (is_file($envFile) && !is_link($envFile)) {
                $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    if (str_starts_with(trim($line), 'APP_KEY=')) {
                        $rawAppKey = trim(substr(trim($line), strlen('APP_KEY=')));
                        break;
                    }
                }
            }
        }

        return hash_hmac('sha256', 'proofpatch-provider-config-aes256gcm-v1', $rawAppKey ?: 'proofpatch-local-key', true);
    }

    private static function encrypt(string $plaintext): string
    {
        $key = self::encryptionKey();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'proofpatch-v1', 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \RuntimeException('Failed to encrypt provider credential.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    private static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if (!is_string($raw) || strlen($raw) <= 28) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag, 'proofpatch-v1');

        return is_string($plaintext) ? $plaintext : null;
    }
}
