<?php

namespace Nikolaynesov\LaravelSerene\Contracts;

interface GroupableException
{
    /**
     * Get the error group identifier for throttling.
     *
     * This method allows exceptions to define their own grouping logic,
     * enabling automatic error grouping without manual key specification.
     *
     * @return string The error group identifier (e.g., 'payment-errors', 'stripe-api:charges')
     */
    public function getErrorGroup(): string;
}
