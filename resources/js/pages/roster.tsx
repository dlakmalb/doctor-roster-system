import AppLayout from '@/components/app-layout';
import { dashboard } from '@/routes';
import { show as monthlySetup } from '@/routes/monthly-setup';
import { generate, regenerate } from '@/routes/rosters';
import { Form, Head, Link, useForm } from '@inertiajs/react';

type Slot = {
    slot_number: number;
    doctor: { name: string; short_code: string } | null;
    error: boolean;
};

type Shift = {
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
    has_generated: boolean;
    has_assignments: boolean;
    last_generated_at: string | null;
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

export default function Roster({
    month,
    status,
    has_generated,
    has_assignments,
    last_generated_at,
    summary,
    days,
}: RosterProps) {
    const regeneration = useForm<Record<string, string>>({});
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
                    <Link
                        href={monthlySetup.url(month)}
                        className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50"
                    >
                        Monthly Setup
                    </Link>
                    <Link
                        href={dashboard.url()}
                        className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50"
                    >
                        Dashboard
                    </Link>
                </div>
            </div>

            {has_generated &&
                (summary.missing_main > 0 || summary.missing_optional > 0) && (
                    <div
                        role="alert"
                        className="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800"
                    >
                        Error: {summary.missing_main} Main and{' '}
                        {summary.missing_optional} Optional required positions
                        remain unfilled.
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
                                <div key={shift.code} className="py-3">
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
                                                                    className={
                                                                        slot.error
                                                                            ? 'font-semibold text-red-700'
                                                                            : 'text-slate-700'
                                                                    }
                                                                >
                                                                    {
                                                                        slot.slot_number
                                                                    }
                                                                    .{' '}
                                                                    {slot.doctor
                                                                        ? `${slot.doctor.short_code} — ${slot.doctor.name}`
                                                                        : slot.error
                                                                          ? 'Unfilled — Error'
                                                                          : 'Unfilled'}
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
        </AppLayout>
    );
}
