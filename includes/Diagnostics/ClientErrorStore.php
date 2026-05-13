<?php
/**
 * Buffer circolare per errori JavaScript e console lato browser.
 *
 * @package FP\RemoteBridge\Diagnostics
 */

declare(strict_types=1);

namespace FP\RemoteBridge\Diagnostics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistenza in opzione WordPress degli eventi client-side raccolti dal Bridge.
 */
final class ClientErrorStore
{
    public const OPTION_KEY = 'fp_remote_bridge_client_errors';
    public const MAX_ENTRIES = 200;
    public const MAX_MESSAGE_LENGTH = 2000;
    public const MAX_STACK_LENGTH = 4000;
    public const MAX_URL_LENGTH = 500;

    /**
     * Aggiunge un evento al buffer.
     *
     * @param array<string, mixed> $entry Evento normalizzato.
     * @return bool True se salvato.
     */
    public static function append(array $entry): bool
    {
        $entries = self::get_entries();
        array_unshift($entries, $entry);
        $entries = array_slice($entries, 0, self::MAX_ENTRIES);

        return update_option(self::OPTION_KEY, $entries, false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_entries(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            return [];
        }

        $entries = [];
        foreach ($stored as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param int $limit Numero massimo di eventi da restituire.
     * @return array<int, array<string, mixed>>
     */
    public static function get_recent(int $limit = 50): array
    {
        if ($limit < 1) {
            $limit = 1;
        }

        return array_slice(self::get_entries(), 0, min($limit, self::MAX_ENTRIES));
    }

    /**
     * @return array<string, int>
     */
    public static function get_summary(): array
    {
        $entries = self::get_entries();
        $summary = [
            'total' => count($entries),
            'javascript' => 0,
            'promise' => 0,
            'console' => 0,
            'frontend' => 0,
            'admin' => 0,
        ];

        foreach ($entries as $entry) {
            $type = isset($entry['type']) ? (string) $entry['type'] : '';
            $context = isset($entry['context']) ? (string) $entry['context'] : '';

            if ($type === 'javascript') {
                ++$summary['javascript'];
            } elseif ($type === 'promise') {
                ++$summary['promise'];
            } elseif ($type === 'console') {
                ++$summary['console'];
            }

            if ($context === 'admin') {
                ++$summary['admin'];
            } else {
                ++$summary['frontend'];
            }
        }

        return $summary;
    }

    /**
     * Normalizza un payload inviato dal browser.
     *
     * @param array<string, mixed> $payload Dati grezzi dal client.
     * @return array<string, mixed>|null Evento pronto per il buffer o null se non valido.
     */
    public static function normalize_payload(array $payload): ?array
    {
        $type = isset($payload['type']) ? sanitize_key((string) $payload['type']) : '';
        $allowedTypes = ['javascript', 'promise', 'console'];
        if (!in_array($type, $allowedTypes, true)) {
            return null;
        }

        $message = isset($payload['message']) ? sanitize_text_field((string) $payload['message']) : '';
        if ($message === '') {
            return null;
        }

        $context = isset($payload['context']) ? sanitize_key((string) $payload['context']) : 'frontend';
        if (!in_array($context, ['frontend', 'admin'], true)) {
            $context = 'frontend';
        }

        $pageUrl = isset($payload['page_url']) ? esc_url_raw((string) $payload['page_url']) : '';
        if ($pageUrl !== '' && strlen($pageUrl) > self::MAX_URL_LENGTH) {
            $pageUrl = substr($pageUrl, 0, self::MAX_URL_LENGTH);
        }

        $source = isset($payload['source']) ? sanitize_text_field((string) $payload['source']) : '';
        $stack = isset($payload['stack']) ? sanitize_textarea_field((string) $payload['stack']) : '';
        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = substr($message, 0, self::MAX_MESSAGE_LENGTH);
        }
        if (strlen($stack) > self::MAX_STACK_LENGTH) {
            $stack = substr($stack, 0, self::MAX_STACK_LENGTH);
        }

        return [
            'id' => wp_generate_uuid4(),
            'type' => $type,
            'context' => $context,
            'message' => $message,
            'source' => $source,
            'line' => isset($payload['line']) ? max(0, (int) $payload['line']) : 0,
            'column' => isset($payload['column']) ? max(0, (int) $payload['column']) : 0,
            'stack' => $stack,
            'page_url' => $pageUrl,
            'user_agent' => isset($payload['user_agent']) ? sanitize_text_field((string) $payload['user_agent']) : '',
            'captured_at' => current_time('mysql'),
            'captured_at_gmt' => gmdate('Y-m-d H:i:s'),
        ];
    }
}
