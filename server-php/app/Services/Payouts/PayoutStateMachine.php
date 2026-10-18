<?php
declare(strict_types=1);

namespace App\Services\Payouts;

use App\Support\ApiException;

/**
 * Allowed status moves (pure). PayoutService asks here before every change.
 *
 * Batch:  draft ⇄ in_review → approved → file_generated → partially_paid | paid
 *         draft | in_review | approved → cancelled
 * Item:   draft ⇄ on_hold          (while the batch is draft / in_review)
 *         draft → approved          (batch approval)
 *         approved → paid           (UTR recorded — never without one)
 *         approved → failed         (bank rejected / returned before credit)
 *         on_hold → failed          (released back to Engage after approval, or batch cancelled)
 *         draft → failed            (batch cancelled)
 *         paid → reversed           (bank returned a credited payment)
 */
final class PayoutStateMachine
{
    public const BATCH_STATUSES = ['draft', 'in_review', 'approved', 'file_generated', 'partially_paid', 'paid', 'cancelled'];
    public const ITEM_STATUSES  = ['draft', 'on_hold', 'approved', 'paid', 'failed', 'reversed'];

    private const BATCH = [
        'draft'          => ['in_review', 'cancelled'],
        'in_review'      => ['draft', 'approved', 'cancelled'],
        'approved'       => ['file_generated', 'cancelled'],
        'file_generated' => ['partially_paid', 'paid'],
        'partially_paid' => ['paid', 'partially_paid', 'file_generated'],
        'paid'           => ['partially_paid', 'file_generated'],
        'cancelled'      => [],
    ];

    private const ITEM = [
        'draft'    => ['on_hold', 'approved', 'failed'],
        'on_hold'  => ['draft', 'failed'],
        'approved' => ['paid', 'failed'],
        'paid'     => ['reversed'],
        'failed'   => [],
        'reversed' => [],
    ];

    /** Batch statuses in which items may be held, adjusted or recalculated. */
    public const EDITABLE = ['draft', 'in_review'];

    /** Batch statuses in which payment results may be recorded. */
    public const PAYABLE = ['file_generated', 'partially_paid', 'paid'];

    public static function canBatch(string $from, string $to): bool
    {
        return in_array($to, self::BATCH[$from] ?? [], true);
    }

    public static function canItem(string $from, string $to): bool
    {
        return in_array($to, self::ITEM[$from] ?? [], true);
    }

    public static function assertBatch(string $from, string $to): void
    {
        if (!self::canBatch($from, $to)) {
            throw ApiException::conflict("A {$from} batch cannot become {$to}.");
        }
    }

    public static function assertItem(string $from, string $to): void
    {
        if (!self::canItem($from, $to)) {
            throw ApiException::conflict("A {$from} item cannot become {$to}.");
        }
    }

    /**
     * Batch status once payment results are in, from its items' statuses.
     *   no item (still) paid        → file_generated
     *   every item paid             → paid
     *   otherwise                   → partially_paid (some paid; others outstanding, failed, held or reversed)
     *
     * @param list<string> $itemStatuses
     */
    public static function settledStatus(array $itemStatuses): string
    {
        $paid = count(array_filter($itemStatuses, static fn (string $s) => $s === 'paid'));
        if ($paid === 0) {
            return 'file_generated';
        }

        return $paid === count($itemStatuses) ? 'paid' : 'partially_paid';
    }

    /**
     * Four-eyes: the approver must not be whoever submitted the batch for review
     * or last changed it.
     */
    public static function fourEyesViolation(string $approver, ?string $preparedBy, ?string $lastEditedBy): ?string
    {
        $approver = strtolower(trim($approver));
        if ($preparedBy !== null && strtolower(trim($preparedBy)) === $approver) {
            return 'Four-eyes rule: you submitted this batch for review, so someone else must approve it.';
        }
        if ($lastEditedBy !== null && strtolower(trim($lastEditedBy)) === $approver) {
            return 'Four-eyes rule: you made the last change to this batch, so someone else must approve it.';
        }

        return null;
    }
}
