<?php

namespace App\Enums;

/** Mirrors subscription_transactions.status (one checkout attempt with the gateway). */
enum TransactionStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Refunded = 'refunded';
}
