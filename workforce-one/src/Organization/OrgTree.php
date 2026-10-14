<?php
namespace WorkforceOne\Organization;

if (!defined('ABSPATH')) exit;

/**
 * The organization chart built on each employee's direct manager (the "supervisor" relationship the
 * approvals already use, 3.31.97). Rows are the active employees with their manager id (0 = none);
 * a manager who is not among them (archived) counts as none. Loops cannot be saved, but old data may
 * hold one: everyone in a loop is treated as having no manager, so the chart never goes round.
 * Pure: no WordPress calls.
 */
final class OrgTree
{
    /** @var array<int,int> id => manager id (0 = none) */
    private $manager = [];
    /** @var array<int,list<int>> id => direct reports */
    private $reports = [];
    /** @var array<int,int> id => level (1 = top) */
    private $level = [];
    /** @var array<int,int> id => everyone under, at every level */
    private $under = [];
    /** @var list<int> people whose managers went round in a loop (old data), shown without a manager */
    private $looped = [];

    /** @param array<int,int> $managers employee id => manager id */
    public function __construct(array $managers)
    {
        foreach ($managers as $id => $m) {
            $this->manager[(int) $id] = (int) $m;
        }
        foreach ($this->manager as $id => $m) {
            if ($m === $id || !isset($this->manager[$m])) {
                $this->manager[$id] = 0;
            }
        }
        foreach ($this->loops() as $id) {
            $this->manager[$id] = 0;
            $this->looped[] = $id;
        }
        foreach ($this->manager as $id => $m) {
            $this->reports[$id] = $this->reports[$id] ?? [];
            if ($m) {
                $this->reports[$m][] = $id;
            }
        }
        foreach ($this->tops() as $top) {
            $this->walk($top, 1);
        }
    }

    /** The people in a loop of managers (A → B → A), in any old data. @return list<int> */
    private function loops(): array
    {
        return self::loopsIn($this->manager);
    }

    /**
     * The people in a loop of managers in a map of employee id => manager id (0 = none); a manager
     * who is not in the map ends the line. Used to check a set of changes (an import) before saving.
     * @param array<int,int> $managers
     * @return list<int>
     */
    public static function loopsIn(array $managers): array
    {
        $out = [];
        $state = [];
        foreach (array_keys($managers) as $start) {
            $path = [];
            $id = (int) $start;
            while ($id && isset($managers[$id]) && !isset($state[$id])) {
                $state[$id] = 1;
                $path[] = $id;
                $id = (int) $managers[$id];
            }
            if ($id && ($state[$id] ?? 0) === 1 && in_array($id, $path, true)) {
                $out = array_merge($out, array_slice($path, (int) array_search($id, $path, true)));
            }
            foreach ($path as $p) {
                $state[$p] = 2;
            }
        }
        return array_values(array_unique($out));
    }

    private function walk(int $id, int $level): int
    {
        $this->level[$id] = $level;
        $n = 0;
        foreach ($this->reports[$id] as $r) {
            $n += 1 + $this->walk($r, $level + 1);
        }
        return $this->under[$id] = $n;
    }

    /** People with no manager in the chart. @return list<int> */
    public function tops(): array
    {
        return array_values(array_keys(array_filter($this->manager, static function ($m) { return $m === 0; })));
    }

    public function managerOf(int $id): int
    {
        return $this->manager[$id] ?? 0;
    }

    /** @return list<int> */
    public function reportsOf(int $id): array
    {
        return $this->reports[$id] ?? [];
    }

    /** Everyone under someone, at every level. */
    public function underCount(int $id): int
    {
        return $this->under[$id] ?? 0;
    }

    /** @return list<int> everyone under someone, at every level */
    public function everyoneUnder(int $id): array
    {
        $out = [];
        foreach ($this->reportsOf($id) as $r) {
            $out[] = $r;
            $out = array_merge($out, $this->everyoneUnder($r));
        }
        return $out;
    }

    public function levelOf(int $id): int
    {
        return $this->level[$id] ?? 0;
    }

    /** Levels in the chart. */
    public function levels(): int
    {
        return $this->level ? max($this->level) : 0;
    }

    /** The reporting line, from the top down to the person. @return list<int> */
    public function chain(int $id): array
    {
        $out = [];
        while ($id && isset($this->manager[$id])) {
            array_unshift($out, $id);
            $id = $this->manager[$id];
        }
        return $out;
    }

    /** Teammates: the others with the same manager (none for someone without a manager). @return list<int> */
    public function teammatesOf(int $id): array
    {
        $m = $this->managerOf($id);
        return $m ? array_values(array_diff($this->reportsOf($m), [$id])) : [];
    }

    /** Managers: people with at least one direct report. @return list<int> */
    public function managers(): array
    {
        return array_values(array_keys(array_filter($this->reports, static function ($r) { return $r !== []; })));
    }

    /**
     * People without a manager who are not at the top of a chart: nobody reports to them either, so
     * they are not in the chart (their requests go to administrators). One person at the top of a
     * chart with reports is the head, not a person without a manager.
     * @return list<int>
     */
    public function withoutManager(): array
    {
        return array_values(array_filter($this->tops(), function ($id) { return $this->reportsOf($id) === []; }));
    }

    /** People whose managers went round in a loop in old data (now shown without a manager). @return list<int> */
    public function looped(): array
    {
        return $this->looped;
    }

    /** Managers with more direct reports than $limit (0 = no limit). @return list<int> */
    public function wideSpans(int $limit): array
    {
        if ($limit <= 0) return [];
        return array_values(array_keys(array_filter($this->reports, static function ($r) use ($limit) { return count($r) > $limit; })));
    }

    /** Would putting $id under $manager make a loop (the manager reports to them, at any level)? */
    public function wouldLoop(int $id, int $manager): bool
    {
        if ($manager === 0) return false;
        if ($manager === $id) return true;
        return in_array($id, $this->chain($manager), true);
    }
}
