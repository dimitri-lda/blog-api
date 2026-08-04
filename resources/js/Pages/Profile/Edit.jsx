import { usePage } from '@inertiajs/react';
import StoreLayout from '@/Layouts/StoreLayout';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({ mustVerifyEmail, status }) {
    const { auth } = usePage().props;

    return (
        <StoreLayout title="Profile settings">
            <main className="mx-auto max-w-6xl px-5 py-12 lg:px-8 lg:py-16">
                <div className="max-w-2xl">
                    <p className="text-xs font-bold uppercase tracking-[.25em] text-blue-600">
                        Your account
                    </p>
                    <h1 className="mt-3 text-4xl font-black tracking-tight">
                        Profile settings
                    </h1>
                    <p className="mt-3 text-slate-500">
                        Keep your details and account security up to date.
                    </p>
                </div>

                <div className="mt-10 grid gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
                    <div className="space-y-6">
                        <section className="rounded-3xl bg-white p-6 shadow-sm sm:p-8">
                            <UpdateProfileInformationForm
                                mustVerifyEmail={mustVerifyEmail}
                                status={status}
                            />
                        </section>

                        <section className="rounded-3xl bg-white p-6 shadow-sm sm:p-8">
                            <UpdatePasswordForm />
                        </section>

                        <section className="rounded-3xl border border-red-100 bg-red-50/50 p-6 sm:p-8">
                            <DeleteUserForm />
                        </section>
                    </div>

                    <aside className="h-fit rounded-3xl bg-slate-950 p-6 text-white lg:sticky lg:top-28">
                        <p className="text-xs font-bold uppercase tracking-[.2em] text-blue-300">
                            Signed in as
                        </p>
                        <p className="mt-4 break-words text-lg font-bold">
                            {auth.user.name}
                        </p>
                        <p className="mt-1 break-words text-sm text-slate-400">
                            {auth.user.email}
                        </p>
                        <div className="mt-6 border-t border-slate-800 pt-5 text-sm leading-6 text-slate-400">
                            Your personal details are used for checkout and order updates.
                        </div>
                    </aside>
                </div>
            </main>
        </StoreLayout>
    );
}
