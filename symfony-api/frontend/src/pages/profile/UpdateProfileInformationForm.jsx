import { useQueryClient } from '@tanstack/react-query';
import InputError from '@/components/InputError';
import InputLabel from '@/components/InputLabel';
import PrimaryButton from '@/components/PrimaryButton';
import TextInput from '@/components/TextInput';
import { Transition } from '@headlessui/react';
import { useApp } from '@/app/AppContext';
import { useForm } from '@/lib/useForm';

export default function UpdateProfileInformation({ className = '' }) {
    const queryClient = useQueryClient();
    const { auth, refetchContext } = useApp();
    const user = auth.user;

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
    });

    const submit = e => {
        e.preventDefault();
        patch('/profile', {
            onSuccess: async response => {
                queryClient.setQueryData(['profile'], response);
                await refetchContext();
            },
        });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-xl font-black text-slate-950">
                    Profile Information
                </h2>
                <p className="mt-2 text-sm leading-6 text-slate-500">
                    Update your account's profile information and email address.
                </p>
            </header>
            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="name" value="Name" />
                    <TextInput
                        id="name"
                        className="mt-2 block w-full rounded-xl border-slate-200 px-4 py-3
                            text-slate-900 shadow-none focus:border-blue-500 focus:ring-blue-200"
                        value={data.name}
                        onChange={e => setData('name', e.target.value)}
                        required
                        isFocused
                        autoComplete="name"
                    />
                    <InputError className="mt-2" message={errors.name} />
                </div>
                <div>
                    <InputLabel htmlFor="email" value="Email" />
                    <TextInput
                        id="email"
                        type="email"
                        className="mt-2 block w-full rounded-xl border-slate-200 px-4 py-3
                            text-slate-900 shadow-none focus:border-blue-500 focus:ring-blue-200"
                        value={data.email}
                        onChange={e => setData('email', e.target.value)}
                        required
                        autoComplete="username"
                    />
                    <InputError className="mt-2" message={errors.email} />
                </div>
                <div className="flex items-center gap-4">
                    <PrimaryButton
                        className="rounded-full bg-slate-950 px-5 py-3 text-sm normal-case
                            tracking-normal hover:bg-blue-600 focus:bg-blue-600"
                        disabled={processing}
                    >
                        Save changes
                    </PrimaryButton>
                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm font-medium text-blue-700">Saved.</p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
