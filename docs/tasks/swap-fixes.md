# Swap fixes (before the L3a move)

## Request (owner, 2026-11-20)
The L3 design (`docs/tasks/schedule-rest.md` §5) found quirks SW-a…SW-n in the shift swaps. The owner chose to **fix them first**,
in their own release, before the L3a move (so the move carries the fixed code). Proposed numbers: fixes 3.31.156, L3a move 3.31.157.
The two race tests (double accept; two swaps on one day sharing a person) are added first, on the unchanged code.

## Owner decisions (2026-11-20)
| Quirk | Today | Decision |
|---|---|---|
| SW-a | an administrator who is also an employee may request a swap with anyone in any department | **Own department only**, like any employee (as the request sheet already offers) |
| SW-c | the administrator's direct approval (wp-admin) has no past-day check | **The administrator may still approve a past day** (corrections): unchanged |
| SW-f | Cancel on the Schedule page has no past-day check; a swap not found / not owned redirects as "cancelled" | **Cancel of a past day is refused** (as withdraw); a swap not found / not owned no longer says "cancelled" |
| SW-m | an administrator's decision sends no push | **Push like every other decision** |
| SW-e | an approver's / administrator's decision overwrites the colleague's `responded_by` / `responded_at`; swaps applied after approval end as `Accepted` | **Keep both**: the colleague's answer and the approver's decision are both recorded (a new column is acceptable); a swap approved by the approvers shows as approved |
| SW-b | several open swaps for the same day (different colleagues); a double click can create the same swap twice | **One open swap per requester and day**: a second request for the same day is refused until the first is closed or cancelled; a double click never creates two |
| SW-h | the Schedule page's "to approve" list ignores substitutes (capped at 50) | **Substitutes see the swaps waiting for them**, like the manager; the caps stay |
| SW-d | when the engine approves at once, APPROVED is committed before the exchange; a changed day leaves the swap stuck Awaiting | Fix (data integrity): the exchange and the approval succeed or fail together |
| SW-g | the approval status is compared case-sensitively in the app path, upper-cased in the Hub path | Fix (consistency) |
| SW-j, SW-l, SW-n, SW-i | achievements count approved swaps; an extra read; week-lock record inside the transaction; per-prefix join | Unchanged (not asked; harmless or correct) |

**Switch:** the owner chose **no switch: all fixes are on at once** (2026-11-20, "كله على طول"), an explicit exception to the
off-by-default rule for this fix release. The golden harness declares every page and trace these fixes change.

## Design
(the architect's design follows)
