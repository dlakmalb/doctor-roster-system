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
import { show as monthlySetup } from '@/routes/monthly-setup';
import { show as showRoster, store as storeRoster } from '@/routes/rosters';
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

type DoctorRequest = {
    id: number;
    request_type: RequestType;
    request_date: string;
    date_label: string;
    doctor: Doctor;
    shift_type: Pick<ShiftType, 'id' | 'code' | 'name'> | null;
    period_label: string;
    note: string | null;
    is_late: boolean;
};

type Exclusion = {
    id: number;
    doctor: Doctor;
    note: string | null;
};

type RequestType = 'day_off' | 'preferred_work';

type Month = {
    year: number;
    month: number;
    label: string;
    previous: { year: number; month: number };
    next: { year: number; month: number };
};

type Warning = {
    type: 'late_request' | 'day_off_limit' | 'staffing_risk';
    message: string;
};

type PageProps = {
    month: Month;
    rosterStatus: 'not_started' | 'draft' | 'final';
    doctors: Doctor[];
    shiftTypes: ShiftType[];
    dayOffRequests: DoctorRequest[];
    preferredWorkRequests: DoctorRequest[];
    exclusions: Exclusion[];
    summary: {
        active_doctors: number;
        day_off_requests: number;
        preferred_work_requests: number;
        excluded_doctors: number;
    };
    warnings: Warning[];
};

type RequestPayload = {
    doctor_id: number | '';
    request_type: RequestType;
    request_date: string;
    shift_type_id: number | '';
    note: string;
};

const fieldClassName =
    'mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-100';

function RequestForm({
    month,
    doctors,
    shiftTypes,
    requests,
    requestType,
}: {
    month: Month;
    doctors: Doctor[];
    shiftTypes: ShiftType[];
    requests: DoctorRequest[];
    requestType: RequestType;
}) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const form = useForm<RequestPayload>({
        doctor_id: '',
        request_type: requestType,
        request_date: '',
        shift_type_id: '',
        note: '',
    });
    const selectedRequest = requests.find(
        (request) => request.id === editingId,
    );
    const validShiftTypes = useMemo(() => {
        if (!form.data.request_date) {
            return shiftTypes;
        }

        const day = new Date(`${form.data.request_date}T00:00:00`).getDay();
        const weekend = day === 0 || day === 6;

        return shiftTypes.filter((shiftType) =>
            weekend
                ? shiftType.code.startsWith('weekend_')
                : shiftType.code.startsWith('weekday_'),
        );
    }, [form.data.request_date, shiftTypes]);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.setData('request_type', requestType);
                setEditingId(null);
            },
        };

        if (editingId === null) {
            form.post(storeRequest.url(month), options);
            return;
        }

        form.put(
            updateRequest.url({ ...month, doctorRequest: editingId }),
            options,
        );
    }

    function edit(request: DoctorRequest) {
        setEditingId(request.id);
        form.setData({
            doctor_id: request.doctor.id,
            request_type: request.request_type,
            request_date: request.request_date,
            shift_type_id: request.shift_type?.id ?? '',
            note: request.note ?? '',
        });
        form.clearErrors();
    }

    function cancelEdit() {
        setEditingId(null);
        form.reset();
        form.setData('request_type', requestType);
        form.clearErrors();
    }

    function remove(request: DoctorRequest) {
        if (!window.confirm('Delete this request?')) {
            return;
        }

        router.delete(
            destroyRequest.url({ ...month, doctorRequest: request.id }),
            { preserveScroll: true },
        );
    }

    const title =
        requestType === 'day_off'
            ? 'Day-Off Requests'
            : 'Preferred Work Requests';

    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="text-xl font-semibold">{title}</h2>
                    <p className="mt-1 text-sm text-slate-500">
                        {requestType === 'day_off'
                            ? 'Full-day and shift-specific unavailability.'
                            : 'Shift-specific preferences only.'}
                    </p>
                </div>
                <span className="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">
                    {requests.length}
                </span>
            </div>

            <form
                onSubmit={submit}
                className="mt-6 grid gap-4 rounded-xl bg-slate-50 p-4 md:grid-cols-2 xl:grid-cols-5"
            >
                <label className="block">
                    <span className="text-sm font-medium">Doctor</span>
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
                        {selectedRequest?.doctor.is_active === false && (
                            <option value={selectedRequest.doctor.id}>
                                {selectedRequest.doctor.name} (inactive)
                            </option>
                        )}
                        {doctors.map((doctor) => (
                            <option key={doctor.id} value={doctor.id}>
                                {doctor.short_code} — {doctor.name}
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
                    <span className="text-sm font-medium">Date</span>
                    <input
                        type="date"
                        value={form.data.request_date}
                        onChange={(event) => {
                            form.setData('request_date', event.target.value);
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
                    <span className="text-sm font-medium">Shift</span>
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
                        required={requestType === 'preferred_work'}
                    >
                        <option value="">
                            {requestType === 'day_off'
                                ? 'Full Day'
                                : 'Select shift'}
                        </option>
                        {validShiftTypes.map((shiftType) => (
                            <option key={shiftType.id} value={shiftType.id}>
                                {shiftType.name}
                            </option>
                        ))}
                    </select>
                    {form.errors.shift_type_id && (
                        <p className="mt-1 text-xs text-red-600">
                            {form.errors.shift_type_id}
                        </p>
                    )}
                </label>

                <label className="block">
                    <span className="text-sm font-medium">Note</span>
                    <input
                        value={form.data.note}
                        onChange={(event) =>
                            form.setData('note', event.target.value)
                        }
                        className={fieldClassName}
                        placeholder="Optional"
                    />
                    {form.errors.note && (
                        <p className="mt-1 text-xs text-red-600">
                            {form.errors.note}
                        </p>
                    )}
                </label>

                <div className="flex items-end gap-2">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60"
                    >
                        {editingId === null ? 'Add request' : 'Save changes'}
                    </button>
                    {editingId !== null && (
                        <button
                            type="button"
                            onClick={cancelEdit}
                            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold"
                        >
                            Cancel
                        </button>
                    )}
                </div>
            </form>

            <div className="mt-5 divide-y divide-slate-200">
                {requests.length === 0 && (
                    <p className="py-6 text-center text-sm text-slate-500">
                        No requests entered for this month.
                    </p>
                )}
                {requests.map((request) => (
                    <article
                        key={request.id}
                        className="flex flex-col gap-3 py-4 lg:flex-row lg:items-center lg:justify-between"
                    >
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="font-semibold">
                                    {request.doctor.name}
                                </p>
                                <span className="rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold">
                                    {request.doctor.short_code}
                                </span>
                                {request.doctor.is_active === false && (
                                    <span className="rounded bg-slate-200 px-2 py-0.5 text-xs">
                                        Inactive
                                    </span>
                                )}
                                {request.is_late && (
                                    <span className="rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">
                                        Late
                                    </span>
                                )}
                            </div>
                            <p className="mt-1 text-sm text-slate-700">
                                {request.date_label} · {request.period_label}
                            </p>
                            {request.note && (
                                <p className="mt-1 text-sm text-slate-500">
                                    {request.note}
                                </p>
                            )}
                        </div>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={() => edit(request)}
                                className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium hover:bg-slate-50"
                            >
                                Edit
                            </button>
                            <button
                                type="button"
                                onClick={() => remove(request)}
                                className="rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50"
                            >
                                Delete
                            </button>
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}

function ExclusionsSection({
    month,
    doctors,
    exclusions,
}: {
    month: Month;
    doctors: Doctor[];
    exclusions: Exclusion[];
}) {
    const form = useForm<{ doctor_id: number | ''; note: string }>({
        doctor_id: '',
        note: '',
    });
    const excludedDoctorIds = new Set(
        exclusions.map((exclusion) => exclusion.doctor.id),
    );

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(storeExclusion.url(month), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    function remove(exclusion: Exclusion) {
        if (!window.confirm('Remove this monthly exclusion?')) {
            return;
        }

        router.delete(
            destroyExclusion.url({
                ...month,
                doctorMonthlyExclusion: exclusion.id,
            }),
            { preserveScroll: true },
        );
    }

    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div className="flex items-center justify-between gap-4">
                <div>
                    <h2 className="text-xl font-semibold">
                        Monthly Exclusions
                    </h2>
                    <p className="mt-1 text-sm text-slate-500">
                        Doctors unavailable for the whole selected month.
                    </p>
                </div>
                <span className="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">
                    {exclusions.length}
                </span>
            </div>

            <form
                onSubmit={submit}
                className="mt-6 grid gap-4 rounded-xl bg-slate-50 p-4 md:grid-cols-[1fr_2fr_auto]"
            >
                <label>
                    <span className="text-sm font-medium">Doctor</span>
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
                        {doctors
                            .filter(
                                (doctor) => !excludedDoctorIds.has(doctor.id),
                            )
                            .map((doctor) => (
                                <option key={doctor.id} value={doctor.id}>
                                    {doctor.short_code} — {doctor.name}
                                </option>
                            ))}
                    </select>
                    {form.errors.doctor_id && (
                        <p className="mt-1 text-xs text-red-600">
                            {form.errors.doctor_id}
                        </p>
                    )}
                </label>
                <label>
                    <span className="text-sm font-medium">Note</span>
                    <input
                        value={form.data.note}
                        onChange={(event) =>
                            form.setData('note', event.target.value)
                        }
                        className={fieldClassName}
                        placeholder="Optional reason"
                        maxLength={2000}
                    />
                    {form.errors.note && (
                        <p className="mt-1 text-xs text-red-600">
                            {form.errors.note}
                        </p>
                    )}
                </label>
                <div className="flex items-end">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60"
                    >
                        Add exclusion
                    </button>
                </div>
            </form>

            <div className="mt-5 divide-y divide-slate-200">
                {exclusions.length === 0 && (
                    <p className="py-6 text-center text-sm text-slate-500">
                        No doctors excluded this month.
                    </p>
                )}
                {exclusions.map((exclusion) => (
                    <div
                        key={exclusion.id}
                        className="flex items-center justify-between gap-4 py-4"
                    >
                        <div>
                            <p className="font-semibold">
                                {exclusion.doctor.name}
                            </p>
                            <p className="mt-1 text-sm text-slate-500">
                                {exclusion.note || 'No note'}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => remove(exclusion)}
                            className="rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50"
                        >
                            Remove
                        </button>
                    </div>
                ))}
            </div>
        </section>
    );
}

export default function MonthlySetup(props: PageProps) {
    const rosterForm = useForm<{ roster?: string }>({});
    const summaryItems = [
        ['Active Doctors', props.summary.active_doctors],
        ['Day-Off Requests', props.summary.day_off_requests],
        ['Preferred Work', props.summary.preferred_work_requests],
        ['Excluded Doctors', props.summary.excluded_doctors],
    ];

    return (
        <AppLayout title={`${props.month.label} Monthly Setup`}>
            <Head title={`${props.month.label} Monthly Setup`} />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <Link
                    href={monthlySetup.url(props.month.previous)}
                    className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50"
                >
                    ← Previous Month
                </Link>
                <p className="text-sm text-slate-500">
                    Roster status:{' '}
                    <span className="font-semibold text-slate-700">
                        {props.rosterStatus === 'not_started'
                            ? 'Not Started'
                            : props.rosterStatus === 'draft'
                              ? 'Draft'
                              : 'Final'}
                    </span>
                </p>
                <Link
                    href={monthlySetup.url(props.month.next)}
                    className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50"
                >
                    Next Month →
                </Link>
            </div>

            <section className="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-teal-200 bg-teal-50 p-5 sm:p-6">
                <div>
                    <h2 className="text-lg font-semibold text-teal-950">
                        Monthly roster structure
                    </h2>
                    <p className="mt-1 text-sm text-teal-800">
                        {props.rosterStatus === 'not_started'
                            ? 'Create the dates and required shifts after reviewing monthly setup.'
                            : 'The shift structure is ready for review.'}
                    </p>
                    {rosterForm.errors.roster && (
                        <p className="mt-2 text-sm font-medium text-red-700">
                            {rosterForm.errors.roster}
                        </p>
                    )}
                </div>
                {props.rosterStatus === 'not_started' ? (
                    <button
                        type="button"
                        disabled={rosterForm.processing}
                        onClick={() =>
                            rosterForm.post(storeRoster.url(props.month))
                        }
                        className="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60"
                    >
                        {rosterForm.processing
                            ? 'Creating...'
                            : 'Create Draft Roster'}
                    </button>
                ) : (
                    <Link
                        href={showRoster.url(props.month)}
                        className="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800"
                    >
                        View Roster
                    </Link>
                )}
            </section>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
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

            <section className="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5">
                <h2 className="font-semibold text-amber-950">Warnings</h2>
                {props.warnings.length === 0 ? (
                    <p className="mt-2 text-sm text-amber-800">
                        No setup warnings for this month.
                    </p>
                ) : (
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
                )}
            </section>

            <div className="mt-6 space-y-6">
                <RequestForm
                    month={props.month}
                    doctors={props.doctors}
                    shiftTypes={props.shiftTypes}
                    requests={props.dayOffRequests}
                    requestType="day_off"
                />
                <RequestForm
                    month={props.month}
                    doctors={props.doctors}
                    shiftTypes={props.shiftTypes}
                    requests={props.preferredWorkRequests}
                    requestType="preferred_work"
                />
                <ExclusionsSection
                    month={props.month}
                    doctors={props.doctors}
                    exclusions={props.exclusions}
                />
            </div>
        </AppLayout>
    );
}
