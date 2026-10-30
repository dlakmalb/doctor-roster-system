export type WeekendPairGroupCode = 'A' | 'B';

export type WeekendPairDoctor = {
    id: number;
    name: string;
    short_code: string;
};

export type WeekendPairConfiguration = {
    id: number;
    version: number;
    effective_saturday: string;
    mode: 6 | 7;
    doctor_ids: number[];
};

export type MonthlyWeekendPairGroup = {
    effective_saturday: string;
    doctors: WeekendPairDoctor[];
    configuration: WeekendPairConfiguration | null;
    latest_version_id: number | null;
};

export type MonthlyWeekendPairState = {
    year: number;
    month: number;
    status:
        | 'not_configured'
        | 'ready_to_confirm'
        | 'confirmed'
        | 'requires_attention';
    read_only: boolean;
    groups: Partial<Record<WeekendPairGroupCode, MonthlyWeekendPairGroup>>;
    issues: string[];
    revision: string;
    population_fingerprint: string | null;
    confirmation: {
        id: number;
        configuration_ids: Record<WeekendPairGroupCode, number>;
        boundary_configuration_id: number | null;
        confirmed_at: string | null;
    } | null;
    boundary: {
        saturday: string;
        group: WeekendPairGroupCode;
        state: 'legacy' | 'pair_rotation';
        configuration: WeekendPairConfiguration | null;
        compatible: boolean | null;
    } | null;
};

export type WeekendPairPayload = {
    revision: string;
    groups: Record<
        WeekendPairGroupCode,
        {
            effective_saturday: string;
            doctor_ids: Array<number | ''>;
        }
    >;
};

export type WeekendPairPageProps = {
    month: { year: number; month: number; label: string };
    configuration: MonthlyWeekendPairState;
    savedDoctors: WeekendPairDoctor[];
    links: {
        show: string;
        confirm: string;
        monthlySetup: string;
        weekendGroups: string;
        roster: string;
    };
};
