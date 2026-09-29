<?php

namespace App\Enums;

/**
 * Mirrors ride_offers.status. No "accepted": who won a ride is ride_requests.driver_id.
 * 'closed' = the ride was resolved (taken by a driver, or cancelled) while this offer was open.
 */
enum OfferStatus: string
{
    case Offered = 'offered';
    case Declined = 'declined';
    case Expired = 'expired';
    case Closed = 'closed';
}
