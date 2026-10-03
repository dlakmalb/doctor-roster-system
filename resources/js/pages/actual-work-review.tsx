import AppLayout from '@/components/app-layout';
import { show as showRoster } from '@/routes/rosters';
import { confirm, remove, save } from '@/routes/rosters/actual-work';
import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Doctor = { id: number; name: string; short_code: string };
type Exception = {
    id: number;
    type: 'main_absent' | 'replacement' | 'optional_worked';
    actual_doctor: Doctor | null;
};
type Assignment = {
    id: number;
    role: 'main' | 'optional';
    slot_number: number;
    doctor: Doctor;
    exception: Exception | null;
};
type Shift = {
    id: number;
    date: string;
    name: string;
    duration_minutes: number;
    assignments: Assignment[];
};
type Preview = {
    average: number;
    rows: {
        doctor_id: number;
        name: string;
        short_code: string;
        actual_worked_minutes: number;
        actual_night_duty_count: number;
        optional_assignment_count: number;
        worked_final_weekend: boolean;
        most_recent_night_shift_at: string | null;
        is_month_excluded: boolean;
        opening_balance_minutes: number;
        monthly_adjustment_minutes: number;
        closing_balance_minutes: number;
    }[];
};
type Props = {
    month: { year: number; month: number; label: string };
    status: 'draft' | 'final';
    confirmed_at: string | null;
    shifts: Shift[];
    doctors: Doctor[];
    summary: {
        main_absences: number;
        replacements: number;
        optionals_worked: number;
    };
    preview: Preview;
};

function AssignmentCard({
    assignment,
    month,
    doctors,
    editable,
}: {
    assignment: Assignment;
    month: Props['month'];
    doctors: Doctor[];
    editable: boolean;
}) {
    const [replacementId, setReplacementId] = useState(
        assignment.exception?.type === 'replacement'
            ? String(assignment.exception.actual_doctor?.id ?? '')
            : '',
    );
    const [processing, setProcessing] = useState(false);
    const status =
        assignment.exception?.type === 'main_absent'
            ? 'Absent'
            : assignment.exception?.type === 'replacement'
              ? `Replacement: ${assignment.exception.actual_doctor?.short_code ?? ''} ${assignment.exception.actual_doctor?.name ?? ''}`
              : assignment.exception?.type === 'optional_worked'
                ? 'Worked'
                : assignment.role === 'main'
                  ? 'Assumed Worked'
                  : 'Not Worked';
    function record(type: Exception['type']) {
        setProcessing(true);
        router.put(
            save.url({ ...month, assignment: assignment.id }),
            {
                exception_type: type,
                actual_doctor_id:
                    type === 'replacement'
                        ? Number(replacementId) || null
                        : null,
            },
            { onFinish: () => setProcessing(false), preserveScroll: true },
        );
    }

    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                        {assignment.role} {assignment.slot_number}
                    </p>
                    <p className="font-semibold">
                        {assignment.doctor.short_code} —{' '}
                        {assignment.doctor.name}
                    </p>
                    <p className="mt-1 text-sm text-slate-700">{status}</p>
                </div>
            </div>
            {editable && (
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    {assignment.role === 'main' ? (
                        <>
                            <button
                                type="button"
                                disabled={processing}
                                onClick={() => record('main_absent')}
                                className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium disabled:opacity-50"
                            >
                                Mark Absent
                            </button>
                            <label
                                className="text-sm font-medium"
                                htmlFor={`replacement-${assignment.id}`}
                            >
                                Replacement doctor
                            </label>
                            <select
                                id={`replacement-${assignment.id}`}
                                value={replacementId}
                                onChange={(event) =>
                                    setReplacementId(event.target.value)
                                }
                                className="max-w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            >
                                <option value="">Select doctor</option>
                                {doctors
                                    .filter(
                                        (doctor) =>
                                            doctor.id !== assignment.doctor.id,
                                    )
                                    .map((doctor) => (
                                        <option
                                            value={doctor.id}
                                            key={doctor.id}
                                        >
                                            {doctor.short_code} — {doctor.name}
                                        </option>
                                    ))}
                            </select>
                            <button
                                type="button"
                                disabled={processing || !replacementId}
                                onClick={() => record('replacement')}
                                className="rounded-lg bg-teal-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                Record Replacement
                            </button>
                        </>
                    ) : (
                        <button
                            type="button"
                            disabled={processing}
                            onClick={() => record('optional_worked')}
                            className="rounded-lg bg-teal-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        >
                            Mark Worked
                        </button>
                    )}
                    {assignment.exception && (
                        <Form
                            action={remove.url({
                                ...month,
                                exception: assignment.exception.id,
                            })}
                            method="delete"
                            options={{ preserveScroll: true }}
                        >
                            {({ processing: removing }) => (
                                <button
                                    disabled={removing}
                                    className="rounded-lg border border-red-300 px-3 py-2 text-sm font-medium text-red-700 disabled:opacity-50"
                                >
                                    Remove Exception
                                </button>
                            )}
                        </Form>
                    )}
                </div>
            )}
        </div>
    );
}

export default function ActualWorkReview({
    month,
    status,
    confirmed_at,
    shifts,
    doctors,
    summary,
    preview,
}: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    return (
        <AppLayout title={`Actual Work Review — ${month.label}`}>
            <Head title={`Actual Work Review — ${month.label}`} />
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <Link
                    href={showRoster.url(month)}
                    className="text-sm font-semibold text-teal-700"
                >
                    ← Planned roster
                </Link>
                <span className="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium">
                    {confirmed_at
                        ? `Confirmed — corrections update workload history (${confirmed_at})`
                        : 'Unconfirmed'}
                </span>
            </div>
            {status !== 'final' && (
                <p className="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    Actual work can be confirmed only for a Final roster.
                </p>
            )}
            {Object.values(errors).length > 0 && (
                <div
                    role="alert"
                    className="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
                >
                    {Object.values(errors).join(' ')}
                </div>
            )}
            <div className="mb-6 grid gap-3 sm:grid-cols-4">
                {(
                    [
                        ['Main absences', summary.main_absences],
                        ['Replacements', summary.replacements],
                        ['Optionals worked', summary.optionals_worked],
                        ['Group average', `${preview.average} min`],
                    ] as const
                ).map(([label, value]) => (
                    <div
                        key={label}
                        className="rounded-xl border border-slate-200 bg-white p-4"
                    >
                        <p className="text-sm text-slate-500">{label}</p>
                        <p className="mt-1 text-2xl font-semibold">{value}</p>
                    </div>
                ))}
            </div>
            <div className="space-y-5">
                {shifts.map((shift) => (
                    <section
                        key={shift.id}
                        className="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5"
                    >
                        <h2 className="mb-3 font-semibold">
                            {shift.date} · {shift.name} ·{' '}
                            {shift.duration_minutes} min
                        </h2>
                        <div className="grid gap-3 lg:grid-cols-2">
                            {shift.assignments.map((assignment) => (
                                <AssignmentCard
                                    key={assignment.id}
                                    assignment={assignment}
                                    month={month}
                                    doctors={doctors}
                                    editable={status === 'final'}
                                />
                            ))}
                        </div>
                    </section>
                ))}
            </div>
            <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-5">
                <h2 className="text-xl font-semibold">
                    Projected doctor history
                </h2>
                <p className="mt-1 text-sm text-slate-600">
                    Actual work, planned Optional assignments, and closing
                    workload balance before confirmation.
                </p>
                <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {preview.rows.map((row) => (
                        <div
                            key={row.doctor_id}
                            className="rounded-xl border border-slate-200 p-4 text-sm"
                        >
                            <h3 className="font-semibold">
                                {row.short_code} — {row.name}
                            </h3>
                            <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1">
                                <dt>Actual work</dt>
                                <dd>{row.actual_worked_minutes} min</dd>
                                <dt>Actual Nights</dt>
                                <dd>{row.actual_night_duty_count}</dd>
                                <dt>Planned Optional</dt>
                                <dd>{row.optional_assignment_count}</dd>
                                <dt>Final weekend</dt>
                                <dd>
                                    {row.worked_final_weekend
                                        ? 'Worked'
                                        : 'Not worked'}
                                </dd>
                                <dt>Most recent Night</dt>
                                <dd>
                                    {row.most_recent_night_shift_at ?? 'None'}
                                </dd>
                                <dt>Monthly exclusion</dt>
                                <dd>
                                    {row.is_month_excluded
                                        ? 'Excluded'
                                        : 'Included'}
                                </dd>
                                <dt>Opening balance</dt>
                                <dd>{row.opening_balance_minutes} min</dd>
                                <dt>Adjustment</dt>
                                <dd>{row.monthly_adjustment_minutes} min</dd>
                                <dt>Closing balance</dt>
                                <dd className="font-semibold">
                                    {row.closing_balance_minutes} min
                                </dd>
                            </dl>
                        </div>
                    ))}
                </div>
            </section>
            {status === 'final' && (
                <Form
                    action={confirm.url(month)}
                    method="post"
                    className="mt-6"
                >
                    {({ processing }) => (
                        <button
                            disabled={processing}
                            className="rounded-lg bg-teal-700 px-5 py-3 font-semibold text-white disabled:opacity-50"
                        >
                            Confirm Actual Work
                        </button>
                    )}
                </Form>
            )}
        </AppLayout>
    );
}
