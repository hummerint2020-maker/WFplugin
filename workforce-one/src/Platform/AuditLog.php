<?php
namespace WorkforceOne\Platform;

if (!defined('ABSPATH')) exit;

/** A port: one Audit Log row (the plugin's audit(), layered modules never write the table themselves). */
interface AuditLog
{
    public function record(string $action, string $entity, int $entityId, string $details): void;
}
