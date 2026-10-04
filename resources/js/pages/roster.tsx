import AppLayout from '@/components/app-layout';
import { dashboard } from '@/routes';
import { show as monthlySetup } from '@/routes/monthly-setup';
import {
    assignmentOptions,
    finalize,
    generate,
    pdf,
    print,
    regenerate,
    reopen,
    show as showRoster,
} from '@/routes/rosters';
import { edit, undo } from '@/routes/rosters/assignments';
import { show as showActualWork } from '@/routes/rosters/actual-work';
import { show as showInitialWorkload } from '@/routes/initial-workload';
import { Form, Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Slot = {
    slot_number: number;
    assignment_id: number | null;
    doctor_id: number | null;
    doctor: { name: string; short_code: string } | null;
    severity: 'Error' | 'Warning' | null;
};

type Shift = {
    id: number;
    code: string;
    name: string;
    start_time: string;
    end_time: string;
    end_date_label: string | null;
    main_count: number;
    optional_count: number;
    main: Slot[];
    optional: Slot[];
};

type Day = {
    date: string;
    label: string;
    shifts: Shift[];
};

type RosterProps = {
    month: { year: number; month: number; label: string };
    status: 'draft' | 'final';
    can_reopen: boolean;
    history_readiness: {
        ready: boolean;
        message: string | null;
        year: number;
        month: number;
        action: 'review' | 'initial_setup' | 'finalize';
        basis: 'confirmed_actual' | 'final_planned' | 'manual_initial' | null;
    };
    has_generated: boolean;
    has_assignments: boolean;
    last_generated_at: string | null;
    can_undo: boolean;
    conflicts: {
        severity: 'Error' | 'Warning';
        code: string;
        message: string;
        target: string;
    }[];
    summary: {
        shifts: number;
        main_positions: number;
        optional_positions: number;
        filled_main: number;
        filled_optional: number;
        missing_main: number;
        missing_optional: number;
    };
    days: Day[];
};

type SelectedSlot = {
    shift_id: number;
    role: 'main' | 'optional';
    slot_number: number;
    expected_assignment_id: number | null;
    expected_doctor_id: number | null;
};

type DoctorOption = {
    id: number;
    short_code: string;
    name: string;
    eligible: boolean;
    reasons: string[];
    preferred_work: boolean;
    source_assignment_id: number | null;
    effective_workload_minutes: number;
    night_count: number;
    optional_count: number;
};

function errorMessage(error: unknown): string {
    if (typeof error === 'object' && error !== null && 'errors' in error) {
        const errors = error.errors;
        if (typeof errors === 'object' && errors !== null) {
            const first = Object.values(errors)[0];
            if (Array.isArray(first) && typeof first[0] === 'string')
                return first[0];
        }
    }
    return 'The request could not be completed. Refresh and try again.';
}

async function jsonRequest<T>(
    url: string,
    method: 'GET' | 'POST',
    data?: object,
): Promise<T> {
    const csrf = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(csrf ? { 'X-XSRF-TOKEN': decodeURIComponent(csrf) } : {}),
        },
        ...(data ? { body: JSON.stringify(data) } : {}),
    });
    const payload: unknown = await response.json();
    if (!response.ok) throw payload;
    return payload as T;
}

export default function Roster({
    month,
    status,
    can_reopen,
    history_readiness,
    has_generated,
    has_assignments,
    last_generated_at,
    can_undo,
    conflicts,
    summary,
    days,
}: RosterProps) {
    const regeneration = useForm<Record<string, string>>({});
    const reopening = useForm<Record<string, string>>({});
    const [picker, setPicker] = useState<SelectedSlot | null>(null);
    const [options, setOptions] = useState<DoctorOption[]>([]);
    const [swapSource, setSwapSource] = useState<SelectedSlot | null>(null);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [warnings, setWarnings] = useState<string[]>([]);
    const [finalizationWarnings, setFinalizationWarnings] = useState<
        RosterProps['conflicts']
    >([]);
    const [warningSignature, setWarningSignature] = useState<string | null>(
        null,
    );
    const [pending, setPending] = useState<Record<
        string,
        string | number | boolean | null
    > | null>(null);
    const preGenerationDraft = status === 'draft' && !has_generated;
    const visibleConflicts = preGenerationDraft
        ? conflicts.filter(
              (item) =>
                  item.code !== 'unfilled_main_slot' &&
                  item.code !== 'unfilled_optional_slot',
          )
        : conflicts;
    const errorCount = visibleConflicts.filter(
        (item) => item.severity === 'Error',
    ).length;
    const warningCount = visibleConflicts.length - errorCount;

    function selected(
        shift: Shift,
        role: 'main' | 'optional',
        slot: Slot,
    ): SelectedSlot {
        return {
            shift_id: shift.id,
            role,
            slot_number: slot.slot_number,
            expected_assignment_id: slot.assignment_id,
            expected_doctor_id: slot.doctor_id,
        };
    }

    async function openPicker(target: SelectedSlot) {
        setMessage(null);
        setBusy(true);
        try {
            const query = new URLSearchParams(
                Object.entries(target).map(([key, value]) => [
                    key,
                    value === null ? '' : String(value),
                ]),
            );
            const result = await jsonRequest<{ options: DoctorOption[] }>(
                `${assignmentOptions.url(month)}?${query}`,
                'GET',
            );
            setOptions(result.options);
            setPicker(target);
        } catch (error) {
            setMessage(errorMessage(error));
        } finally {
            setBusy(false);
        }
    }

    async function save(
        payload: Record<string, string | number | boolean | null>,
        confirmed = false,
    ) {
        setBusy(true);
        setMessage(null);
        try {
            const result = await jsonRequest<{
                status: string;
                warnings: string[];
            }>(edit.url(month), 'POST', {
                ...payload,
                confirm_soft_override: confirmed,
            });
            if (result.status === 'confirmation_required') {
                setWarnings(result.warnings);
                setPending(payload);
                return;
            }
            setPicker(null);
            setSwapSource(null);
            setWarnings([]);
            setPending(null);
            router.reload();
        } catch (error) {
            setMessage(errorMessage(error));
        } finally {
            setBusy(false);
        }
    }

    async function undoLast() {
        setBusy(true);
        setMessage(null);
        try {
            await jsonRequest(undo.url(month), 'POST', {});
            router.reload();
        } catch (error) {
            setMessage(errorMessage(error));
        } finally {
            setBusy(false);
        }
    }

    async function finalizeRoster(signature: string | null = null) {
        setBusy(true);
        setMessage(null);
        try {
            const result = await jsonRequest<{
                status:
                    | 'finalized'
                    | 'errors'
                    | 'confirmation_required'
                    | 'stale_confirmation';
                errors: RosterProps['conflicts'];
                warnings: RosterProps['conflicts'];
                warning_signature: string | null;
            }>(finalize.url(month), 'POST', { warning_signature: signature });
            if (result.status === 'finalized') {
                setFinalizationWarnings([]);
                setWarningSignature(null);
                setPicker(null);
                setSwapSource(null);
                router.reload();
            } else if (result.status === 'errors') {
                setFinalizationWarnings([]);
                setWarningSignature(null);
                setMessage(
                    preGenerationDraft
                        ? 'This roster cannot be finalized because required assignments are still unfilled.'
                        : `This roster cannot be finalized because it has ${result.errors.length} validation error(s). Review Live validation. ${result.errors
                              .slice(0, 3)
                              .map((error) => error.message)
                              .join(' ')}`,
                );
            } else {
                setFinalizationWarnings(result.warnings);
                setWarningSignature(result.warning_signature);
                if (result.status === 'stale_confirmation') {
                    setMessage(
                        'Warnings changed. Review the current warnings before confirming again.',
                    );
                }
            }
        } catch (error) {
            setMessage(errorMessage(error));
        } finally {
            setBusy(false);
        }
    }

    function clickSwapTarget(target: SelectedSlot) {
        if (!swapSource || !target.expected_assignment_id) return;
        void save({
            operation: 'swap',
            ...swapSource,
            target_shift_id: target.shift_id,
            target_role: target.role,
            target_slot_number: target.slot_number,
            target_expected_assignment_id: target.expected_assignment_id,
            target_expected_doctor_id: target.expected_doctor_id,
        });
    }
    const summaryItems = [
        ['Total shifts', summary.shifts],
        [
            'Main positions',
            `${summary.filled_main} / ${summary.main_positions} filled`,
        ],
        [
            'Optional positions',
            `${summary.filled_optional} / ${summary.optional_positions} filled`,
        ],
    ];
    const validationItems = (
        <ul className="mt-3 max-h-72 space-y-2 overflow-y-auto text-sm">
            {visibleConflicts.map((item, index) => (
                <li key={`${item.target}-${index}`}>
                    <button
                        type="button"
                        onClick={() =>
                            document
                                .getElementById(item.target)
                                ?.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'center',
                                })
                        }
                        className="text-left underline underline-offset-2"
                    >
                        <strong>{item.severity}</strong> - {item.message}
                    </button>
                </li>
            ))}
        </ul>
    );

    return (
        <AppLayout title={`${month.label} Roster`}>
            <Head title={`${month.label} Roster`} />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-3">
                    <span className="rounded-full bg-teal-100 px-3 py-1 text-sm font-semibold text-teal-800">
                        {status === 'draft' ? 'Draft' : 'Final'}
                    </span>
                    <p className="text-sm text-slate-600">
                        {status === 'draft'
                            ? has_generated
                                ? `Last generated: ${last_generated_at}`
                                : 'The monthly shift structure is ready for assignments.'
                            : 'This roster has been finalized.'}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {status === 'draft' && can_undo && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => void undoLast()}
                            className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold disabled:opacity-50"
                        >
                            Undo Last Change
                        </button>
                    )}
                    {status === 'draft' && swapSource && (
                        <button
                            type="button"
                            onClick={() => setSwapSource(null)}
                            className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold"
                        >
                            Cancel Swap
                        </button>
                    )}
                    {status === 'draft' && (
                        <Form {...generate.form(month)}>
                            {({ processing, errors }) => (
                                <div>
                                    <button
                                        disabled={processing}
                                        type="submit"
                                        className="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50"
                                    >
                                        {processing
                                            ? 'Generating…'
                                            : has_generated
                                              ? 'Fill Unfilled Slots'
                                              : 'Generate Assignments'}
                                    </button>
                                    {errors.roster && (
                                        <p className="text-sm text-red-700">
                                            {errors.roster}
                                        </p>
                                    )}
                                </div>
                            )}
                        </Form>
                    )}
                    {status === 'draft' && has_assignments && (
                        <div>
                            <button
                                disabled={regeneration.processing}
                                type="button"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            'Regenerating will replace all current Draft assignments for this month. Continue?',
                                        )
                                    ) {
                                        regeneration.post(
                                            regenerate.url(month),
                                        );
                                    }
                                }}
                                className="rounded-lg border border-red-600 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50"
                            >
                                {regeneration.processing
                                    ? 'Regenerating…'
                                    : 'Regenerate Assignments'}
                            </button>
                            {regeneration.errors.roster && (
                                <p className="text-sm text-red-700">
                                    {regeneration.errors.roster}
                                </p>
                            )}
                        </div>
                    )}
                    {status === 'draft' && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => void finalizeRoster()}
                            className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        >
                            Finalize Roster
                        </button>
                    )}
                    {status === 'final' && can_reopen && (
                        <button
                            type="button"
                            disabled={reopening.processing}
                            onClick={() => {
                                if (
                                    window.confirm(
                                        'Reopening will return this roster to Draft and allow the assignments to be changed. Continue?',
                                    )
                                ) {
                                    reopening.post(reopen.url(month));
                                }
                            }}
                            className="rounded-lg border border-slate-500 bg-white px-4 py-2 text-sm font-semibold disabled:opacity-50"
                        >
                            Reopen Roster
                        </button>
                    )}
                    <a
                        href={print.url(month)}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold"
                    >
                        Preview / Print
                    </a>
                    <a
                        href={pdf.url(month)}
                        className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold"
                    >
                        Download PDF
                    </a>
                    <Link
                        href={monthlySetup.url(month)}
                        className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50"
                    >
                        Monthly Setup
                    </Link>
                    {status === 'final' && (
                        <Link
                            href={showActualWork.url(month)}
                            className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50"
                        >
                            Actual Work Review
                        </Link>
                    )}
                    <Link
                        href={dashboard.url()}
                        className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50"
                    >
                        Dashboard
                    </Link>
                </div>
            </div>

            {status === 'draft' && !history_readiness.ready && (
                <div
                    role="alert"
                    className="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                >
                    <p>{history_readiness.message}</p>
                    <Link
                        href={
                            history_readiness.action === 'review'
                                ? showActualWork.url(history_readiness)
                                : history_readiness.action === 'finalize'
                                  ? showRoster.url(history_readiness)
                                  : showInitialWorkload.url(history_readiness)
                        }
                        className="mt-2 inline-block font-semibold underline"
                    >
                        {history_readiness.action === 'review'
                            ? 'Review previous month'
                            : history_readiness.action === 'finalize'
                              ? 'Finalize previous roster'
                              : 'Open Initial Setup'}
                    </Link>
                </div>
            )}

            {message && (
                <div
                    role="alert"
                    className="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"
                >
                    {message}
                </div>
            )}
            {reopening.errors.roster && (
                <p role="alert" className="mb-4 text-sm text-red-700">
                    {reopening.errors.roster}
                </p>
            )}
            {status === 'final' && !can_reopen && (
                <p className="mb-4 text-sm text-slate-600">
                    Actual-work review has started. Correct historical actual
                    work from the Previous Month Review screen.
                </p>
            )}
            {status === 'draft' && swapSource && (
                <div
                    role="status"
                    className="mb-4 rounded-lg bg-sky-50 p-3 text-sm text-sky-900"
                >
                    Swap mode: choose another occupied slot.
                </div>
            )}

            <section
                id="conflicts"
                className="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"
            >
                {preGenerationDraft ? (
                    <>
                        <h2 className="font-semibold">
                            Roster not generated yet
                        </h2>
                        <p className="mt-1 text-sm text-slate-600">
                            The monthly shift structure is ready. Generate
                            assignments to fill the roster&apos;s Main and
                            Optional positions.
                        </p>
                        <p className="mt-1 text-sm text-slate-600">
                            {summary.missing_main + summary.missing_optional}{' '}
                            positions remaining.
                        </p>
                        {visibleConflicts.length > 0 && (
                            <>
                                <p className="mt-3 text-sm">
                                    Errors: {errorCount} · Warnings:{' '}
                                    {warningCount}
                                </p>
                                {validationItems}
                            </>
                        )}
                    </>
                ) : (
                    <>
                        <h2 className="font-semibold">Live validation</h2>
                        <p className="mt-1 text-sm">
                            Errors: {errorCount} · Warnings: {warningCount}
                        </p>
                        {visibleConflicts.length === 0 ? (
                            <p className="mt-2 text-sm text-teal-800">
                                No roster conflicts detected.
                            </p>
                        ) : (
                            validationItems
                        )}
                    </>
                )}
            </section>

            {has_generated && summary.missing_main > 0 && (
                <div
                    role="alert"
                    className="mb-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800"
                >
                    Error: {summary.missing_main} Main required{' '}
                    {summary.missing_main === 1 ? 'position' : 'positions'}{' '}
                    remain unfilled. All Main positions must be filled before
                    Finalization.
                </div>
            )}
            {has_generated && summary.missing_optional > 0 && (
                <div
                    role="alert"
                    className="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-800"
                >
                    Warning: {summary.missing_optional} Optional{' '}
                    {summary.missing_optional === 1 ? 'position' : 'positions'}{' '}
                    {status === 'final'
                        ? `${summary.missing_optional === 1 ? 'was' : 'were'} left unfilled when this roster was finalized.`
                        : `${summary.missing_optional === 1 ? 'remains' : 'remain'} unfilled. You may still Finalize after reviewing the warnings.`}
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-3">
                {summaryItems.map(([label, value]) => (
                    <div
                        key={label}
                        className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <p className="text-sm font-medium text-slate-500">
                            {label}
                        </p>
                        <p className="mt-2 text-2xl font-semibold">{value}</p>
                    </div>
                ))}
            </div>

            <div className="mt-6 grid gap-4 lg:grid-cols-2">
                {days.map((day) => (
                    <section
                        key={day.date}
                        className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
                    >
                        <h2 className="text-lg font-semibold">{day.label}</h2>
                        <div className="mt-4 divide-y divide-slate-100">
                            {day.shifts.map((shift) => (
                                <div
                                    key={shift.code}
                                    id={`shift-${shift.id}`}
                                    className="scroll-mt-6 py-3"
                                >
                                    <div>
                                        <h3 className="font-semibold">
                                            {shift.name}
                                        </h3>
                                        <p className="mt-0.5 text-sm text-slate-600">
                                            {shift.start_time} →{' '}
                                            {shift.end_date_label &&
                                                `${shift.end_date_label} `}
                                            {shift.end_time}
                                        </p>
                                        {visibleConflicts
                                            .filter(
                                                (item) =>
                                                    item.target ===
                                                    `shift-${shift.id}`,
                                            )
                                            .map((item, index) => (
                                                <p
                                                    key={index}
                                                    className="mt-1 text-xs text-amber-800"
                                                >
                                                    {item.severity} -{' '}
                                                    {item.message}
                                                </p>
                                            ))}
                                    </div>
                                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                        {(['main', 'optional'] as const).map(
                                            (role) => (
                                                <div key={role}>
                                                    <h4 className="text-sm font-semibold text-slate-700 capitalize">
                                                        {role}
                                                    </h4>
                                                    <ol className="mt-1 space-y-1 text-sm">
                                                        {shift[role].map(
                                                            (slot) => (
                                                                <li
                                                                    key={
                                                                        slot.slot_number
                                                                    }
                                                                    id={`slot-${shift.id}-${role}-${slot.slot_number}`}
                                                                    className={
                                                                        preGenerationDraft ||
                                                                        !slot.severity
                                                                            ? 'scroll-mt-6 rounded-lg border border-slate-100 p-2 text-slate-700'
                                                                            : slot.severity ===
                                                                                'Error'
                                                                              ? 'scroll-mt-6 rounded-lg border border-red-200 bg-red-50 p-2 font-semibold text-red-700'
                                                                              : 'scroll-mt-6 rounded-lg border border-amber-200 bg-amber-50 p-2 font-semibold text-amber-800'
                                                                    }
                                                                >
                                                                    <span className="font-medium capitalize">
                                                                        {role}{' '}
                                                                        slot{' '}
                                                                        {
                                                                            slot.slot_number
                                                                        }
                                                                        :{' '}
                                                                    </span>
                                                                    {slot.doctor
                                                                        ? `${slot.doctor.short_code} - ${slot.doctor.name}`
                                                                        : slot.severity &&
                                                                            !preGenerationDraft
                                                                          ? `Unfilled - ${slot.severity}`
                                                                          : 'Unfilled'}
                                                                    {visibleConflicts
                                                                        .filter(
                                                                            (
                                                                                item,
                                                                            ) =>
                                                                                item.target ===
                                                                                `slot-${shift.id}-${role}-${slot.slot_number}`,
                                                                        )
                                                                        .map(
                                                                            (
                                                                                item,
                                                                                index,
                                                                            ) => (
                                                                                <p
                                                                                    key={
                                                                                        index
                                                                                    }
                                                                                    className="mt-1 text-xs"
                                                                                >
                                                                                    {
                                                                                        item.severity
                                                                                    }{' '}
                                                                                    -{' '}
                                                                                    {
                                                                                        item.message
                                                                                    }
                                                                                </p>
                                                                            ),
                                                                        )}
                                                                    {status ===
                                                                        'draft' && (
                                                                        <div className="mt-2 flex flex-wrap gap-2">
                                                                            {swapSource ? (
                                                                                slot.doctor && (
                                                                                    <button
                                                                                        type="button"
                                                                                        disabled={
                                                                                            busy
                                                                                        }
                                                                                        onClick={() =>
                                                                                            clickSwapTarget(
                                                                                                selected(
                                                                                                    shift,
                                                                                                    role,
                                                                                                    slot,
                                                                                                ),
                                                                                            )
                                                                                        }
                                                                                        className="rounded border border-sky-300 px-2 py-1 text-xs font-semibold text-sky-800 disabled:opacity-50"
                                                                                    >
                                                                                        Select
                                                                                        for
                                                                                        Swap
                                                                                    </button>
                                                                                )
                                                                            ) : (
                                                                                <>
                                                                                    <button
                                                                                        type="button"
                                                                                        disabled={
                                                                                            busy
                                                                                        }
                                                                                        onClick={() =>
                                                                                            void openPicker(
                                                                                                selected(
                                                                                                    shift,
                                                                                                    role,
                                                                                                    slot,
                                                                                                ),
                                                                                            )
                                                                                        }
                                                                                        className="rounded border border-teal-300 px-2 py-1 text-xs font-semibold text-teal-800 disabled:opacity-50"
                                                                                    >
                                                                                        {slot.doctor
                                                                                            ? 'Replace'
                                                                                            : 'Assign Doctor'}
                                                                                    </button>
                                                                                    {slot.doctor && (
                                                                                        <button
                                                                                            type="button"
                                                                                            disabled={
                                                                                                busy
                                                                                            }
                                                                                            onClick={() =>
                                                                                                setSwapSource(
                                                                                                    selected(
                                                                                                        shift,
                                                                                                        role,
                                                                                                        slot,
                                                                                                    ),
                                                                                                )
                                                                                            }
                                                                                            className="rounded border border-sky-300 px-2 py-1 text-xs font-semibold text-sky-800 disabled:opacity-50"
                                                                                        >
                                                                                            Swap
                                                                                        </button>
                                                                                    )}
                                                                                    {slot.doctor && (
                                                                                        <button
                                                                                            type="button"
                                                                                            disabled={
                                                                                                busy
                                                                                            }
                                                                                            onClick={() => {
                                                                                                if (
                                                                                                    window.confirm(
                                                                                                        'Clearing this assignment will leave the slot unfilled. Continue?',
                                                                                                    )
                                                                                                )
                                                                                                    void save(
                                                                                                        {
                                                                                                            operation:
                                                                                                                'clear',
                                                                                                            ...selected(
                                                                                                                shift,
                                                                                                                role,
                                                                                                                slot,
                                                                                                            ),
                                                                                                        },
                                                                                                    );
                                                                                            }}
                                                                                            className="rounded border border-red-300 px-2 py-1 text-xs font-semibold text-red-700 disabled:opacity-50"
                                                                                        >
                                                                                            Clear
                                                                                        </button>
                                                                                    )}
                                                                                </>
                                                                            )}
                                                                        </div>
                                                                    )}
                                                                </li>
                                                            ),
                                                        )}
                                                    </ol>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>
                ))}
            </div>

            {status === 'draft' && picker && (
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-label="Choose a doctor"
                    className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-3 sm:p-6"
                >
                    <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-4 shadow-xl sm:p-6">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-lg font-semibold">
                                {picker.expected_assignment_id
                                    ? 'Replace doctor'
                                    : 'Assign doctor'}
                            </h2>
                            <button
                                type="button"
                                onClick={() => setPicker(null)}
                                className="rounded border border-slate-300 px-3 py-1.5 text-sm"
                            >
                                Close
                            </button>
                        </div>
                        <p className="mt-1 text-sm text-slate-600">
                            Eligible doctors are ordered by scheduling
                            preference. Choose any eligible doctor.
                        </p>
                        <ul className="mt-4 space-y-2">
                            {options.map((doctor) => (
                                <li
                                    key={doctor.id}
                                    className="rounded-lg border border-slate-200 p-3 text-sm"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p className="font-semibold">
                                                {doctor.short_code} -{' '}
                                                {doctor.name}
                                            </p>
                                            <p className="text-slate-600">
                                                Workload:{' '}
                                                {
                                                    doctor.effective_workload_minutes
                                                }{' '}
                                                min · Night:{' '}
                                                {doctor.night_count} · Optional:{' '}
                                                {doctor.optional_count}
                                            </p>
                                            {doctor.preferred_work && (
                                                <p className="font-semibold text-teal-800">
                                                    {picker.role === 'optional'
                                                        ? 'Preferred Work - Main request'
                                                        : 'Preferred Work'}
                                                </p>
                                            )}
                                            {!doctor.eligible && (
                                                <p className="text-red-700">
                                                    Unavailable -{' '}
                                                    {doctor.reasons.join(' ')}
                                                </p>
                                            )}
                                        </div>
                                        <button
                                            type="button"
                                            disabled={
                                                busy ||
                                                !doctor.eligible ||
                                                doctor.id ===
                                                    picker.expected_doctor_id
                                            }
                                            onClick={() =>
                                                void save({
                                                    operation: 'replace',
                                                    ...picker,
                                                    doctor_id: doctor.id,
                                                    expected_source_assignment_id:
                                                        doctor.source_assignment_id,
                                                })
                                            }
                                            className="rounded-lg bg-teal-700 px-3 py-2 font-semibold text-white disabled:opacity-50"
                                        >
                                            Select
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}
            {status === 'draft' && pending && (
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-label="Confirm scheduling warnings"
                    className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/70 p-3"
                >
                    <div className="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
                        <h2 className="text-lg font-semibold">
                            Confirm scheduling warning
                        </h2>
                        <ul className="mt-3 list-disc space-y-2 pl-5 text-sm">
                            {warnings.map((warning) => (
                                <li key={warning}>{warning}</li>
                            ))}
                        </ul>
                        <div className="mt-5 flex flex-wrap gap-2">
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => void save(pending, true)}
                                className="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                Continue and save
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setPending(null);
                                    setWarnings([]);
                                }}
                                className="rounded-lg border border-slate-300 px-4 py-2 text-sm"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            )}
            {finalizationWarnings.length > 0 && warningSignature && (
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-label="Finalize with warnings"
                    className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-900/70 p-3"
                >
                    <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-5 shadow-xl">
                        <h2 className="text-lg font-semibold">
                            Finalize with warnings?
                        </h2>
                        {message && (
                            <p
                                role="alert"
                                className="mt-2 text-sm text-red-700"
                            >
                                {message}
                            </p>
                        )}
                        <ul className="mt-3 list-disc space-y-2 pl-5 text-sm">
                            {finalizationWarnings.map((warning, index) => (
                                <li key={`${warning.target}-${index}`}>
                                    <strong>Warning</strong> - {warning.message}
                                </li>
                            ))}
                        </ul>
                        <div className="mt-5 flex flex-wrap gap-2">
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() =>
                                    void finalizeRoster(warningSignature)
                                }
                                className="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                Finalize Anyway
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setFinalizationWarnings([]);
                                    setWarningSignature(null);
                                }}
                                className="rounded-lg border border-slate-300 px-4 py-2 text-sm"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
