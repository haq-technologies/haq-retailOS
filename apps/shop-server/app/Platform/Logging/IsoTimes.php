<?php

declare(strict_types=1);

namespace App\Platform\Logging;

use DateTimeInterface;
use Illuminate\Log\Logger;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger as Monolog;

/**
 * Log times with their zone (finding LG10): "2026-11-20T19:47:25.123+00:00", not "2026-11-20 19:47:25". The
 * server's times are UTC (spec section 4) but the line did not say so, and the Windows helper's lines beside
 * them were in the PC's own time. A tap on every channel that writes lines.
 */
final class IsoTimes
{
    public const FORMAT = DateTimeInterface::RFC3339_EXTENDED;

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if (! $monolog instanceof Monolog) {
            return;
        }
        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface && ($formatter = $handler->getFormatter()) instanceof NormalizerFormatter) {
                $formatter->setDateFormat(self::FORMAT);
            }
        }
    }
}
