import AppLayout from '@/components/app-layout';
import { show as monthlySetup } from '@/routes/monthly-setup';
import { show as showRoster } from '@/routes/rosters';
import { show as showActualWork } from '@/routes/rosters/actual-work';
import { show as showInitialWorkload } from '@/routes/initial-workload';
import { Head, Link } from '@inertiajs/react';

type MonthSummary = {
    year: number;
    month: number;
    label: string;
    status: 'not_started' | 'draft' | 'final';
    actual_work_confirmed: boolean;
    history_readiness: {
        ready: boolean;
        message: string | null;
        year: number;
        month: number;
        action: 'review' | 'initial_setup' | 'finalize';
        basis: 'confirmed_actual' | 'final_planned' | 'manual_initial' | null;
    };
};

type DashboardProps = {
    initialSetup: {
        required: boolean;
        month: { year: number; month: number; label: string };
    };
    primaryMonth: MonthSummary;
    secondaryMonth: MonthSummary | null;
};

const statusLabels: Record<MonthSummary['status'], string> = {
    not_started: 'Not Started',
    draft: 'Draft',
    final: 'Final',
};

export default function Dashboard({
    initialSetup,
    primaryMonth,
    secondaryMonth,
}: DashboardProps) {
    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />

            {initialSetup.required ? (
                <section className="max-w-2xl rounded-2xl border border-teal-200 bg-white p-5 shadow-sm sm:p-7">
                    <p className="text-sm font-semibold tracking-wide text-teal-700 uppercase">
                        First-time setup
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold text-slate-950">
                        Initial Setup
                    </h2>
                    <p className="mt-2 max-w-xl text-sm leading-6 text-slate-600 sm:text-base">
                        Enter this month&apos;s existing roster workload before
                        preparing next month&apos;s first roster.
                    </p>
                    <Link
                        href={showInitialWorkload.url(initialSetup.month)}
                        className="mt-5 inline-flex min-h-11 items-center justify-center rounded-lg bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-800"
                    >
                        Start Initial Setup
                    </Link>
                </section>
            ) : (
                <div className="space-y-5">
                    <section className="rounded-2xl border border-teal-200 bg-white p-5 shadow-sm sm:p-7">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p className="text-sm font-medium text-teal-700">
                                    Next roster
                                </p>
                                <h2 className="mt-1 text-2xl font-semibold text-slate-950 sm:text-3xl">
                                    {primaryMonth.label}
                                </h2>
                            </div>
                            <span className="w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                {statusLabels[primaryMonth.status]}
                            </span>
                        </div>

                        {primaryMonth.history_readiness.message && (
                            <div
                                className={`mt-5 rounded-xl border p-4 ${primaryMonth.history_readiness.ready ? 'border-teal-200 bg-teal-50' : 'border-amber-200 bg-amber-50'}`}
                            >
                                <p
                                    className={`text-sm font-medium ${primaryMonth.history_readiness.ready ? 'text-teal-950' : 'text-amber-950'}`}
                                >
                                    {primaryMonth.history_readiness.message}
                                </p>
                                {!primaryMonth.history_readiness.ready &&
                                    primaryMonth.history_readiness.action ===
                                        'review' && (
                                        <Link
                                            href={showActualWork.url(
                                                primaryMonth.history_readiness,
                                            )}
                                            className="mt-3 inline-flex min-h-10 items-center rounded-lg border border-amber-400 bg-white px-4 py-2 text-sm font-semibold text-amber-950"
                                        >
                                            Review Actual Work
                                        </Link>
                                    )}
                                {primaryMonth.history_readiness.action ===
                                    'finalize' && (
                                    <Link
                                        href={showRoster.url(
                                            primaryMonth.history_readiness,
                                        )}
                                        className="mt-3 inline-flex min-h-10 items-center rounded-lg border border-amber-400 bg-white px-4 py-2 text-sm font-semibold text-amber-950"
                                    >
                                        Finalize previous roster
                                    </Link>
                                )}
                            </div>
                        )}

                        <div className="mt-5 flex flex-col gap-3 sm:flex-row">
                            <Link
                                href={monthlySetup.url(primaryMonth)}
                                className="inline-flex min-h-11 items-center justify-center rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800"
                            >
                                Manage Monthly Setup
                            </Link>
                            {primaryMonth.status !== 'not_started' && (
                                <Link
                                    href={showRoster.url(primaryMonth)}
                                    className="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                                >
                                    {primaryMonth.status === 'draft'
                                        ? 'Continue Draft Roster'
                                        : 'View Final Roster'}
                                </Link>
                            )}
                        </div>
                    </section>

                    {secondaryMonth && (
                        <section className="rounded-xl border border-slate-200 bg-slate-50 p-4 sm:flex sm:items-center sm:justify-between sm:gap-5 sm:p-5">
                            <div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <h3 className="text-lg font-semibold text-slate-800">
                                        {secondaryMonth.label}
                                    </h3>
                                    <span className="rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700">
                                        {statusLabels[secondaryMonth.status]}
                                    </span>
                                </div>
                                <p className="mt-1 text-sm text-slate-600">
                                    Current roster and actual work.
                                </p>
                            </div>
                            <div className="mt-4 flex flex-col gap-2 sm:mt-0 sm:flex-row">
                                <Link
                                    href={showRoster.url(secondaryMonth)}
                                    className="inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800"
                                >
                                    View Roster
                                </Link>
                                {secondaryMonth.status === 'final' && (
                                    <Link
                                        href={showActualWork.url(
                                            secondaryMonth,
                                        )}
                                        className="inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800"
                                    >
                                        Actual Work Review
                                    </Link>
                                )}
                            </div>
                        </section>
                    )}

                    <p className="text-sm text-slate-500">
                        Need to correct the historical baseline?{' '}
                        <Link
                            href={showInitialWorkload.url(initialSetup.month)}
                            className="font-medium text-teal-800 underline underline-offset-2"
                        >
                            Open Initial Setup
                        </Link>
                    </p>
                </div>
            )}
        </AppLayout>
    );
}
