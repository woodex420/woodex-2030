<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Http\Request;
use PDOException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * What a person is told when the database ended their request because somebody else was writing the same
 * records in the same moment (master list T-093).
 *
 * MySQL and MariaDB end one of two requests that each wait for a row the other holds (a deadlock), and one that
 * has waited too long for a lock; SQLite says "database is locked". Nothing of the ended request is kept. Where
 * the books are written the order of the locks keeps this from happening (App\Domain\Accounts\Services\LedgerTurn,
 * tried by tools/ci/two-writers.php), but no order can be proved for every pair of screens in the application.
 * Wherever it does happen, the answer is not "something went wrong on our side": it is 409, in plain words, with
 * "do it again". The fault is still reported under its request number, so a place where it happens often is found.
 */
final class TwoWritersMet
{
    public const string MESSAGE = 'Somebody else was saving the same records in that moment. Nothing of what you just did was saved. Do it again.';

    public static function respond(Request $request, Throwable $exception): ?Response
    {
        if (! self::ended($exception)) {
            return null;
        }

        return $request->expectsJson() || $request->is('api/*') || $request->is('webhooks/*')
            ? response()->json(['message' => self::MESSAGE], Response::HTTP_CONFLICT)
            : response()->view('errors.409', ['exception' => new ConflictHttpException(self::MESSAGE, $exception)], Response::HTTP_CONFLICT);
    }

    /** Was this request ended by the database because of another writer? */
    public static function ended(Throwable $exception): bool
    {
        $detector = new ConcurrencyErrorDetector;

        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof DeadlockException || ($cause instanceof PDOException && $detector->causedByConcurrencyError($cause))) {
                return true;
            }
        }

        return false;
    }
}
