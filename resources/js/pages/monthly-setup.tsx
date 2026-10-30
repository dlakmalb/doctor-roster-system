import {
    destroy as destroyExclusion,
    store as storeExclusion,
} from '@/actions/App/Http/Controllers/DoctorMonthlyExclusionController';
import {
    destroy as destroyShiftRestriction,
    store as storeShiftRestrictions,
    update as updateShiftRestrictions,
} from '@/actions/App/Http/Controllers/DoctorMonthlyShiftRestrictionController';
import {
    destroy as destroyWeekdayPreference,
    store as storeWeekdayPreference,
    update as updateWeekdayPreference,
} from '@/actions/App/Http/Controllers/DoctorMonthlyWeekdayPreferenceController';
import {
    destroy as destroyRequest,
    store as storeRequest,
    update as updateRequest,
} from '@/actions/App/Http/Controllers/DoctorRequestController';
import { store as storeMonthlyCapacities } from '@/actions/App/Http/Controllers/MonthlyShiftCapacityController';
import AppLayout from '@/components/app-layout';
import { show as showRoster } from '@/routes/rosters';
import { generateRoster as generateRosterFromSetup } from '@/routes/monthly-setup';
import { show as showWeekendGroups } from '@/routes/weekend-groups';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';

type Doctor = {
    id: number;
    name: string;
    short_code: string;
    is_active?: boolean;
};

type ShiftType = {
    id: number;
    code: string;
    name: string;
    start_time: string;
    end_time: string;
    is_overnight: boolean;
};

type CapacityConfiguration = {
    shift_type: Pick<ShiftType, 'id' | 'code' | 'name'>;
    main_count: number | null;
    optional_count: number | null;
    source: 'global_default' | 'monthly_custom' | 'roster_snapshot' | 'inconsistent';
    error: string | null;
    per_shift?: Array<{ date: string; main_count: number; optional_count: number }>;
};

type CapacityPayload = {
    capacities: Array<{
        shift_type: string;
        main_count: number | '';
        optional_count: number | '';
    }>;
};

type RequestKind = 'off_request' | 'preferred_work' | 'monthly_exclusion';
type BackendRequestType = 'day_off' | 'preferred_work';

type MonthlyRequest = {
    id: number;
    kind: RequestKind;
    request_date: string | null;
    date_label: string;
    doctor: Doctor;
    shift_type: Pick<ShiftType, 'id' | 'code' | 'name'> | null;
    shift_label: string | null;
    note: string | null;
    is_late: boolean;
    has_date_limit_warning: boolean;
};

type Month = {
    year: number;
    month: number;
    label: string;
};

type Warning = {
    type:
        | 'late_request'
        | 'day_off_limit'
        | 'staffing_risk'
        | 'restricted_preferred_work'
        | 'restricted_weekday_preference';
    message: string;
};

type ShiftRestriction = {
    id: number;
    doctor: Doctor;
    shift_type: Pick<ShiftType, 'id' | 'code' | 'name'>;
};

type WeekdayPreference = {
    id: number;
    doctor: Doctor;
    shift_type: Pick<ShiftType, 'id' | 'code' | 'name'>;
    weekday: number;
    weekday_name: string;
    conflicts_with_restriction: boolean;
};

type PageProps = {
    month: Month;
    weekendPairConfigurationUrl: string;
    rosterStatus: 'not_started' | 'draft' | 'final';
    rosterAction: 'generate' | 'view_draft' | 'view_final';
    isPlanningPeriod: boolean;
    doctors: Doctor[];
    shiftTypes: ShiftType[];
    capacityConfiguration: CapacityConfiguration[];
    capacityEditable: boolean;
    weekendDayCapacities: Array<{
        saturday: string;
        group: string | null;
        mode: number | null;
        main_count: number;
        source: 'legacy' | 'confirmed_pairs';
    }>;
    weekendCapacityError: string | null;
    shiftRestrictions: ShiftRestriction[];
    weekdayPreferences: WeekdayPreference[];
    requests: MonthlyRequest[];
    summary: {
        active_doctors: number;
        total_requests: number;
        warnings: number;
    };
    warnings: Warning[];
};

type RequestPayload = {
    doctor_id: number | '';
    request_type: BackendRequestType;
    request_date: string;
    shift_type_id: number | '';
    note: string;
};

const fieldClassName =
    'mt-1.5 min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-100';

const requestTypeLabels: Record<RequestKind, string> = {
    off_request: 'Off Request',
    preferred_work: 'Preferred Work',
    monthly_exclusion: 'Monthly Exclusion',
};

function getShiftLabel(shiftType: ShiftType): string {
    if (shiftType.code.endsWith('_day')) {
        return 'Day';
    }
    if (shiftType.code.endsWith('_evening')) {
        return 'Evening';
    }
    if (shiftType.code.endsWith('_night')) {
        return 'Night';
    }

    return shiftType.name;
}

function Chevron({ expanded }: { expanded: boolean }) {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 20 20"
            fill="none"
            className="size-5 shrink-0 text-slate-500"
        >
            {expanded ? (
                <path
                    d="m5 12 5-5 5 5"
                    stroke="currentColor"
                    strokeWidth="1.8"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
            ) : (
                <path
                    d="m5 8 5 5 5-5"
                    stroke="currentColor"
                    strokeWidth="1.8"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
            )}
        </svg>
    );
}

export default function MonthlySetup(props: PageProps) {
    const isFinal = props.rosterStatus === 'final';
    const canEditMonthlySetup = props.isPlanningPeriod && !isFinal;
    const [requestKind, setRequestKind] = useState<RequestKind>('off_request');
    const [expandedSections, setExpandedSections] = useState({
        restrictions: true,
        weekdays: false,
        requests: false,
    });
    const [editingId, setEditingId] = useState<number | null>(null);
    const [exclusionProcessing, setExclusionProcessing] = useState(false);
    const form = useForm<RequestPayload>({
        doctor_id: '',
        request_type: 'day_off',
        request_date: '',
        shift_type_id: '',
        note: '',
    });
    const rosterForm = useForm<{ roster?: string }>({});
    const capacityForm = useForm<CapacityPayload>({
        capacities: props.capacityConfiguration.map((capacity) => ({
            shift_type: capacity.shift_type.code,
            main_count: capacity.main_count ?? '',
            optional_count: capacity.optional_count ?? '',
        })),
    });
    const capacityMonthError = (
        capacityForm.errors as typeof capacityForm.errors & { month?: string }
    ).month;
    const restrictionForm = useForm<{
        doctor_id: number | '';
        shift_type_ids: number[];
    }>({ doctor_id: '', shift_type_ids: [] });
    const [editingRestrictionId, setEditingRestrictionId] = useState<
        number | null
    >(null);
    const weekdayPreferenceForm = useForm<{
        doctor_id: number | '';
        shift_type_id: number | '';
        weekdays: number[];
    }>({ doctor_id: '', shift_type_id: '', weekdays: [] });
    const [editingWeekdayPreferenceId, setEditingWeekdayPreferenceId] =
        useState<number | null>(null);
    const selectedPreferenceShiftType = props.shiftTypes.find(
        (shiftType) =>
            shiftType.id === weekdayPreferenceForm.data.shift_type_id,
    );
    const compatibleWeekdays = selectedPreferenceShiftType?.code.startsWith(
        'weekend_',
    )
        ? [6, 7]
        : [1, 2, 3, 4, 5];
    const selectedRequest = props.requests.find(
        (request) =>
            request.id === editingId && request.kind !== 'monthly_exclusion',
    );
    const excludedDoctorIds = new Set(
        props.requests
            .filter((request) => request.kind === 'monthly_exclusion')
            .map((request) => request.doctor.id),
    );
    const shiftTypesInDisplayOrder = useMemo(() => {
        const displayOrder = new Map(
            props.capacityConfiguration.map((capacity, index) => [
                capacity.shift_type.code,
                index,
            ] as const),
        );

        return [...props.shiftTypes].sort(
            (left, right) =>
                (displayOrder.get(left.code) ?? Number.MAX_SAFE_INTEGER) -
                (displayOrder.get(right.code) ?? Number.MAX_SAFE_INTEGER),
        );
    }, [props.capacityConfiguration, props.shiftTypes]);
    const validShiftTypes = useMemo(() => {
        if (!form.data.request_date) {
            return [];
        }

        const day = new Date(`${form.data.request_date}T00:00:00`).getDay();
        const weekend = day === 0 || day === 6;

        return shiftTypesInDisplayOrder.filter((shiftType) =>
            weekend
                ? shiftType.code.startsWith('weekend_')
                : shiftType.code.startsWith('weekday_'),
        );
    }, [form.data.request_date, shiftTypesInDisplayOrder]);

    function toggleSection(section: keyof typeof expandedSections): void {
        setExpandedSections((current) => ({
            ...current,
            [section]: !current[section],
        }));
    }

    function resetForm(): void {
        setEditingId(null);
        setRequestKind('off_request');
        form.reset();
        form.setData('request_type', 'day_off');
        form.clearErrors();
    }

    function changeRequestKind(kind: RequestKind): void {
        setRequestKind(kind);
        form.setData(
            'request_type',
            kind === 'preferred_work' ? 'preferred_work' : 'day_off',
        );
        if (kind === 'monthly_exclusion' || kind !== requestKind) {
            form.setData('request_date', '');
            form.setData('shift_type_id', '');
        }
        form.clearErrors();
    }

    function submit(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        if (requestKind === 'monthly_exclusion') {
            setExclusionProcessing(true);
            router.post(
                storeExclusion.url(props.month),
                { doctor_id: form.data.doctor_id, note: form.data.note },
                {
                    preserveScroll: true,
                    onSuccess: resetForm,
                    onError: (errors) => {
                        form.setError(errors);
                        setExpandedSections((current) => ({
                            ...current,
                            requests: true,
                        }));
                    },
                    onFinish: () => setExclusionProcessing(false),
                },
            );
            return;
        }

        const options = {
            preserveScroll: true,
            onSuccess: resetForm,
            onError: () =>
                setExpandedSections((current) => ({
                    ...current,
                    requests: true,
                })),
        };
        if (editingId === null) {
            form.post(storeRequest.url(props.month), options);
            return;
        }

        form.put(
            updateRequest.url({ ...props.month, doctorRequest: editingId }),
            options,
        );
    }

    function edit(request: MonthlyRequest): void {
        if (
            request.kind === 'monthly_exclusion' ||
            request.request_date === null
        ) {
            return;
        }

        setEditingId(request.id);
        setExpandedSections((current) => ({ ...current, requests: true }));
        setRequestKind(request.kind);
        form.setData({
            doctor_id: request.doctor.id,
            request_type:
                request.kind === 'off_request' ? 'day_off' : 'preferred_work',
            request_date: request.request_date,
            shift_type_id: request.shift_type?.id ?? '',
            note: request.note ?? '',
        });
        form.clearErrors();
    }

    function remove(request: MonthlyRequest): void {
        const isExclusion = request.kind === 'monthly_exclusion';
        if (
            !window.confirm(
                isExclusion
                    ? 'Remove this monthly exclusion?'
                    : 'Delete this request?',
            )
        ) {
            return;
        }

        if (isExclusion) {
            router.delete(
                destroyExclusion.url({
                    ...props.month,
                    doctorMonthlyExclusion: request.id,
                }),
                { preserveScroll: true },
            );
            return;
        }

        router.delete(
            destroyRequest.url({ ...props.month, doctorRequest: request.id }),
            { preserveScroll: true },
        );
    }

    function editRestrictions(doctorId: number): void {
        const restrictions = props.shiftRestrictions.filter(
            (restriction) => restriction.doctor.id === doctorId,
        );
        setEditingRestrictionId(restrictions[0]?.id ?? null);
        setExpandedSections((current) => ({ ...current, restrictions: true }));
        restrictionForm.setData({
            doctor_id: doctorId,
            shift_type_ids: restrictions.map(
                (restriction) => restriction.shift_type.id,
            ),
        });
        restrictionForm.clearErrors();
    }

    function saveRestrictions(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onError: () =>
                setExpandedSections((current) => ({
                    ...current,
                    restrictions: true,
                })),
            onSuccess: () => {
                setEditingRestrictionId(null);
                restrictionForm.reset();
            },
        };
        if (editingRestrictionId === null) {
            restrictionForm.post(
                storeShiftRestrictions.url(props.month),
                options,
            );
        } else {
            restrictionForm.put(
                updateShiftRestrictions.url({
                    ...props.month,
                    doctorMonthlyShiftRestriction: editingRestrictionId,
                }),
                options,
            );
        }
    }

    function resetWeekdayPreferenceForm(): void {
        setEditingWeekdayPreferenceId(null);
        weekdayPreferenceForm.reset();
        weekdayPreferenceForm.clearErrors();
    }

    function submitWeekdayPreference(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        if (editingWeekdayPreferenceId === null) {
            weekdayPreferenceForm.post(
                storeWeekdayPreference.url(props.month),
                {
                    preserveScroll: true,
                    onSuccess: resetWeekdayPreferenceForm,
                    onError: () =>
                        setExpandedSections((current) => ({
                            ...current,
                            weekdays: true,
                        })),
                },
            );
            return;
        }

        const weekday = weekdayPreferenceForm.data.weekdays[0];
        if (weekday === undefined) {
            weekdayPreferenceForm.setError('weekdays', 'Select a weekday.');
            setExpandedSections((current) => ({
                ...current,
                weekdays: true,
            }));
            return;
        }
        router.put(
            updateWeekdayPreference.url({
                ...props.month,
                doctorMonthlyWeekdayPreference: editingWeekdayPreferenceId,
            }),
            {
                doctor_id: weekdayPreferenceForm.data.doctor_id,
                shift_type_id: weekdayPreferenceForm.data.shift_type_id,
                weekdays: [weekday],
            },
            {
                preserveScroll: true,
                onSuccess: resetWeekdayPreferenceForm,
                onError: (errors) => {
                    weekdayPreferenceForm.setError(errors);
                    setExpandedSections((current) => ({
                        ...current,
                        weekdays: true,
                    }));
                },
            },
        );
    }

    function editWeekdayPreference(preference: WeekdayPreference): void {
        if (preference.doctor.is_active === false) {
            return;
        }
        setEditingWeekdayPreferenceId(preference.id);
        setExpandedSections((current) => ({ ...current, weekdays: true }));
        weekdayPreferenceForm.setData({
            doctor_id: preference.doctor.id,
            shift_type_id: preference.shift_type.id,
            weekdays: [preference.weekday],
        });
        weekdayPreferenceForm.clearErrors();
    }

    const summaryItems = [
        ['Active Doctors', props.summary.active_doctors],
        ['Total Requests', props.summary.total_requests],
        ['Warnings', props.summary.warnings],
    ] as const;

    return (
        <AppLayout title={`${props.month.label} Monthly Setup`}>
            <Head title={`${props.month.label} Monthly Setup`} />

            <div className="mb-5 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                <div>
                    <h1 className="text-xl font-semibold text-slate-950">
                        {props.month.label} Monthly Setup
                    </h1>
                    <p className="mt-1 text-sm text-slate-600">
                        Roster status:{' '}
                        <span className="font-semibold text-slate-800">
                            {props.rosterStatus === 'not_started'
                                ? 'Not Started'
                                : props.rosterStatus === 'draft'
                                  ? 'Draft'
                                  : 'Final'}
                        </span>
                    </p>
                </div>
                {props.rosterAction === 'generate' ? (
                    <button
                        type="button"
                        disabled={rosterForm.processing}
                        onClick={() =>
                            rosterForm.post(
                                generateRosterFromSetup.url(props.month),
                            )
                        }
                        className="inline-flex min-h-11 items-center justify-center rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60"
                    >
                        {rosterForm.processing
                            ? 'Generating…'
                            : 'Generate Roster'}
                    </button>
                ) : props.rosterAction === 'view_draft' ? (
                    <Link
                        href={showRoster.url(props.month)}
                        className="inline-flex min-h-11 items-center justify-center rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800"
                    >
                        View Draft Roster
                    </Link>
                ) : (
                    <Link
                        href={showRoster.url(props.month)}
                        className="inline-flex min-h-11 items-center justify-center rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800"
                    >
                        View Final Roster
                    </Link>
                )}
                <Link
                    href={showWeekendGroups.url(props.month)}
                    className="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-800"
                >
                    Weekend Group Rotation
                </Link>
                <Link
                    href={props.weekendPairConfigurationUrl}
                    className="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-800"
                >
                    Weekend Pair Configuration
                </Link>
            </div>

            {isFinal && (
                <div className="mb-5 flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="font-semibold text-amber-950">
                            Monthly Setup is locked
                        </h2>
                        <p className="mt-1 text-sm text-amber-900">
                            This roster is Final. Reopen it before changing
                            requests, exclusions, shift restrictions, or weekday
                            preferences.
                        </p>
                    </div>
                </div>
            )}

            {!props.isPlanningPeriod && (
                <p className="mb-5 rounded-xl border border-slate-200 bg-slate-100 p-4 text-sm text-slate-700">
                    This is a historical roster view. Monthly Setup values are read-only.
                </p>
            )}

            {rosterForm.errors.roster && (
                <p className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                    {rosterForm.errors.roster}
                </p>
            )}

            <section className="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 className="text-xl font-semibold text-slate-950">
                            Shift Capacity Configuration
                        </h2>
                        <p className="mt-1 text-sm text-slate-600">
                            Set Main and Optional positions for each active shift type in {props.month.label}.
                        </p>
                    </div>
                    {props.capacityEditable && (
                        <button
                            type="submit"
                            form="monthly-shift-capacity-form"
                            disabled={capacityForm.processing}
                            className="inline-flex min-h-11 items-center justify-center rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60"
                        >
                            {capacityForm.processing ? 'Saving…' : 'Save Capacities'}
                        </button>
                    )}
                </div>
                {!props.capacityEditable && (
                    <p className="mt-3 rounded-lg bg-slate-100 p-3 text-sm text-slate-700">
                        Capacities are read-only because a roster record exists. These values come from its saved capacity snapshots.
                    </p>
                )}
                {capacityMonthError && (
                    <p role="alert" className="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                        {capacityMonthError}
                    </p>
                )}
                {props.capacityEditable && (
                    <div className="mt-3 space-y-1 text-sm text-slate-600">
                        <p>Weekend Day Main is automatic for pair-enabled weekends: six doctors require 2 positions; seven require 3. The monthly Main setting below applies only to legacy weekends. Optional capacity remains editable.</p>
                        {props.weekendCapacityError && <p role="alert" className="text-red-700">{props.weekendCapacityError}</p>}
                        {props.weekendDayCapacities.map((weekend) => (
                            <p key={weekend.saturday}>Weekend starting {weekend.saturday}: {weekend.source === 'legacy' ? 'Legacy monthly setting' : `Group ${weekend.group}, ${weekend.mode} doctors`} — Day Main {weekend.main_count} (Saturday and Sunday shifts in this month).</p>
                        ))}
                    </div>
                )}
                {capacityForm.errors.capacities && (
                    <p role="alert" className="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                        {capacityForm.errors.capacities}
                    </p>
                )}
                {props.capacityEditable ? (
                    <form
                        id="monthly-shift-capacity-form"
                        onSubmit={(event: FormEvent<HTMLFormElement>) => {
                            event.preventDefault();
                            capacityForm.post(storeMonthlyCapacities.url(props.month));
                        }}
                        className="mt-4 overflow-x-auto"
                    >
                        <table className="w-full min-w-[42rem] text-left text-sm">
                            <thead className="border-b border-slate-200 text-xs tracking-wide text-slate-500 uppercase">
                                <tr>
                                    <th className="px-3 py-3 font-semibold">Shift Type</th>
                                    <th className="px-3 py-3 font-semibold">Main Positions</th>
                                    <th className="px-3 py-3 font-semibold">Optional Positions</th>
                                    <th className="px-3 py-3 font-semibold">Configuration</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {props.capacityConfiguration.map((capacity, index) => (
                                    <tr key={capacity.shift_type.code}>
                                        <th scope="row" className="px-3 py-3 font-medium text-slate-900">{capacity.shift_type.name}</th>
                                        {(['main_count', 'optional_count'] as const).map((field) => {
                                            const errorKey: `capacities.${number}.main_count` | `capacities.${number}.optional_count` = `capacities.${index}.${field}`;
                                            const error = capacityForm.errors[errorKey];

                                            return (
                                                <td key={field} className="px-3 py-3">
                                                    <input
                                                        aria-label={`${capacity.shift_type.name} ${field === 'main_count' ? (capacity.shift_type.code === 'weekend_day' ? 'Legacy Main only' : 'Main') : 'Optional'} positions`}
                                                        aria-invalid={Boolean(error)}
                                                        aria-describedby={error ? `${errorKey}-error` : undefined}
                                                        type="number"
                                                        min="0"
                                                        max="255"
                                                        step="1"
                                                        value={capacityForm.data.capacities[index]?.[field] ?? ''}
                                                        onChange={(event) => {
                                                            const value = event.target.value;
                                                            capacityForm.setData('capacities', capacityForm.data.capacities.map((item, itemIndex) => itemIndex === index ? { ...item, [field]: value === '' ? '' : Number(value) } : item));
                                                        }}
                                                        className={`${fieldClassName} mt-0 max-w-36`}
                                                    />
                                                    {capacity.shift_type.code === 'weekend_day' && field === 'main_count' && <p className="mt-1 text-xs text-slate-500">Legacy weekends only; pair-enabled Main is automatic.</p>}
                                                    {error && <p id={`${errorKey}-error`} role="alert" className="mt-1 text-xs text-red-700">{error}</p>}
                                                </td>
                                            );
                                        })}
                                        <td className="px-3 py-3 text-slate-600">
                                            {capacity.source === 'global_default' ? 'Inherited from global defaults' : 'Customized for this month'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </form>
                ) : (
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[42rem] text-left text-sm">
                            <thead className="border-b border-slate-200 text-xs tracking-wide text-slate-500 uppercase">
                                <tr>
                                    <th className="px-3 py-3 font-semibold">Shift Type</th>
                                    <th className="px-3 py-3 font-semibold">Main Positions</th>
                                    <th className="px-3 py-3 font-semibold">Optional Positions</th>
                                    <th className="px-3 py-3 font-semibold">Snapshot Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {props.capacityConfiguration.map((capacity) => (
                                    <tr key={capacity.shift_type.code}>
                                        <th scope="row" className="px-3 py-3 font-medium text-slate-900">{capacity.shift_type.name}</th>
                                        <td className="px-3 py-3">
                                            {capacity.per_shift ? capacity.per_shift.map((shift) => (
                                                <p key={shift.date}>{shift.date}: Main {shift.main_count}, Optional {shift.optional_count}</p>
                                            )) : (capacity.main_count ?? '-')}
                                        </td>
                                        <td className="px-3 py-3">{capacity.optional_count ?? '-'}</td>
                                        <td className="px-3 py-3">
                                            {capacity.source === 'inconsistent' ? (
                                                <span className="text-red-700">{capacity.error}</span>
                                            ) : 'Saved roster snapshots'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            <section className="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                <h2 className="text-xl font-semibold text-slate-950">
                    <button
                        type="button"
                        aria-expanded={expandedSections.restrictions}
                        aria-controls="monthly-shift-restrictions-content"
                        onClick={() => toggleSection('restrictions')}
                        className="flex w-full cursor-pointer items-center justify-between gap-4 rounded-lg text-left hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-700"
                    >
                        <span className="text-left">
                            <span className="block">
                                Monthly Shift Restrictions
                            </span>
                            <span className="mt-1 block text-sm text-slate-600">
                                Restrict a doctor from selected shift types for{' '}
                                {props.month.label}. No restriction means all
                                shift types are allowed.
                            </span>
                            <span className="mt-1 block text-xs text-slate-500">
                                {
                                    new Set(
                                        props.shiftRestrictions.map(
                                            (item) => item.doctor.id,
                                        ),
                                    ).size
                                }{' '}
                                {new Set(
                                    props.shiftRestrictions.map(
                                        (item) => item.doctor.id,
                                    ),
                                ).size === 1
                                    ? 'doctor'
                                    : 'doctors'}{' '}
                                with restrictions
                            </span>
                        </span>
                        <Chevron expanded={expandedSections.restrictions} />
                    </button>
                </h2>
                <div
                    id="monthly-shift-restrictions-content"
                    aria-hidden={!expandedSections.restrictions}
                    inert={!expandedSections.restrictions}
                    className="grid overflow-hidden transition-[grid-template-rows] duration-200 ease-out motion-reduce:transition-none"
                    style={{
                        gridTemplateRows: expandedSections.restrictions
                            ? '1fr'
                            : '0fr',
                    }}
                >
                    <div className="min-h-0 overflow-hidden">
                        {canEditMonthlySetup && (
                            <form
                                onSubmit={saveRestrictions}
                                className="mt-4 grid gap-4 rounded-xl bg-slate-50 p-4 sm:grid-cols-2"
                            >
                                <label className="block">
                                    <span className="text-sm font-medium text-slate-800">
                                        Active doctor
                                    </span>
                                    <select
                                        className={fieldClassName}
                                        required
                                        value={restrictionForm.data.doctor_id}
                                        onChange={(event) => {
                                            const doctorId = event.target.value
                                                ? Number(event.target.value)
                                                : '';
                                            const restrictions =
                                                typeof doctorId === 'number'
                                                    ? props.shiftRestrictions.filter(
                                                          (item) =>
                                                              item.doctor.id ===
                                                              doctorId,
                                                      )
                                                    : [];
                                            restrictionForm.setData({
                                                doctor_id: doctorId,
                                                shift_type_ids:
                                                    restrictions.map(
                                                        (item) =>
                                                            item.shift_type.id,
                                                    ),
                                            });
                                            setEditingRestrictionId(
                                                restrictions[0]?.id ?? null,
                                            );
                                        }}
                                    >
                                        <option value="">Select doctor</option>
                                        {props.doctors.map((doctor) => (
                                            <option
                                                key={doctor.id}
                                                value={doctor.id}
                                            >
                                                {doctor.short_code} -{' '}
                                                {doctor.name}
                                            </option>
                                        ))}
                                    </select>
                                    {restrictionForm.errors.doctor_id && (
                                        <p className="mt-1 text-xs text-red-600">
                                            {restrictionForm.errors.doctor_id}
                                        </p>
                                    )}
                                </label>
                                <fieldset className="sm:col-span-2">
                                    <legend className="text-sm font-medium text-slate-800">
                                        Prohibited shift types
                                    </legend>
                                    <div className="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        {shiftTypesInDisplayOrder.map((shiftType) => (
                                            <label
                                                key={shiftType.id}
                                                className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white p-3 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    disabled={
                                                        !restrictionForm.data
                                                            .doctor_id
                                                    }
                                                    checked={restrictionForm.data.shift_type_ids.includes(
                                                        shiftType.id,
                                                    )}
                                                    onChange={(event) =>
                                                        restrictionForm.setData(
                                                            'shift_type_ids',
                                                            event.target.checked
                                                                ? [
                                                                      ...restrictionForm
                                                                          .data
                                                                          .shift_type_ids,
                                                                      shiftType.id,
                                                                  ]
                                                                : restrictionForm.data.shift_type_ids.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          shiftType.id,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                {shiftType.name}
                                            </label>
                                        ))}
                                    </div>
                                    {restrictionForm.errors.shift_type_ids && (
                                        <p className="mt-1 text-xs text-red-600">
                                            {
                                                restrictionForm.errors
                                                    .shift_type_ids
                                            }
                                        </p>
                                    )}
                                </fieldset>
                                <button
                                    type="submit"
                                    disabled={
                                        !restrictionForm.data.doctor_id ||
                                        restrictionForm.processing
                                    }
                                    className="min-h-11 w-fit rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60"
                                >
                                    Save restrictions
                                </button>
                            </form>
                        )}
                        {props.shiftRestrictions.length === 0 ? (
                            <p className="mt-4 text-sm text-slate-500">
                                No monthly shift restrictions.
                            </p>
                        ) : (
                            <div className="mt-4 divide-y divide-slate-200">
                                {Array.from(
                                    new Map(
                                        props.shiftRestrictions.map((item) => [
                                            item.doctor.id,
                                            item.doctor,
                                        ]),
                                    ).values(),
                                ).map((doctor) => {
                                    const restrictions =
                                        props.shiftRestrictions.filter(
                                            (item) =>
                                                item.doctor.id === doctor.id,
                                        );

                                    return (
                                        <article
                                            key={doctor.id}
                                            className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div>
                                                <p className="font-semibold text-slate-900">
                                                    {doctor.name}{' '}
                                                    <span className="font-normal text-slate-500">
                                                        ({doctor.short_code})
                                                    </span>
                                                    {!doctor.is_active && (
                                                        <span className="ml-2 text-xs">
                                                            Inactive
                                                        </span>
                                                    )}
                                                </p>
                                                <p className="text-sm text-slate-600">
                                                    Restricted:{' '}
                                                    {restrictions
                                                        .map(
                                                            (item) =>
                                                                item.shift_type
                                                                    .name,
                                                        )
                                                        .join(', ')}
                                                </p>
                                            </div>
                                            {canEditMonthlySetup && (
                                                <div className="flex flex-wrap gap-2">
                                                    {doctor.is_active !==
                                                        false && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                editRestrictions(
                                                                    doctor.id,
                                                                )
                                                            }
                                                            className="min-h-10 rounded-lg border border-slate-300 px-3 text-sm"
                                                        >
                                                            Edit
                                                        </button>
                                                    )}
                                                    {restrictions.map(
                                                        (item) => (
                                                            <button
                                                                key={item.id}
                                                                type="button"
                                                                onClick={() =>
                                                                    router.delete(
                                                                        destroyShiftRestriction.url(
                                                                            {
                                                                                ...props.month,
                                                                                doctorMonthlyShiftRestriction:
                                                                                    item.id,
                                                                            },
                                                                        ),
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                                className="min-h-10 rounded-lg border border-red-200 px-3 text-sm text-red-700"
                                                            >
                                                                Remove{' '}
                                                                {
                                                                    item
                                                                        .shift_type
                                                                        .name
                                                                }
                                                            </button>
                                                        ),
                                                    )}
                                                </div>
                                            )}
                                        </article>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>
            </section>

            <section className="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                <h2 className="text-xl font-semibold text-slate-950">
                    <button
                        type="button"
                        aria-expanded={expandedSections.weekdays}
                        aria-controls="monthly-weekday-preferences-content"
                        onClick={() => toggleSection('weekdays')}
                        className="flex w-full cursor-pointer items-center justify-between gap-4 rounded-lg text-left hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-700"
                    >
                        <span className="text-left">
                            <span className="block">
                                Monthly Preferred Weekdays
                            </span>
                            <span className="mt-1 block text-sm text-slate-600">
                                These preferences gently influence scheduling
                                for {props.month.label}. They are not guaranteed
                                assignments and do not create extra duties.
                            </span>
                            <span className="mt-1 block text-xs text-slate-500">
                                {props.weekdayPreferences.length}{' '}
                                {props.weekdayPreferences.length === 1
                                    ? 'preference'
                                    : 'preferences'}{' '}
                                configured
                            </span>
                        </span>
                        <Chevron expanded={expandedSections.weekdays} />
                    </button>
                </h2>
                <div
                    id="monthly-weekday-preferences-content"
                    aria-hidden={!expandedSections.weekdays}
                    inert={!expandedSections.weekdays}
                    className="grid overflow-hidden transition-[grid-template-rows] duration-200 ease-out motion-reduce:transition-none"
                    style={{
                        gridTemplateRows: expandedSections.weekdays
                            ? '1fr'
                            : '0fr',
                    }}
                >
                    <div className="min-h-0 overflow-hidden">
                        {canEditMonthlySetup && (
                            <form
                                onSubmit={submitWeekdayPreference}
                                className="mt-4 grid gap-4 rounded-xl bg-slate-50 p-4 sm:grid-cols-2"
                            >
                                <label className="block">
                                    <span className="text-sm font-medium text-slate-800">
                                        Active doctor
                                    </span>
                                    <select
                                        className={fieldClassName}
                                        required
                                        value={
                                            weekdayPreferenceForm.data.doctor_id
                                        }
                                        onChange={(event) =>
                                            weekdayPreferenceForm.setData({
                                                ...weekdayPreferenceForm.data,
                                                doctor_id: event.target.value
                                                    ? Number(event.target.value)
                                                    : '',
                                                weekdays: [],
                                            })
                                        }
                                    >
                                        <option value="">Select doctor</option>
                                        {props.doctors.map((doctor) => (
                                            <option
                                                key={doctor.id}
                                                value={doctor.id}
                                            >
                                                {doctor.short_code} -{' '}
                                                {doctor.name}
                                            </option>
                                        ))}
                                    </select>
                                    {weekdayPreferenceForm.errors.doctor_id && (
                                        <p className="mt-1 text-xs text-red-600">
                                            {
                                                weekdayPreferenceForm.errors
                                                    .doctor_id
                                            }
                                        </p>
                                    )}
                                </label>
                                <label className="block">
                                    <span className="text-sm font-medium text-slate-800">
                                        Shift type
                                    </span>
                                    <select
                                        className={fieldClassName}
                                        required
                                        value={
                                            weekdayPreferenceForm.data
                                                .shift_type_id
                                        }
                                        onChange={(event) =>
                                            weekdayPreferenceForm.setData({
                                                ...weekdayPreferenceForm.data,
                                                shift_type_id: event.target
                                                    .value
                                                    ? Number(event.target.value)
                                                    : '',
                                                weekdays: [],
                                            })
                                        }
                                    >
                                        <option value="">
                                            Select shift type
                                        </option>
                                        {shiftTypesInDisplayOrder.map((shiftType) => (
                                            <option
                                                key={shiftType.id}
                                                value={shiftType.id}
                                            >
                                                {shiftType.name}
                                            </option>
                                        ))}
                                    </select>
                                    {weekdayPreferenceForm.errors
                                        .shift_type_id && (
                                        <p className="mt-1 text-xs text-red-600">
                                            {
                                                weekdayPreferenceForm.errors
                                                    .shift_type_id
                                            }
                                        </p>
                                    )}
                                </label>
                                <fieldset className="sm:col-span-2">
                                    <legend className="text-sm font-medium text-slate-800">
                                        Preferred weekdays
                                    </legend>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {compatibleWeekdays.map((weekday) => {
                                            const label =
                                                new Intl.DateTimeFormat('en', {
                                                    weekday: 'long',
                                                    timeZone: 'UTC',
                                                }).format(
                                                    new Date(
                                                        Date.UTC(
                                                            2024,
                                                            0,
                                                            weekday,
                                                        ),
                                                    ),
                                                );
                                            const alreadySaved =
                                                weekdayPreferenceForm.data
                                                    .doctor_id !== '' &&
                                                weekdayPreferenceForm.data
                                                    .shift_type_id !== '' &&
                                                props.weekdayPreferences.some(
                                                    (preference) =>
                                                        preference.id !==
                                                            editingWeekdayPreferenceId &&
                                                        preference.doctor.id ===
                                                            weekdayPreferenceForm
                                                                .data
                                                                .doctor_id &&
                                                        preference.shift_type
                                                            .id ===
                                                            weekdayPreferenceForm
                                                                .data
                                                                .shift_type_id &&
                                                        preference.weekday ===
                                                            weekday,
                                                );

                                            return (
                                                <label
                                                    key={weekday}
                                                    className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white p-3 text-sm"
                                                >
                                                    <input
                                                        type={
                                                            editingWeekdayPreferenceId ===
                                                            null
                                                                ? 'checkbox'
                                                                : 'radio'
                                                        }
                                                        name="preferred-weekday"
                                                        disabled={
                                                            !weekdayPreferenceForm
                                                                .data
                                                                .shift_type_id ||
                                                            alreadySaved
                                                        }
                                                        checked={weekdayPreferenceForm.data.weekdays.includes(
                                                            weekday,
                                                        )}
                                                        onChange={(event) =>
                                                            weekdayPreferenceForm.setData(
                                                                'weekdays',
                                                                editingWeekdayPreferenceId !==
                                                                    null
                                                                    ? [weekday]
                                                                    : event
                                                                            .target
                                                                            .checked
                                                                      ? [
                                                                            ...weekdayPreferenceForm
                                                                                .data
                                                                                .weekdays,
                                                                            weekday,
                                                                        ]
                                                                      : weekdayPreferenceForm.data.weekdays.filter(
                                                                            (
                                                                                item,
                                                                            ) =>
                                                                                item !==
                                                                                weekday,
                                                                        ),
                                                            )
                                                        }
                                                    />
                                                    {label}
                                                    {alreadySaved && (
                                                        <span className="text-xs text-slate-500">
                                                            Already saved
                                                        </span>
                                                    )}
                                                </label>
                                            );
                                        })}
                                    </div>
                                    {weekdayPreferenceForm.errors.weekdays && (
                                        <p className="mt-1 text-xs text-red-600">
                                            {
                                                weekdayPreferenceForm.errors
                                                    .weekdays
                                            }
                                        </p>
                                    )}
                                </fieldset>
                                <div className="flex gap-2">
                                    <button
                                        type="submit"
                                        disabled={
                                            !weekdayPreferenceForm.data
                                                .doctor_id ||
                                            !weekdayPreferenceForm.data
                                                .shift_type_id ||
                                            weekdayPreferenceForm.data.weekdays
                                                .length === 0 ||
                                            weekdayPreferenceForm.processing
                                        }
                                        className="min-h-11 rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60"
                                    >
                                        {editingWeekdayPreferenceId === null
                                            ? 'Save preferences'
                                            : 'Update preference'}
                                    </button>
                                    {editingWeekdayPreferenceId !== null && (
                                        <button
                                            type="button"
                                            onClick={resetWeekdayPreferenceForm}
                                            className="min-h-11 rounded-lg border border-slate-300 px-4 py-2.5 text-sm"
                                        >
                                            Cancel
                                        </button>
                                    )}
                                </div>
                            </form>
                        )}
                        {props.weekdayPreferences.length === 0 ? (
                            <p className="mt-4 text-sm text-slate-500">
                                No monthly weekday preferences.
                            </p>
                        ) : (
                            <div className="mt-4 divide-y divide-slate-200">
                                {props.weekdayPreferences.map((preference) => (
                                    <article
                                        key={preference.id}
                                        className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <p className="font-semibold text-slate-900">
                                            {preference.doctor.name} -{' '}
                                            {preference.shift_type.name}:{' '}
                                            {preference.weekday_name}
                                            {preference.doctor.is_active ===
                                                false && (
                                                <span className="ml-2 text-xs font-normal text-slate-500">
                                                    Inactive
                                                </span>
                                            )}
                                            {preference.conflicts_with_restriction && (
                                                <span className="ml-2 text-xs font-semibold text-amber-800">
                                                    Conflicts with shift
                                                    restriction
                                                </span>
                                            )}
                                        </p>
                                        {canEditMonthlySetup && (
                                            <div className="flex gap-2">
                                                {preference.doctor.is_active !==
                                                    false && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            editWeekdayPreference(
                                                                preference,
                                                            )
                                                        }
                                                        className="min-h-10 rounded-lg border border-slate-300 px-3 text-sm"
                                                    >
                                                        Edit
                                                    </button>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        if (
                                                            window.confirm(
                                                                'Remove this weekday preference?',
                                                            )
                                                        ) {
                                                            router.delete(
                                                                destroyWeekdayPreference.url(
                                                                    {
                                                                        ...props.month,
                                                                        doctorMonthlyWeekdayPreference:
                                                                            preference.id,
                                                                    },
                                                                ),
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }
                                                    }}
                                                    className="min-h-10 rounded-lg border border-red-200 px-3 text-sm text-red-700"
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                        )}
                                    </article>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </section>

            <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                <h2 className="text-xl font-semibold text-slate-950">
                    <button
                        type="button"
                        aria-expanded={expandedSections.requests}
                        aria-controls="doctor-requests-content"
                        onClick={() => toggleSection('requests')}
                        className="flex w-full cursor-pointer items-center justify-between gap-4 rounded-lg text-left hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-700"
                    >
                        <span className="text-left">
                            <span className="block">Doctor Requests</span>
                            <span className="mt-1 block text-sm text-slate-600">
                                {isFinal
                                    ? `Review the requests and exclusions recorded for ${props.month.label}.`
                                    : `Add and review requests for ${props.month.label}.`}
                            </span>
                            <span className="mt-1 block text-xs text-slate-500">
                                {props.requests.length}{' '}
                                {props.requests.length === 1
                                    ? 'request'
                                    : 'requests'}
                            </span>
                        </span>
                        <Chevron expanded={expandedSections.requests} />
                    </button>
                </h2>
                <div
                    id="doctor-requests-content"
                    aria-hidden={!expandedSections.requests}
                    inert={!expandedSections.requests}
                    className="grid overflow-hidden transition-[grid-template-rows] duration-200 ease-out motion-reduce:transition-none"
                    style={{
                        gridTemplateRows: expandedSections.requests
                            ? '1fr'
                            : '0fr',
                    }}
                >
                    <div className="min-h-0 overflow-hidden">
                        <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-600">
                            {summaryItems.map(([label, value]) => (
                                <span key={label}>
                                    {label}:{' '}
                                    <strong className="text-slate-900">
                                        {value}
                                    </strong>
                                </span>
                            ))}
                        </div>

                        {canEditMonthlySetup && (
                            <form
                                onSubmit={submit}
                                className="mt-5 grid gap-4 rounded-xl bg-slate-50 p-4 sm:grid-cols-2 xl:grid-cols-6"
                            >
                                <label className="block xl:col-span-1">
                                    <span className="text-sm font-medium text-slate-800">
                                        Doctor
                                    </span>
                                    <select
                                        value={form.data.doctor_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'doctor_id',
                                                event.target.value
                                                    ? Number(event.target.value)
                                                    : '',
                                            )
                                        }
                                        className={fieldClassName}
                                        required
                                    >
                                        <option value="">Select doctor</option>
                                        {selectedRequest?.doctor.is_active ===
                                            false && (
                                            <option
                                                value={
                                                    selectedRequest.doctor.id
                                                }
                                            >
                                                {selectedRequest.doctor.name}{' '}
                                                (inactive)
                                            </option>
                                        )}
                                        {props.doctors
                                            .filter(
                                                (doctor) =>
                                                    requestKind !==
                                                        'monthly_exclusion' ||
                                                    doctor.id ===
                                                        form.data.doctor_id ||
                                                    !excludedDoctorIds.has(
                                                        doctor.id,
                                                    ),
                                            )
                                            .map((doctor) => (
                                                <option
                                                    key={doctor.id}
                                                    value={doctor.id}
                                                >
                                                    {doctor.short_code} -{' '}
                                                    {doctor.name}
                                                </option>
                                            ))}
                                    </select>
                                    {form.errors.doctor_id && (
                                        <p className="mt-1 text-xs text-red-600">
                                            {form.errors.doctor_id}
                                        </p>
                                    )}
                                </label>

                                <label className="block">
                                    <span className="text-sm font-medium text-slate-800">
                                        Request Type
                                    </span>
                                    <select
                                        value={requestKind}
                                        onChange={(event) =>
                                            changeRequestKind(
                                                event.target
                                                    .value as RequestKind,
                                            )
                                        }
                                        className={fieldClassName}
                                    >
                                        {(
                                            Object.keys(
                                                requestTypeLabels,
                                            ) as RequestKind[]
                                        ).map((kind) => (
                                            <option
                                                key={kind}
                                                value={kind}
                                                disabled={
                                                    editingId !== null &&
                                                    kind === 'monthly_exclusion'
                                                }
                                            >
                                                {requestTypeLabels[kind]}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                {requestKind !== 'monthly_exclusion' && (
                                    <>
                                        <label className="block">
                                            <span className="text-sm font-medium text-slate-800">
                                                Date
                                            </span>
                                            <input
                                                type="date"
                                                value={form.data.request_date}
                                                onChange={(event) => {
                                                    form.setData(
                                                        'request_date',
                                                        event.target.value,
                                                    );
                                                    form.setData(
                                                        'shift_type_id',
                                                        '',
                                                    );
                                                }}
                                                className={fieldClassName}
                                                required
                                            />
                                            {form.errors.request_date && (
                                                <p className="mt-1 text-xs text-red-600">
                                                    {form.errors.request_date}
                                                </p>
                                            )}
                                        </label>
                                        <label className="block">
                                            <span className="text-sm font-medium text-slate-800">
                                                Shift
                                            </span>
                                            <select
                                                value={form.data.shift_type_id}
                                                disabled={!form.data.request_date}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'shift_type_id',
                                                        event.target.value
                                                            ? Number(
                                                                  event.target
                                                                      .value,
                                                              )
                                                            : '',
                                                    )
                                                }
                                                className={fieldClassName}
                                                required={
                                                    requestKind ===
                                                    'preferred_work'
                                                }
                                            >
                                                <option value="">
                                                    {!form.data.request_date
                                                        ? 'Select date first'
                                                        : requestKind ===
                                                            'off_request'
                                                          ? 'Full Day'
                                                          : 'Select shift'}
                                                </option>
                                                {validShiftTypes.map(
                                                    (shiftType) => (
                                                        <option
                                                            key={shiftType.id}
                                                            value={shiftType.id}
                                                        >
                                                            {getShiftLabel(
                                                                shiftType,
                                                            )}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            {form.errors.shift_type_id && (
                                                <p className="mt-1 text-xs text-red-600">
                                                    {form.errors.shift_type_id}
                                                </p>
                                            )}
                                        </label>
                                    </>
                                )}

                                <label className="block xl:col-span-2">
                                    <span className="text-sm font-medium text-slate-800">
                                        Note
                                    </span>
                                    <input
                                        value={form.data.note}
                                        onChange={(event) =>
                                            form.setData(
                                                'note',
                                                event.target.value,
                                            )
                                        }
                                        className={fieldClassName}
                                        placeholder="Optional"
                                        maxLength={2000}
                                    />
                                    {form.errors.note && (
                                        <p className="mt-1 text-xs text-red-600">
                                            {form.errors.note}
                                        </p>
                                    )}
                                </label>

                                <div className="flex items-end gap-2 sm:col-span-2 xl:col-span-6">
                                    <button
                                        type="submit"
                                        disabled={
                                            form.processing ||
                                            exclusionProcessing
                                        }
                                        className="min-h-11 rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60"
                                    >
                                        {editingId !== null
                                            ? 'Save changes'
                                            : 'Add Request'}
                                    </button>
                                    {editingId !== null && (
                                        <button
                                            type="button"
                                            onClick={resetForm}
                                            className="min-h-11 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-800"
                                        >
                                            Cancel
                                        </button>
                                    )}
                                </div>
                            </form>
                        )}

                        {props.warnings.length > 0 && (
                            <details className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                                <summary className="cursor-pointer text-sm font-semibold text-amber-950">
                                    {props.warnings.length} setup warning
                                    {props.warnings.length === 1 ? '' : 's'}
                                </summary>
                                <ul className="mt-3 space-y-2 text-sm text-amber-950">
                                    {props.warnings.map((warning, index) => (
                                        <li
                                            key={`${warning.type}-${index}`}
                                            className="flex gap-2"
                                        >
                                            <span aria-hidden="true">⚠</span>
                                            <span>{warning.message}</span>
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        )}

                        <div className="mt-3 divide-y divide-slate-200">
                            {props.requests.length === 0 && (
                                <p className="py-6 text-center text-sm text-slate-500">
                                    No doctor requests for this month.
                                </p>
                            )}
                            {props.requests.map((request) => (
                                <article
                                    key={`${request.kind}-${request.id}`}
                                    className="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between"
                                >
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="font-semibold text-slate-950">
                                                {request.doctor.name}
                                            </p>
                                            <span className="rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                                {request.doctor.short_code}
                                            </span>
                                            <span className="rounded-full bg-teal-50 px-2.5 py-1 text-xs font-semibold text-teal-900">
                                                {
                                                    requestTypeLabels[
                                                        request.kind
                                                    ]
                                                }
                                            </span>
                                            {request.doctor.is_active ===
                                                false && (
                                                <span className="rounded-full bg-slate-200 px-2.5 py-1 text-xs font-medium text-slate-800">
                                                    Inactive
                                                </span>
                                            )}
                                            {request.is_late && (
                                                <span className="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">
                                                    Late Request
                                                </span>
                                            )}
                                            {request.has_date_limit_warning && (
                                                <span className="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">
                                                    4th+ requested date
                                                </span>
                                            )}
                                        </div>
                                        <p className="mt-1 text-sm text-slate-700">
                                            {request.kind ===
                                            'monthly_exclusion'
                                                ? request.date_label
                                                : `${request.date_label} · ${request.shift_label}`}
                                        </p>
                                        {request.note && (
                                            <p className="mt-1 text-sm break-words text-slate-600">
                                                {request.note}
                                            </p>
                                        )}
                                    </div>
                                    {canEditMonthlySetup && (
                                        <div className="flex shrink-0 gap-2">
                                            {request.kind !==
                                                'monthly_exclusion' && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        edit(request)
                                                    }
                                                    className="min-h-10 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50"
                                                >
                                                    Edit
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => remove(request)}
                                                className="min-h-10 rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
                                            >
                                                {request.kind ===
                                                'monthly_exclusion'
                                                    ? 'Remove'
                                                    : 'Delete'}
                                            </button>
                                        </div>
                                    )}
                                </article>
                            ))}
                        </div>
                    </div>
                </div>
            </section>
        </AppLayout>
    );
}
