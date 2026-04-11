<?php

declare(strict_types=1);

namespace Netopia\CsCart\Dto;

/**
 * 3D Secure browser fingerprint data.
 *
 * Collected client-side (via JS) and server-side (IP, UA, OS).
 * All string values are sanitized and length-limited before reaching this DTO.
 */
final readonly class ThreeDsData
{
    public function __construct(
        public string $browserUserAgent,
        public string $os,
        public string $osVersion,
        public string $mobile,
        public string $screenPoint,
        public string $screenPrint,
        public string $browserColorDepth,
        public string $browserScreenHeight,
        public string $browserScreenWidth,
        public string $browserPlugins,
        public string $browserJavaEnabled,
        public string $browserLanguage,
        public string $browserTz,
        public string $browserTzOffset,
        public string $ipAddress,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'BROWSER_USER_AGENT'    => $this->browserUserAgent,
            'OS'                    => $this->os,
            'OS_VERSION'            => $this->osVersion,
            'MOBILE'                => $this->mobile,
            'SCREEN_POINT'          => $this->screenPoint,
            'SCREEN_PRINT'          => $this->screenPrint,
            'BROWSER_COLOR_DEPTH'   => $this->browserColorDepth,
            'BROWSER_SCREEN_HEIGHT' => $this->browserScreenHeight,
            'BROWSER_SCREEN_WIDTH'  => $this->browserScreenWidth,
            'BROWSER_PLUGINS'       => $this->browserPlugins,
            'BROWSER_JAVA_ENABLED'  => $this->browserJavaEnabled,
            'BROWSER_LANGUAGE'      => $this->browserLanguage,
            'BROWSER_TZ'            => $this->browserTz,
            'BROWSER_TZ_OFFSET'     => $this->browserTzOffset,
            'IP_ADDRESS'            => $this->ipAddress,
        ];
    }
}
