<?php

namespace App\Services;

/** No Stripe price to subscribe to: neither the chosen plan's nor the configured trial price. */
class BillingNotConfiguredException extends \RuntimeException {}
