<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * What AttendanceService did: ok, or a code saying why not. Codes are SignInRules, BreakRules and
 * LocationAssessment (QR_*) constants plus the ones below; details carry what a message needs
 * (distance, the Sign In window, the work location's name). Transports turn codes into text:
 * the Web shows the same messages as before 3.31.46, the API will use Api\ErrorMap.
 */
final class AttendanceResult
{
    public const ATTENDANCE_DISABLED = 'attendance_disabled';
    public const OUTSIDE_LOCATION = 'outside_location';
    public const SAVE_FAILED = 'save_failed';
    public const IDEMPOTENCY_CONFLICT = 'idempotency_conflict';
    public const IN_PROGRESS = 'in_progress';

    /** @var bool */
    public $ok;
    /** @var string 'ok' or an error code */
    public $code;
    /** @var array<string,mixed> */
    public $details;
    /** @var bool the stored result of an earlier request with the same idempotency key */
    public $replayed = false;

    /** @param array<string,mixed> $details */
    private function __construct(bool $ok, string $code, array $details)
    {
        $this->ok = $ok;
        $this->code = $code;
        $this->details = $details;
    }

    /** @param array<string,mixed> $details e.g. event_id, event_at, classification, break_id, minutes */
    public static function success(array $details = []): self
    {
        return new self(true, 'ok', $details);
    }

    /** @param array<string,mixed> $details */
    public static function failure(string $code, array $details = []): self
    {
        return new self(false, $code, $details);
    }

    /** @return array{ok:bool,code:string,details:array<string,mixed>} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'code' => $this->code, 'details' => $this->details];
    }

    /** @param array{ok?:bool,code?:string,details?:array<string,mixed>} $a */
    public static function fromArray(array $a): self
    {
        $r = new self(!empty($a['ok']), (string) ($a['code'] ?? ''), is_array($a['details'] ?? null) ? $a['details'] : []);
        $r->replayed = true;
        return $r;
    }
}
