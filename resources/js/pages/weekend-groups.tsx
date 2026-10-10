import AppLayout from '@/components/app-layout';
import { show as monthlySetup } from '@/routes/monthly-setup';
import {
    configure as configureRotation,
    store as saveMembership,
} from '@/routes/weekend-groups';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Doctor = { id: number; name: string; short_code: string };
type Membership = {
    id: number;
    group: 'A' | 'B' | null;
    effective_from_saturday: string;
    doctor: Doctor & { is_active: boolean };
};
type Props = {
    month: { year: number; month: number; label: string };
    doctors: Doctor[];
    memberships: Membership[];
    membershipHistory: Membership[];
    weekends: { saturday: string; label: string; group: 'A' | 'B' | null }[];
    anchor: { saturday: string; group: 'A' | 'B' } | null;
    configurationError: string | null;
};

export default function WeekendGroups(props: Props) {
    const form = useForm({
        doctor_id: '',
        group_code: 'A' as 'A' | 'B',
        effective_from_saturday: '',
    });
    const configurationForm = useForm({
        anchor_saturday: '',
        anchor_group: 'B' as 'A' | 'B',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(saveMembership.url(props.month), { preserveScroll: true });
    }

    function configure(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        configurationForm.put(configureRotation.url(props.month), {
            preserveScroll: true,
        });
    }

    return (
        <AppLayout title="Weekend Group Rotation">
            <Head title={`${props.month.label} Weekend Groups`} />
            <div className="mx-auto flex max-w-5xl flex-col gap-5">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-slate-950">
                            Weekend Group Rotation
                        </h1>
                        <p className="mt-1 text-sm text-slate-600">
                            Effective-dated group membership and scheduled
                            weekend for {props.month.label}.
                        </p>
                    </div>
                    <Link
                        href={monthlySetup.url(props.month)}
                        className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800"
                    >
                        Monthly Setup
                    </Link>
                </header>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-950">
                        Weekend schedule
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Saturday and Sunday Main positions prioritize the
                        scheduled group. Eligible opposite-group doctors may
                        cover when needed; hard scheduling rules always apply.
                    </p>
                    {props.anchor && (
                        <p className="mt-2 text-sm text-slate-700">
                            Rotation anchor: {props.anchor.saturday}, Group{' '}
                            {props.anchor.group}
                        </p>
                    )}
                    {!props.anchor && (
                        <form
                            onSubmit={configure}
                            className="mt-4 grid gap-3 sm:grid-cols-3"
                        >
                            <label className="text-sm font-medium text-slate-800">
                                Anchor Saturday
                                <input
                                    type="date"
                                    value={
                                        configurationForm.data.anchor_saturday
                                    }
                                    onChange={(event) =>
                                        configurationForm.setData(
                                            'anchor_saturday',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3"
                                    required
                                />
                                {configurationForm.errors.anchor_saturday && (
                                    <span className="text-xs text-red-700">
                                        {
                                            configurationForm.errors
                                                .anchor_saturday
                                        }
                                    </span>
                                )}
                            </label>
                            <label className="text-sm font-medium text-slate-800">
                                Group at anchor
                                <select
                                    value={configurationForm.data.anchor_group}
                                    onChange={(event) =>
                                        configurationForm.setData(
                                            'anchor_group',
                                            event.target.value as 'A' | 'B',
                                        )
                                    }
                                    className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3"
                                >
                                    <option value="A">Group A</option>
                                    <option value="B">Group B</option>
                                </select>
                            </label>
                            <div className="flex items-end">
                                <button
                                    type="submit"
                                    disabled={configurationForm.processing}
                                    className="min-h-11 rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
                                >
                                    Save rotation anchor
                                </button>
                            </div>
                        </form>
                    )}
                    {props.weekends.length > 0 ? (
                        <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                            {props.weekends.map((weekend) => (
                                <li
                                    key={weekend.saturday}
                                    className="rounded-lg bg-slate-50 p-3 text-sm font-medium text-slate-800"
                                >
                                    {weekend.label}:{' '}
                                    {weekend.group
                                        ? `Group ${weekend.group}`
                                        : 'Not configured'}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="mt-3 text-sm text-slate-600">
                            No weekends in this month.
                        </p>
                    )}
                    {props.configurationError && (
                        <p className="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-950">
                            {props.configurationError}
                        </p>
                    )}
                </section>

                <section className="grid gap-5 lg:grid-cols-2">
                    {(['A', 'B'] as const).map((group) => (
                        <div
                            key={group}
                            className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                        >
                            <h2 className="text-lg font-semibold text-slate-950">
                                Group {group}
                            </h2>
                            <ul className="mt-3 space-y-2">
                                {props.memberships
                                    .filter((item) => item.group === group)
                                    .map((item) => (
                                        <li
                                            key={item.id}
                                            className="flex justify-between gap-3 border-b border-slate-100 pb-2 text-sm"
                                        >
                                            <span>
                                                {item.doctor.short_code} ·{' '}
                                                {item.doctor.name}
                                                {!item.doctor.is_active &&
                                                    ' (inactive)'}
                                            </span>
                                            <span className="shrink-0 text-slate-500">
                                                From{' '}
                                                {item.effective_from_saturday}
                                            </span>
                                        </li>
                                    ))}
                            </ul>
                        </div>
                    ))}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-950">
                        Membership history through {props.month.label}
                    </h2>
                    <div className="mt-3 divide-y divide-slate-100">
                        {props.membershipHistory.map((item) => (
                            <p
                                key={item.id}
                                className="py-2 text-sm text-slate-700"
                            >
                                {item.doctor.short_code} · {item.doctor.name}:
                                {item.group
                                    ? `Group ${item.group}`
                                    : 'No group'}{' '}
                                from {item.effective_from_saturday}
                                {!item.doctor.is_active && ' · inactive'}
                            </p>
                        ))}
                    </div>
                    {props.membershipHistory.length === 0 && (
                        <p className="mt-2 text-sm text-slate-600">
                            No membership history is recorded.
                        </p>
                    )}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-950">
                        Record a membership change
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Changes begin on a Saturday and keep earlier membership
                        history. Final rosters that would be affected are
                        protected.
                    </p>
                    <form
                        onSubmit={submit}
                        className="mt-4 grid gap-4 sm:grid-cols-3"
                    >
                        <label className="text-sm font-medium text-slate-800">
                            Doctor
                            <select
                                value={form.data.doctor_id}
                                onChange={(event) =>
                                    form.setData(
                                        'doctor_id',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3"
                                required
                            >
                                <option value="">Select doctor</option>
                                {props.doctors.map((doctor) => (
                                    <option key={doctor.id} value={doctor.id}>
                                        {doctor.short_code} · {doctor.name}
                                    </option>
                                ))}
                            </select>
                            {form.errors.doctor_id && (
                                <span className="text-xs text-red-700">
                                    {form.errors.doctor_id}
                                </span>
                            )}
                        </label>
                        <label className="text-sm font-medium text-slate-800">
                            Group
                            <select
                                value={form.data.group_code}
                                onChange={(event) =>
                                    form.setData(
                                        'group_code',
                                        event.target.value as 'A' | 'B',
                                    )
                                }
                                className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3"
                            >
                                <option value="A">Group A</option>
                                <option value="B">Group B</option>
                            </select>
                        </label>
                        <label className="text-sm font-medium text-slate-800">
                            Effective Saturday
                            <input
                                type="date"
                                value={form.data.effective_from_saturday}
                                onChange={(event) =>
                                    form.setData(
                                        'effective_from_saturday',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3"
                                required
                            />
                            {form.errors.effective_from_saturday && (
                                <span className="text-xs text-red-700">
                                    {form.errors.effective_from_saturday}
                                </span>
                            )}
                        </label>
                        <div className="sm:col-span-3">
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="min-h-11 rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                {form.processing
                                    ? 'Saving…'
                                    : 'Save membership change'}
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </AppLayout>
    );
}
