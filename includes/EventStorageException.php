<?php

declare(strict_types=1);

namespace PaymosEasyDigitalDownloads;

defined('ABSPATH') || exit;

/** Durable event storage/configuration failed; delivery must be retried. */
final class EventStorageException extends \RuntimeException
{
}
