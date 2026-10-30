# Monthly Weekend Pair Configuration - Phase 2A-2 UAT

Status: implementation and test source added; tests, builds, typechecks and browser UAT have NOT RUN during this task. No UAT acceptance is recorded here.

This phase saves monthly pair arrangements. The current roster generator does not enforce these pairs. Automatic capacity changes, pair-aware generation, replacement persistence and pair-aware finalization are outside this UAT.

## Access and preparation

Use a disposable or approved UAT environment and synthetic doctors. Have the environment operator apply the existing migrations and prepare the frontend assets through the normal deployment process. No migrations, seeders, database commands, generators, Composer/npm commands or other verification commands were executed by Codex.

Sign in with an existing administrator account. Open the Monthly Setup month offered by the existing dashboard. Initial workload setup and planning-period navigation retain their existing restrictions; this phase does not bypass them. For the November examples, use a UAT period and baseline that permit November 2026 Monthly Setup. Use a separate fresh environment for the no-anchor case, because an existing anchor is intentionally immutable.

New authenticated routes:

| Route name | Method and path | Response |
| --- | --- | --- |
| `weekend-pairs.show` | GET `/weekend-pairs/{year}/{month}` | Inertia page |
| `weekend-pairs.confirm` | POST `/weekend-pairs/{year}/{month}` | Redirect with success or validation feedback |

The existing `monthly-weekend-pairs.show` and `.store` JSON endpoints retain their paths and JSON responses. New frontend links and submissions use server-generated route URLs; generated Wayfinder files were not edited or regenerated.

The new page can also be opened for a chosen year/month through its authenticated route. Monthly Setup's existing calendar access rules still apply when using the return link. There is no new month picker or preview screen.

## Browser checklist

Record the environment, browser, account, month, starting versions, expected outcome, actual outcome and pass/fail for each case. Use screenshots where useful. All entries below are pending manual verification.

1. **Fresh setup:** Open Weekend Pair Configuration with no rotation anchor. Expect both group sections to render safely, the instruction “Configure Weekend Group Rotation before setting weekend pairs,” the backend issue message, a working rotation link and disabled confirmation. Reload and verify that merely viewing did not create anchors, pair configurations, participation records or confirmations.

2. **Anchor:** Follow Weekend Group Rotation and configure the approved anchor through that existing screen. For the November legacy-boundary example, use Saturday 7 November 2026 and Group B in the fresh UAT environment. Verify the pair page return link uses the same year/month.

3. **Memberships:** Assign six eligible doctors each to A and B effective 7 November. Return to the pair page. Confirm the doctors' full names and short codes match their groups. Temporarily leave one membership missing: expect a backend issue and blocked confirmation, with a link to repair it. Restore the membership through the existing workflow. Check unsupported group sizes in a separate fixture: explain the six/seven-doctor limit and block confirmation.

4. **November six-doctor pairs:** Open November 2026. Expect effective Saturdays 14 November for A and 7 November for B, exactly three pairs per group, six selectors per group and no Doctor 7 control. A fresh configuration must begin with empty positions, not automatically assigned pairs. Select all doctors manually. Confirm the selected values stay unchanged through ordinary rerenders. The help text must describe Saturday Day, Saturday Night, Sunday Day and the Saturday Day pair's Sunday Night duty.

5. **Initial confirmation:** Complete both groups and select Confirm Weekend Pairs. Expect processing feedback and disabled repeated submission. Success must appear only after the server responds, with saved status Confirmed, saved versions and the same month still open. Refresh to verify persistence.

6. **Saved review:** Leave the page and return through Monthly Setup. Expect the saved positions and ordering, configuration versions and confirmation time. Keep the separate Weekend Group Rotation link accessible. Use keyboard Tab navigation to reach every editable control and both return links.

7. **Local pair reorder:** Select a doctor used in another position in the same group. Expect the two positions to swap, with no duplicate and no change in the other group. Exchange doctors between different pairs, not just within one pair. Expect an unsaved indicator; the saved status must not imply those edits have been confirmed. Before submitting, choose Discard local changes and verify the saved ordering returns without a request that writes data. Repeat the change for the next step.

8. **Pair-only re-confirmation:** Select Re-confirm Weekend Pairs before creating any roster. Expect success and a new version only for the changed group. The other group must retain its version. Repeat with the current unchanged form: expect the same versions and confirmation record, without a new history entry. If an old request is replayed with an obsolete revision, expect stale validation instead of another write.

9. **History:** Have the environment reviewer inspect the month's confirmation records through an approved read-only inspection method. The new record must reference the earlier record through `supersedes_confirmation_id`. Earlier records must retain their original configuration IDs, administrator and timestamps. Old configuration versions and ordered doctor positions must remain unchanged. Verify anchor Saturday and both anchor phases are identical on correction versions. No history browser is included in this phase.

10. **Duplicate and membership validation:** Normal selectors prevent duplicates by swapping. In a disposable UAT environment, use browser developer tools to replay an authenticated form POST with a duplicate within B, then a duplicate across A/B. Expect indexed errors beside the affected controls and no writes. Replay a swap of one A doctor with one B doctor in the payload: counts and global uniqueness should remain correct, but the backend must reject group membership. Check the group-level feedback. Also submit an omitted eligible doctor and an incorrect effective Saturday; expect relevant errors without partial records. Do not weaken backend validation to carry out this check.

11. **Excluded doctor:** With seven eligible A doctors initially confirmed and no roster, exclude one through the existing Monthly Setup exclusion workflow. Reload the pair page deliberately. Expect six eligible A doctors, Requires attention and preserved compatible positions with unresolved positions left blank. The excluded doctor must not appear as an eligible option. Complete all six positions and re-confirm. Expect a six-doctor version and retained confirmation history.

12. **Seven-doctor configuration:** In a fixture with seven eligible doctors, expect three pairs plus Additional Rotation Position / Doctor 7. Swap that doctor with any base-pair doctor. Expect no duplicates and no permanent “Day-only doctor” label. Check the explanation that Weekend Day has three Main doctors, Night has two, and the additional Day role rotates through all seven doctors. This page explains the existing pair algorithm; it does not change shift capacities.

13. **Six-to-seven transition:** For a future month with no roster, include a seventh eligible doctor with correct effective membership. If an exclusion was previously used, remove it only through the authorized existing UAT workflow. Reload: preserve the six compatible saved positions, leave the new position blank and require manual completion. Re-confirm and verify a seven-doctor version, unchanged anchor/phases and preserved history.

14. **Seven-to-six transition:** In another unrostered month, exclude one of seven doctors and reload. Verify exactly six positions, no automatic replacement choice, complete population validation, successful six-doctor confirmation and preserved previous seven-doctor references.

15. **December carry-forward:** After November configuration, open December 2026. Expect applicable saved ordering to carry forward, backend-derived December effective Saturdays and Ready to confirm when applicable. Confirm unchanged compatible arrangements: versions should be reused. No new versions should appear merely from loading a new month.

16. **Phase continuity:** Have the reviewer compare stored anchors/phases before and after confirmations and correction versions. Use the existing Phase 1 rotation tests/service output in the operator's verification process to confirm occurrence continuity and selection of the newest same-Saturday version. No new frontend rotation calculation or future preview screen exists. Generated roster assignments are not evidence for this phase's pair rotation.

17. **Legacy Sunday boundary:** In the first pair-enabled November fixture, expect the 31 October Saturday to be labeled legacy, with no historical pair configuration created for that date. Confirm/re-confirm November and verify the preceding Saturday is still untouched.

18. **Later Sunday boundary:** After confirming an earlier month, open August 2027, which begins on Sunday. Expect a historical boundary for 31 July, its group and configuration version, and compatibility state. In a separate fixture with incompatible effective membership at that boundary, expect Requires attention, the actual backend issue and blocked confirmation. No new configuration may be backdated to the preceding Saturday.

19. **Stale revision:** Open the same month in two browser tabs. Confirm a reorder in tab A, then submit tab B. Expect stale validation, no false success, and tab B's local choices retained. Select Reload latest configuration deliberately: it must explain that local edits are discarded, load A's saved order/version and replace the revision. Confirming the fresh state should work. Repeat with a relevant exclusion/membership change between load and submit.

20. **Draft protection:** Only after completing editable-month tests, use the existing roster workflow to create a Draft in the isolated UAT month. Return to pair configuration: expect saved positions, a read-only explanation, no editable selectors/confirmation action and a working View roster link. A replayed confirmation request must be rejected without writes. Viewing must not create versions. Draft creation here is a lock trigger; do not assess generated pair duties as part of Phase 2A-2 acceptance.

21. **Final protection:** Use a separately prepared Final fixture or finalize through the existing workflow according to its normal rules. Expect the same pair-page read-only behavior and backend refusal of changes. This phase does not add or change finalization rules.

22. **Responsive/accessibility:** Check desktop, tablet and a 320–390px mobile viewport. Group cards must fit without horizontal page overflow; long doctor names remain usable. Check visible keyboard focus, select labels, clear swap guidance, error placement, success announcements, disabled processing state, empty/missing groups, discard behavior and deliberate stale reload. Navigate away/back and verify the selected year/month on every link.

## Automation supplied

`tests/Feature/MonthlyWeekendPairUiTest.php` covers authentication, selected month and server-generated links, six/seven-doctor page data, setup issues, initial confirmation, pair-only correction, idempotency, immutable references, stale/field/group validation, exclusion mode transitions, Draft/Final protection, Sunday boundaries, carry-forward, JSON compatibility and transactional rollback through the new browser adapter. `MonthlySetupTest.php` checks the selected-month navigation URL.

These are Laravel/Inertia response tests, not browser rendering tests. There is no existing React component test runner in this project's package configuration; no testing dependency was added. Dropdown swapping, unsaved/discard behavior, inline rendering, keyboard accessibility and responsiveness require the manual checks above. All new/updated tests are NOT RUN; no build or typecheck result is claimed.

Existing backend tests remain responsible for the detailed Phase 1 algorithm, coordination contention, membership correction and historical invariants. Re-run those alongside the UI integration tests in the operator's authorized verification process. Do not interpret the previously reported Phase 2A-1 test count as verification of these new changes.
