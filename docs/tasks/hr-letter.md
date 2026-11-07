# HR letter request (owner request 2026-11-07)

An employee asks HR for a letter (employment letter, salary certificate, …); it goes to HR through an approval workflow.
New request type: switchable, off by default; HTML mockup before any UI (CLAUDE.md).

## Owner decisions (2026-11-07)
1. **What HR delivers is a setting**, one of three: a PDF the system generates from a template; a file HR uploads (PDF)
   on the request; or the request only (its status, the letter handed over outside the system).
2. **Letter types are a configurable list** the employee picks from, plus **"Other" with free text**.
3. **Decided through Approval Workflows** (a new workflow type; the default to be designed: HR at one level).
4. The employee chooses **who it is addressed to** ("To whom it may concern" by default) and the **language** (Arabic / English).

## Design
To be written by the architect (below), with open questions for the owner.
