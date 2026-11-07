<?php

declare(strict_types=1);

namespace App\Sales;

/**
 * A lost sale names an item the restored data lacks, such as one created at the counter after the last
 * backup. The manager chooses the item to record each such line under; the line keeps its printed name.
 */
final class BackEntryNeedsItems extends SaleRefused
{
    /**
     * @param  list<int>  $lines  the line numbers, from 1
     */
    public function __construct(public readonly array $lines)
    {
        parent::__construct(count($lines) === 1
            ? "Line {$lines[0]} names an item the restored data does not have. Choose the item to record it under."
            : 'Lines '.implode(', ', $lines).' name items the restored data does not have. Choose the item to record each under.');
    }
}
