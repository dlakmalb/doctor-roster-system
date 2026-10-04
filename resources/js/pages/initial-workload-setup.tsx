import AppLayout from '@/components/app-layout';
import { save } from '@/routes/initial-workload';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Existing = {
    actual_worked_minutes: number;
    actual_night_duty_count: number;
    optional_assignment_count: number;
    worked_final_weekend: boolean;
    most_recent_night_shift_at: string | null;
} | null;
type Doctor = {
    id: number;
    name: string;
    short_code: string;
    existing: Existing;
};
type Entry = {
    doctor_id: number;
    actual_hours: string;
    actual_night_duty_count: number;
    optional_assignment_count: number;
    worked_final_weekend: boolean;
    most_recent_night_shift_at: string | null;
};
type Props = {
    month: { year: number; month: number; label: string };
    doctors: Doctor[];
};

export default function InitialWorkloadSetup({ month, doctors }: Props) {
    const form = useForm<{ doctors: Entry[] }>({
        doctors: doctors.map((doctor) => ({
            doctor_id: doctor.id,
            actual_hours: doctor.existing
                ? String(doctor.existing.actual_worked_minutes / 60)
                : '0',
            actual_night_duty_count:
                doctor.existing?.actual_night_duty_count ?? 0,
            optional_assignment_count:
                doctor.existing?.optional_assignment_count ?? 0,
            worked_final_weekend:
                doctor.existing?.worked_final_weekend ?? false,
            most_recent_night_shift_at:
                doctor.existing?.most_recent_night_shift_at
                    ?.slice(0, 16)
                    .replace(' ', 'T') ?? null,
        })),
    });
    function update(index: number, changes: Partial<Entry>) {
        form.setData(
            'doctors',
            form.data.doctors.map((entry, current) =>
                current === index ? { ...entry, ...changes } : entry,
            ),
        );
    }
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(save.url(month));
    }

    return (
        <AppLayout title={`Initial Setup - ${month.label}`}>
            <Head title={`Initial Setup - ${month.label}`} />
            <p className="mb-6 max-w-3xl text-slate-600">
                Enter the month of history before the first roster. Opening
                balances start at zero. You can correct this same baseline
                later; confirmed later balances will be recalculated.
            </p>
            <form onSubmit={submit} className="space-y-5">
                {Object.values(form.errors).length > 0 && (
                    <div
                        role="alert"
                        className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
                    >
                        {Object.values(form.errors).join(' ')}
                    </div>
                )}
                <div className="grid gap-4 md:grid-cols-2">
                    {doctors.map((doctor, index) => {
                        const entry = form.data.doctors[index];
                        return (
                            <section
                                key={doctor.id}
                                className="rounded-2xl border border-slate-200 bg-white p-5"
                            >
                                <h2 className="font-semibold">
                                    {doctor.short_code} - {doctor.name}
                                </h2>
                                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label className="grid gap-1 text-sm font-medium">
                                        Actual worked hours
                                        <input
                                            type="number"
                                            min="0"
                                            step="any"
                                            required
                                            value={entry.actual_hours}
                                            onChange={(event) =>
                                                update(index, {
                                                    actual_hours:
                                                        event.target.value,
                                                })
                                            }
                                            className="rounded-lg border border-slate-300 px-3 py-2"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-sm font-medium">
                                        Actual Night duties
                                        <input
                                            type="number"
                                            min="0"
                                            step="1"
                                            required
                                            value={
                                                entry.actual_night_duty_count
                                            }
                                            onChange={(event) =>
                                                update(index, {
                                                    actual_night_duty_count:
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                })
                                            }
                                            className="rounded-lg border border-slate-300 px-3 py-2"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-sm font-medium">
                                        Planned Optional duties
                                        <input
                                            type="number"
                                            min="0"
                                            step="1"
                                            required
                                            value={
                                                entry.optional_assignment_count
                                            }
                                            onChange={(event) =>
                                                update(index, {
                                                    optional_assignment_count:
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                })
                                            }
                                            className="rounded-lg border border-slate-300 px-3 py-2"
                                        />
                                    </label>
                                    <label className="grid gap-1 text-sm font-medium">
                                        Most recent Night start
                                        <input
                                            type="datetime-local"
                                            value={
                                                entry.most_recent_night_shift_at ??
                                                ''
                                            }
                                            onChange={(event) =>
                                                update(index, {
                                                    most_recent_night_shift_at:
                                                        event.target.value ||
                                                        null,
                                                })
                                            }
                                            className="rounded-lg border border-slate-300 px-3 py-2"
                                        />
                                    </label>
                                    <label className="flex items-center gap-2 text-sm font-medium sm:col-span-2">
                                        <input
                                            type="checkbox"
                                            checked={entry.worked_final_weekend}
                                            onChange={(event) =>
                                                update(index, {
                                                    worked_final_weekend:
                                                        event.target.checked,
                                                })
                                            }
                                            className="size-4"
                                        />
                                        Worked the final weekend
                                    </label>
                                </div>
                            </section>
                        );
                    })}
                </div>
                <button
                    disabled={form.processing}
                    className="rounded-lg bg-teal-700 px-5 py-3 font-semibold text-white disabled:opacity-50"
                >
                    Save Initial Setup
                </button>
            </form>
        </AppLayout>
    );
}
