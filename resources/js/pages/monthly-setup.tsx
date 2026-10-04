import {
    destroy as destroyExclusion,
    store as storeExclusion,
} from '@/actions/App/Http/Controllers/DoctorMonthlyExclusionController';
import {
    destroy as destroyRequest,
    store as storeRequest,
    update as updateRequest,
} from '@/actions/App/Http/Controllers/DoctorRequestController';
import AppLayout from '@/components/app-layout';
import { show as showRoster } from '@/routes/rosters';
import { generateRoster as generateRosterFromSetup } from '@/routes/monthly-setup';
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
    type: 'late_request' | 'day_off_limit' | 'staffing_risk';
    message: string;
};

type PageProps = {
    month: Month;
    rosterStatus: 'not_started' | 'draft' | 'final';
    rosterAction: 'generate' | 'view_draft' | 'view_final';
    doctors: Doctor[];
    shiftTypes: ShiftType[];
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
            </div>

            {isFinal && (
                <div className="mb-5 flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="font-semibold text-amber-950">
                            Monthly Setup is locked
                        </h2>
                        <p className="mt-1 text-sm text-amber-900">
                            This roster is Final. Reopen the roster before
                            changing requests or exclusions.
                        </p>
                    </div>
                </div>
            )}

            {rosterForm.errors.roster && (
                <p className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                    {rosterForm.errors.roster}
                </p>
            )}

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
