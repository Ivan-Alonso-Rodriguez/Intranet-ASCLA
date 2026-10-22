<?php
namespace ASCLA\Core\Services;

/** Challenge state must never be issued when authenticated encryption fails. */
final class ChallengeStateException extends \RuntimeException
{
}
