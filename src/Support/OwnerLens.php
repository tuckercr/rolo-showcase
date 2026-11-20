<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one definition of ownership scoping, shared by every owned entity
 * (contacts since migration 0021, tasks since 0024). owner_id NULL means
 * shared. Two different questions get asked of that column:
 *
 *  - the LENS ("Mine"): rows a person tends, i.e. their own plus shared
 *    ones. This is what personalizes the dashboard, the daily email and
 *    the Slack bot.
 *  - the FILTER: an exact match chosen on a list or by an agent, i.e.
 *    anyone, shared only, or one user's rows.
 *
 * Ownership is a lens and never an access control: nothing here hides a
 * record from anyone who asks for it directly.
 *
 * This is also the seam a future workspace (tenant) boundary would follow.
 * A tenant predicate is the same shape of fragment, applied in the same
 * model methods that take these, so owned entities must keep building
 * their WHERE clauses through this class instead of inlining the SQL.
 */
final class OwnerLens
{
    /**
     * WHERE fragment for the Mine lens. $column is a trusted, hardcoded
     * column reference from the calling model (never user input). The
     * placeholder name is a parameter so callers never reuse one inside a
     * statement, which native prepares reject.
     */
    public static function mine(string $column, string $placeholder): string
    {
        return sprintf('(%1$s = :%2$s OR %1$s IS NULL)', $column, $placeholder);
    }

    /**
     * WHERE fragment and bound parameters for the exact owner filter:
     * '' = anyone (null: add nothing), 'shared' = unowned rows only, a
     * numeric string = that user's rows. Anything else falls through as
     * "anyone"; callers validate what they accept.
     *
     * @return array{0: string, 1: array<string, int>}|null
     */
    public static function filter(string $column, string $owner, string $placeholder = 'owner_id'): ?array
    {
        if ($owner === 'shared') {
            return [$column . ' IS NULL', []];
        }

        if (ctype_digit($owner)) {
            return [sprintf('%s = :%s', $column, $placeholder), [$placeholder => (int) $owner]];
        }

        return null;
    }
}
