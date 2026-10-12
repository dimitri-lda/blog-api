import { Link, useNavigate } from 'react-router-dom';
import { useState } from 'react';
import Checkbox from '@/components/Checkbox';
import InputError from '@/components/InputError';
import InputLabel from '@/components/InputLabel';
import PrimaryButton from '@/components/PrimaryButton';
import TextInput from '@/components/TextInput';
import GuestLayout from '@/layouts/GuestLayout';
import { useApp } from '@/app/AppContext';
import { useForm } from '@/lib/useForm';

export default function Register() {
    const navigate = useNavigate();
    const { refetchContext } = useApp();
    const [unhandledError, setUnhandledError] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        accepted_terms: false,
    });
    const submit = event => {
        event.preventDefault();
        setUnhandledError(false);
        post('/auth/register', {
            onSuccess: async () => {
                await refetchContext();
                navigate('/');
            },
            onError: fieldErrors => {
                if (Object.keys(fieldErrors).length === 0) {
                    setUnhandledError(true);
                }
            },
        });
    };

    return <GuestLayout title="Create your account">
        <form onSubmit={submit} className="space-y-4">
            <div className="mb-8">
                <h1 className="text-2xl font-black text-slate-950">Create your account</h1>
                <p className="mt-2 text-sm text-slate-500">
                    Save your bag, track orders and move better.
                </p>
            </div>
            {unhandledError && <p className="text-sm text-red-600">Registration failed.</p>}
            <div>
                <InputLabel htmlFor="name" value="Full name" />
                <TextInput
                    id="name"
                    name="name"
                    value={data.name}
                    className="mt-1 block w-full"
                    autoComplete="name"
                    isFocused={true}
                    onChange={e => setData('name', e.target.value)}
                    required
                />
                <InputError message={errors.name} className="mt-2" />
            </div>
            <div className="mt-4">
                <InputLabel htmlFor="email" value="Email" />
                <TextInput
                    id="email"
                    type="email"
                    name="email"
                    value={data.email}
                    className="mt-1 block w-full"
                    autoComplete="username"
                    onChange={e => setData('email', e.target.value)}
                    required
                />
                <InputError message={errors.email} className="mt-2" />
            </div>
            <div className="mt-4">
                <InputLabel htmlFor="password" value="Password" />
                <TextInput
                    id="password"
                    type="password"
                    name="password"
                    value={data.password}
                    className="mt-1 block w-full"
                    autoComplete="new-password"
                    onChange={e => setData('password', e.target.value)}
                    required
                />
                <InputError message={errors.password} className="mt-2" />
            </div>
            <div className="mt-4">
                <InputLabel htmlFor="password_confirmation" value="Confirm Password" />
                <TextInput
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    value={data.password_confirmation}
                    className="mt-1 block w-full"
                    autoComplete="new-password"
                    onChange={e => setData('password_confirmation', e.target.value)}
                    required
                />
                <InputError message={errors.password_confirmation} className="mt-2" />
            </div>
            <label className="flex items-start gap-3 pt-2 text-sm text-slate-600">
                <Checkbox
                    name="accepted_terms"
                    checked={data.accepted_terms}
                    onChange={e => setData('accepted_terms', e.target.checked)}
                    required
                />
                <span>
                    I agree to the{' '}
                    <a href="#" className="font-semibold text-slate-900 underline">Terms</a>
                    {' '}and{' '}
                    <a href="#" className="font-semibold text-slate-900 underline">Privacy Policy</a>.
                </span>
            </label>
            <InputError message={errors.accepted_terms} />
            <div className="mt-6 flex items-center justify-between">
                <Link
                    to="/login"
                    className="rounded-md text-sm text-gray-600 underline hover:text-gray-900
                        focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Already have an account?
                </Link>
                <PrimaryButton className="ms-4" disabled={processing}>
                    Create account
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>;
}
