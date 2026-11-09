<?php
namespace WorkforceOne\HrLetters;

if (!defined('ABSPATH')) exit;

/** The letters' private files (HL3): the folder workforce-one-letters, random 32-character keys, never in the Media Library. */
interface LetterFiles
{
    /** The Amiri font's bytes. */
    public function font(): string;

    /** Keeps the bytes, and returns the new key ('' when they could not be written). */
    public function store(string $bytes, string $extension): string;

    /**
     * An uploaded PDF (an entry of $_FILES), checked: a real upload, at most $maxBytes, starting with %PDF-.
     * @param mixed $file
     * @return array{key:string,name:string,size:int,error:string} error: file | size | type | write
     */
    public function uploadPdf($file, int $maxBytes): array;

    /** A file's bytes, or null when it is not there. */
    public function read(string $key): ?string;

    public function delete(string $key): void;
}
