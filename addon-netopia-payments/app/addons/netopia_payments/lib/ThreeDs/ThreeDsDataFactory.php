<?php

declare(strict_types=1);

namespace Netopia\CsCart\ThreeDs;

use Netopia\CsCart\Dto\ThreeDsData;
use Netopia\CsCart\Support\Sanitizer;

/**
 * Builds ThreeDsData from HTTP server variables and POST fingerprint fields.
 */
final class ThreeDsDataFactory
{
    private const string DEFAULT_SCREEN_PRINT = 'Current Resolution: 1920x1080';
    private const string DEFAULT_COLOR_DEPTH  = '24';
    private const string DEFAULT_SCREEN_H     = '1080';
    private const string DEFAULT_SCREEN_W     = '1920';
    private const string DEFAULT_TZ           = 'Europe/Bucharest';
    private const string DEFAULT_LANG         = 'en-US';
    private const string DEFAULT_IP           = '127.0.0.1';

    /**
     * Collect 3DS data from real client request.
     *
     * @param array<string, mixed> $server Typically $_SERVER
     * @param array<string, mixed> $post   Typically $_POST
     */
    public function fromRequest(array $server, array $post): ThreeDsData
    {
        return $this->build(
            server:       $server,
            post:         $post,
            defaultAgent: 'Unknown',
        );
    }

    /**
     * Default 3DS data for server-side flows (e.g. payment link generation
     * where there's no live browser context).
     *
     * @param array<string, mixed> $server
     */
    public function forServerContext(array $server): ThreeDsData
    {
        return $this->build(
            server:       $server,
            post:         [],
            defaultAgent: 'NETOPIA Payment Link',
        );
    }

    /**
     * Build a ThreeDsData DTO from a best-effort merge of $server defaults and
     * optional $post fingerprint fields. When $post is empty this degrades
     * gracefully to the server-context path.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $post
     */
    private function build(array $server, array $post, string $defaultAgent): ThreeDsData
    {
        return new ThreeDsData(
            browserUserAgent:    Sanitizer::threeDsField((string) ($server['HTTP_USER_AGENT'] ?? $defaultAgent)),
            os:                  php_uname('s'),
            osVersion:           php_uname('r'),
            mobile:              (isset($post['netopia_mobile']) && $post['netopia_mobile'] === 'true') ? 'true' : 'false',
            screenPoint:         Sanitizer::threeDsField((string) ($post['netopia_screen_point'] ?? 'false')),
            screenPrint:         Sanitizer::threeDsField((string) ($post['netopia_screen_print'] ?? self::DEFAULT_SCREEN_PRINT)),
            browserColorDepth:   Sanitizer::threeDsField((string) ($post['netopia_color_depth'] ?? self::DEFAULT_COLOR_DEPTH)),
            browserScreenHeight: Sanitizer::threeDsField((string) ($post['netopia_screen_height'] ?? self::DEFAULT_SCREEN_H)),
            browserScreenWidth:  Sanitizer::threeDsField((string) ($post['netopia_screen_width'] ?? self::DEFAULT_SCREEN_W)),
            browserPlugins:      Sanitizer::threeDsField((string) ($post['netopia_plugins'] ?? '')),
            browserJavaEnabled:  Sanitizer::threeDsField((string) ($post['netopia_java_enabled'] ?? 'false')),
            browserLanguage:     Sanitizer::threeDsField((string) ($post['netopia_language'] ?? ($server['HTTP_ACCEPT_LANGUAGE'] ?? self::DEFAULT_LANG))),
            browserTz:           Sanitizer::threeDsField((string) ($post['netopia_tz'] ?? self::DEFAULT_TZ)),
            browserTzOffset:     Sanitizer::threeDsField((string) ($post['netopia_tz_offset'] ?? '0')),
            ipAddress:           Sanitizer::ipAddress((string) ($server['REMOTE_ADDR'] ?? self::DEFAULT_IP)),
        );
    }
}
