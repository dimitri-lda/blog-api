import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import Checkbox from '@/components/Checkbox';
import InputError from '@/components/InputError';
import InputLabel from '@/components/InputLabel';
import PrimaryButton from '@/components/PrimaryButton';
import TextInput from '@/components/TextInput';
import GuestLayout from '@/layouts/GuestLayout';
import { api, RequestError } from '@/api/client';
import { useApp } from '@/app/AppContext';

export default function Login() {
    const navigate = useNavigate();
    const location = useLocation();
    const status = location.state?.passwordReset
        ? 'Your password has been reset. Log in with your new password.'
        : null;
    const { refetchContext } = useApp();
    const [data, setDataState] = useState({ email: '', password: '', remember_me: false });
    const [errors, setErrors] = useState({});
    const setData = (key, value) => setDataState(current => ({ ...current, [key]: value }));
    const flatten = payload => Object.fromEntries(
        Object.entries(payload?.errors ?? {}).map(([field, messages]) => [
            field,
            Array.isArray(messages) ? messages[0] : messages,
        ]),
    );
    const login = useMutation({
        mutationFn: () => api.post('/auth/login', data),
        onSuccess: async () => {
            await refetchContext();
            navigate('/');
        },
        onError: error => {
            setErrors(error instanceof RequestError ? flatten(error.payload) : {});
            setData('password', '');
        },
    });
    const submit = event => {
        event.preventDefault();
        setErrors({});
        login.mutate();
    };

    return <GuestLayout title="Log in">
        {status && (
            <div className="mb-4 text-sm font-medium text-blue-600">
                {status}
            </div>
        )}
        <form onSubmit={submit}>
            <div>
                <InputLabel htmlFor="email" value="Email" />
                <TextInput
                    id="email"
                    type="email"
                    name="email"
                    value={data.email}
                    className="mt-1 block w-full"
                    autoComplete="username"
                    isFocused={true}
                    onChange={e => setData('email', e.target.value)}
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
                    autoComplete="current-password"
                    onChange={e => setData('password', e.target.value)}
                />
                <InputError message={errors.password} className="mt-2" />
            </div>
            <div className="mt-4 block">
                <label className="flex items-center">
                    <Checkbox
                        name="remember_me"
                        checked={data.remember_me}
                        onChange={e => setData('remember_me', e.target.checked)}
                    />
                    <span className="ms-2 text-sm text-gray-600">Remember me</span>
                </label>
            </div>
            <div className="mt-4 flex items-center justify-end">
                <Link
                    to="/forgot-password"
                    className="rounded-md text-sm text-gray-600 underline hover:text-gray-900
                        focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Forgot your password?
                </Link>
                <PrimaryButton className="ms-4" disabled={login.isPending}>
                    Log in
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>;
}
