import type { MonthlyWeekendPairGroup, WeekendPairDoctor, WeekendPairGroupCode } from '@/types/weekend-pairs';

type Props = {
    code: WeekendPairGroupCode;
    group?: MonthlyWeekendPairGroup;
    positions: Array<number | ''>;
    disabled: boolean;
    readOnly: boolean;
    savedDoctors: WeekendPairDoctor[];
    errors: Record<string, string | undefined>;
    onSelect: (position: number, doctorId: number | '') => void;
};

export default function WeekendPairGroup({ code, group, positions, disabled, readOnly, savedDoctors, errors, onSelect }: Props) {
    const count = group?.doctors.length ?? 0;
    const mode = readOnly ? group?.configuration?.mode ?? count : count;
    const supported = count === 6 || count === 7;
    const groupError = errors[`groups.${code}.doctor_ids`] ?? errors[`groups.${code}`];

    function selector(index: number) {
        const id = `group-${code}-doctor-${index}`;
        const error = errors[`groups.${code}.doctor_ids.${index}`];
        const value = positions[index] ?? '';
        const eligibleDoctor = group?.doctors.find((candidate) => candidate.id === value);
        const doctor = eligibleDoctor ?? savedDoctors.find((candidate) => candidate.id === value);

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
                {count} eligible participating doctors{[6, 7].includes(mode) ? ` · ${mode}-doctor mode` : ''}
            </p>
            <dl className="mt-4 grid gap-2 text-sm">
                <div><dt className="inline text-slate-500">Effective Saturday: </dt><dd className="inline font-medium">{group?.effective_saturday ?? 'Needs setup'}</dd></div>
                <div><dt className="inline text-slate-500">Applicable configuration: </dt><dd className="inline font-medium">{group?.configuration ? `Version ${group.configuration.version} · from ${group.configuration.effective_saturday}` : 'Not configured'}</dd></div>
            </dl>
            {!supported && <p className="mt-4 text-sm text-amber-900">The MVP supports six or seven participating doctors in each group. Review exclusions and Weekend Group Rotation.</p>}
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
                        <legend className="px-1 text-sm font-semibold text-teal-900">Additional Rotation Position</legend>
                        {selector(6)}
                    </fieldset>
                )}
            </div>
            <p className="mt-4 text-sm text-slate-600">
                {mode === 7
                    ? 'With seven doctors, Weekend Day has three Main doctors and Weekend Night has two. The additional Day role rotates across all seven doctors over successive group weekends.'
                    : 'The three pairs rotate between Saturday Day, Saturday Night and Sunday Day. The Saturday Day pair also works Sunday Night.'}
            </p>
            <p className="mt-2 text-xs text-slate-500">The starting pair changes on each scheduled weekend for this group.</p>
        </section>
    );
}
