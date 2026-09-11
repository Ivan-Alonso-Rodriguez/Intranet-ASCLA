<?php
namespace ASCLA\Core\Database;
/** A schema change did not complete; activation must stop rather than record success. */
final class MigrationException extends \RuntimeException {}
