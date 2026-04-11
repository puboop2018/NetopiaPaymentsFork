<?php

declare(strict_types=1);

namespace Netopia\Payment2\Exception;

/**
 * Thrown when a public or private key fails to load (missing, wrong format, etc.).
 */
class KeyLoadException extends NetopiaException
{
}
