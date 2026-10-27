<?php
namespace WorkforceOne\Approvals\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the approval workflows (3.31.128). The SQL is the SQL the plugin ran before, unchanged. */
final class WordPressWorkflowStore implements WorkflowStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $workflows;
    /** @var string */
    private $steps;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
        $this->workflows = $db->prefix . 'ews_approval_workflows';
        $this->steps = $db->prefix . 'ews_approval_workflow_steps';
    }

    public function byKey(string $key): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->workflows} WHERE workflow_key=%s LIMIT 1", $key));
        return is_object($r) ? $r : null;
    }

    public function activeByKey(string $key): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->workflows} WHERE workflow_key=%s AND active=1 LIMIT 1", $key));
        return is_object($r) ? $r : null;
    }

    public function keyById(int $id): string
    {
        return (string) $this->db->get_var($this->db->prepare("SELECT workflow_key FROM {$this->workflows} WHERE id=%d", $id));
    }

    public function activeSteps(int $workflowId): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->steps} WHERE workflow_id=%d AND active=1 ORDER BY step_order ASC", $workflowId));
    }

    public function idByKey(string $key): ?string
    {
        $v = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->workflows} WHERE workflow_key=%s LIMIT 1", $key));
        return $v === null ? null : (string) $v;
    }

    public function insertWorkflow(array $row, array $formats): void
    {
        $this->db->insert($this->workflows, $row, $formats);
    }

    public function setModeActive(int $id, string $mode, string $now)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->workflows} SET approval_mode=%s,active=1,updated_at=%s WHERE id=%d", $mode, $now, $id));
    }

    public function deleteSteps(int $workflowId)
    {
        return $this->db->query($this->db->prepare("DELETE FROM {$this->steps} WHERE workflow_id=%d", $workflowId));
    }

    public function insertStep(array $row, array $formats)
    {
        return $this->db->insert($this->steps, $row, $formats);
    }

    public function lastError(): string
    {
        return (string) $this->db->last_error;
    }
}
