import { dashboard, logout } from '@/routes';
import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

type AppLayoutProps = PropsWithChildren<{
    title: string;
}>;

export default function AppLayout({ title, children }: AppLayoutProps) {
    const { auth, flash } = usePage().props;

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900">
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
                    <div>
                        <p className="text-xs font-semibold tracking-[0.2em] text-teal-700 uppercase">
                            Administration
                        </p>
                        <p className="text-lg font-semibold">
                            Doctor Roster System
                        </p>
                    </div>
                    <nav className="flex flex-wrap items-center gap-2 text-sm font-medium">
                        <Link
                            href={dashboard.url()}
                            className="rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-100"
                        >
                            Dashboard
                        </Link>
                        <Link
                            href={logout.url()}
                            method="post"
                            as="button"
                            className="rounded-lg border border-slate-300 px-3 py-2 text-slate-700 hover:bg-slate-100"
                        >
                            Logout
                        </Link>
                    </nav>
                </div>
            </header>

            <main className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                {flash.status && (
                    <div
                        role="status"
                        className="mb-6 rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-sm font-medium text-teal-950"
                    >
                        {flash.status}
                    </div>
                )}
                <div className="mb-8 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-sm text-slate-500">
                            Signed in as {auth.user.name}
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                            {title}
                        </h1>
                    </div>
                </div>
                {children}
            </main>
        </div>
    );
}
