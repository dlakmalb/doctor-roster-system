import type { MonthlyWeekendPairGroup, WeekendDayMainCount, WeekendPairDoctor, WeekendPairGroupCode } from '@/types/weekend-pairs';

type Props = {
    code: WeekendPairGroupCode;
    group?: MonthlyWeekendPairGroup;
    positions: Array<number | ''>;
    capacity: WeekendDayMainCount;
    disabled: boolean;
    readOnly: boolean;
    savedDoctors: WeekendPairDoctor[];
    errors: Record<string, string | undefined>;
    onSelect: (position: number, doctorId: number | '') => void;
    onCapacityChange: (capacity: WeekendDayMainCount) => void;
};

export default function WeekendPairGroup({ code, group, positions, capacity, disabled, readOnly, savedDoctors, errors, onSelect, onCapacityChange }: Props) {
    const count = group?.doctors.length ?? 0;
    const mode = capacity === 3 ? 7 : 6;
    const supported = count >= 6;
    const selected = positions.filter((id) => id !== '');
    const selectedCount = new Set(selected).size;
    const outsideRotation = group?.doctors.filter((doctor) => !positions.includes(doctor.id)) ?? [];
    const ineligible = selected.some((id) => !group?.doctors.some((doctor) => doctor.id === id));
    const groupError = errors[`groups.${code}.doctor_ids`] ?? errors[`groups.${code}`];
    const capacityError = errors[`groups.${code}.weekend_day_main_count`];

    function selector(index: number) {
        const id = `group-${code}-doctor-${index}`;
        const value = positions[index] ?? '';
        const eligibleDoctor = group?.doctors.find((candidate) => candidate.id === value);
        const doctor = eligibleDoctor ?? savedDoctors.find((candidate) => candidate.id === value);
        const error = errors[`groups.${code}.doctor_ids.${index}`]
            ?? (!readOnly && value !== '' && !eligibleDoctor ? 'This saved doctor is not currently eligible. Select an eligible rotation doctor.' : undefined);

        return (
            <div key={id} className="min-w-0">
                <label htmlFor={id} className="text-sm font-medium text-slate-700">Doctor {index + 1}</label>
                {readOnly ? (
                    <p id={id} className="mt-1.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                        {doctor ? `${doctor.short_code} - ${doctor.name}${eligibleDoctor ? '' : ' (not currently eligible)'}` : value === '' ? 'Not configured' : `Saved doctor #${value}`}
                    </p>
                ) : (
                    <select
                        id={id}
                        name={`groups.${code}.doctor_ids.${index}`}
                        value={value}
                        disabled={disabled || !supported}
                        required
                        aria-invalid={Boolean(error)}
                        aria-describedby={[`group-${code}-swap-help`, error ? `${id}-error` : ''].filter(Boolean).join(' ')}
                        onChange={(event) => onSelect(index, event.target.value === '' ? '' : Number(event.target.value))}
                        className="mt-1.5 min-h-11 w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-100 disabled:bg-slate-50 disabled:text-slate-500"
                    >
                        <option value="">Select a doctor</option>
                        {value !== '' && !eligibleDoctor && (
                            <option value={value} disabled>{doctor ? `${doctor.short_code} - ${doctor.name}` : `Saved doctor #${value}`} (not currently eligible)</option>
                        )}
                        {group?.doctors.map((candidate) => (
                            <option key={candidate.id} value={candidate.id}>
                                {candidate.short_code} - {candidate.name}
                            </option>
                        ))}
                    </select>
                )}
                {error && <p id={`${id}-error`} className="mt-1 text-sm text-red-700">{error}</p>}
            </div>
        );
    }

    return (
        <section aria-labelledby={`group-${code}-title`} className="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 id={`group-${code}-title`} className="text-lg font-semibold text-slate-950">Group {code}</h2>
            <p className="mt-1 text-sm text-slate-600">
                {count} eligible participating doctors · {selectedCount} selected for weekend rotation
            </p>
            <fieldset className="mt-4" disabled={disabled || readOnly} aria-describedby={`group-${code}-capacity-help${capacityError ? ` group-${code}-capacity-error` : ''}`}>
                <legend className="text-sm font-semibold text-slate-800">Weekend Day Main capacity</legend>
                <div className="mt-2 grid gap-2">
                    {([2, 3] as const).map((value) => (
                        <label key={value} className="flex min-h-11 items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm has-checked:border-teal-600 has-checked:bg-teal-50 has-disabled:opacity-60">
                            <input
                                type="radio"
                                name={`groups.${code}.weekend_day_main_count`}
                                value={value}
                                checked={capacity === value}
                                disabled={count < (value === 3 ? 7 : 6)}
                                aria-invalid={Boolean(capacityError)}
                                onChange={() => onCapacityChange(value)}
                                className="accent-teal-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700"
                            />
                            {value} Main — {value === 3 ? 7 : 6} rotation doctors
                        </label>
                    ))}
                </div>
            </fieldset>
            <p className="mt-2 text-sm text-slate-700">Weekend Night Main: 2 doctors</p>
            <p id={`group-${code}-capacity-help`} className="mt-1 text-xs text-slate-500">This capacity applies to this group’s pair-enabled weekends. The separate legacy monthly Weekend Day Main capacity is unchanged.</p>
            {capacityError && <p id={`group-${code}-capacity-error`} role="alert" className="mt-2 text-sm text-red-700">{capacityError}</p>}
            <dl className="mt-4 grid gap-2 text-sm">
                <div><dt className="inline text-slate-500">Effective Saturday: </dt><dd className="inline font-medium">{group?.effective_saturday ?? 'Needs setup'}</dd></div>
                <div><dt className="inline text-slate-500">Applicable configuration: </dt><dd className="inline font-medium">{group?.configuration ? `Version ${group.configuration.version} · from ${group.configuration.effective_saturday}` : 'Not configured'}</dd></div>
            </dl>
            {!supported && <p className="mt-4 text-sm text-amber-900">At least six eligible doctors are required. Review Monthly Participation and Weekend Group Rotation.</p>}
            {!readOnly && (
                <div className="mt-3 space-y-1 text-sm text-amber-900" aria-live="polite">
                    {capacity === 3 && count < 7 && <p>3 Main requires at least seven eligible doctors. Choose 2 Main or review group eligibility.</p>}
                    {positions.some((id) => id === '') && <p>Fill all {mode} rotation positions before confirming.</p>}
                    {selectedCount !== selected.length && <p>Select each rotation doctor only once.</p>}
                    {ineligible && <p>Saved selections include doctors who are not currently eligible. Review the marked positions.</p>}
                </div>
            )}
            {groupError && <p role="alert" className="mt-3 text-sm text-red-700">{groupError}</p>}
            {errors[`groups.${code}.effective_saturday`] && <p role="alert" className="mt-3 text-sm text-red-700">{errors[`groups.${code}.effective_saturday`]}</p>}
            {!readOnly && <p id={`group-${code}-swap-help`} className="mt-4 text-xs text-slate-500">Choosing a doctor already in another position swaps the two positions. Changes stay local until confirmation.</p>}
            <div className="mt-4 grid gap-4">
                {[0, 1, 2].map((pair) => (
                    <fieldset key={pair} className="min-w-0 rounded-xl border border-slate-200 p-3">
                        <legend className="px-1 text-sm font-semibold text-slate-800">Pair {pair + 1}</legend>
                        <div className="grid gap-3 sm:grid-cols-2">{selector(pair * 2)}{selector(pair * 2 + 1)}</div>
                    </fieldset>
                ))}
                {mode === 7 && (
                    <fieldset className="min-w-0 rounded-xl border border-teal-200 bg-teal-50/40 p-3">
                        <legend className="px-1 text-sm font-semibold text-teal-900">7th Rotation Position</legend>
                        {selector(6)}
                        <p className="mt-2 text-sm text-teal-900">The additional Weekend Day Main position rotates among all seven selected doctors on successive scheduled weekends.</p>
                    </fieldset>
                )}
            </div>
            <p className="mt-4 text-sm text-slate-600">
                {mode === 7
                    ? 'With seven doctors, Weekend Day has three Main doctors and Weekend Night has two. The additional Day role rotates across all seven doctors over successive group weekends.'
                    : 'The three pairs rotate between Saturday Day, Saturday Night and Sunday Day. The Saturday Day pair also works Sunday Night.'}
            </p>
            <p className="mt-2 text-xs text-slate-500">The starting pair changes on each scheduled weekend for this group.</p>
            <section aria-labelledby={`group-${code}-outside-title`} className="mt-4 border-t border-slate-200 pt-4">
                <h3 id={`group-${code}-outside-title`} className="text-sm font-semibold text-slate-800">Eligible doctors outside rotation ({outsideRotation.length})</h3>
                <p className="mt-1 text-xs text-slate-500">These doctors remain eligible for ordinary Main and Optional duties, subject to existing scheduling rules.</p>
                {outsideRotation.length > 0 ? (
                    <ul className="mt-2 space-y-1 text-sm text-slate-700">
                        {outsideRotation.map((doctor) => <li key={doctor.id}>{doctor.short_code} — {doctor.name}</li>)}
                    </ul>
                ) : count > 0 ? (
                    <p className="mt-2 text-sm text-slate-600">All eligible doctors are selected for rotation.</p>
                ) : (
                    <p className="mt-2 text-sm text-slate-600">No eligible doctors are available.</p>
                )}
            </section>
        </section>
    );
}
