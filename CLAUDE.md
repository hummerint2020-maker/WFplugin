# Working method

## Implement, then review (every release)

1. **Implement** in the main session: code, tests, translations, checks (unit, phpstan, phpcs, modules, translations, the related e2e suites).
2. **Review** before the release is delivered: run a read-only subagent on the strongest available model (the Agent tool with a `model` override) over the release diff. It must not edit, commit or push. Ask it for ranked findings with `file:line`, a failing scenario and a fix, marked CONFIRMED or PLAUSIBLE.
   - Money, permissions, concurrency and upgrade paths are always in the brief.
   - A feature that is off by default must change nothing when off.
3. **Fix** every CONFIRMED finding in the main session (and the PLAUSIBLE ones that hold up when traced), then rerun the affected tests. Say in the PR section which findings were fixed and which were not, and why.
4. **Deliver** the report and the zip. A release is never treated as approved: wait for the owner.

## Conventions

- HTML mockups before any new UI; new features are switchable and off by default.
- Always fetch and merge; never force-push. One release per approval type.
