import AppLayout from '@/components/app-layout';
import { dashboard } from '@/routes';
import { show as monthlySetup } from '@/routes/monthly-setup';
import { Head, Link } from '@inertiajs/react';

type Shift = {
    code: string;
    name: string;
    start_time: string;
    end_time: string;
    end_date_label: string | null;
    main_count: number;
    optional_count: number;
};

type Day = {
    date: string;
    label: string;
    shifts: Shift[];
};

type RosterProps = {
    month: { year: number; month: number; label: string };
    status: 'draft' | 'final';
    summary: {
        shifts: number;
        main_positions: number;
        optional_positions: number;
    };
    days: Day[];
};

export default function Roster({ month, status, summary, days }: RosterProps) {
    const summaryItems = [
        ['Total shifts', summary.shifts],
        ['Required Main positions', summary.main_positions],
        ['Required Optional positions', summary.optional_positions],
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
                            ? 'The monthly shift structure is ready. Doctor assignments will be generated in the next scheduling step.'
                            : 'This roster has been finalized.'}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
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

            <div className="grid gap-4 sm:grid-cols-3">
                {summaryItems.map(([label, value]) => (
                    <div
                        key={label}
                        className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <p className="text-sm font-medium text-slate-500">
                            {label}
                        </p>
                        <p className="mt-2 text-3xl font-semibold">{value}</p>
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
                                    className="flex flex-wrap items-start justify-between gap-2 py-3"
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
                                    </div>
                                    <p className="text-sm font-medium text-slate-700">
                                        {shift.main_count} Main /{' '}
                                        {shift.optional_count} Optional
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </AppLayout>
    );
}
