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
    rosterStatus: 'not_started' | 'draft' | 'final';
    rosterAction: 'generate' | 'view_draft' | 'view_final';
    doctors: Doctor[];
    shiftTypes: ShiftType[];
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

export default function MonthlySetup(props: PageProps) {
    const isFinal = props.rosterStatus === 'final';
    const [requestKind, setRequestKind] = useState<RequestKind>('off_request');
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
    const validShiftTypes = useMemo(() => {
        if (!form.data.request_date) {
            return props.shiftTypes;
        }

        const day = new Date(`${form.data.request_date}T00:00:00`).getDay();
        const weekend = day === 0 || day === 6;

        return props.shiftTypes.filter((shiftType) =>
            weekend
                ? shiftType.code.startsWith('weekend_')
                : shiftType.code.startsWith('weekday_'),
        );
    }, [form.data.request_date, props.shiftTypes]);

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
                    onError: (errors) => form.setError(errors),
                    onFinish: () => setExclusionProcessing(false),
                },
            );
            return;
        }

        const options = { preserveScroll: true, onSuccess: resetForm };
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
                { preserveScroll: true, onSuccess: resetWeekdayPreferenceForm },
            );
            return;
        }

        const weekday = weekdayPreferenceForm.data.weekdays[0];
        if (weekday === undefined) {
            weekdayPreferenceForm.setError('weekdays', 'Select a weekday.');
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
                onError: (errors) => weekdayPreferenceForm.setError(errors),
            },
        );
    }

    function editWeekdayPreference(preference: WeekdayPreference): void {
        if (preference.doctor.is_active === false) {
            return;
        }
        setEditingWeekdayPreferenceId(preference.id);
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

            {rosterForm.errors.roster && (
                <p className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                    {rosterForm.errors.roster}
                </p>
            )}

            <section className="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                <div>
                    <h2 className="text-xl font-semibold text-slate-950">
                        Monthly Shift Restrictions
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Restrict a doctor from selected shift types for{' '}
                        {props.month.label}. No restriction means all shift
                        types are allowed.
                    </p>
                </div>
                {!isFinal && (
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
                                        shift_type_ids: restrictions.map(
                                            (item) => item.shift_type.id,
                                        ),
                                    });
                                    setEditingRestrictionId(
                                        restrictions[0]?.id ?? null,
                                    );
                                }}
                            >
                                <option value="">Select doctor</option>
                                {props.doctors.map((doctor) => (
                                    <option key={doctor.id} value={doctor.id}>
                                        {doctor.short_code} - {doctor.name}
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
                                {props.shiftTypes.map((shiftType) => (
                                    <label
                                        key={shiftType.id}
                                        className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white p-3 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            disabled={
                                                !restrictionForm.data.doctor_id
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
                                    {restrictionForm.errors.shift_type_ids}
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
                            const restrictions = props.shiftRestrictions.filter(
                                (item) => item.doctor.id === doctor.id,
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
                                                        item.shift_type.name,
                                                )
                                                .join(', ')}
                                        </p>
                                    </div>
                                    {!isFinal && (
                                        <div className="flex flex-wrap gap-2">
                                            {doctor.is_active !== false && (
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
                                            {restrictions.map((item) => (
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
                                                    {item.shift_type.name}
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </article>
                            );
                        })}
                    </div>
                )}
            </section>

            <section className="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                <div>
                    <h2 className="text-xl font-semibold text-slate-950">
                        Monthly Preferred Weekdays
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        These preferences gently influence scheduling for{' '}
                        {props.month.label}. They are not guaranteed assignments
                        and do not create extra duties.
                    </p>
                </div>
                {!isFinal && (
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
                                value={weekdayPreferenceForm.data.doctor_id}
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
                                    <option key={doctor.id} value={doctor.id}>
                                        {doctor.short_code} - {doctor.name}
                                    </option>
                                ))}
                            </select>
                            {weekdayPreferenceForm.errors.doctor_id && (
                                <p className="mt-1 text-xs text-red-600">
                                    {weekdayPreferenceForm.errors.doctor_id}
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
                                value={weekdayPreferenceForm.data.shift_type_id}
                                onChange={(event) =>
                                    weekdayPreferenceForm.setData({
                                        ...weekdayPreferenceForm.data,
                                        shift_type_id: event.target.value
                                            ? Number(event.target.value)
                                            : '',
                                        weekdays: [],
                                    })
                                }
                            >
                                <option value="">Select shift type</option>
                                {props.shiftTypes.map((shiftType) => (
                                    <option
                                        key={shiftType.id}
                                        value={shiftType.id}
                                    >
                                        {shiftType.name}
                                    </option>
                                ))}
                            </select>
                            {weekdayPreferenceForm.errors.shift_type_id && (
                                <p className="mt-1 text-xs text-red-600">
                                    {weekdayPreferenceForm.errors.shift_type_id}
                                </p>
                            )}
                        </label>
                        <fieldset className="sm:col-span-2">
                            <legend className="text-sm font-medium text-slate-800">
                                Preferred weekdays
                            </legend>
                            <div className="mt-2 flex flex-wrap gap-2">
                                {compatibleWeekdays.map((weekday) => {
                                    const label = new Intl.DateTimeFormat(
                                        'en',
                                        {
                                            weekday: 'long',
                                            timeZone: 'UTC',
                                        },
                                    ).format(
                                        new Date(Date.UTC(2024, 0, weekday)),
                                    );
                                    const alreadySaved =
                                        weekdayPreferenceForm.data.doctor_id !==
                                            '' &&
                                        weekdayPreferenceForm.data
                                            .shift_type_id !== '' &&
                                        props.weekdayPreferences.some(
                                            (preference) =>
                                                preference.id !==
                                                    editingWeekdayPreferenceId &&
                                                preference.doctor.id ===
                                                    weekdayPreferenceForm.data
                                                        .doctor_id &&
                                                preference.shift_type.id ===
                                                    weekdayPreferenceForm.data
                                                        .shift_type_id &&
                                                preference.weekday === weekday,
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
                                                    !weekdayPreferenceForm.data
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
                                                            : event.target
                                                                    .checked
                                                              ? [
                                                                    ...weekdayPreferenceForm
                                                                        .data
                                                                        .weekdays,
                                                                    weekday,
                                                                ]
                                                              : weekdayPreferenceForm.data.weekdays.filter(
                                                                    (item) =>
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
                                    {weekdayPreferenceForm.errors.weekdays}
                                </p>
                            )}
                        </fieldset>
                        <div className="flex gap-2">
                            <button
                                type="submit"
                                disabled={
                                    !weekdayPreferenceForm.data.doctor_id ||
                                    !weekdayPreferenceForm.data.shift_type_id ||
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
                                    {preference.doctor.is_active === false && (
                                        <span className="ml-2 text-xs font-normal text-slate-500">
                                            Inactive
                                        </span>
                                    )}
                                    {preference.conflicts_with_restriction && (
                                        <span className="ml-2 text-xs font-semibold text-amber-800">
                                            Conflicts with shift restriction
                                        </span>
                                    )}
                                </p>
                                {!isFinal && (
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
            </section>

            <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 className="text-xl font-semibold text-slate-950">
                            Doctor Requests
                        </h2>
                        <p className="mt-1 text-sm text-slate-600">
                            {isFinal
                                ? `Review the requests and exclusions recorded for ${props.month.label}.`
                                : `Add and review requests for ${props.month.label}.`}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-600">
                        {summaryItems.map(([label, value]) => (
                            <span key={label}>
                                {label}:{' '}
                                <strong className="text-slate-900">
                                    {value}
                                </strong>
                            </span>
                        ))}
                    </div>
                </div>

                {!isFinal && (
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
                                    <option value={selectedRequest.doctor.id}>
                                        {selectedRequest.doctor.name} (inactive)
                                    </option>
                                )}
                                {props.doctors
                                    .filter(
                                        (doctor) =>
                                            requestKind !==
                                                'monthly_exclusion' ||
                                            doctor.id === form.data.doctor_id ||
                                            !excludedDoctorIds.has(doctor.id),
                                    )
                                    .map((doctor) => (
                                        <option
                                            key={doctor.id}
                                            value={doctor.id}
                                        >
                                            {doctor.short_code} - {doctor.name}
                                        </option>
                                    ))}
                            </select>
                            {form.errors.doctor_id && (
                                <p className="mt-1 text-xs text-red-600">
                                    {form.errors.doctor_id}
                                </p>
                            )}
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
                                            form.setData('shift_type_id', '');
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
                                        onChange={(event) =>
                                            form.setData(
                                                'shift_type_id',
                                                event.target.value
                                                    ? Number(event.target.value)
                                                    : '',
                                            )
                                        }
                                        className={fieldClassName}
                                        required={
                                            requestKind === 'preferred_work'
                                        }
                                    >
                                        <option value="">
                                            {requestKind === 'off_request'
                                                ? 'Full Day'
                                                : 'Select shift'}
                                        </option>
                                        {validShiftTypes.map((shiftType) => (
                                            <option
                                                key={shiftType.id}
                                                value={shiftType.id}
                                            >
                                                {getShiftLabel(shiftType)}
                                            </option>
                                        ))}
                                    </select>
                                    {form.errors.shift_type_id && (
                                        <p className="mt-1 text-xs text-red-600">
                                            {form.errors.shift_type_id}
                                        </p>
                                    )}
                                </label>
                            </>
                        )}

                        <label className="block">
                            <span className="text-sm font-medium text-slate-800">
                                Request Type
                            </span>
                            <select
                                value={requestKind}
                                onChange={(event) =>
                                    changeRequestKind(
                                        event.target.value as RequestKind,
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

                        <label className="block xl:col-span-2">
                            <span className="text-sm font-medium text-slate-800">
                                Note
                            </span>
                            <input
                                value={form.data.note}
                                onChange={(event) =>
                                    form.setData('note', event.target.value)
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
                                    form.processing || exclusionProcessing
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
                                        {requestTypeLabels[request.kind]}
                                    </span>
                                    {request.doctor.is_active === false && (
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
                                    {request.kind === 'monthly_exclusion'
                                        ? request.date_label
                                        : `${request.date_label} · ${request.shift_label}`}
                                </p>
                                {request.note && (
                                    <p className="mt-1 text-sm break-words text-slate-600">
                                        {request.note}
                                    </p>
                                )}
                            </div>
                            {!isFinal && (
                                <div className="flex shrink-0 gap-2">
                                    {request.kind !== 'monthly_exclusion' && (
                                        <button
                                            type="button"
                                            onClick={() => edit(request)}
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
                                        {request.kind === 'monthly_exclusion'
                                            ? 'Remove'
                                            : 'Delete'}
                                    </button>
                                </div>
                            )}
                        </article>
                    ))}
                </div>
            </section>
        </AppLayout>
    );
}
