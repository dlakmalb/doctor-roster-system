import { store } from '@/actions/App/Http/Controllers/Auth/LoginController';
import { Form, Head } from '@inertiajs/react';

export default function Login() {
    return (
        <>
            <Head title="Login" />
            <main className="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-12">
                <section className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
                    <p className="text-sm font-semibold tracking-[0.18em] text-teal-700 uppercase">
                        Doctor Roster System
                    </p>
                    <h1 className="mt-3 text-3xl font-semibold tracking-tight text-slate-900">
                        Admin login
                    </h1>
                    <p className="mt-2 text-sm text-slate-600">
                        Sign in to prepare monthly roster information.
                    </p>

                    <Form {...store.form()} className="mt-8 space-y-5">
                        {({ errors, processing }) => (
                            <>
                                <label className="block">
                                    <span className="text-sm font-medium text-slate-700">
                                        Email
                                    </span>
                                    <input
                                        name="email"
                                        type="email"
                                        autoComplete="email"
                                        required
                                        autoFocus
                                        className="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-100"
                                    />
                                    {errors.email && (
                                        <span className="mt-1 block text-sm text-red-600">
                                            {errors.email}
                                        </span>
                                    )}
                                </label>

                                <label className="block">
                                    <span className="text-sm font-medium text-slate-700">
                                        Password
                                    </span>
                                    <input
                                        name="password"
                                        type="password"
                                        autoComplete="current-password"
                                        required
                                        className="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-100"
                                    />
                                    {errors.password && (
                                        <span className="mt-1 block text-sm text-red-600">
                                            {errors.password}
                                        </span>
                                    )}
                                </label>

                                <label className="flex items-center gap-2 text-sm text-slate-600">
                                    <input
                                        type="checkbox"
                                        name="remember"
                                        value="1"
                                        className="size-4 rounded border-slate-300 text-teal-700"
                                    />
                                    Remember me
                                </label>

                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full rounded-lg bg-teal-700 px-4 py-2.5 font-semibold text-white hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {processing ? 'Signing in…' : 'Sign in'}
                                </button>
                            </>
                        )}
                    </Form>
                </section>
            </main>
        </>
    );
}
