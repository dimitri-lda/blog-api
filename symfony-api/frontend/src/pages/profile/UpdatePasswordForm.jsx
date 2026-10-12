import InputError from '@/components/InputError';
import InputLabel from '@/components/InputLabel';
import PrimaryButton from '@/components/PrimaryButton';
import TextInput from '@/components/TextInput';
import { Transition } from '@headlessui/react';
import { useRef } from 'react';
import { useForm } from '@/lib/useForm';

export default function UpdatePasswordForm({ className = '' }) {
    const passwordInput = useRef();
    const currentPasswordInput = useRef();
    const {
        data,
        setData,
        errors,
        put,
        reset,
        processing,
        recentlySuccessful,
    } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword = e => {
        e.preventDefault();
        put('/profile/password', {
            onSuccess: () => reset(),
            onError: fieldErrors => {
                if (fieldErrors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }
                if (fieldErrors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-xl font-black text-slate-950">
                    Update Password
                </h2>
                <p className="mt-2 text-sm leading-6 text-slate-500">
                    Ensure your account is using a long, random password to stay
                    secure.
                </p>
            </header>
            <form onSubmit={updatePassword} className="mt-6 space-y-6">
                <div>
                    <InputLabel
                        htmlFor="current_password"
                        value="Current Password"
                    />
                    <TextInput
                        id="current_password"
                        ref={currentPasswordInput}
                        value={data.current_password}
                        onChange={e => setData('current_password', e.target.value)}
                        type="password"
                        className="mt-2 block w-full rounded-xl border-slate-200 px-4 py-3
                            text-slate-900 shadow-none focus:border-blue-500 focus:ring-blue-200"
                        autoComplete="current-password"
                    />
                    <InputError message={errors.current_password} className="mt-2" />
                </div>
                <div>
                    <InputLabel htmlFor="password" value="New Password" />
                    <TextInput
                        id="password"
                        ref={passwordInput}
                        value={data.password}
                        onChange={e => setData('password', e.target.value)}
                        type="password"
                        className="mt-2 block w-full rounded-xl border-slate-200 px-4 py-3
                            text-slate-900 shadow-none focus:border-blue-500 focus:ring-blue-200"
                        autoComplete="new-password"
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>
                <div>
                    <InputLabel
                        htmlFor="password_confirmation"
                        value="Confirm Password"
                    />
                    <TextInput
                        id="password_confirmation"
                        value={data.password_confirmation}
                        onChange={e => setData('password_confirmation', e.target.value)}
                        type="password"
                        className="mt-2 block w-full rounded-xl border-slate-200 px-4 py-3
                            text-slate-900 shadow-none focus:border-blue-500 focus:ring-blue-200"
                        autoComplete="new-password"
                    />
                    <InputError message={errors.password_confirmation} className="mt-2" />
                </div>
                <div className="flex items-center gap-4">
                    <PrimaryButton
                        className="rounded-full bg-slate-950 px-5 py-3 text-sm normal-case
                            tracking-normal hover:bg-blue-600 focus:bg-blue-600"
                        disabled={processing}
                    >
                        Update password
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
