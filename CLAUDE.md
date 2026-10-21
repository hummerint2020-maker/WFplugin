# Working method

Three steps for every release: **design, implement, review.** The design and the review run as read-only subagents on the strongest available model (the Agent tool with a `model` override). They never edit, commit or push. The main session implements.

## 1. Design (the architect)

For a new feature, a new request type, a schema change, or anything touching money, permissions, the approval engine or payroll. Small fixes skip it.

- Brief the architect with the owner's decisions (docs/tasks/*.md), the mockup, and the files the change will touch.
- It returns a design: data model and upgrade path, the exact files and functions to add or change, how it stays off by default, edge cases, the test list, and the risks.
- It lists **open questions for the owner** instead of deciding them. Product decisions belong to the owner; never settle one inside a design.
- The design is saved in `docs/tasks/<feature>.md` before implementation starts.

## 2. Implement

In the main session, following the design. Where the code has to differ from the design, say so in the PR section. Run the checks: unit, phpstan, phpcs, modules, translations, and the related e2e suites.

## 3. Review

Before the release is delivered, the reviewer reads the release diff against the design. Ask for ranked findings with `file:line`, a failing scenario and a fix, marked CONFIRMED or PLAUSIBLE.

- Money, permissions, concurrency and upgrade paths are always in the brief.
- A feature that is off by default must change nothing when off.
- Fix every CONFIRMED finding (and the PLAUSIBLE ones that hold up when traced), then rerun the affected tests. The PR section says which findings were fixed and which were not, and why.

Then deliver the report and the zip. A release is never treated as approved: wait for the owner.

## Conventions

- HTML mockups before any new UI; new features are switchable and off by default.
- Always fetch and merge; never force-push. One release per approval type.
