<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * For whom the shop's own server asks, when it carries a visitor's request here (master list T-191).
 *
 * With the product link on, everything a visitor of the shop reads of this application, and the basket a visitor
 * sends to it, arrives from one place: the shop's server. Without help every visitor of the shop would be counted
 * as that one address: five enquiries a minute and fifty a day for the whole shop, and 120 reads a minute for the
 * whole shop, which about fourteen open pages use up (defect D-082). A server that proves it is the shop's, by the key
 * both sides hold (PUBLIC_LEADS_BRIDGE_KEY, sent as the header X-Woodex-Key), may therefore name the visitor it
 * speaks for, in the header X-Woodex-Visitor. From anyone else that header is not read at all: a visitor who
 * writes it himself is counted by the address he really has.
 *
 * The address is used for one thing and kept nowhere: counting. The limiters "public-leads" (what a visitor
 * sends) and "v1-read" (what a visitor reads) count by it. A request that is vouched for is not put to the robot
 * check, which is the shop's server's to make, so nothing else needs it. It is never written to a log (the rule
 * of App\Support\RequestLog).
 */
final class BridgedVisitor
{
    public const string KEY_HEADER = 'X-Woodex-Key';

    public const string VISITOR_HEADER = 'X-Woodex-Visitor';

    /** Did the request come from a server that holds the bridge key? Never true while no key is set here. */
    public static function vouchedFor(Request $request): bool
    {
        $expected = (string) config('woodex.public_leads.bridge_key');
        $given = (string) $request->header(self::KEY_HEADER);

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    /** The address a request is counted by: the visitor's own, as far as anyone may say whose it is. */
    public static function address(Request $request): string
    {
        $named = trim((string) $request->header(self::VISITOR_HEADER));

        if ($named !== '' && self::vouchedFor($request) && filter_var($named, FILTER_VALIDATE_IP) !== false) {
            return $named;
        }

        return (string) $request->ip();
    }
}
