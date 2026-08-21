<?php

declare(strict_types=1);

namespace App\Application\Forms\Exception;

use App\Domain\Forms\ValueObject\FormId;

/**
 * This form already holds as many files as it may.
 *
 * The budget is what stands in for reference counting: a form whose id somebody
 * has is otherwise an unbounded place to put bytes, and the files that nobody
 * ever saves are collected on a schedule rather than at once. Counting them is
 * cheap and it bounds the damage.
 */
final class FileBudgetSpent extends \RuntimeException
{
    public function __construct(
        public readonly FormId $formId,
        public readonly int $limit,
    ) {
        parent::__construct(\sprintf('Form %s already holds %d files, which is all it may.', $formId, $limit));
    }
}
