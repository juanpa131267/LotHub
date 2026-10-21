<?php

namespace App\Events;

use App\Models\Payments\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/** Se emite cuando un abono queda APLICADO (manual, extracto o pasarela); dispara la confirmación al cliente. */
class PaymentRegistered
{
    use Dispatchable;

    public function __construct(public Payment $payment) {}
}
