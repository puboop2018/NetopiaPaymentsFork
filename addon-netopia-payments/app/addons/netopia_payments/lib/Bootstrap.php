<?php

declare(strict_types=1);

namespace Netopia\CsCart;

use Netopia\CsCart\Http\ApiClient;
use Netopia\CsCart\Ipn\IpnHandler;
use Netopia\CsCart\Ipn\IpnVerifier;
use Netopia\CsCart\Key\KeyStorage;
use Netopia\CsCart\Log\CsCartLogger;
use Netopia\CsCart\Payment\PayloadBuilder;
use Netopia\CsCart\Payment\PaymentLinkEmailSender;
use Netopia\CsCart\Payment\PaymentLinkService;
use Netopia\CsCart\Session\ThreeDsSessionStore;
use Netopia\CsCart\Status\StatusMapper;
use Netopia\CsCart\Support\ClockInterface;
use Netopia\CsCart\Support\SystemClock;
use Netopia\CsCart\ThreeDs\ThreeDsDataFactory;
use Netopia\CsCart\ThreeDs\ThreeDsReturnHandler;
use Netopia\Payment2\Enum\PaymentMode;
use Psr\Log\LoggerInterface;

/**
 * Lightweight service container for the NETOPIA CS-Cart addon.
 *
 * Instantiates the object graph that the procedural hook wrappers in
 * func.php delegate to. Depends on CS-Cart globals only through the
 * bootstrap method — individual services stay pure.
 */
final class Bootstrap
{
    private static ?self $instance = null;

    public readonly PayloadBuilder $payloadBuilder;
    public readonly KeyStorage $keyStorage;
    public readonly IpnVerifier $ipnVerifier;
    public readonly StatusMapper $statusMapper;
    public readonly ThreeDsDataFactory $threeDsFactory;
    public readonly ClockInterface $clock;
    public readonly LoggerInterface $logger;

    private function __construct(
        string $keysBaseDir,
        string $notifyUrl,
        string $redirectUrl,
        string $primaryCurrency,
        string $language,
    ) {
        $this->clock          = new SystemClock();
        $this->logger         = new CsCartLogger();
        $this->payloadBuilder = new PayloadBuilder(
            clock:           $this->clock,
            notifyUrl:       $notifyUrl,
            redirectUrl:     $redirectUrl,
            primaryCurrency: $primaryCurrency,
            language:        $language,
        );
        $this->keyStorage     = new KeyStorage($keysBaseDir);
        $this->ipnVerifier    = new IpnVerifier();
        $this->statusMapper   = new StatusMapper();
        $this->threeDsFactory = new ThreeDsDataFactory();
    }

    public static function instance(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $addonsDir = \function_exists('fn_get_files_dir_path') && \class_exists(\Tygh\Registry::class)
            ? (string) \Tygh\Registry::get('config.dir.addons')
            : '';

        $keysBaseDir = $addonsDir . 'netopia_payments/keys';

        $notifyUrl   = \function_exists('fn_url')
            ? (string) fn_url('payment_notification.notify?payment=netopia_payments', AREA, 'current')
            : '';
        $redirectUrl = \function_exists('fn_url')
            ? (string) fn_url('payment_notification.return?payment=netopia_payments', AREA, 'current')
            : '';

        $primaryCurrency = \defined('CART_PRIMARY_CURRENCY') ? CART_PRIMARY_CURRENCY : 'RON';

        return self::$instance = new self(
            keysBaseDir:     $keysBaseDir,
            notifyUrl:       $notifyUrl,
            redirectUrl:     $redirectUrl,
            primaryCurrency: $primaryCurrency,
            language:        'RO',
        );
    }

    /**
     * Construct an API client for the given processor params.
     *
     * @param array<string, mixed> $processorParams
     */
    public function apiClient(array $processorParams): ApiClient
    {
        return new ApiClient(
            apiKey: (string) ($processorParams['api_key'] ?? ''),
            mode:   PaymentMode::fromMixed($processorParams['mode'] ?? null),
            logger: $this->logger,
        );
    }

    public function apiClientFor(string $apiKey, PaymentMode $mode): ApiClient
    {
        return new ApiClient($apiKey, $mode, $this->logger);
    }

    public function ipnHandler(
        \Closure $orderLookup,
        \Closure $processorDataLookup,
        \Closure $paymentInfoUpdater,
        \Closure $paymentFinalizer,
        \Closure $responder,
    ): IpnHandler {
        return new IpnHandler(
            verifier:             $this->ipnVerifier,
            keyStorage:           $this->keyStorage,
            statusMapper:         $this->statusMapper,
            orderLookup:          $orderLookup,
            processorDataLookup:  $processorDataLookup,
            paymentInfoUpdater:   $paymentInfoUpdater,
            paymentFinalizer:     $paymentFinalizer,
            responder:            $responder,
            logger:               $this->logger,
        );
    }

    public function threeDsReturnHandler(
        ThreeDsSessionStore $session,
        \Closure $orderLookup,
        \Closure $processorDataLookup,
        \Closure $paymentInfoUpdater,
        \Closure $paymentFinalizer,
        \Closure $placementRouter,
        \Closure $checkoutRedirect,
    ): ThreeDsReturnHandler {
        return new ThreeDsReturnHandler(
            session:             $session,
            payloadBuilder:      $this->payloadBuilder,
            statusMapper:        $this->statusMapper,
            orderLookup:         $orderLookup,
            processorDataLookup: $processorDataLookup,
            paymentInfoUpdater:  $paymentInfoUpdater,
            paymentFinalizer:    $paymentFinalizer,
            placementRouter:     $placementRouter,
            checkoutRedirect:    $checkoutRedirect,
            apiClientFactory:    \Closure::fromCallable([$this, 'apiClientFor']),
            logger:              $this->logger,
        );
    }

    public function paymentLinkService(
        \Closure $paymentInfoUpdater,
        \Closure $orderStatusChanger,
    ): PaymentLinkService {
        return new PaymentLinkService(
            payloadBuilder:      $this->payloadBuilder,
            threeDsFactory:      $this->threeDsFactory,
            apiClientFactory:    \Closure::fromCallable([$this, 'apiClientFor']),
            paymentInfoUpdater:  $paymentInfoUpdater,
            orderStatusChanger:  $orderStatusChanger,
            logger:              $this->logger,
        );
    }

    public function paymentLinkEmailSender(
        \Closure $mailSender,
        \Closure $translator,
    ): PaymentLinkEmailSender {
        $companyName = \class_exists(\Tygh\Registry::class)
            ? (string) (\Tygh\Registry::get('settings.Company.company_name') ?: 'Our Store')
            : 'Our Store';

        $primaryCurrency = \defined('CART_PRIMARY_CURRENCY') ? CART_PRIMARY_CURRENCY : 'RON';

        return new PaymentLinkEmailSender(
            mailSender:      $mailSender,
            translator:      $translator,
            companyName:     $companyName,
            primaryCurrency: $primaryCurrency,
        );
    }

    /** Reset the singleton (for tests). */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
