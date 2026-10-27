<?php
namespace WorkforceOne\Platform;

if (!defined('ABSPATH')) exit;

/**
 * The database transaction, for a Service that owns one (3.31.128). It sends START TRANSACTION, COMMIT and ROLLBACK and
 * nothing else: it does not nest. A Service that was told its caller owns the transaction never calls it, and a Store never does.
 */
interface Transaction
{
    /** @return bool false when the database refused to start it */
    public function begin(): bool;

    /** @return bool false when the database refused the commit */
    public function commit(): bool;

    public function rollback(): void;
}
