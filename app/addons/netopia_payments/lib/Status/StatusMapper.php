<?php

declare(strict_types=1);

namespace Netopia\CsCart\Status;

use Netopia\Payment2\Enum\PaymentStatus;

/**
 * Maps NETOPIA payment statuses to CS-Cart order statuses.
 *
 * Default mapping per status group:
 *   success → P (Processed)
 *   pending → O (Open)
 *   cancel  → I (Cancelled)
 *   fail    → F (Failed)
 *
 * Processor params may override per-status via `status_map_<int>` keys.
 */
final class StatusMapper
{
    /**
     * Resolve the CS-Cart order status for a NETOPIA status code.
     *
     * @param array<string, mixed> $processorParams Optional custom mapping
     */
    public function map(int $netopiaStatus, array $processorParams = []): string
    {
        $custom = $processorParams['status_map_' . $netopiaStatus] ?? null;
        if (is_string($custom) && $custom !== '') {
            return $custom;
        }

        $enum = PaymentStatus::tryFrom($netopiaStatus);
        if ($enum === null) {
            return 'O';
        }

        return match ($enum->group()) {
            'success' => 'P',
            'cancel'  => 'I',
            'fail'    => 'F',
            default   => 'O',
        };
    }

    /**
     * Return the status definitions used by the admin status-mapping UI.
     *
     * @return array<int, array{label: string, default: string, group: string}>
     */
    public function definitions(): array
    {
        $definitions = [];
        foreach (PaymentStatus::cases() as $status) {
            $group = $status->group();
            $definitions[$status->value] = [
                'label'   => $status->label(),
                'default' => match ($group) {
                    'success' => 'P',
                    'cancel'  => 'I',
                    'fail'    => 'F',
                    default   => 'O',
                },
                'group'   => $group,
            ];
        }

        return $definitions;
    }
}
