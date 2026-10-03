import AppLayout from '@/components/app-layout';
import { show as monthlySetup } from '@/routes/monthly-setup';
import { Head, Link } from '@inertiajs/react';

type MonthSummary = {
    year: number;
    month: number;
    label: string;
    status: 'not_started' | 'draft' | 'final';
};

const statusLabels: Record<MonthSummary['status'], string> = {
    not_started: 'Not Started',
    draft: 'Draft',
    final: 'Final',
};

export default function Dashboard({ months }: { months: MonthSummary[] }) {
    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />
            <div className="grid gap-6 md:grid-cols-2">
                {months.map((month) => (
                    <section
                        key={`${month.year}-${month.month}`}
                        className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"
                    >
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-sm font-medium text-slate-500">
                                    Roster month
                                </p>
                                <h2 className="mt-1 text-2xl font-semibold">
                                    {month.label}
                                </h2>
                            </div>
                            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                {statusLabels[month.status]}
                            </span>
                        </div>
                        <Link
                            href={monthlySetup.url(month)}
                            className="mt-8 inline-flex rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800"
                        >
                            Manage Monthly Setup
                        </Link>
                    </section>
                ))}
            </div>
        </AppLayout>
    );
}
