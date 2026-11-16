<?php
namespace WorkforceOne\Support;

if (!defined('ABSPATH')) exit;

/**
 * A list of rows kept on a temporary stream instead of in memory (3.31.149): the long tables of
 * the Report Center's exports. The first 2 MB stay in memory, the rest goes to a temporary file.
 * Rows come back in the order they were added, with their exact types (int, float, string).
 *
 * @implements \IteratorAggregate<int,array<int|string,mixed>>
 */
final class RowSpool implements \IteratorAggregate, \Countable
{
    /** @var resource */
    private $h;
    /** @var int */
    private $n = 0;

    public function __construct()
    {
        $h = fopen('php://temp/maxmemory:2097152', 'w+');
        if ($h === false) throw new \RuntimeException('RowSpool: no temporary stream');
        $this->h = $h;
    }

    /** @param array<int|string,mixed> $row */
    public function add(array $row): void
    {
        $s = serialize($row);
        fseek($this->h, 0, SEEK_END);
        $data = pack('N', strlen($s)) . $s;
        if (fwrite($this->h, $data) !== strlen($data)) throw new \RuntimeException('RowSpool: the temporary stream is full');
        $this->n++;
    }

    public function count(): int
    {
        return $this->n;
    }

    /** @return \Generator<int,array<int|string,mixed>> */
    public function getIterator(): \Generator
    {
        rewind($this->h);
        for ($i = 0; $i < $this->n; $i++) {
            $len = unpack('N', $this->read(4));
            yield $i => unserialize($this->read((int) $len[1]), ['allowed_classes' => false]);
        }
    }

    private function read(int $len): string
    {
        $out = '';
        while (strlen($out) < $len) {
            $chunk = fread($this->h, $len - strlen($out));
            if ($chunk === false || $chunk === '') throw new \RuntimeException('RowSpool: the temporary stream ended early');
            $out .= $chunk;
        }
        return $out;
    }
}
