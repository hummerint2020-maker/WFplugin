<?php
namespace WorkforceOne\Polls;

if (!defined('ABSPATH')) exit;

/**
 * Employee polls: a poll's state, who may vote, when results are shown, and the checks on a save.
 * Pure: times are passed as 'Y-m-d H:i:s' strings in site time.
 */
final class PollRules
{
    public const VISIBILITY = [
        'after_vote' => 'After the employee votes',
        'after_close' => 'When the poll closes',
        'admins' => 'Admins only',
    ];

    public const STATES = ['hidden' => 'Hidden', 'archived' => 'Archived', 'scheduled' => 'Scheduled', 'open' => 'Open', 'closed' => 'Closed'];

    public const ERRORS = [
        'question' => 'A poll needs a question and at least two choices.',
        'dates' => 'End time cannot be before start time.',
        'choices_locked' => 'Choices cannot be changed after employees have voted. You can still edit the question, visibility, status and dates.',
        'closed' => 'This poll is closed.',
        'not_for_you' => 'This poll is not for your department.',
        'choice' => 'Please choose one of the poll\'s choices.',
        'already' => 'You have already voted in this poll.',
    ];

    /** hidden (switched off), archived, scheduled (before its start), open, or closed (after its end). */
    public static function state(string $status, ?string $startsAt, ?string $endsAt, string $now): string
    {
        if ($status === 'archived') return 'archived';
        if ($status !== 'active') return 'hidden';
        if ($startsAt && $startsAt > $now) return 'scheduled';
        if ($endsAt && $endsAt < $now) return 'closed';
        return 'open';
    }

    /** The poll is for this employee: everyone, or their department. */
    public static function inAudience(?int $audienceDepartment, ?int $employeeDepartment): bool
    {
        return !$audienceDepartment || $audienceDepartment === (int) $employeeDepartment;
    }

    /** '' when the employee may vote (or change their vote) now, else an ERRORS code. */
    public static function voteError(string $state, bool $inAudience, bool $hasVoted, bool $allowChange): string
    {
        if ($state !== 'open') return 'closed';
        if (!$inAudience) return 'not_for_you';
        if ($hasVoted && !$allowChange) return 'already';
        return '';
    }

    /** Whether an employee sees the results now (admins always do). */
    public static function resultsVisible(string $visibility, string $state, bool $hasVoted): bool
    {
        $over = in_array($state, ['closed', 'archived'], true);
        if ($visibility === 'admins') return false;
        if ($visibility === 'after_close') return $over;
        return $hasVoted || $over;
    }

    /**
     * Checks on a save. @param string[] $choices non-empty choice texts; $lockedChoices the saved ones
     * when employees have already voted (null otherwise).
     */
    public static function saveError(string $question, array $choices, ?string $startsAt, ?string $endsAt, ?array $lockedChoices): string
    {
        if ($question === '' || count($choices) < 2) return 'question';
        if ($startsAt && $endsAt && $endsAt < $startsAt) return 'dates';
        if ($lockedChoices !== null && array_values($lockedChoices) !== array_values($choices)) return 'choices_locked';
        return '';
    }

    /**
     * Votes per choice as whole percentages that add up to 100 (largest remainder).
     * @param array<int|string,int> $votes choice => votes
     * @return array<int|string,int>
     */
    public static function percentages(array $votes): array
    {
        $total = array_sum($votes);
        if ($total <= 0) return array_map(static function () { return 0; }, $votes);
        $out = []; $rest = [];
        foreach ($votes as $k => $n) { $exact = $n * 100 / $total; $out[$k] = (int) floor($exact); $rest[$k] = $exact - $out[$k]; }
        arsort($rest);
        foreach (array_slice(array_keys($rest), 0, 100 - array_sum($out)) as $k) $out[$k]++;
        return $out;
    }

    /** Share of the audience that voted, in whole percent (null without an audience). */
    public static function participation(int $voters, int $audience): ?int
    {
        return $audience > 0 ? (int) round(min($voters, $audience) * 100 / $audience) : null;
    }

    /** "Closes in 3 days" / "Closes in 5 hours" / "Closes today" for an open poll with an end. */
    public static function closesLabel(?string $endsAt, string $now): string
    {
        if (!$endsAt) return '';
        $secs = strtotime($endsAt) - strtotime($now);
        if ($secs <= 0) return '';
        if ($secs >= 2 * 86400) return 'Closes in ' . (int) floor($secs / 86400) . ' days';
        if ($secs >= 86400) return 'Closes tomorrow';
        if ($secs >= 2 * 3600) return 'Closes in ' . (int) floor($secs / 3600) . ' hours';
        return 'Closes soon';
    }
}
