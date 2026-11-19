<?php
namespace ASCLA\Core\Jobs;

/** Yield the worker while an external operation is pending; this is not a failed attempt. */
final class DeferredJob extends \RuntimeException
{
    public function __construct(string $message,public readonly int $delay=30) { parent::__construct($message); }
}
