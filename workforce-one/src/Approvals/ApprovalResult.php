<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/**
 * What a Service of the approvals engine answers (3.31.128): a value, or an error code with its message. The Services never
 * build a WP_Error; the trait that called them turns this back into exactly the return the old function gave.
 */
final class ApprovalResult
{
    /** @var bool */
    public $ok;
    /** @var mixed */
    public $value;
    /** @var string */
    public $code;
    /** @var string */
    public $message;

    private function __construct(bool $ok, $value, string $code, string $message)
    {
        $this->ok = $ok;
        $this->value = $value;
        $this->code = $code;
        $this->message = $message;
    }

    /** @param mixed $value */
    public static function ok($value = true): self
    {
        return new self(true, $value, '', '');
    }

    public static function error(string $code, string $message): self
    {
        return new self(false, null, $code, $message);
    }
}
