import AppLayout from '@/components/app-layout';
import WeekendPairGroup from '@/components/weekend-pair-group';
import type {
    MonthlyWeekendPairState,
    WeekendDayMainCount,
    WeekendPairGroupCode,
    WeekendPairPageProps,
    WeekendPairPayload,
} from '@/types/weekend-pairs';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';

const groupCodes: WeekendPairGroupCode[] = ['A', 'B'];
const secondaryButton = 'inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:opacity-60';

const statuses: Record<MonthlyWeekendPairState['status'], { label: string; explanation: string }> = {
    not_configured: { label: 'Not configured', explanation: 'Weekend pairs have not been configured for this month.' },
    ready_to_confirm: { label: 'Ready to confirm', explanation: "Review the doctor pairs and confirm this month's configuration." },
    confirmed: { label: 'Confirmed', explanation: 'Weekend pair configuration confirmed.' },
    requires_attention: { label: 'Requires attention', explanation: 'The configuration needs review before it can be confirmed.' },
};

function initialPayload(state: MonthlyWeekendPairState): WeekendPairPayload {
    function groupValues(code: WeekendPairGroupCode): WeekendPairPayload['groups']['A'] {
        const group = state.groups[code];
        const saved = group?.configuration?.doctor_ids ?? [];
        const capacity: WeekendDayMainCount = group?.configuration
            ? group.configuration.mode === 7 ? 3 : 2
            : group?.weekend_day_main_count ?? 2;
        const positions = Array.from({ length: capacity === 3 ? 7 : 6 }, (_, index): number | '' => saved[index] ?? '');

        return { effective_saturday: group?.effective_saturday ?? '', doctor_ids: positions, weekend_day_main_count: capacity };
    }

    return { revision: state.revision, groups: { A: groupValues('A'), B: groupValues('B') } };
}

function WeekendPairEditor({ month, configuration, savedDoctors, links }: WeekendPairPageProps) {
    const [snapshot, setSnapshot] = useState(configuration);
    const [reloading, setReloading] = useState(false);
    const submitting = useRef(false);
    const form = useForm<WeekendPairPayload>(initialPayload(snapshot));
    const errors = form.errors as Record<string, string | undefined>;
    const readOnly = snapshot.read_only || configuration.read_only;
    const displayedState = readOnly ? configuration : snapshot;
    const displayedPayload = readOnly ? initialPayload(configuration) : form.data;
    const needsReload = Boolean(errors.revision) || configuration.revision !== snapshot.revision;
    const disabled = readOnly || form.processing || reloading || needsReload;
    const setupIncomplete = displayedState.issues.length > 0 || groupCodes.some((code) => {
        const group = displayedState.groups[code];
        return !group || group.doctors.length < 6;
    });
    const complete = groupCodes.every((code) => {
        const values = form.data.groups[code];
        const group = snapshot.groups[code];
        const ids = values.doctor_ids;
        return ids.length === (values.weekend_day_main_count === 3 ? 7 : 6)
            && ids.every((id) => id !== '' && group?.doctors.some((doctor) => doctor.id === id))
            && new Set(ids).size === ids.length
            && values.effective_saturday === group?.effective_saturday
            && new Date(`${values.effective_saturday}T00:00:00Z`).getUTCDay() === 6;
    });
    const status = statuses[displayedState.status];
    const boundary = displayedState.boundary;

    function selectCapacity(code: WeekendPairGroupCode, capacity: WeekendDayMainCount): void {
        const values = form.data.groups[code];
        if (disabled || capacity === values.weekend_day_main_count
            || (snapshot.groups[code]?.doctors.length ?? 0) < (capacity === 3 ? 7 : 6)) {
            return;
        }
        if (capacity === 2 && values.doctor_ids[6] !== '' && values.doctor_ids[6] !== undefined
            && !window.confirm('Switch to 2 Main and remove the doctor in the 7th Rotation Position from weekend rotation? This doctor remains eligible for ordinary Main and Optional duties, subject to existing scheduling rules.')) {
            return;
        }
        const positions: Array<number | ''> = capacity === 3 ? [...values.doctor_ids.slice(0, 6), ''] : values.doctor_ids.slice(0, 6);
        form.setData('groups', {
            ...form.data.groups,
            [code]: { ...values, weekend_day_main_count: capacity, doctor_ids: positions },
        });
    }

    function selectDoctor(code: WeekendPairGroupCode, position: number, doctorId: number | ''): void {
        if (disabled) {
            return;
        }
        const ids = [...form.data.groups[code].doctor_ids];
        const previousPosition = doctorId === '' ? -1 : ids.indexOf(doctorId);
        if (previousPosition !== -1 && previousPosition !== position) {
            ids[previousPosition] = ids[position];
        }
        ids[position] = doctorId;
        form.setData('groups', {
            ...form.data.groups,
            [code]: { ...form.data.groups[code], doctor_ids: ids },
        });
    }

    function submit(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        if (disabled || setupIncomplete || !complete || submitting.current) {
            return;
        }
        submitting.current = true;
        form.post(links.confirm, {
            preserveScroll: true,
            onSuccess: (page) => {
                const nextState = page.props.configuration as MonthlyWeekendPairState;
                const nextPayload = initialPayload(nextState);
                setSnapshot(nextState);
                form.setData(nextPayload);
                form.setDefaults(nextPayload);
                form.clearErrors();
            },
            onFinish: () => {
                submitting.current = false;
            },
        });
    }

    function reload(): void {
        if (form.processing || reloading) {
            return;
        }
        setReloading(true);
        router.get(links.show, {}, {
            preserveState: false,
            preserveScroll: true,
            onFinish: () => setReloading(false),
        });
    }

    return (
        <div className="mx-auto flex max-w-5xl flex-col gap-5">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="text-xl font-semibold text-slate-950">{month.label}</h2>
                    <p className="mt-1 text-sm text-slate-600">Arrange the ordered Main pairs for each weekend group.</p>
                </div>
                <nav aria-label="Monthly configuration" className="flex flex-wrap gap-2">
                    <Link href={links.monthlySetup} className={secondaryButton}>Monthly Setup</Link>
                    <Link href={links.weekendGroups} className={secondaryButton}>Weekend Group Rotation</Link>
                </nav>
            </header>

            <section aria-label="Confirmation status" className="rounded-xl border border-slate-200 bg-white p-5">
                <p className="font-semibold text-slate-950">Saved status: {status.label}</p>
                <p className="mt-1 text-sm text-slate-600">{status.explanation}</p>
                {displayedState.confirmation?.confirmed_at && <p className="mt-2 text-xs text-slate-500">Last confirmed: <time dateTime={displayedState.confirmation.confirmed_at}>{displayedState.confirmation.confirmed_at}</time></p>}
                {!readOnly && displayedState.status === 'requires_attention' && displayedState.issues.length === 0 && <p className="mt-2 text-sm text-amber-900">Eligibility or saved configuration has changed. Review every position and re-confirm both groups.</p>}
                {!readOnly && form.isDirty && <p role="status" className="mt-3 font-medium text-amber-900">Unsaved changes - these selections have not been confirmed.</p>}
                {!readOnly && snapshot.confirmation && form.isDirty && <p className="mt-1 text-sm text-slate-600">Re-confirmation preserves the earlier confirmation and configuration history.</p>}
            </section>

            {readOnly && (
                <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                    <p>This month already has a Draft or Final roster. Weekend pair configuration is read-only.</p>
                    <Link href={links.roster} className="mt-2 inline-block font-semibold underline">View roster</Link>
                </div>
            )}

            {setupIncomplete && (
                <section aria-label="Setup guidance" className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                    <h2 className="font-semibold">Configure Weekend Group Rotation before setting weekend pairs.</h2>
                    <ul className="mt-2 list-disc space-y-1 pl-5">{displayedState.issues.map((issue, index) => <li key={`${index}-${issue}`}>{issue}</li>)}</ul>
                    <Link href={links.weekendGroups} className="mt-3 inline-block font-semibold underline">Review group anchor and memberships</Link>
                </section>
            )}

            {boundary && (
                <section aria-label="Sunday month boundary" className="rounded-xl border border-slate-200 bg-slate-100 p-4 text-sm text-slate-700">
                    <h2 className="font-semibold">Sunday month boundary</h2>
                    {boundary.state === 'legacy' ? (
                        <p className="mt-1">The preceding Saturday, {boundary.saturday} (Group {boundary.group}), belongs to the legacy scheduling rules. This confirmation does not backdate pair configuration.</p>
                    ) : (
                        <p className="mt-1">Saturday {boundary.saturday}, Group {boundary.group}, uses historical {boundary.configuration ? `configuration version ${boundary.configuration.version}` : 'configuration that is missing'}. {boundary.compatible ? 'The historical configuration is compatible.' : 'The historical configuration needs attention before confirmation.'}</p>
                    )}
                </section>
            )}

            <form onSubmit={submit} className="flex flex-col gap-5" aria-busy={form.processing}>
                {Object.keys(errors).length > 0 && (
                    <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                        <p className="font-semibold">The configuration could not be confirmed.</p>
                        <ul className="mt-2 list-disc space-y-1 pl-5">{[...new Set(Object.values(errors))].map((error) => <li key={error}>{error}</li>)}</ul>
                    </div>
                )}
                {needsReload && (
                    <div role="alert" className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                        <p>The server configuration has changed. Reload the latest state before confirming. Reloading discards your local edits.</p>
                        <button type="button" disabled={form.processing || reloading} onClick={reload} className={`${secondaryButton} mt-3`}>{reloading ? 'Reloading…' : 'Reload latest configuration'}</button>
                    </div>
                )}
                <div className="grid min-w-0 gap-5 lg:grid-cols-2">
                    {groupCodes.map((code) => (
                        <WeekendPairGroup
                            key={code}
                            code={code}
                            group={displayedState.groups[code]}
                            positions={displayedPayload.groups[code].doctor_ids}
                            capacity={displayedPayload.groups[code].weekend_day_main_count}
                            errors={errors}
                            disabled={disabled || setupIncomplete}
                            readOnly={readOnly}
                            savedDoctors={savedDoctors}
                            onSelect={(position, doctorId) => selectDoctor(code, position, doctorId)}
                            onCapacityChange={(capacity) => selectCapacity(code, capacity)}
                        />
                    ))}
                </div>
                {!readOnly && (
                    <div className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-5">
                        <div className="text-sm text-slate-600">
                            <p>Both groups are confirmed together for {month.label}.</p>
                            {!complete && <p className="mt-1">Select six or seven eligible rotation doctors for each group, according to the selected Weekend Day Main capacity.</p>}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button type="button" disabled={disabled || !form.isDirty} className={secondaryButton} onClick={() => { form.reset(); form.clearErrors(); }}>Discard local changes</button>
                            <button type="submit" disabled={disabled || setupIncomplete || !complete} className="inline-flex min-h-11 items-center justify-center rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:opacity-60">
                                {form.processing ? 'Confirming…' : snapshot.confirmation ? 'Re-confirm Weekend Pairs' : 'Confirm Weekend Pairs'}
                            </button>
                        </div>
                    </div>
                )}
            </form>
        </div>
    );
}

export default function WeekendPairs(props: WeekendPairPageProps) {
    return (
        <AppLayout title="Weekend Pair Configuration">
            <Head title={`${props.month.label} Weekend Pair Configuration`} />
            <WeekendPairEditor key={`${props.month.year}-${props.month.month}`} {...props} />
        </AppLayout>
    );
}
