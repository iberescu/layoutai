<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a non-premium workspace tries to spend more free (promotional)
 * credit than its monthly cap allows.
 */
class FreeCreditLimitException extends RuntimeException
{
    public function __construct(
        public readonly int $capCents,
        public readonly int $spentCents,
        public readonly int $requestedCents,
        string $message = '',
    ) {
        parent::__construct($message ?: sprintf(
            'Monthly free-credit limit reached: $%s of $%s used this month. Upgrade to premium to spend more.',
            number_format($spentCents / 100, 2),
            number_format($capCents / 100, 2),
        ));
    }
}
