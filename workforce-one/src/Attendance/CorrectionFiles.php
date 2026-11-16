<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** A request's photo in the private corrections folder (3.31.143). The checks, the name and the folder stay in the plugin. */
interface CorrectionFiles
{
    /** Whether a file was sent with the request. @param mixed $file an entry of $_FILES */
    public function hasUpload($file): bool;

    /**
     * Saves the photo. @param mixed $file an entry of $_FILES
     * @return array{0:?string,1:?string,2:string} [key, name, error code ('' = saved, or nothing was sent)]
     */
    public function save($file): array;

    /** Removes a saved photo. */
    public function delete(string $key): void;
}
