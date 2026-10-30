# Doctor Roster MVP - Hospital UAT Checklist

**Scenario month:** October 2026
**Hospital reviewer:** Dr Nuwan
**Status:** Prepared for UAT; no hospital acceptance testing is recorded here.

## Before UAT

Use an isolated local or UAT environment with a roster copy approved for testing. Confirm the inputs with the hospital before interpreting the result. The repository's reference fixtures describe a development scenario and are not evidence of an approved hospital roster. Do not seed or overwrite an existing hospital roster as part of this checklist.

October 2026 has 31 dates and, with the current shift configuration, 84 shifts: 243 required Main positions and 129 required Optional positions (372 total). These are capacity totals, not a promise that every position can be staffed.

### A. Setup verification

- [ ] Active doctor list and monthly participation are confirmed.
- [ ] September historical workload values are confirmed, including actual hours, night duties, Optional count, last night, and final-weekend status.
- [ ] October Day-Off and Preferred Work requests are confirmed.
- [ ] October monthly shift restrictions and exclusions are confirmed.
- [ ] October weekend group memberships and rotation dates are confirmed.
- [ ] Shift types, times, and Main/Optional capacities match the approved scenario.

## Generated roster verification

Generate the October Draft only in the isolated UAT environment. Review the saved roster and record values from the roster summary and its validation/conflict list.

### B. Generated roster

- [ ] Main coverage and Main vacancies are recorded.
- [ ] Optional coverage and Optional vacancies are recorded. An Optional vacancy is a warning; it must not be filled by breaking a hard rule.
- [ ] No doctor has two assignments starting on the same date.
- [ ] Main-to-Main night recovery is respected; Main-to-Optional and Optional-to-Main recovery is not incorrectly imposed.
- [ ] Day-Off requests and monthly shift restrictions apply to Main assignments; Optional assignments may use those dates/shifts.
- [ ] Monthly exclusions, inactive status, and nonparticipation exclude a doctor from both roles.
- [ ] Preferred Work fulfillment is reviewed for Main assignments; an Optional assignment does not fulfill it.
- [ ] Main weekend group priorities and any cross-group exceptions are reviewed.
- [ ] Main scheduled hours and Optional assignment counts per doctor are reviewed for fairness.
- [ ] No genuine time overlap is present.

## Manual management

### C. Manual assignment management

- [ ] Replace a Main assignment and confirm eligibility feedback.
- [ ] Replace an Optional assignment and confirm role-specific eligibility feedback.
- [ ] Select a doctor who has an assignment on a different date.
- [ ] Confirm a same-date assignment is blocked, including an assignment in the other role or shift.
- [ ] Swap Main and Optional assignments; confirm both sides are validated.
- [ ] Promote an Optional assignment into an empty Main position where eligible.
- [ ] Clear an assignment and confirm the resulting vacancy and validation message.
- [ ] Undo the most recent valid change.
- [ ] Attempt a stale edit and confirm it is rejected without changing assignments.
- [ ] Attempt an invalid assignment with any soft-warning confirmation and confirm hard conflicts remain blocked.

## Validation, finalization, and documents

### D. Finalization and documents

- [ ] An unfilled Main position or hard eligibility/conflict error prevents finalization.
- [ ] Optional vacancies and other soft warnings are shown for review.
- [ ] Confirming warnings for the current warning state finalizes the roster; changing the warning state requires a fresh confirmation.
- [ ] Final status is visible and ordinary assignment edits are protected.
- [ ] Reopening follows the existing eligibility rules and preserves the roster plan.
- [ ] Print and PDF show all 31 dates in order, with Main and Optional sections, correct names/short codes, vacancies, and Draft/Final status.
- [ ] PDF and print output reflect saved planned assignments, not Actual Work replacements.

## Actual Work and history

### E. Actual Work and historical carry-forward

- [ ] Record a Main absence and confirm its actual-work effect.
- [ ] Record a Main replacement and confirm credit goes to the replacement doctor.
- [ ] Record an Optional doctor who actually worked; planned Optional assignments alone must not add worked hours.
- [ ] Confirm Actual Work and verify historical workload values, including actual night duty counts.
- [ ] Create/review the following month's setup and confirm October's confirmed values carry forward.
- [ ] Correct confirmed October Actual Work and review recalculated later history and any affected Draft warning.

## Hospital feedback record

Repeat this record for each scenario or issue. Attach a roster reference that does not expose patient information.

| Scenario | Expected result | Actual result | Pass/Fail | Comments |
| -------- | --------------- | ------------- | --------- | -------- |
|          |                 |               |           |          |
|          |                 |               |           |          |
|          |                 |               |           |          |

**Hospital reviewer confirmation (Dr Nuwan):** ______________________________
**Date:** __________________
**Open issues / follow-up owner:** __________________________________________

## Coverage reporting for local comparison

Use the same doctor population, participation, September workload, October requests, restrictions/exclusions, weekend groups, and shift capacities for each comparison run. Record the input snapshot and whether the roster was freshly generated. Do not compare runs with different inputs as generator improvements.

### Roster-level metrics

On the October roster page, record the Summary values for shifts, Main positions, Optional positions, filled Main, filled Optional, missing Main, and missing Optional. The validator/conflict list is the source for the current hard-error and warning counts; count each listed issue once. Record the exact roster year/month and Draft/Final status with the results.

### Per-doctor planned distribution (read-only SQL)

Run against a local test database or an isolated UAT database using a read-only database account. Substitute the roster year and month in the `WHERE` clause. This reports Optional assignment count and Main scheduled minutes/hours from the saved plan. It does not calculate actual worked hours.

```sql
SELECT
    d.id AS doctor_id,
    d.short_code,
    d.name,
    SUM(CASE WHEN a.role = 'optional' THEN 1 ELSE 0 END) AS optional_assignments,
    SUM(CASE WHEN a.role = 'main' THEN st.duration_minutes ELSE 0 END) AS main_scheduled_minutes,
    ROUND(SUM(CASE WHEN a.role = 'main' THEN st.duration_minutes ELSE 0 END) / 60.0, 2) AS main_scheduled_hours
FROM rosters r
JOIN roster_shifts rs ON rs.roster_id = r.id
JOIN shift_types st ON st.id = rs.shift_type_id
CROSS JOIN doctors d
LEFT JOIN roster_assignments a ON a.roster_shift_id = rs.id AND a.doctor_id = d.id
WHERE r.year = 2026 AND r.month = 10
GROUP BY d.id, d.short_code, d.name
ORDER BY d.short_code;
```

The roster page summary gives position totals and vacancies without SQL:

- Required Main = `main_positions`; filled Main = `filled_main`; Main vacancies = `missing_main`.
- Required Optional = `optional_positions`; filled Optional = `filled_optional`; Optional vacancies = `missing_optional`.
- Hard-conflict count = number of current `Error` entries in the validation list; warning count = number of current `Warning` entries.

Do not infer performance improvement from a single run. For manual performance observations, use the same isolated dataset and configuration, note the app/runtime/database versions and elapsed generation time, and do not run a benchmark against shared or hospital data.

## Code-review notes for the developer

- The committed October reference configuration describes 14 active doctors plus inactive doctor H. Shift capacities yield 84 shifts, 243 Main positions, and 129 Optional positions. The September baseline, October request set, and weekend rotation fixtures are development inputs; hospital approval remains to be confirmed. Do not run seeders to prepare UAT.
- Generation and manual assignment changes use database transactions and lock the roster row. Recovery search is bounded at depth 5 and 240 explored states; these limits can leave feasible Optional positions vacant and should not be changed without measurements.
- The candidate ranker maintains role-aware conflict cache and in-memory workload counters. Recovery repeatedly evaluates candidates; no database query is evident inside its core recursive search path from this review. Validate timing with a controlled manual observation if needed.
- The end-to-end test file checks the October workflow and fourteen-doctor completeness, while focused suites cover role eligibility, manual operations, validation/finalization, documents, Actual Work, history, and weekend groups. This checklist is a manual hospital workflow aid, not proof that UAT has occurred.
