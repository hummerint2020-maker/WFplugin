<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * One Sign In / Sign Out request, as facts. The transport (the Web form today, the API later) fills
 * it; AttendanceService decides. Nothing here says whether the event is allowed.
 */
final class AttendanceCommand
{
    public const SIGN_IN = 'sign_in';
    public const SIGN_OUT = 'sign_out';

    /** @var string sign_in | sign_out */
    public $type;
    /** @var float|null */
    public $latitude;
    /** @var float|null */
    public $longitude;
    /** @var float|null metres */
    public $accuracy;
    /** @var int|null device location time, Unix milliseconds */
    public $locationTimestampMs;
    /** @var bool a server-issued Face token was consumed for this request */
    public $faceOk = false;
    /** @var array{id:int}|null kiosk of a validated QR (QR Sign-In only) */
    public $qrKiosk;
    /** @var array{name:string,latitude:mixed,longitude:mixed,radius:mixed}|null that kiosk's work location */
    public $qrLocation;
    /** @var string|null client key that makes a retried request return the first result */
    public $idempotencyKey;

    public function __construct(string $type)
    {
        $this->type = $type === self::SIGN_OUT ? self::SIGN_OUT : self::SIGN_IN;
    }

    /** The facts that identify a request for idempotency (not the Face result: a retry has no fresh token). */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([$this->type, $this->latitude, $this->longitude, $this->accuracy, $this->locationTimestampMs,
            $this->qrKiosk['id'] ?? null]));
    }
}
